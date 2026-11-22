# Staff two-factor authentication: implementation plan (AR3)

**Status:** AR3 approved on 2026-11-22 (decision D-07 in `docs/product/OWNER_DECISIONS_2026-11-22.md`). MFA is **mandatory for staff before the public launch**, with a **14-day enrolment grace period**. Not implemented yet.

**Scope:** staff accounts only (`staff` guard, `staff_accounts`). Rider sign-in (Fortify on the `web` guard) is unchanged.

## Current state (inspected)

- **Staff sign-in:** custom `Admin\AuthController@login` on the `staff` guard, separate from Fortify.
  - Email and password, with a dummy hash so the response doesn't reveal whether an account exists.
  - Deactivated accounts and accounts without a role are refused.
  - The session is regenerated, `auth_started_at` is stored (the `SessionMaxAge` middleware enforces an absolute session age), and `staff.signed_in` is audited.
  - Rate limited (`staff-login`).
- **Sensitive actions:** password confirmation (`admin/auth/confirm-password`, recent-confirmation window) guards role and staff management.
- **Libraries:** `pragmarx/google2fa` and `bacon/bacon-qr-code` are already installed as Fortify dependencies, so **no new package** is needed. Fortify's `TwoFactorAuthenticationProvider` can be reused directly with the staff model.

## Design

### Data (additive migration)

`staff_accounts` gains:

- `two_factor_secret` (text, encrypted cast)
- `two_factor_recovery_codes` (text, encrypted cast; a JSON list of **hashed** codes, so a stolen database copy plus the app key reveals no usable codes)
- `two_factor_confirmed_at` (timestamp)

A new table, `staff_two_factor_used_codes` (`staff_account_id`, `window`, `used_at`), blocks reuse of a TOTP code within its 30-second window.

### Policy (D-07)

- **Required for all staff.** No `optional` mode is shipped. Disabling 2FA is not offered.
- **Grace period:** each staff account gets 14 days to enrol.
  - **When the clock starts:** at the later of two moments: when the account is created (or its invitation accepted), or when the requirement is switched on in that environment.
  - **Data:** stored as `staff_accounts.mfa_required_from`.
- **Switch:** `STAFF_MFA_ENFORCED`, an environment variable.
  - It is `true` in production; the production configuration check refuses to start with it off.
  - It may be off in local development and E2E. There is no staff-editable setting, because a setting could weaken the requirement.
- **During the grace period:**
  - full access;
  - a banner on every screen: "Set up two-factor sign-in by <date>" with the days left;
  - a reminder email at 7 days and 1 day left.
- **After the grace period and not enrolled:**
  - after the password step, the staff member can only reach the enrolment screen and sign out;
  - every other staff route answers 403 `{"mfa": "enrolment_required"}` (middleware `staff-mfa`, added to the authenticated staff route group).
- **Administrator reset:**
  - administrators can reset another staff member's 2FA, with password confirmation;
  - the reset is audited and the target's sessions are revoked;
  - **no new grace period:** the target must enrol at their next sign-in;
  - administrators never see the secret or the codes.
- **Before launch:** every staff account must have enrolled (the readiness check counts the unenrolled accounts). The grace period is for accounts added after that.

### Sign-in (two steps)

1. `POST admin/auth/login`: email and password checked as today.
   - Not enrolled: sign in as today.
   - Enrolled: do **not** log in. Store `staff_mfa_pending = {id, at}` in the session (5-minute expiry), regenerate the session, and answer `{"two_factor": true}`.
2. `POST admin/auth/two-factor-challenge` with `{code}` or `{recovery_code}`:
   - Rate limited (5 per minute, keyed by the pending id and IP).
   - Valid TOTP (±1 window, not reused) or an unused recovery code (that code is then consumed): log in, regenerate, set `auth_started_at`, and audit `staff.signed_in` with `via: totp | recovery_code`.
   - A recovery code used: email the staff member ("a recovery code was used; N left") and show a banner to regenerate the codes.
   - Failures: audit `staff.mfa_failed` (no code stored).
   - **Audited (D-07):** enrolment, recovery-code use, administrator resets, emergency (break-glass) recovery. After 10 failures, the pending state is cleared and the password step must be repeated.

### Enrolment (signed-in staff, password confirmed within 10 min)

- `POST admin/auth/two-factor`: generate the secret (not yet active). Returns a QR code (SVG via bacon, rendered by the portal) and the manual key.
- `POST admin/auth/two-factor/confirm` with `{code}`: set `two_factor_confirmed_at`, generate 8 recovery codes, and show them **once**. Audit `staff.mfa_enabled`.
- `POST admin/auth/two-factor/recovery-codes`: regenerate (password confirmation required); the old codes stop working. Audited.
- No disable endpoint. MFA is required (D-07); a lost device goes through the recovery paths below.

### Recovery paths

1. **Lost device:** a recovery code at sign-in, then re-enrol.
2. **Lost device and codes:** another administrator resets 2FA (audited), the staff member re-enrols at next sign-in.
3. **Last administrator locked out:** an `artisan staff:mfa-reset {email}` command, which needs server access, writes an audit entry and asks for confirmation. Documented as break-glass.

### Staff portal

- A sign-in second step: a 6-digit code field (`autocomplete="one-time-code"`, numeric keyboard) and a "Use a recovery code" toggle.
- Account → "Two-factor sign-in": enrol with the QR code and manual key, confirm, and show the recovery codes once (copy and download as text). Regenerate is also here.
- Staff list: an "MFA on" column; an administrator reset action.

### What does not change

Rider authentication, the rider and staff guard separation, permissions, `RouteAuthorizationTest` rules (the new routes are added to its lists), password reset (a reset does **not** remove 2FA), and session max age.

## Tests

**Feature tests:**

- Enrolment needs password confirmation. Confirming with a wrong code fails.
- The sign-in flow: password only, then challenge, then logged in. Without the challenge, staff routes give 401.
- The challenge expires after 5 minutes. TOTP reuse in the same window is refused. The ±1 window is accepted and ±2 refused (time travel).
- A recovery code works once. Regenerated codes invalidate the old ones.
- Rate limit on the challenge. 10 failures clear the pending state.
- **Grace period:**
  - day 13: full access with the banner; day 15: only enrolment and sign-out, other routes 403;
  - the clock starts at the later of account creation and enforcement;
  - an administrator reset gives no new grace period;
  - production refuses to start with enforcement off.
- An administrator reset (password confirmed, audited, sessions revoked). A non-admin cannot reset. The break-glass command.
- The secret and codes are encrypted at rest and never in any API response or audit entry.
- `RouteAuthorizationTest` covers the new routes.

**E2E** (Chromium, Firefox, WebKit once available):

- Enrol, generating codes with an `otplib`-style TOTP in the test from the manual key.
- Sign out and back in with a code, then with a recovery code.
- Admin reset.
- After the grace period, the portal is blocked until enrolment; during it, the banner shows.

## Effort

- About one and a half sessions.
- **Risk:** medium, because it touches sign-in. The grace period is the rollout: existing staff enrol within 14 days of enforcement, which must be on before the public launch.
