# Security Requirements and Status

Status: **implemented** = in code and covered by an automated test; **partial**; **open**. Verified 2026-11-18 locally. Not a claim that the system is secure: no external review or penetration test has been done.

| # | Requirement | Status | How |
|---|---|---|---|
| 1 | Authentication | implemented | Fortify + custom sign-up/confirmation; 18 auth feature tests; 18 web E2E runs |
| 2 | Password hashing and reset security | implemented | Argon2id; strict hash verification; bcrypt legacy path re-hashes; reset tokens single-use, 30 min, generic answers, sessions ended |
| 3 | Session security | implemented | HttpOnly SameSite=Lax cookie, DB sessions, regenerate on login, idle + absolute expiry, revoke others, end on password/role change and suspension. `Secure` flag must be on in production |
| 4 | CSRF | implemented | Sanctum stateful API: XSRF cookie + header on state-changing requests |
| 5 | CORS | implemented | exact origins (`CORS_ALLOWED_ORIGINS` or `APP_URL`), credentials; SPAs normally same-origin via proxy |
| 6 | Rate limiting | implemented | login 5/min (email+IP), sign-up per IP per hour, auth emails 5/10 min, applications 5/day, contributions 30/day, reports 30/day, uploads 60/h, API 120/min |
| 7 | Input validation | implemented | every endpoint validates; enums, lengths, formats; detail keys whitelisted |
| 8 | SQL injection | implemented | Eloquent/query builder bindings only; LIKE wildcards escaped in searches |
| 9 | XSS | implemented | Vue escapes output; no `v-html`; HTML page CSP `script-src 'self'`; API CSP `default-src 'none'` |
| 10 | Security headers | implemented | nosniff, frame DENY, Referrer-Policy, CORP, CSP; HSTS on HTTPS |
| 11–12 | File uploads | implemented | size ≤ 8 MB, decoded as real JPEG/PNG/WebP, dimension limits, re-encoded to WebP (strips EXIF/GPS), private disk, authorized download; tests reject fake images and HTML disguised as PNG |
| 13–14 | IDOR / ownership | implemented | policies; child lookups through parents; tests for trips, contacts, contributions, events |
| 15 | Role escalation | implemented | roles only via admin + password confirmation; last admin kept; tests |
| 16 | Audit logging | implemented | actions write audit rows in their transaction; admin-only read |
| 17 | Sensitive data | implemented | email never public; reviewer hidden from riders; emergency contacts owner-only; staff cannot open trips |
| 18 | Environment variables | implemented | `.env` git-ignored in both repos; no secrets in SPAs; passwords never committed (history checked) |
| 19 | Dependency checks | partial | `composer audit` clean; `pnpm audit --prod` clean except one accepted build-time advisory (GHSA-fc5q-39xc-vjpf, no fix released, not in bundles); 2 moderate dev-only advisories |
| 20 | Brute force and abuse | partial | rate limits, generic answers, suspension, reports. **Open:** CAPTCHA on sign-up, account lockout notifications, spam heuristics |
| — | Database least privilege | implemented | `mrn_app`: no superuser, no DDL, no RLS bypass (test) |
| — | Account deletion / data export | open | not built |
