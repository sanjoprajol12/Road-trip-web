# Security Architecture

Status: **approved** direction (2026-11-27). This is a design, not a claim that anything is secure. Security status is reported only from tests that ran, and from an external review before launch.

## 1. What we protect

| Asset | Why it matters |
|---|---|
| Rider accounts and sessions | takeover lets an attacker post as the rider and read private trips |
| Private trips, emergency contacts, private notes | personal safety and privacy; staff must not see them either |
| Unpublished contributions and their photos | may reveal where a rider is or was |
| Staff powers (roles, moderation, verification, suspension, CMS, settings) | abuse changes what thousands of riders trust |
| Trust labels and the audit trail | the product's value is "who reported what, when"; tampering destroys it |
| Secrets (SMTP, storage, provider keys, encryption key) | lead to wider compromise |

**Threats by actor:** an anonymous attacker (credential stuffing, enumeration, spam, upload abuse), a malicious rider (IDOR on trips, events and contributions; privilege escalation; stored XSS; self-verification), a compromised or careless staff account (over-broad permissions, self-review, rewriting riders' words), and supply-chain compromise (npm packages).

## 2. Authentication

### 2.1 Two audiences (confirmed by the owner, Q-02)

| | Rider | Staff |
|---|---|---|
| Accounts | `users` | `staff_accounts` |
| Sign-up | public (if the registration setting is open) | none; invitation or a server console command only |
| Cookie | `mrn_session` | `mrn_staff_session` (path `/api/v1/admin`) |
| Idle / absolute lifetime | 120 min / 7 days (Q-27) | 60 min / 12 h |
| Password minimum | 10 (Q-27) | 12 |
| MFA | no | TOTP + recovery codes (Q-03, S14) |
| Reset link goes to | the frontend origin | the admin origin |

The session middleware picks the audience **by path**: `/api/v1/admin/*` reads only the staff cookie and table, and everything else reads only the rider cookie and table. A rider's credentials or cookie can never reach a staff route (SA-7, tested).

### 2.2 Sessions (TD-06)

- Token: 32 random bytes (`crypto.randomBytes`), base64url. The cookie holds the token; the database holds `sha256(token)`. A database leak does not reveal usable sessions.
- Cookie flags: `HttpOnly; Secure; SameSite=Lax; Path=…`, host-only (no `Domain`). `Secure` is off only on `localhost`.
- The session id is created fresh at sign-in (no fixation); the CSRF token is rotated with it.
- Every request checks the idle and absolute expiry and the account status. A suspended, deactivated or deletion-scheduled account fails on the next request (G18).
- All of an account's sessions end on password change or reset, suspension, deactivation, role change (staff), or "sign out everywhere".

### 2.3 Passwords

- Argon2id (`@node-rs/argon2`, OWASP parameters: m=19 MiB, t=2, p=1, revisited on the real host).
- Length is the rule (no composition rules), max 128. A check against known-breached passwords through the k-anonymity range API is proposed under Q-25/Q-27, and would be the only external call in sign-up.
- Unknown email and wrong password give the same answer and take similar time (a dummy hash is verified for unknown emails).

### 2.4 Email confirmation and password reset

- Tokens: 32 random bytes, stored hashed, single use, purpose-bound. Email confirmation lasts 24 h, password reset 30 min. A new token voids older ones of the same purpose.
- Sign-up, resend and forgot-password give the **same answer** whether or not the account exists (no enumeration). An existing address gets an "already registered" email instead.
- Reset links point only to the configured frontend origin (`APP_URL` / `ADMIN_URL` from the environment, never from the request), so there are no open redirects.

### 2.5 Re-authentication

Changing the password, privilege changes (roles, invitations), deleting the account and exporting data need the password re-entered within the last 10 minutes (`confirmed_at` on the session). Without it the answer is 403 `password_confirmation_required`, which the UI turns into a password prompt.

### 2.6 Staff MFA (S14, Q-03)

TOTP (RFC 6238) with an encrypted secret, 10 single-use recovery codes (hashed), and an enrolment grace period if mandatory. Audited: enrolment, a recovery code used, an administrator reset. Built in S14; the staff session design in S1 already has an `mfa_verified_at` slot, so nothing needs rework.

### 2.7 Login activity

`login_events` records success and failure per account with the time, an HMAC of the IP and the user agent. Riders and staff see their own history (`/me/login-activity`). Kept 90 days (Q-05).

## 3. Authorization

### 3.1 Layers

1. **Route guard** (`requireRider`, `requireRiderWrite`, `requireStaff('perm.key')`), declared on every route. A test lists all routes and fails on a route with no class, a staff route with no permission, or a permission key that is not in the registry (G15, P11).
2. **Policy functions** (`events.policy.ts` …): pure functions `(actor, resource) → allowed | reason`, unit-tested, called by services. They cover ownership, membership, claims, no-self-review and state.
3. **Scoped queries:** records are loaded through their owner or parent (`where trip_id = $trip and exists(member = session user)`). A record the caller may not see answers **404, not 403**, so its existence does not leak.
4. **Database constraints** as the last net (unique registrations, CHECKs, FK restrict, append-only audit).

### 3.2 Identities and claims

| Identity | Holds |
|---|---|
| Guest | nothing |
| Rider | ownership of their own records; **never** a staff permission |
| Verified claims (rider) | `trip_contributor` (a label), `event_organizer` (may host), `expert_reviewer:<field>` (may review safety guides in that field), `identity` (Q-11). Read **live** on every check, so a suspension or revocation applies on the next request |
| Staff | roles → permissions. Several roles add up. `admin` = every permission, including future ones |

**No rider verification ever grants a staff permission, and no staff role grants a rider claim** (P10, tested).

### 3.3 Permission registry (initial; one gate per key)

| Module | Keys |
|---|---|
| Overview | `overview.view` |
| Staff and riders | `users.view`, `users.emails.view`, `users.invite`, `users.activate`, `users.suspend`, `users.password_reset`, `users.sessions.revoke`, `users.roles.manage`, `users.manage_staff`, `users.deletions.view` |
| Roles | `roles.manage` |
| Audit | `audit.view` |
| Verification | `verification.view`, `verification.decide` (trip contributor, identity), `verification.decide_sensitive` (organizer, expert) |
| Content | `content.destinations.manage`, `content.routes.manage`, `content.services.manage` |
| Places | `places.manage`, `places.publish`, `places.review` |
| Contributions | `contributions.moderate`, `moderation.evidence.view` |
| Events | `events.view`, `events.cancel` |
| Guides | `guides.manage`, `guides.publish` |
| Media | `media.manage` |
| CMS | `cms.home.edit`, `cms.home.publish`, `cms.pages.manage`, `cms.faqs.manage`, `cms.blog.manage`, `cms.team.manage`, `cms.promotions.manage`, `cms.menus.manage`, `cms.notices.manage`, `cms.content.publish` |
| Contact | `contact.view`, `contact.manage` |
| Settings | `settings.general.manage`, `settings.branding.manage`, `settings.social.manage`, `settings.seo.manage`, `settings.footer.manage`, `settings.mail.manage`, `settings.storage.manage`, `settings.maps.manage`, `settings.security.manage`, `settings.trust.manage`, `settings.registration.manage`, `settings.maintenance.manage`, `system.cache.manage` |

### 3.4 Built-in roles (defaults; staff can create more)

| Role | Permissions |
|---|---|
| `admin` | all (fixed; cannot be edited or deleted) |
| `moderator` | `overview.view`, `users.view`, `users.suspend`, `verification.view`, `verification.decide`, `contributions.moderate`, `places.review`, `content.destinations.manage`, `content.routes.manage`, `content.services.manage`, `guides.manage`, `events.view`, `events.cancel`, `media.manage` |

### 3.5 Rules that are not just "has permission"

| Rule | Enforced in |
|---|---|
| Nobody grants, or builds a role around, a permission they do not hold; only administrators grant `admin` | staff service |
| The last **active** administrator keeps the role and cannot be deactivated (row lock) | staff service + concurrency test |
| Account actions are never applied to yourself; actions on staff targets also need `users.manage_staff` | policies |
| **No self-review:** a staff member never decides their own claim, contribution, place suggestion or guide review, nor acts on their own rider account (linked account, or the same email) | `isSamePerson()` used by every decision policy |
| Riders never see who reviewed them | response schemas |
| Authors edit only while draft or pending; staff never edit a rider's text (they request corrections) | contribution policy |
| Only approved, non-suspended organizers host or manage events; private events need an invitation | event policy |
| Trips: owner / editor / viewer; **staff have no access**; invitees see only the title, inviter and role until they accept | trip policy, scoped queries |
| Emergency contacts: the owner only (group sharing: Q-10) | scoped queries |
| Safety-critical guides publish only after review by an expert in the matching field who is not the author; editing clears the review | guide policy + DB CHECK |
| Contribution-suspended riders can still read, plan trips and join events | `requireCanContribute` on contribution routes only |

## 4. Threats and controls (OWASP Top 10 and the brief's list)

| Threat | Control | Verified by |
|---|---|---|
| Broken access control / IDOR | route classes, policies, parent-scoped queries, 404 for foreign records | route-matrix test; an IDOR test per resource (another rider's id → 404) |
| Privilege escalation | registry, cannot grant beyond own permissions, password confirmation, sessions end on role change | permission tests |
| SQL injection | Drizzle parameter binding only; any `sql` template uses parameters; `LIKE` wildcards escaped | lint rule against `sql.raw` with input; injection-string tests on search |
| XSS | React escapes output; no `dangerouslySetInnerHTML` (lint rule). CMS rich text is stored as structured blocks, not HTML; if HTML is ever needed, it is sanitized on the server with an allow-list. CSP `script-src 'self'` | lint; E2E with script payloads in every rider text field |
| CSRF | SameSite=Lax + synchronizer token header + Origin check (TD-09) | tests without and with a wrong token |
| CORS misconfiguration | closed by default (same-origin proxy); if opened, exact origins only, never `*` with credentials | config test |
| Brute force, credential stuffing | rate limits per email + IP, generic answers, login events; CAPTCHA (Q-25) | rate-limit tests |
| Account enumeration | same answers and similar timing for sign-up, login and reset | tests compare responses |
| Session hijacking | HttpOnly + Secure cookies, hashed tokens, rotation at sign-in, idle and absolute expiry, revoke all | session tests |
| Insecure password reset | hashed single-use 30-min tokens, fixed origin, all sessions end | tests |
| Insecure uploads | size, count and pixel limits; decoded and re-encoded by `sharp` (rejects polyglots, strips EXIF/GPS); random storage keys; never served from the API origin as HTML; private files only through authorized endpoints | tests with fake images and HTML disguised as PNG |
| SSRF | the server fetches only configured provider URLs (routing, geocoding, SMTP, storage), set by staff with `settings.*.manage`; host allow-list; no user-supplied URLs are ever fetched; GPX/GeoJSON is uploaded as a file, not by URL | tests on settings validation |
| Unsafe redirects | redirect targets are internal paths or configured origins only; CMS links and menu items allow internal paths or `https:` only | validation tests |
| Sensitive data exposure | response schemas list allowed fields; email never public; pino redaction; IPs stored only as HMAC | snapshot tests of public responses |
| Mass assignment | Zod strips unknown keys; services pick fields explicitly (e.g. `role` in a sign-up body is ignored) | tests |
| Error leakage | generic 500 with a request id; stack traces only in logs | test |
| Security misconfiguration | `helmet`; env validated at boot; production refuses the local storage driver, missing secrets or `Secure=false` | boot tests |
| Vulnerable dependencies | few dependencies, each justified; lockfiles; `npm audit --omit=dev` in CI; Dependabot or Renovate when a remote exists | CI |
| Logging and monitoring failures | audit for staff decisions and security events; login events; structured logs with request ids | — |
| API abuse | rate limits, pagination caps, body size limits (100 KB JSON), query timeouts (`statement_timeout`) | tests |

## 5. Secrets and configuration

- `.env` files are git-ignored. Each app has a `.env.example` with placeholders only. The two frontends have **no secrets at all**.
- Secrets in the backend environment: `DATABASE_URL` (app role), `SESSION_SECRET`-style keys for HMACs, `SETTINGS_ENCRYPTION_KEY` (32 bytes, for encrypted settings). Migration credentials (`MIGRATION_DATABASE_URL`, owner role) exist only in the deploy step, **never in the running app** (a lesson from the old project).
- Encrypted settings (SMTP password, storage keys): AES-256-GCM with a random IV per value; responses show only `"••••" + last 4`. Rotating the key = re-encrypting with a script.
- Secrets in logs: pino `redact` paths for authorization, cookie, password, token and secret fields.

## 6. Privacy

| Topic | Design |
|---|---|
| Data minimisation | sign-up asks for name, email and password only; profile fields are optional; IPs only as HMACs; no analytics trackers (Q-26) |
| Location privacy | photo GPS removed on upload; unpublished contributions visible only to the author and moderators |
| Retention | contact messages, login events, moderation evidence and deletion records cleaned by worker jobs (Q-05); each cleanup is audited, repeatable and safe to retry |
| Export | `/me/export`: the rider's profile, contributions, trips they own, saved items, emergency contacts and claims, as JSON (S13) |
| Deletion | per Q-04; processing switched off by default until tested on a copy; files removed after the database commit, with retries |
| Staff visibility | staff never see private trips, emergency contacts or private notes; rider emails need `users.emails.view` |

## 7. Database security

- `mrn_app`: CONNECT plus SELECT/INSERT/UPDATE/DELETE on application tables; no DDL, not superuser, no `BYPASSRLS`. INSERT and SELECT only on `audit_logs` (a trigger refuses UPDATE and DELETE). Extra revokes where a table should never be deleted from by the app (decided per table at S13).
- `mrn_owner`: migrations only, used by the deploy step.
- `statement_timeout` (e.g. 5 s) and `idle_in_transaction_session_timeout` on the app role.
- TLS to the database in production.

## 8. Security testing plan

| When | What |
|---|---|
| Every stage | API tests for 401/403/404 per endpoint class, IDOR tests per resource, validation tests, the route-matrix test |
| S1 | session, CSRF, rate limit, enumeration, password reset and role-escalation tests |
| S2, S6 | upload abuse tests |
| S10 | concurrency tests on capacity and waitlist |
| CI | `npm audit --omit=dev` in all three apps; lint rules (no `dangerouslySetInnerHTML`, no raw SQL with input) |
| Phase 8 | OWASP ZAP baseline scan against the E2E environment; manual review against OWASP ASVS level 2 for authentication, sessions and access control |
| Before launch | external review or penetration test (owner decision) |
