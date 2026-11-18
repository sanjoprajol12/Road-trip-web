# Handoff

Last updated: 2026-11-18, session 5 (Vercel container deployment preparation). Earlier sessions: 1 (foundation), 2 (Supabase auth, verification), 3 (removed shared package), 4 (Laravel architecture and product areas).

## 1. Goal
My Ride Nepal, a motorcycle travel platform for Nepal, with three layers decided by the owner on 2026-11-18:
- `web/` (repo Road-trip-web): **Laravel 12 REST API** (`/api/v1`, Fortify + Sanctum cookie sessions, policies, actions, migrations) **and the public site / rider platform** (Vue 3 SPA in `resources/app`, served by Laravel from `public/app`).
- `admin/` (repo Road-trip-admin): **staff-only** Vue 3 SPA.
- Supabase = **local PostgreSQL only** (plus Mailpit). The remote Supabase project is out of scope.
No shared code between the repos. The project is not yet fully verified (WebKit, screen reader, Lighthouse, CI on GitHub, external security review are open).
- **Hosting:** Vercel selected for the combined Laravel API and public SPA. `web/Dockerfile.vercel` and `web/Caddyfile` prepare a container build; the Vercel project has not been linked or deployed. Production database, domain, email provider, and durable photo storage remain unselected.

## 2. Current State
- **Backend:** 8 Laravel migrations, 19 models, 5 policies, 7 actions, 96 API routes, Scribe docs at `/docs`. 55 feature tests (381 assertions) pass on real Postgres. `composer audit` clean.
- **Web SPA:** Home, Explore, Destination, Routes (Leaflet map, stretch-by-stretch reports), Experiences (library, detail, progressive contribute form with photos), Events (list, detail, host form, organizer workspace), Preparation (guides), My Rides (trip workspace), rider profiles, Account (profile, verification, notifications, contributions, emergency contacts, password and sessions), auth pages. Types, lint, 6 unit tests, build pass. E2E 27/27 (9 tests × Chromium, Firefox, Pixel 7).
- **Admin SPA:** Overview, Verification queue/detail, Moderation, Destinations, Routes + segment editor + service points, Events oversight, Guides, People (roles with password confirmation, suspension), Audit log. Types, lint, 12 unit tests, build pass. E2E 16/16 (8 tests × Chromium, Firefox), twice in a row.
- **Database:** local Supabase container now runs only Postgres (~55 MB) and Mailpit (~10 MB). App database `myridenepal` (+ `myridenepal_test`); roles `mrn_owner` (migrations) and `mrn_app` (requests: no DDL, no RLS bypass, cannot change audit rows). The old `postgres` database (Supabase schema, 4 dev destinations, test-run audit rows, 0 users) is untouched.
- **Not built:** Offline Ride Pack (PWA). **Not verified:** WebKit (missing system libraries), CI on GitHub, screen reader, axe, Lighthouse.
- Nothing committed (owner rule). No Laravel or Vite server is left running; the Postgres + Mailpit containers are (stop with `pnpm db:stop` in web).
- Vercel container app scratch storage is directed to `/tmp`; that is not durable. Contribution photo handling still uses Laravel's local disk and must be changed to private object storage before launch.

## 3. Active Files
- Backend: `web/routes/api.php`, `web/routes/web.php` (SPA fallback), `web/bootstrap/app.php`, `web/app/{Http/Controllers/Api/V1/**,Actions/**,Policies/*,Enums/*,Models/*,Http/Resources/*,Http/Middleware/*,Notifications/*,Providers/*,Support/Audit.php}`, `web/config/{fortify,cors,hashing,mrn,database,scribe}.php`, `web/database/{migrations,seeders,factories}`, `web/tests/Feature/*`, `web/scripts/setup-local-db.sh`, `web/supabase/config.toml`, `web/.env.example`.
- Web SPA: `web/resources/app/src/**` (notably `lib/api/client.ts`, `types/api.ts`, `features/{routes,experiences,events,preparation,rides,profiles,authentication,verification,explore}`), `web/vite.config.ts`, `web/playwright.config.ts`, `web/e2e/*`.
- Admin: `admin/src/**` (notably `lib/api/client.ts`, `lib/usePasswordConfirm.ts`, `types/api.ts`, `features/{verification,moderation,content,events,guides,users,audit,dashboard,authentication}`), `admin/vite.config.ts`, `admin/playwright.config.ts`, `admin/e2e/*`.
- CI: `web/.github/workflows/ci.yml`, `admin/.github/workflows/ci.yml`.
- Docs: `web/docs/{architecture,api,database,security,product,design,audits}/*`, `web/docs/sessions/2026-11-18-session-04-laravel-architecture.html`.

## 4. Changes Made
- Owner decisions applied: Laravel 12 (not 13), Fortify/Sanctum/Scribe/axios, RLS dropped for policies, remote Supabase untouched, repo names unchanged, public site in `web` (owner chose this when a peer session reported a conflicting decision; asked via a question, no Inertia).
- Restructure: briefly merged both SPAs into `admin` (per the first brief), then, after the owner's answer, restored `admin` to staff-only from a snapshot and moved the public SPA into `web/resources/app`.
- Laravel: schema for users/roles/verifications/audit, destinations/routes/segments/service points, contributions/media/reports, events/registrations/updates, trips/members/stops/expenses/checklist, guides, emergency contacts, notifications; Fortify + custom sign-up and email link; Argon2id; bcrypt legacy path (verified with a real GoTrue hash); sessions with idle + absolute expiry and revocation; rate limits; security headers with separate CSP for the SPA page; photo upload validation and WebP re-encode; audit log; health endpoint.
- Frontends: Supabase client removed from both; axios client with CSRF handling and readable errors; all pages listed in section 2.
- Supabase: legacy migrations, seed and RLS tests archived to `docs/database/legacy-supabase/`; auth, API gateway and PostgREST disabled; Mailpit SMTP exposed on 54325.
- Security fixes found by tests: password-reset endpoint revealed unknown emails (now generic); email link failed when opened from a mail client (no session); deleting a segment broke road reports (now refused); audit rows were changeable by the app role (now revoked).
- Vercel deployment prep: added a FrankenPHP container build with Node-based SPA asset compilation and Caddy routing to Laravel; documented project root, Container preset, environment requirements, migration process, and durable-storage blocker.

## 5. List of Failed Attempts
- Laravel served SQLite errors because `web/.env.local` (old Vite file) replaced `.env` under `APP_ENV=local`; moved into `resources/app/`.
- `whereILike` does not exist in Laravel 12 (`whereLike` is case-insensitive); base `Controller` lacked `AuthorizesRequests`.
- Fortify resolves the reset-link response with arguments, so an `instance` binding was ignored; bound a class instead.
- Verify-email route named `auth.verify-email` was actually `api.auth.verify-email`.
- Test harness: mixing `actingAs()` on the web guard with Sanctum's guard across requests; fixed by a fresh guard per identity.
- Production build: CSP `default-src 'none'` blocked the SPA page; Vite prefixed `/app/` onto public images; both fixed and verified in a browser.
- `pnpm audit` ignore list in `package.json` is ignored by pnpm 12; moved to `pnpm-workspace.yaml`.
- Kong still started with the API disabled; excluded with `supabase start -x kong`.
- First full admin E2E run: 1 Firefox failure. Cause: the `auth-email` rate limiter keyed by email+IP put every password confirmation (no email field) from one IP into one bucket; now keyed per signed-in account.

## 6. Next Steps
1. **Owner:** import `Road-trip-web` into Vercel with root directory `web` and Container preset; provision production Postgres, email, domain, backups, and durable private photo storage; use `docs/architecture/DEPLOYMENT_READINESS.md` for required environment and migration setup. Then deploy a preview and verify it before production.
2. Push both repos and let CI run (admin E2E needs read access to Road-trip-web: add `WEB_REPO_TOKEN` if private); run `sudo pnpm exec playwright install-deps webkit`.
3. Content: real destinations, routes and guides from qualified people (guides in safety categories need a verified expert reviewer account).
4. Verification track: screen-reader pass, axe, Lighthouse on production builds, WebKit, external security review.
5. Future recommendations awaiting approval are listed in `docs/product/FEATURE_SCOPE.md` (Offline Ride Pack, CAPTCHA, staff MFA, account deletion/export, saved items, email digests, queued jobs, map tile provider, automatic outdating).
