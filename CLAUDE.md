# My Ride Nepal: working instructions

Read first, every session: `docs/handoff.md`, then `docs/audits/PROJECT_AUDIT_CHECKLIST.md` and the doc for the area you touch (`docs/architecture/*`, `docs/security/*`).

## Layout (architecture Option A, owner direction 2026-11-18; replaces Option B)
- `web/` (this repo, GitHub Road-trip-web): **Laravel 12 API at the repo root** (REST `/api/v1`, `app/`, `routes/`, `database/`), Fortify + Sanctum, policies, actions, CMS/settings backend, `docs/`, `supabase/config.toml` (local Postgres + Mailpit only), **and the public website in `frontend/`** (Vue 3, its own `package.json`, lockfile, Vite config, `.env`, `vercel.json`, E2E). Laravel never serves the frontend; `frontend/` is excluded from the API image.
- `../admin/` (GitHub Road-trip-admin): **the staff portal only** (Vue 3, repo root `src/`).
- **No shared code between the repos** and no third repository or package: the small API client/format helpers exist once per app.
- Three independent processes: `composer dev` (API :8010), `pnpm dev` in `frontend/` (:5180), `pnpm dev` in `../admin` (:5181). No command starts two apps.
- `APP_URL` is the public website origin (rider email links), `ADMIN_URL` the staff portal origin. `/var/www/html/Road-trip/CLAUDE.md` and `handoff.md` are symlinks into this repo. Setup: `docs/architecture/DEVELOPMENT_SETUP.md`.

## Owner rules
- No git commits, no `git add`, no Claude attribution, unless the owner asks in that session.
- The remote Supabase project is out of scope: never connect to it, read it, migrate it or import from it without explicit approval. Supabase locally = PostgreSQL (+ Mailpit) only.
- Ask before: new dependencies outside the approved stack, deleting files with real content, paid services, deployment decisions, destructive database operations.
- Never invent content (destinations, prices, road conditions, riders, reviews, photos, safety guidance). Dev seed rows carry `is_dev_seed = true`.
- Photos: CC0/public domain or owner-supplied, only for the place shown, credited in `frontend/public/images/photos/LICENSES.md`.
- No emojis in the UI. Lucide icons only. Follow `docs/design/DESIGN_SYSTEM.md`.
- Business rules and authorization live in Laravel (policies, actions, validation) plus database constraints. Frontend checks are UX only. Every new endpoint: policy/gate, validation, audit row for staff decisions, feature test.
- Migrations: `php artisan migrate --database=pgsql_owner` (owner role). The app role `mrn_app` must never get DDL rights.
- Never put secrets in either frontend; there are no frontend env secrets at all. Provider keys (mail, storage, maps, routing) stay in Laravel and are used through server-side proxies.
- Every session ends with: checks run (below), `docs/handoff.md` updated (six sections), a new HTML report in `docs/sessions/`.

## Checks
```
php artisan test && composer audit                                     # web: API on real Postgres (myridenepal_test)
cd frontend && pnpm type-check && pnpm lint && pnpm test:unit && pnpm build && pnpm audit --prod
cd frontend && pnpm test:e2e --project=chromium --project=firefox --project=mobile
cd ../admin && pnpm type-check && pnpm lint && pnpm test:unit && pnpm build && pnpm audit --prod
cd ../admin && pnpm test:e2e --project=chromium --project=firefox
```
E2E needs `pnpm db:start`; each suite starts its own API on :8011 against the **isolated `myridenepal_e2e` database** (never the development database) and its own preview (:4180 public, :4181 staff). Running servers on those ports are reused locally: stop stale ones first. WebKit needs system libraries (owner, sudo). Never create `web/.env.local` (Laravel would load it instead of `.env` when APP_ENV=local).
