# My Ride Nepal: working instructions

Read first, every session: `docs/handoff.md`, then `docs/audits/PROJECT_AUDIT_CHECKLIST.md` and the doc for the area you touch (`docs/architecture/*`, `docs/security/*`).

## Layout
- `web/` (this repo, GitHub Road-trip-web): **Laravel 12 API** (`app/`, `routes/api.php`, `database/`), the **public site and rider platform SPA** (`resources/app`, Vue 3, built into `public/app`), `docs/`, and `supabase/config.toml` (local Postgres + Mailpit only).
- `../admin/` (GitHub Road-trip-admin): **staff-only** Vue SPA. Never put staff screens here or public pages there.
- **No shared code between the repos** (owner decision). If both need something, each keeps its own copy.
- `/var/www/html/Road-trip/CLAUDE.md` and `handoff.md` are symlinks into this repo. Setup: `docs/architecture/DEVELOPMENT_SETUP.md`.

## Owner rules
- No git commits, no `git add`, no Claude attribution, unless the owner asks in that session.
- The remote Supabase project is out of scope: never connect to it, read it, migrate it or import from it without explicit approval. Supabase locally = PostgreSQL (+ Mailpit) only.
- Ask before: new dependencies outside the approved stack, deleting files with real content, paid services, deployment decisions, destructive database operations.
- Never invent content (destinations, prices, road conditions, riders, reviews, photos, safety guidance). Dev seed rows carry `is_dev_seed = true`.
- Photos: CC0/public domain or owner-supplied, only for the place shown, credited in `public/images/photos/LICENSES.md`.
- No emojis in the UI. Lucide icons only. Follow `docs/design/DESIGN_SYSTEM.md`.
- Business rules and authorization live in Laravel (policies, actions, validation) plus database constraints. Frontend checks are UX only. Every new endpoint: policy/gate, validation, audit row for staff decisions, feature test.
- Migrations: `php artisan migrate --database=pgsql_owner` (owner role). The app role `mrn_app` must never get DDL rights.
- Never put secrets in either SPA; there are no frontend env secrets at all.
- Every session ends with: checks run (below), `docs/handoff.md` updated (six sections), a new HTML report in `docs/sessions/`.

## Checks
```
php artisan test                                                      # backend, real Postgres (myridenepal_test)
pnpm type-check && pnpm lint && pnpm test:unit && pnpm build          # web SPA (same in ../admin)
pnpm test:e2e --project=chromium --project=firefox --project=mobile  # web E2E; needs `pnpm db:start`
cd ../admin && pnpm test:e2e --project=chromium --project=firefox     # admin E2E (starts Laravel from ../web)
composer audit && pnpm audit --prod
```
E2E reuses running servers on :8010/:4181 locally: stop old ones first. WebKit needs system libraries (owner, sudo). Never create `web/.env.local` (Laravel would load it instead of `.env` when APP_ENV=local).
