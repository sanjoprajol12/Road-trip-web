# Development Setup

## What runs where
| Process | Command | Port |
|---|---|---|
| PostgreSQL + Mailpit (Supabase CLI, Docker) | `cd web && pnpm db:start` | 54322 (Postgres), 54324 (Mailpit inbox), 54325 (SMTP) |
| Laravel API | `cd web && php artisan serve --port=8010` | 8010 |
| Web SPA (Vite) | `cd web && pnpm dev` | 5180 (proxies /api, /sanctum to 8010) |
| Admin SPA (Vite) | `cd admin && pnpm dev` | 5181 (proxies /api, /sanctum to 8010) |

## Requirements
PHP 8.3 with pdo_pgsql, gd (WebP), intl, bcmath; Composer 2; Node 24 + pnpm 12; Docker; `psql`. RAM in use: Postgres ~55 MB + Mailpit ~10 MB + Laravel ~60 MB + one Vite ~150 MB.

## First-time setup
```sh
cd web
composer install && pnpm install
cp .env.example .env && php artisan key:generate
# put two long random values in DB_PASSWORD and DB_MIGRATION_PASSWORD (openssl rand -hex 24)
pnpm db:start                                   # Postgres + Mailpit only (supabase start -x kong)
pnpm db:setup                                   # creates myridenepal + myridenepal_test and the mrn_owner / mrn_app roles
php artisan migrate --database=pgsql_owner      # migrations run as the owner role
php artisan db:seed                             # 4 development destinations (flagged is_dev_seed)
cd ../admin && pnpm install
```
First administrator: sign up in the web app, confirm via Mailpit (http://127.0.0.1:54324), then `php artisan mrn:grant-admin you@example.com`.

## Everyday commands (in `web/` unless noted)
| Command | Does |
|---|---|
| `php artisan test` | backend feature tests on `myridenepal_test` |
| `pnpm test:unit`, `pnpm type-check`, `pnpm lint`, `pnpm build` | web SPA checks (same scripts in `admin/`) |
| `pnpm test:e2e --project=chromium --project=firefox --project=mobile` | web E2E (builds the SPA, Laravel serves it on :8010) |
| `cd ../admin && pnpm test:e2e --project=chromium --project=firefox` | admin E2E (starts Laravel from ../web and the admin preview on :4181) |
| `php artisan migrate --database=pgsql_owner` | new migrations (owner role) |
| `php artisan scribe:generate` | regenerate API docs into `public/docs` |
| `pnpm db:stop` | stop Postgres + Mailpit (data kept in the Docker volume) |

## Remote database rule
No remote project is linked. Never point `.env` at the remote Supabase project, and never run migrations or scripts against it without the owner's explicit approval. `scripts/setup-local-db.sh` refuses any host but 127.0.0.1; both E2E suites refuse to run unless `.env` points at the local `myridenepal` database.

## Browsers for E2E
`@playwright/test` is pinned to 1.63.0 to use the cached Chromium 1243 / Firefox 1543 / WebKit 2359 builds. WebKit needs system libraries: `sudo pnpm exec playwright install-deps webkit` (owner).

## Known local quirks
- Laravel loads `.env.{APP_ENV}` instead of `.env` when such a file exists: never create `web/.env.local` (the SPA's Vite env file lives in `resources/app/`).
- E2E reuses a running server on :8010 / :4181 locally: stop old ones to avoid testing a stale build.
