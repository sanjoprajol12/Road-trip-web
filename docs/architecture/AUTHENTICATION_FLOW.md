# Authentication Flow

Laravel owns authentication: Fortify (login, logout, forgot/reset, password change, password confirmation) + a custom sign-up and email confirmation (`AuthController`), with Sanctum cookie sessions for both SPAs. No tokens in localStorage; Supabase Auth is disabled.

## Session model
- Browser cookies: `my-ride-nepal-session` (HttpOnly, SameSite=Lax, Secure in production) and `XSRF-TOKEN` (readable by JS, echoed as `X-XSRF-TOKEN`).
- Sessions live in the `sessions` table (driver `database`), so they can be listed and revoked.
- Idle timeout `SESSION_LIFETIME` (120 min); absolute lifetime `SESSION_MAX_MINUTES` (7 days, `SessionMaxAge` middleware).
- The session id is regenerated on login and on email-link sign-in (no fixation); logout invalidates it.

## Flows
| Flow | Endpoint(s) | Behaviour |
|---|---|---|
| Bootstrap | `GET /api/v1/auth/session` | 200 with the user or `null` (no 401 noise for guests) |
| CSRF | `GET /sanctum/csrf-cookie` | the SPA's axios client calls it before the first state-changing request |
| Sign-up | `POST /auth/register` (throttle `register`) | creates the account (Argon2id), sends a 24 h signed confirmation link, **no session**. Existing email: same 202 answer, nothing created or sent |
| Confirm | `GET /auth/verify-email/{id}/{hash}?expires&signature` (`web` middleware) | valid link: marks verified, signs in, redirects to `/account?verified=1`; tampered/expired: `/sign-in?verification=invalid` |
| Resend | `POST /auth/email/resend` | generic answer |
| Login | `POST /auth/login` (5/min per email+IP) | wrong password or unknown email: same 422 message; correct password but unconfirmed: "Confirm your email first"; suspended: "suspended until …". Legacy bcrypt hashes verify and are re-hashed to Argon2id |
| Logout | `POST /auth/logout` | 204, session invalidated |
| Forgot | `POST /auth/forgot-password` | same answer for known and unknown addresses; link to `/reset-password?token&email`, valid 30 min |
| Reset | `POST /auth/reset-password` | sets password, confirms the email, **deletes all sessions of that user**; SPA sends the rider to sign in |
| Change password | `PUT /auth/password` | needs the current password; other sessions are deleted |
| Confirm password | `POST /auth/confirm-password` | required (423) before staff role changes |
| Sessions | `GET /users/me/sessions`, `DELETE /users/me/sessions/others` | list devices; sign out everywhere else |

## Migration from Supabase Auth
No real accounts existed locally (0 users); the remote project was not touched. Supabase/GoTrue bcrypt (`$2a$10$`) compatibility was verified on 2026-11-18 with a disposable local GoTrue account: PHP verifies it, the app re-hashes to Argon2id on first login (`FortifyServiceProvider::passwordMatches`, tested in `AuthTest`). A future import of real users would copy id (UUID), email, `email_confirmed_at`, `encrypted_password` into `users`; see `../database/MIGRATION_STRATEGY.md`.
