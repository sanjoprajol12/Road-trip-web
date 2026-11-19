# My Ride Nepal: web (API + public website)

Motorcycle travel in Nepal. This repository holds two independent applications:

- **Laravel 12 API** at the root: `app/`, `routes/`, `database/`: authentication (Fortify + Sanctum), authorization, business rules, uploads, notifications, audit log. Production image: `Dockerfile.vercel`.
- **Public website and rider platform** in `frontend/` (Vue 3 + TypeScript + Vite + Tailwind): its own `package.json`, `.env`, build, tests and `vercel.json`.

Also: project documentation in `docs/`; `supabase/config.toml`, used only to run a local PostgreSQL and the Mailpit mail catcher. The staff portal is in the Road-trip-admin repository.

## Run
```sh
pnpm db:start              # Postgres + Mailpit
composer dev               # API on :8010
cd frontend && pnpm dev    # public website on :5180 (separate terminal; proxies /api to :8010)
```
First-time setup and every command: `docs/architecture/DEVELOPMENT_SETUP.md`. API reference: `/docs` (Scribe).

## Tests
API: `php artisan test` · `composer audit`. Public website (in `frontend/`): `pnpm type-check && pnpm lint && pnpm test:unit && pnpm build`, `pnpm test:e2e`.
