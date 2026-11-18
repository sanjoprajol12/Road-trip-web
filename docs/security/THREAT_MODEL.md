# Threat Model (STRIDE, 2026-11-18)

Assets: rider accounts and sessions; private trips and emergency contacts; unpublished contributions and photos (location); staff powers (roles, verification, moderation); the audit trail; trust labels shown to riders.

| Threat | Example | Mitigation | Residual |
|---|---|---|---|
| Spoofing | credential stuffing, session theft | Argon2id, login rate limit, HttpOnly cookies, CSRF, session regeneration, absolute expiry | no MFA; no breached-password check |
| Spoofing | fake "verified expert" | claim-specific verification decided by admins, live checks | relies on staff judgement of statements |
| Tampering | editing someone else's trip or report | policies, parent-scoped lookups, author edits only while pending | — |
| Tampering | staff rewriting a rider's words | no endpoint can change the body after moderation; staff notes are separate | — |
| Repudiation | staff deny a decision | audit rows with actor, IP, before/after, written in the same transaction | — (the app role cannot update or delete audit rows) |
| Information disclosure | email or reviewer identity leak, private event details, photo GPS | resources whitelist fields, policy-gated reads, EXIF stripped, private disk | OSM tile requests reveal viewed map areas to the tile server |
| Information disclosure | account enumeration | same answers for sign-up, login, reset; checks only after a correct password | timing differences not measured |
| Denial of service | upload bombs, ReDoS, spam sign-ups | size/pixel limits, rate limits | no CAPTCHA; single instance |
| Elevation of privilege | rider to staff, moderator to admin, removing last admin | admin-only + password confirmation + row lock; sessions end on change | — |
| Elevation of privilege | revoked organizer keeps hosting | organizer status read live on every event action | events already created remain; staff can cancel |
| Safety harm | dangerous repair advice, stale road report taken as live | safety guides need a matching expert review; observed dates and age shown everywhere; "not an emergency service" notices | content quality depends on moderation |
