# System Architecture

Current as of 2026-11-18 (session 10): **architecture Option A**, by the owner's explicit direction. It replaces Option B (both frontends in `admin`, session 7) and every older description in handoffs and session reports. Proposal and reasons: `PROPOSED_ARCHITECTURE.md`; execution: `MIGRATION_PLAN.md`.

## Shape
```
/var/www/html/Road-trip/                 workspace folder (not a project repo)
├── CLAUDE.md, handoff.md -> web/...     symlinks
├── web/    repo Road-trip-web
│   ├── app/ config/ database/ routes/ tests/    Laravel 12 API (root)
│   ├── frontend/                                public website + rider platform (Vue 3, own package.json, .env, vercel.json, e2e/)
│   ├── docs/  supabase/config.toml  scripts/
│   └── Dockerfile.vercel  Caddyfile  .env.production.example
└── admin/  repo Road-trip-admin                 staff portal (Vue 3, own package.json, .env, vercel.json, e2e/)
```
```
 browser ── public website (:5180 dev / :4180 preview / own host) ──┐                    ┌── PostgreSQL 17
                                                                    ├─ /api/v1 JSON ── Laravel 12 (:8010) ─┤   (local: Supabase CLI container)
 browser ── staff portal   (:5181 dev / :4181 preview / own host) ──┘  Sanctum cookie sessions              └── Mailpit (local mail catcher)
```
Each frontend host proxies `/api` and `/sanctum` to the API (Vite proxy in development, `vercel.json` rewrites in production), so session cookies are first-party on that host and CORS stays closed.

## Responsibilities
| Layer | Owns |
|---|---|
| Laravel (`web/app`, `web/routes`) | authentication (Fortify + Sanctum), authorization (policies, gates, permission registry), validation, business rules (actions in transactions), audit log, notifications, file uploads, settings and CMS backend, API docs (Scribe, dev only), migrations, seeders |
| Public website (`web/frontend`) | explore, routes and maps, experiences and contributions, events, preparation, My Rides, account, rider auth pages. Presentation and UX checks only |
| Staff portal (`admin`) | overview, verification, moderation, content, events, guides, people and roles, media, settings, audit log; staff auth pages. Presentation only |
| PostgreSQL | data, foreign keys, check constraints, partial unique indexes; `mrn_owner` (DDL) and `mrn_app` (DML) roles |
| Supabase CLI | only the local Postgres container and Mailpit (`supabase start -x kong`) |

Neither frontend holds a credential or talks to Postgres. The two frontends share no code: each has its own small `src/lib` (API client, formatting, redirect guard).

## Databases (local)
`myridenepal` (development), `myridenepal_test` (`php artisan test`), `myridenepal_e2e` (both E2E suites, through their own API on :8011). Remote Supabase is out of scope.

## Serving
- **Development:** `composer dev` in `web` (:8010); `pnpm dev` in `web/frontend` (:5180); `pnpm dev` in `admin` (:5181). Independent: verified by stopping each one while the others kept answering.
- **E2E:** each frontend's Playwright config starts its own API from the web repo on :8011 with `DB_DATABASE=myridenepal_e2e` and that app's preview.
- **Production:** three deployables (`DEPLOYMENT_READINESS.md`): the API container (`Dockerfile.vercel`, `frontend/` excluded), the public website (`web/frontend/vercel.json`), the staff portal (`admin/vercel.json`).

## Dependencies (checked 2026-11-18)
| Package | Where | Why |
|---|---|---|
| laravel/framework 12.x, laravel/sanctum 4.x, laravel/fortify 1.x | web | API, SPA cookie auth, auth endpoints |
| knuckleswtf/scribe 5.x (dev) | web | API docs (config skipped when not installed, so `--no-dev` images build) |
| supabase CLI 3.2.0 (dev) | web | local Postgres + Mailpit only |
| vue 3.5, vue-router 5, vite 8, tailwindcss 4, lucide-vue-next, axios | web/frontend, admin | both apps (separate lockfiles) |
| leaflet 1.9 (+ @types) | web/frontend | maps |
| @fontsource-variable archivo, source-serif-4 | web/frontend, admin | self-hosted fonts |
| @playwright/test 1.63.0 (dev, exact) | web/frontend, admin | E2E; matches cached browsers |
| Not used: Pinia, GSAP, Redis, Spatie permissions, Inertia, Livewire | | not needed |
