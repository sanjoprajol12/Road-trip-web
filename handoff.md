# Development Handoff

Last updated: 2026-11-27, session 2 (owner decisions applied to the plan; dependency verification; Phase 3 project foundation; **restructured into three separate projects connected only through the API**). Session 1: discovery and planning (`reports/sessions/2026-11-27-session-01-discovery.html`).

## Goal
1. Update the planning documents with the owner's decisions (Q-01, Q-02, Q-20, Q-22, architecture approval).
2. Verify every dependency's maintenance, licence, security and compatibility before installing it.
3. Phase 3: project foundation (roadmap tasks 3.0–3.8).

## Current State
- **Planning approved** and the documents updated. Q-01: no bookings, payments or garage. Q-02: separate rider and staff authentication. Q-20: the old app stays as a reference; data moves only through the S16 audit and plan. Q-22: white page background, neutral surfaces.
- **Phase 3 done**, except two owner steps: the WebKit system libraries (`sudo`) and the first git commit.
  - Local infrastructure: PostgreSQL 18.6 + PostGIS 3.6 on 127.0.0.1:5442 (`mrn_owner`, `mrn_app`; databases `myridenepal_dev`, `_test`, `_e2e`) and Mailpit (8035/1035), via `backend/scripts/dev-infra.sh` (`npm run db:up` in `backend/`).
  - `backend/`: Express 5 + TypeScript 6.0. Env validation, pino with redaction, helmet, request ids, the JSON error format, body limits, `/health/live` and `/health/ready` (also under `/api/v1/health`), Drizzle client with statement timeouts, graceful shutdown.
  - `frontend/`, `admin/`: React 19 + Vite 8 + TypeScript 5.9 + React Router 8 + TanStack Query 5 + Tailwind 4 with the approved tokens. Fonts self-hosted. The API client, a live service-status component with loading, error, retry and available states, lazy routes, a not-found page. Admin has the dark staff sidebar, a skip link and `noindex`.
  - CI: one workflow per project (`backend.yml`, `frontend.yml`, `admin.yml`) plus `e2e.yml`; none has run on GitHub (no remote). Locally, `npm run check` in each project runs the same checks.
  - **Restructure (owner direction):** no root `package.json` and no root `scripts/`. Database infrastructure moved into `backend/` (`scripts/dev-infra.sh`, `database/init.sh`, `.env.infra`). Each project has its own `.gitignore` and `check` script. The web apps' Playwright configs no longer build or start the backend: they reach a running API at `E2E_API_URL` (default :4001), and a global setup fails fast if it is down.
- **No product features yet.** No migrations, no tables, no authentication.

## Active Files
- Root (documentation and repository level only): `.gitignore`, `.github/workflows/{backend,frontend,admin,e2e}.yml`, `README.md`, `CLAUDE.md`, `handoff.md`, `docs/`, `reports/`
- `backend/`: `scripts/dev-infra.sh`, `database/init.sh`, `.env.infra.example`, `.gitignore`
- `backend/`: `src/{server,app,logger}.ts`, `src/config/env.ts`, `src/database/client.ts`, `src/common/errors.ts`, `src/middleware/errors.ts`, `src/modules/health/health.routes.ts`, `tests/{helpers,foundation.test}.ts`, configs, `.env.example`, `.env.test.example`, README
- `frontend/`, `admin/`: `src/{main.tsx,app/routes.tsx}`, `src/styles/{tokens,index}.css`, `src/lib/api/client.ts`, `src/features/system/{api.ts,ApiStatus.tsx,ApiStatus.test.tsx}`, pages, `e2e/smoke.spec.ts`, `playwright.config.ts`, configs, README; admin adds `src/layouts/StaffLayout.tsx`
- Docs changed: all planning docs (statuses, decisions), `TECHNICAL_DECISIONS.md` TD-23 and TD-24, `DESIGN_SYSTEM.md` §3, §5 and §13, `RISKS_AND_OPEN_QUESTIONS.md` (decided section, RK-15, RK-16), `IMPLEMENTATION_ROADMAP.md` (3.0, S16), `REQUIREMENTS_INVENTORY.md` (LM-1; Impl/Test for X8, I-1…I-8, health)

## Changes Made
- **Dependency verification (TD-24)** changed three choices:
  1. `react-leaflet` rejected (Hippocratic-2.1 licence, not OSI-approved); Leaflet will be used directly.
  2. TypeScript 7 is not yet supported by `typescript-eslint` and `openapi-typescript`: the backend uses 6.0.3, the frontends 5.9.3.
  3. ESLint 9 is deprecated and `eslint-plugin-jsx-a11y` does not support ESLint 10: switched to `eslint-plugin-jsx-a11y-x`, with a scoped `minimatch` override to clear a moderate advisory.
- **Design:** `--page #ffffff`, `--surface-subtle #f5f6f7`, `--surface-sunken #eceef0`, cool neutral lines and muted text; one `--shadow-overlay` for floating elements only. All contrast re-measured (text ≥ 5.10:1 on every light surface; `--line-strong` darkened to `#7b8189` so it stays ≥ 3:1 on sunken).
- **Infrastructure:** the Docker Compose plugin is missing on this machine, so `docker-compose.yml` was replaced by a plain `docker run` script, now `backend/scripts/dev-infra.sh`.

## Database Changes
- No migrations or tables.
- `backend/database/init.sh`, on the first container start: roles `mrn_owner` and `mrn_app` (NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOBYPASSRLS); three databases owned by `mrn_owner`; CONNECT/TEMPORARY for `mrn_app` only; `pg_trgm`; CREATE on `public` revoked from PUBLIC; USAGE for `mrn_app`. Table privileges come in Phase 4 migrations.
- Verified: `mrn_app` gets "permission denied for schema public" on CREATE TABLE.

## Tests
All actually run on 2026-11-27:
- After the restructure: `npm run check` **ok in each project on its own** (backend 10 tests, frontend 3, admin 3; production audit 0 in each). The API was started with `npm run start:e2e` in `backend/`; E2E over HTTP: frontend **9 passed**, admin **9 passed** (chromium, firefox, mobile). Without an API, E2E stops at once with a clear message (verified).
- Backend: 10 tests passed (health with a real DB, 503 when the DB is unreachable, 404 / 400 / 413 error shapes, request id, security headers, env validation).
- Frontend and admin: 3 component tests each (loading → available; error with reference → retry; network failure).
- Independence check: API stopped → both frontends still serve (proxy 502, UI shows unavailable); frontend stopped → admin unaffected; API restarted → admin reconnects.
- Production build of the API started with `node dist/server.js` and answered `/health/ready`.
- `npm audit --omit=dev`: 0 in all apps. Full audit: backend 4 moderate (accepted, dev-only drizzle-kit/esbuild, TD-24); frontends 0.
- CI YAML parses; the workflow has never run on GitHub.

## Failed Attempts
- `docker compose`: plugin not installed → replaced by a script.
- The first dependency check script returned empty rows: zsh does not split words in `$var`, and npm 12 returns `npm view --json` as an array. Rewritten in Node.
- The frontend ESLint config crashed: the a11y fork exports `configs`, not `flatConfigs`. Fixed.
- The frontend build failed on `document`/`window` in E2E files (the Node tsconfig lacked DOM). Fixed.
- **Admin E2E timed out:** a `sed` edit silently failed to add `PORT=4002`, and an orphaned API on 4001 (from my manual test, where `pkill -f` killed its own shell) had made the first frontend E2E pass by reuse. Both fixed, and both suites re-run from a clean state, each starting its own API.
- A verification command printed the local `mrn_app` development password into the session transcript (local-only, random, gitignored). Rotate with `bash scripts/dev-infra.sh reset` in `backend/` before any real data exists.

## Known Issues
- **WebKit E2E not run:** needs `sudo npx playwright install-deps webkit` (missing `libevent-2.1-7t64`, `libmanette-0.2-0`).
- **No git commit yet** (owner to approve). The repository is initialised on `main` with no remote, so CI has never run on GitHub.
- The deployment configuration per app (I-6) waits for the host decision (Q-19).
- The fonts include all subsets in `dist/`; browsers download only the subsets they need (unicode-range). Trim to latin/latin-ext later if the build size matters.

## Next Steps
1. **Owner:** run `sudo npx playwright install-deps webkit`; approve the first commit (and a remote, if wanted, so CI runs).
2. Phase 4, backend foundation (roadmap 4.1–4.8): first migrations (grants via default privileges, `audit_logs` + append-only trigger, provinces/districts reference data with a cited source, pg-boss schema), `validate()`, session and CSRF middleware, rate limiter, permission registry + route-matrix test, audit service, OpenAPI generation + type generation in both frontends, worker + Mailpit transport, DB privilege test. Verify each new dependency (express-rate-limit, pg-boss, nodemailer, zod-openapi, openapi-typescript) against TD-24 at install time.
3. Before S1: answer Q-17 (languages) and Q-27 (password and session policy).

## Important Decisions
- Owner, 2026-11-27: Q-01, Q-02, Q-20, Q-22 and the architecture are approved (see `RISKS_AND_OPEN_QUESTIONS.md`).
- TD-24: no `react-leaflet`; TypeScript 6.0 (backend) and 5.9 (frontends); `eslint-plugin-jsx-a11y-x`; the drizzle-kit esbuild advisory accepted as dev-only.
- TD-23: `backend/scripts/dev-infra.sh` instead of Docker Compose.
- TD-01 (owner direction): three separate projects connected only through the API; no root `package.json`, no cross-project paths.
- Health endpoints are also mounted under `/api/v1/health`, so the frontends can reach them through their `/api` proxy.
