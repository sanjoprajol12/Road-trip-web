# Development Setup

Architecture Option A (owner, 2026-11-18): three independent applications.
- `web/` (repo Road-trip-web): the **Laravel API** at the repo root, and the **public website** in `web/frontend/` (its own `package.json`, lockfile, Vite config, `.env`, `vercel.json`, tests).
- `admin/` (repo Road-trip-admin): the **staff portal** only, at the repo root.

`npm run dev` in `web` starts the API and the public website together (owner request 2026-11-19); everything else is one application per command, and the staff portal always has its own. Stopping a frontend never stops the API or the other frontend; stopping the API only makes API calls fail (502 from the dev proxy) while both frontends keep running (verified 2026-11-18).

## What runs where
| Process | Folder | Command | Port |
|---|---|---|---|
| PostgreSQL + Mailpit (Supabase CLI, Docker) | `web` | `pnpm db:start` | 54322 (Postgres), 54324 (Mailpit inbox), 54325 (SMTP) |
| **API + public website together** | `web` | `npm run dev` (port guard, then concurrently 10.0.7 via npx: API, queue, logs, website; Ctrl+C stops all; one crash stops all; `API_PORT` / `WEB_PORT` to move them) | 8010 + 5180 |
| Laravel API only (+ queue listener + log tail) | `web` | `npm run dev:api` = `composer dev` | 8010 |
| Public website only | `web` | `npm run dev:web` (or `pnpm dev` in `web/frontend`) | 5180 (proxies /api, /sanctum to `API_TARGET`, default :8010) |
| Staff portal | `admin` | `pnpm dev` | 5181 (proxies /api, /sanctum to `API_TARGET`, default :8010) |

`APP_URL` in `web/.env` is the public website origin (`http://127.0.0.1:5180`): rider email links. `ADMIN_URL` is the staff portal origin (`http://127.0.0.1:5181`): staff invitations and staff password resets. Each frontend's own `.env` (copy `.env.example`) holds only `API_TARGET`; nothing in a frontend `.env` is bundled.

## Requirements
PHP 8.3 with pdo_pgsql, gd (WebP), intl, bcmath; Composer 2; Node 24 + pnpm 12; Docker; `psql`.

## First-time setup
```sh
cd web
composer install && pnpm install                # pnpm here installs only the Supabase CLI
cp .env.example .env && php artisan key:generate
# put two long random values in DB_PASSWORD and DB_MIGRATION_PASSWORD (openssl rand -hex 24)
pnpm db:start                                   # Postgres + Mailpit only (supabase start -x kong)
pnpm db:setup                                   # creates myridenepal, myridenepal_test, myridenepal_e2e and the mrn_owner / mrn_app roles
php artisan migrate --database=pgsql_owner      # development database, owner role
php artisan db:seed                             # development destinations (flagged is_dev_seed)
DB_DATABASE=myridenepal_e2e php artisan migrate --database=pgsql_owner && DB_DATABASE=myridenepal_e2e php artisan db:seed   # isolated E2E database
cd frontend && pnpm install && cp .env.example .env
cd ../../admin && pnpm install && cp .env.example .env
```
First administrator: see `AUTHENTICATION_FLOW.md` (staff accounts are created on the server, never by sign-up).

## Everyday commands
| Where | Command | Does |
|---|---|---|
| web | `php artisan test` | backend feature tests on `myridenepal_test` |
| web | `composer audit` | PHP dependency advisories |
| web | `php artisan migrate --database=pgsql_owner` | new migrations (owner role); repeat with `DB_DATABASE=myridenepal_e2e` for the E2E database |
| web | `php artisan mrn:permissions:sync` | copy `config/permissions.php` into the database after adding or removing a permission |
| web | `php artisan scribe:generate` | regenerate API docs into `public/docs` |
| web | `docker build -f Dockerfile.vercel -t mrn-api .` | production API image (no frontend inside) |
| web | `pnpm db:stop` | stop Postgres + Mailpit (data kept in the Docker volume) |
| web/frontend and admin | `pnpm type-check && pnpm lint && pnpm test:unit && pnpm build` | that app's types, lint, unit tests, production build (`dist/`) |
| web/frontend and admin | `pnpm preview` | serve the build (:4180 public, :4181 staff) |
| web/frontend | `pnpm test:e2e --project=chromium --project=firefox --project=mobile` | public E2E |
| admin | `pnpm test:e2e --project=chromium --project=firefox` | staff E2E |
| web/frontend and admin | `pnpm audit --prod` | JS dependency advisories |

## E2E
Each frontend's suite starts **its own** Laravel API from the web repo on `E2E_API_PORT` (default **8011**, never your :8010) with `DB_DATABASE=myridenepal_e2e`, plus that app's preview. Test accounts use the `@e2e.test` domain and are deleted before and after a run, in `myridenepal_e2e` only; the development database is never touched. The suite's API runs with `API_PER_MINUTE=5000` because every test browser shares 127.0.0.1 (production default 120). Locally, running servers on 8011/4180/4181 are reused: stop stale ones first (a reused preview serves an old build).

`@playwright/test` is pinned to 1.63.0 to use the cached browser builds. WebKit needs system libraries: `sudo pnpm exec playwright install-deps webkit` (owner).

## Remote database rule
No remote project is linked. Never point `.env` at the remote Supabase project, and never run migrations or scripts against it without the owner's explicit approval. `scripts/setup-local-db.sh` refuses any host but 127.0.0.1; the E2E suites refuse to run unless the API `.env` points at the local Postgres.

## Known local quirks
- Laravel loads `.env.{APP_ENV}` instead of `.env` when such a file exists: never create `web/.env.local`.
- The old combined command (`pnpm dev` = both apps from `admin`) no longer exists. A staff dev server started with the old script before 2026-11-18 serves deleted folders: stop it and start `pnpm dev` in `admin`.

## Quality checks without extra tools (session 13)
- `frontend/e2e/quality.spec.ts`: on 13 public pages, one h1, `lang`, one main landmark, alt text, labelled controls, named buttons and links, unique ids; keyboard skip link; reduced motion; LCP and CLS in Chromium on the production build (budgets 2.5 s and 0.1). `admin/e2e/quality.spec.ts`: the same structure rules on 13 staff screens.
- axe-core and Lighthouse are not installed (new dependencies need the owner's approval). WebKit fails to launch here: it needs system libraries (for example `libevent-2.1`) that need sudo (`npx playwright install-deps webkit`, owner).
