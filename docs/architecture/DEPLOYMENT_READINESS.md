# Deployment Readiness

Updated 2026-11-18 (session 10, migration stage A4). **Nothing is deployed and no account, plan or provider has been chosen**: those are owner decisions (AR4 hosting plan, AR5 production Postgres, D1 storage, mail provider).

## Three deployables
| Deployable | Repo / root directory | Build | Output | Config |
|---|---|---|---|---|
| Laravel API | Road-trip-web, `/` | container `Dockerfile.vercel` (FrankenPHP, PHP 8.4, `composer install --no-dev`) | image listening on `$PORT` | env vars (template `.env.production.example`) |
| Public website | Road-trip-web, `frontend` | `node scripts/check-deploy-config.mjs && pnpm build` | `frontend/dist` | `frontend/vercel.json` |
| Staff portal | Road-trip-admin, `/` | `node scripts/check-deploy-config.mjs && pnpm build` | `dist` | `admin/vercel.json` (adds `X-Robots-Tag: noindex`) |

Each `vercel.json` rewrites `/api/*` and `/sanctum/*` to the API origin (placeholder `API_ORIGIN_NOT_SET` until the owner has one; the build guard refuses the placeholder or a local address), falls back to `index.html` for client routes, sets a CSP (self only; OpenStreetMap tiles for maps), HSTS, nosniff and caching (hashed assets immutable, `index.html` no-cache). The API project's Ignored Build Step should skip builds when only `frontend/` or `docs/` changed (`git diff --quiet HEAD^ HEAD -- . ':!frontend' ':!docs'`).

## The previous Vercel failure (diagnosed)
No Vercel log was available on this machine, so the failure was reproduced from the committed tree (`git archive HEAD` → `docker build -f Dockerfile.vercel`): the frontend stage built, then **`composer install --no-dev` failed in `post-autoload-dump`** with `Class "Knuckles\Scribe\Config\AuthIn" not found`, because `config/scribe.php` uses classes of a dev-only package. Fixed in `config/scribe.php` (returns no config when Scribe is not installed). The current image builds and, run locally against `myridenepal_e2e`, answers `/api/v1/health` 200 (database ok), `/api/v1/site` 200, `/api/v1/destinations` 200, `/api/v1/users/me` 401, `/` 404.

Even with the build fixed, a deployment cannot serve requests until the items below exist.

## Production prerequisites
| Need | Why | Status |
|---|---|---|
| Postgres reachable from the API | sessions, cache and every request use it | **owner decision AR5** (remote Supabase stays out of scope) |
| `APP_KEY` (stable) | encrypted cookies and stored secret settings | generate once, store in the platform |
| `APP_URL`, `ADMIN_URL`, `SANCTUM_STATEFUL_DOMAINS` | email links; Sanctum treats both frontend hosts as first-party | after domains exist |
| `TRUSTED_PROXIES=*` | correct scheme, host and client IP behind the platform proxy (rate limits, audit) | ready (`bootstrap/app.php`) |
| `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null` | host-only secure cookies per frontend | template |
| `QUEUE_CONNECTION=sync` | serverless containers run no worker | template |
| Mail provider | confirmation, reset, invitation emails | owner (credentials can live encrypted in Settings) |
| Durable storage | uploads on the container disk vanish when an instance is replaced | **D1 / O6 open.** Enforced since session 24: outside local/testing the local disk is refused (`UploadPolicy`, 422 + critical log, existing files still served); set `UPLOADS_ENABLED=false` until a provider is verified (`UPLOAD_SAFETY_DESIGN.md`) |
| Migrations | run once per release with the owner role (`php artisan migrate --database=pgsql_owner --force`) from a trusted machine or CI job | documented |
| Health check | `GET /api/v1/health` (checks the database) | ready |
| Error monitoring | logs to stderr (`LOG_CHANNEL=stderr`) are kept by the platform; a hosted error tracker would be a new service | owner |
| Backups | provider's automated backups + a tested restore | with AR5 |
| Crawler-visible SEO | the public site is a client-rendered SPA | **D4** (prerender) |
| Plan terms | Vercel Hobby is for personal, non-commercial use; check current limits | **AR4** |

## Production settings checklist
| Setting | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `HASH_DRIVER` | `argon2id` |
| `AUTH_REGISTER_PER_HOUR` | `20` (default) |
| `API_PER_MINUTE` | `120` (default) |
| `CORS_ALLOWED_ORIGINS` | empty (same-origin proxies) |
| OpenStreetMap tiles | the public tile server allows light use with attribution; heavy use needs a tile provider (owner decision) |

## Not done yet (blocks a public launch)
Owner items above; backups and a tested restore; monitoring and alerting; privacy policy, terms and account deletion; Lighthouse and screen-reader passes; WebKit run; real content replacing development seed data; CI never run on GitHub; HTTPS/HSTS verified on a real host.

## Session 13 notes
- **Mail:** staff can switch on an SMTP override in Settings > Mail (password encrypted with `APP_KEY`). Saving runs `queue:restart` so workers pick it up. The production mail provider and its credentials are still an owner decision; nothing is connected.
- **Maps:** the tile URL is a setting, but the public website's CSP (`frontend/vercel.json`, `img-src`) must also allow a new tile host, or the map stays blank. Geocoding/routing providers can be configured and tested, but no proxy uses them until D2. Options to evaluate (costs and limits must be checked with each provider before choosing): a Nominatim-compatible service (the public OSM instance has a strict usage policy and is not for production traffic), an OSRM-compatible service (the public demo server is not for production), openrouteservice (API key, quota-based plans), or self-hosting either.
- **Rate limits:** `CONTACT_PER_HOUR` (default 5 per address, daily cap 4x) for the contact form; raise only for local E2E.
- **Contact retention (D7):** messages are kept until staff delete them; no automatic deletion until the owner sets a period.
