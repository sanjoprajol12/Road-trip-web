# System Architecture

Current as of 2026-11-18 (session 4). Supersedes the Supabase-client architecture of sessions 1 to 3.

## Shape
```
/var/www/html/Road-trip/                       workspace folder (has an empty editor-created .git; not a project repo)
├── CLAUDE.md, handoff.md  -> web/...          symlinks
├── web/    repo Road-trip-web     Laravel 12 API  +  public/rider Vue SPA (resources/app)  +  docs/  +  supabase/config.toml
└── admin/  repo Road-trip-admin   staff Vue SPA (staff only)
```
Owner decisions: Laravel 12 is the only backend; Supabase is PostgreSQL only; the public site lives with the API in `web`; `admin` is staff-only; no code is shared between the two repos (each keeps its own copies); no Inertia, no Blade frontend.

```
 browser ── web SPA (Vue) ──┐                       ┌── PostgreSQL 17 (Supabase CLI container, local)
                            ├─ /api/v1 (JSON) ─ Laravel 12 ─┤   database myridenepal (role mrn_app)
 browser ── admin SPA (Vue) ┘   Sanctum cookie session      └── Mailpit (local mail catcher)
```

## Responsibilities
| Layer | Owns |
|---|---|
| Laravel (`web/app`, `routes/api.php`) | authentication (Fortify + Sanctum), authorization (policies, gates), validation, business rules (actions in transactions), audit log, notifications, file uploads, API docs, migrations, seeders |
| Web SPA (`web/resources/app`) | public site and rider platform: explore, routes and maps, experiences and contributions, events, preparation, My Rides, account. Presentation and UX checks only |
| Admin SPA (`admin/src`) | staff screens: overview, verification, moderation, destinations, routes, events, guides, people, audit log. Presentation only |
| PostgreSQL | data, foreign keys, check constraints, partial unique indexes |
| Supabase CLI | only runs the local Postgres container and Mailpit (`supabase start -x kong`); auth, API gateway, PostgREST, Studio, storage, realtime are disabled |

Neither frontend holds a database credential or talks to Postgres. All business decisions are made in Laravel; the SPAs keep small copies of rules only to explain refusals before a request (the server decides).

## Serving
- **Production shape:** Laravel serves the built web SPA (`public/app`, any non-API path falls back to its `index.html`) and the API on one origin. The admin SPA is a separate static build that must be served from a host whose `/api` and `/sanctum` are proxied to the same Laravel app (same-site cookies). See `DEPLOYMENT_READINESS.md`.
- **Development:** Laravel on :8010 (`php artisan serve`); web Vite on :5180 and admin Vite on :5181, both proxying `/api` and `/sanctum` to :8010.
- **E2E:** web suite uses Laravel on :8010 serving the built SPA; admin suite uses its preview on :4181 proxying to :8010.

## Frontend structure
```
web/resources/app/src/                     admin/src/
  app/router, app/pages                      app/router, app/layouts
  components/{ui,navigation}                 components/ui (StatusLine, ConfirmDialog)
  features/<area>/{pages,components,         features/<area>/pages   (verification, moderation, content,
           services,labels,rules}                                      events, guides, users, audit, dashboard)
  lib/api/client.ts  (axios, CSRF, errors)   lib/api/client.ts (copy), lib/usePasswordConfirm.ts
  types/api.ts       (API shapes)            types/api.ts (admin shapes)
```
State: a module-level auth ref per app (no Pinia; one global value does not need a store library).

## Dependencies (checked 2026-11-18)
| Package | Where | Why |
|---|---|---|
| laravel/framework 12.73, laravel/sanctum 4.5, laravel/fortify 1.43 | web | API, SPA cookie auth, auth endpoints |
| knuckleswtf/scribe 5.11 (dev) | web | API docs + OpenAPI |
| vue 3.5, vue-router 5, vite 8, tailwindcss 4, lucide-vue-next | both SPAs | stack |
| axios 1.21 | both SPAs | API client with automatic XSRF header |
| leaflet 1.9 (+ @types) | web SPA | route maps (OpenStreetMap tiles) |
| @fontsource-variable archivo / source-serif-4 | web SPA (archivo also admin) | self-hosted fonts |
| supabase CLI 3.2.0 (dev) | web | local Postgres + Mailpit only |
| @playwright/test 1.63.0 (dev, exact) | both | E2E; matches cached browsers |
| Not used: Pinia, GSAP, Redis, Spatie permissions, Inertia | | not needed yet (see future recommendations) |
