# Deployment Readiness

**Vercel is the selected deployment target for the combined Laravel API and public SPA.** The container build is prepared in `Dockerfile.vercel` and `Caddyfile`; no Vercel project has been linked or deployed yet. A production database, domain, email provider, and durable photo storage still need to be provisioned.

## Required topology
- One origin for the public site and the API: Laravel serves `public/app` (built web SPA) and `/api/v1`, `/sanctum`, `/docs`.
- On Vercel, use the `web/` directory as the project root and the **Container** framework preset. `Dockerfile.vercel` builds the Vue SPA, packages Laravel with FrankenPHP, and serves Laravel's `public/` directory. The Vite output stays at `public/app`, so `/app/assets/...` and Laravel's SPA fallback work on the same origin. There is no static `dist` output directory in this deployment.
- Admin SPA: static files on a host (e.g. `admin.example.com`) whose `/api` and `/sanctum` are reverse-proxied to the same Laravel app, so cookies stay first-party. Add that origin to `SANCTUM_STATEFUL_DOMAINS`. Staff sessions are then separate from rider sessions (different host cookies) unless `SESSION_DOMAIN` is set to the parent domain; keep it unset.
- PostgreSQL 17 (Supabase-hosted Postgres is acceptable as plain Postgres) with the two roles from `scripts/setup-local-db.sh` (owner for migrations, app for requests) and TLS.

### Vercel project setup
1. Import the `Road-trip-web` repository and set **Root Directory** to `web`.
2. Select **Container** as the Framework Preset. Vercel's Vite/SPA preset expects `dist`; this app is served by Laravel in a container and does not use a Vercel static output directory.
3. Add the production environment variables below in Vercel. Never copy the local `.env` into the project or image.
4. Provision the database and external photo storage before enabling rider photo uploads. Vercel container filesystems are ephemeral; `LARAVEL_STORAGE_PATH` is redirected to `/tmp` for framework scratch files only.
5. Run migrations from a controlled environment with the migration owner credentials, then deploy. Do not put migration-owner credentials in the long-lived web runtime environment.

Required runtime variables include `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=require`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_SECURE_COOKIE=true`, `MAIL_*` values for the selected transactional provider, and the public/admin origins in `SANCTUM_STATEFUL_DOMAINS`. Use the restricted application database role for `DB_USERNAME`; provide `DB_MIGRATION_USERNAME` and `DB_MIGRATION_PASSWORD` only to the one-off migration process.

**Not production-ready yet:** contribution photos currently use Laravel's local private disk, which is not durable on Vercel. Before launch, select durable private object storage and update the upload/read paths to use it. Configure persistent session/cache/queue services if the database-backed defaults create unacceptable load. The Vercel container runtime is newer platform functionality; validate its behavior and limits in a preview deployment before production.

## Production settings checklist
| Setting | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | public HTTPS origin |
| `SESSION_SECURE_COOKIE` | `true` |
| `AUTH_REGISTER_PER_HOUR` | `20` (default) |
| `MAIL_*` | a real transactional mail provider (owner to choose; may cost) |
| `HASH_DRIVER` | `argon2id` |
| Queue worker | `php artisan queue:work` if notifications move to the queue |
| Storage | `storage/app/private` on persistent disk (contribution photos) or an S3-compatible disk |
| OpenStreetMap tiles | the public tile server allows light use with attribution; heavy use needs a tile provider (owner decision) |

## Not done yet (blocks a public launch)
Backups and a tested restore; monitoring and alerting; CAPTCHA on sign-up; privacy policy, terms and account deletion; Lighthouse and screen-reader passes; WebKit run; real content replacing development seed data; CI never run on GitHub; HTTPS/HSTS verified on a real host.
