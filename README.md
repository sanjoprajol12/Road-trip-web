# My Ride Nepal: web (API + public website)

Motorcycle travel in Nepal. This repository holds two independent applications:

- **Laravel 12 API** at the root: `app/`, `routes/`, `database/`: authentication (Fortify + Sanctum), authorization, business rules, uploads, notifications, audit log. Production image: `Dockerfile.vercel`.
- **Public website and rider platform** in `frontend/` (Vue 3 + TypeScript + Vite + Tailwind): its own `package.json`, `.env`, build, tests and `vercel.json`.

Also: project documentation in `docs/`; `supabase/config.toml`, used only to run a local PostgreSQL and the Mailpit mail catcher. The staff portal is in the Road-trip-admin repository.

## Run
```sh
npm run db:start           # Postgres + Mailpit
npm run dev                # API on :8010 (with queue listener and log tail) + public website on :5180
```
`npm run dev` refuses to start when :8010 or :5180 is already taken (so no second server starts), stops everything on
Ctrl+C, and stops the rest if one part crashes. Other ports: `API_PORT=8020 WEB_PORT=5190 npm run dev`.
Separately: `npm run dev:api` (= `composer dev`, API only) and `npm run dev:web` (public website only, needs the API).
The staff portal is not started here: `cd ../admin && npm run dev`.
First-time setup and every command: `docs/architecture/DEVELOPMENT_SETUP.md`. API reference: `/docs` (Scribe).

## Tests
API: `php artisan test` · `composer audit`. Public website (in `frontend/`): `pnpm type-check && pnpm lint && pnpm test:unit && pnpm build`, `pnpm test:e2e`.
