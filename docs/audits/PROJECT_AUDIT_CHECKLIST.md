# Project Audit Checklist (A–M)

Status values: PASS, PARTIAL, FAIL, NOT TESTED, NOT STARTED, NOT APPLICABLE. PASS only with evidence produced in that session. Latest: 2026-11-18, session 4 (Laravel architecture). **The project is not fully verified** (see open items).

| Cat | Area | Status | Evidence / remaining work |
|---|---|---|---|
| A | Testing and code quality | PARTIAL | Laravel: 55 feature tests (381 assertions) on real Postgres. Web SPA: vue-tsc, oxlint + eslint clean, 6 unit tests, build; E2E 9 tests × Chromium, Firefox, Pixel 7. Admin: same gates, 12 unit tests, E2E 8 tests × Chromium, Firefox. Not run: WebKit, component tests, load tests |
| B | Database integrity, migrations, backups | PARTIAL | Laravel migrations (8) from zero; FKs, checks, partial unique indexes; least-privilege roles (tested); append-only audit (tested); idempotent setup script. No backups/restore (no production database) |
| C | Logging and observability | PARTIAL | Laravel log channel; health endpoint with DB check; audit log. No monitoring, alerting or error tracking |
| D | CI/CD | PARTIAL | Workflows for both repos (backend tests + composer audit, SPA checks + pnpm audit, E2E on Postgres 17 + Mailpit). Never run on GitHub. No deployment |
| E | Accessibility and browser compatibility | PARTIAL | Labels, one h1 per page, skip links, focus rings, native dialogs, keyboard-operable menu, reduced motion, AA contrast tokens; 0 horizontal overflow on 13 public pages × 3 widths and 10 admin pages × 2 widths. Not done: screen reader, axe, Lighthouse, WebKit |
| F | Dependency and supply-chain security | PARTIAL | `composer audit` clean; `pnpm audit --prod` clean in both SPAs with 1 documented build-time exception (no upstream fix); 2 moderate dev-only advisories; versions pinned by lockfiles |
| G | Performance and Core Web Vitals | PARTIAL | Route code-splitting, Leaflet loaded only on route pages, hero preload, WebP srcsets, lazy images. No Lighthouse/CWV measurement |
| H | Documentation and handover | PASS | docs/{product,architecture,api,database,security,design,audits,sessions}, generated API docs, handoff, session reports 01–04 |
| I | Scope tracking | PASS | `product/FEATURE_SCOPE.md` (decisions, open items, future recommendations), roadmap |
| J | Authentication, authorization, abuse prevention | PARTIAL | See `security/SECURITY_REQUIREMENTS.md`: auth flows, sessions, CSRF, rate limits, policies, IDOR, role escalation, uploads all tested. Open: CAPTCHA, MFA for staff, external review |
| K | Privacy and data lifecycle | PARTIAL | Email never public, reviewer hidden, emergency contacts and trips private (staff excluded), EXIF stripped from photos, cascades on account deletion. Open: account deletion UI, data export, privacy policy, retention periods |
| L | AI safety | NOT APPLICABLE | No AI features |
| M | Critical user workflows | PARTIAL | Verified end to end: sign-up/confirm/sign-in/out/reset/session persistence; profile; verification apply + staff decisions + suspension + reinstatement; roles with password confirmation; audit log; road report submission; moderation publish; event create/publish/join/waitlist promotion; group trip with viewer access and outsider refusal. Backend-tested but not E2E: invitations, approval joining, staff event cancellation, guide expert review, photo uploads in the browser |
