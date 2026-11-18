# Deployment Readiness

**Not deployed. No hosting, domain or production database has been chosen (owner decision pending).** This lists what a deployment must provide; nothing here has been executed.

## Required topology
- One origin for the public site and the API: Laravel serves `public/app` (built web SPA) and `/api/v1`, `/sanctum`, `/docs`.
- Admin SPA: static files on a host (e.g. `admin.example.com`) whose `/api` and `/sanctum` are reverse-proxied to the same Laravel app, so cookies stay first-party. Add that origin to `SANCTUM_STATEFUL_DOMAINS`. Staff sessions are then separate from rider sessions (different host cookies) unless `SESSION_DOMAIN` is set to the parent domain; keep it unset.
- PostgreSQL 17 (Supabase-hosted Postgres is acceptable as plain Postgres) with the two roles from `scripts/setup-local-db.sh` (owner for migrations, app for requests) and TLS.

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
