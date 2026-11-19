# Authentication Flow

Two separate audiences (owner direction 2026-11-18, migration stage A6). **Riders** sign in on the public website with rider accounts (`users`, guard `web`, Fortify + custom sign-up). **Staff** sign in on the staff portal with staff accounts (`staff_accounts`, guard `staff`, own controller). Nothing is shared: not the table, password, session cookie, session table, reset broker or provisioning. No tokens in localStorage; Supabase Auth is not used.

## Session model
| | Rider | Staff |
|---|---|---|
| Cookie | `my-ride-nepal-session` | `my-ride-nepal-session_staff` |
| Table | `sessions` | `staff_sessions` |
| Guard | `web` (Sanctum stateful reads it) | `staff` |
| Idle timeout | `SESSION_LIFETIME` (120 min) | `STAFF_SESSION_IDLE_MINUTES` (60) |
| Absolute lifetime | `SESSION_MAX_MINUTES` (7 days) | `STAFF_SESSION_MAX_MINUTES` (12 h) |
| CSRF cookie from | `GET /sanctum/csrf-cookie` | `GET /api/v1/admin/auth/csrf-cookie` |

`App\Http\Middleware\SelectSessionAudience` (global, before any session starts) picks the staff set for `/api/v1/admin/*` and the rider set for everything else. Cookies are host-only (`SESSION_DOMAIN=null`) and every frontend proxies the API same-origin, so CORS stays closed. Session ids are regenerated on login; logout invalidates them.

## Rider flows (`/api/v1/auth`, unchanged URLs)
| Flow | Endpoint(s) | Behaviour |
|---|---|---|
| Bootstrap | `GET /auth/session` | 200 with the rider or `null` |
| Sign-up | `POST /auth/register` (throttle `register`) | accepts only name, email, password (extra fields like `role` are ignored); creates a rider account, never a staff account; 24 h signed confirmation link; existing email: same 202 answer |
| Confirm / resend | `GET /auth/verify-email/{id}/{hash}`, `POST /auth/email/resend` | as before |
| Login | `POST /auth/login` (5/min per email+IP) | rider accounts only: staff credentials are "do not match"; unconfirmed / suspended / deactivated messages only after a correct password |
| Logout, forgot, reset, change, confirm | `POST /auth/logout`, `/auth/forgot-password`, `/auth/reset-password`, `PUT /auth/password`, `POST /auth/confirm-password` | rider broker `users` (table `password_reset_tokens`), links to `APP_URL/reset-password` |
| Sessions | `GET /users/me/sessions`, `DELETE /users/me/sessions/others` | |

## Staff flows (`/api/v1/admin/auth`)
| Flow | Endpoint | Behaviour |
|---|---|---|
| CSRF | `GET csrf-cookie` | 204; starts the staff session and sets XSRF-TOKEN for it |
| Bootstrap | `GET session` | the staff account (roles, permissions, `rider_linked`) or `null` |
| Login | `POST login` (throttle `staff-login`, 5/min per email+IP) | staff accounts only: rider credentials, unknown emails, wrong passwords and accounts without a password all get "These credentials do not match a staff account."; deactivated / no role only after a correct password; audited `staff.signed_in` |
| Logout | `POST logout` | 204, staff session invalidated |
| Forgot / reset | `POST forgot-password`, `POST reset-password` | broker `staff` (table `staff_password_reset_tokens`, 30 min), link to `ADMIN_URL/reset-password`; min 12 characters; all staff sessions of that account end |
| Change / confirm | `PUT password`, `POST confirm-password` | confirmation (423 flow) required before role, role-assignment and invitation changes |
| Invitation | `POST invitations/accept` | token (hash only stored, 72 h, single use) creates a staff account with its own password, or adds roles to an existing staff account after its staff password; a rider account is never involved |
| Rider link | `POST rider-link` (rider email + rider password), `DELETE rider-link` | explicit, audited; only feeds the "nobody reviews their own work" rule; grants nothing |
| Preview | `POST /admin/preview-token` | 30-minute signed token (`App\Support\PreviewToken`) that lets the public API through maintenance mode; replaces the old "staff browse the site during maintenance" behaviour |

## Provisioning
- **No staff sign-up** (no route; `RouteAuthorizationTest` and `StaffAuthTest` prove it).
- First administrator: `php artisan mrn:staff:create-admin <email> --name="…"` on the server (password asked twice, hidden, ≥ 12 characters). Refused once an administrator with a password exists; also completes a carried-over administrator that has no password yet.
- Everyone else: invitation from the Staff page (`users.invite` + password confirmation, cannot invite beyond own permissions).
- Staff who held roles under the old model were carried over by migration `2026_11_18_100011` with the same roles and **no password**; they set one through a staff password link (Staff page, or Forgot password) or the console command. Accounts are never linked by email.
- Two-factor sign-in for staff is **not enabled** (owner decision AR3 pending). Option: Fortify's TOTP feature on the staff guard; its libraries (`pragmarx/google2fa`, `bacon/bacon-qr-code`) are already installed.

## Self-review rule
`StaffAccount::isSamePersonAs($riderId)`: the linked rider account, and conservatively a rider with the same email. Moderation (`ContributionPolicy::moderate`), claim decisions (`VerificationPolicy::decide`), guide expert review (`GuidePolicy::expertReview`), rider account actions (`UserController`, `SuspendUser`) refuse it.

## Migration from Supabase Auth
No real accounts existed locally (0 users); the remote project was not touched. Supabase/GoTrue bcrypt (`$2a$10$`) compatibility was verified on 2026-11-18 with a disposable local GoTrue account: PHP verifies it, the app re-hashes to Argon2id on first login (`FortifyServiceProvider::passwordMatches`, tested in `AuthTest`). A future import of real users would copy id (UUID), email, `email_confirmed_at`, `encrypted_password` into `users`; see `../database/MIGRATION_STRATEGY.md`.
