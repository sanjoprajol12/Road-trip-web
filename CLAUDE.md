# My Ride Nepal (Node rebuild): working instructions

Read first, every session: `handoff.md` (in this repository), then `docs/architecture/IMPLEMENTATION_ROADMAP.md` and the docs for the area you touch.

## What this is
A Node.js rebuild of My Ride Nepal, a motorcycle travel planning, rider knowledge and community platform for Nepal. Requirements: `docs/architecture/REQUIREMENTS_INVENTORY.md`. Open decisions: `docs/architecture/RISKS_AND_OPEN_QUESTIONS.md`.

- `/var/www/html/Road-trip` is the **old Laravel/Vue implementation**: reference only. Never edit it, never copy code from it, never connect to its databases or containers.
- **Three separate git repositories connected only through the HTTP API** (owner direction 2026-11-27), checked out side by side in `/var/www/html/Road-trip-node/`:
  - `backend/` → git@github.com:sanjoprajol12/Road-trip-web.git (`develop`): Express 5 + TS + Drizzle + PostgreSQL. Owns the database (`database/init.sh`, `scripts/dev-infra.sh`) **and the project documentation** (`docs/`, `handoff.md`, `reports/`, this file).
  - `frontend/` → git@github.com:sanjoprajol12/Road-trip-front.git (`develop`): public website, React.
  - `admin/` → git@github.com:sanjoprajol12/Road-trip-admin.git (`develop`): staff portal, React.
- Each repository has its own package.json, lockfile, `.env`, `.gitignore`, tests, build, README and `.github/workflows/ci.yml`. No root `package.json`, no workspaces, no shared package, and nothing in one repository reads, imports, builds or starts another's files. The web apps reach the API only at a URL (`/api` proxy, `API_TARGET`, `E2E_API_URL`); their CI checks out this repository only to start an API for browser tests. The API contract is the OpenAPI document; each web app generates its own types from it.
- `Road-trip-web` and `Road-trip-admin` used to hold the old Laravel/Vue app. On 2026-11-27 the owner chose to **replace `develop`** with the Node code; the old code stays on `main` and on the backup branch `laravel-develop-2026-11-27`, and in the local clones under `/var/www/html/Road-trip`.

## Rules
- Phase gates: do not start a phase or stage without owner approval of the previous one. Planning was approved and Phase 3 (foundation) completed on 2026-11-27; Phase 4 is next.
- The old app is a reference only (Q-20): never delete, modify or migrate its data. Any import goes through the S16 audit and plan, approved by the owner.
- Verify a dependency (maintenance, licence, security, compatibility) before installing it, and record it in TD-24.
- No commits, pushes, deployments, purchases or new external services without the owner asking in that session. Commit each repository separately.
- New dependency → a line in `docs/architecture/TECHNICAL_DECISIONS.md` saying why.
- Never invent content (destinations, routes, prices, distances, times, reviews, riders, photos). Dev seed rows have `is_dev_seed = true` and are labelled in the UI.
- Business rules and authorization live only in the backend. Every route is classified; staff routes name a permission; foreign records answer 404.
- Migrations run as `mrn_owner`; the app runs as `mrn_app` (DML only). Grants live in migrations.
- Follow `docs/design/DESIGN_SYSTEM.md`: Lucide icons, no emojis, tokens only.
- A requirement is done only when its Impl and Test columns in the inventory are filled and the test ran.
- End of every session: run the checks, update `handoff.md` (the brief's format), and write `reports/sessions/<date>-session-NN-<topic>.html`.

## Commands
- Infrastructure (backend repository): `npm run db:up` / `npm run db:down` (Docker, no Compose plugin needed; passwords in `.env.infra`)
- All checks, per project: `npm run check` (lint, typecheck, format, tests, build, production audit)
- Per project: `npm run dev | test | lint | typecheck | build`
- Browser tests: in the backend repository, `npm run build && npm run start:e2e` (API on :4001, `myridenepal_e2e`); then in `frontend/` or `admin/`, `npm run test:e2e` (`E2E_API_URL` overrides the API address)
- Backend tests run only against `myridenepal_test`; E2E only against `myridenepal_e2e`
- Never `pkill -f` with a pattern that also matches the shell running it; stop servers by port

## Ports (development)
API 4000 · frontend 5273 · admin 5274 · PostgreSQL 5442 · Mailpit 8035 (UI) / 1035 (SMTP). The old project uses 5432/54322/54324: leave those alone.
