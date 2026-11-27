# Technical Decisions

Status: **Accepted** by the owner on 2026-11-27, on the condition that every dependency is verified for maintenance, licensing, security and compatibility before installation (done in TD-24, which changed three choices: no `react-leaflet`, TypeScript 6.0/5.9 instead of 7, and `eslint-plugin-jsx-a11y-x`). TD-07 is confirmed by Q-02. Any decision that is later changed gets a new entry; the old one is not edited away.

Each entry gives the context, the options considered, the decision and its consequences. Library choices were checked for maintenance, licence, security record and complexity. Versions are pinned in Phase 3, after checking the current release notes.

Environment found on 2026-11-27: Node 24.18, npm 12.0, Docker 29, psql 18 client. The old project's PostgreSQL 17 and Mailpit containers are running on this machine. This project uses its own containers and ports and never connects to them.

---

## TD-01 Three independent applications in one repository

- **Context:** the brief requires `backend/`, `admin/` and `frontend/`, each with its own `package.json`, lockfile, configuration, tests, build and deployment. No npm workspaces and no shared runtime package.
- **Decision:** three separate npm projects, **connected only through the HTTP API** (owner direction 2026-11-27, which replaced the earlier root `package.json` with convenience scripts). There is no root `package.json`, and no project reads, imports, builds or starts another project's files. Each has its own `.gitignore`, `npm run check` and CI workflow. Infrastructure that belongs to the database (local Docker setup, role and database init) lives in `backend/`, because the backend owns its database. Browser tests reach a running API by URL (`E2E_API_URL`).
- **Repositories (owner direction 2026-11-27):** one git repository per project: backend → `Road-trip-web`, frontend → `Road-trip-front`, admin → `Road-trip-admin`, all on `develop`. The old Laravel/Vue code in `Road-trip-web` and `Road-trip-admin` was replaced on `develop`, and kept on `main` and the backup branch `laravel-develop-2026-11-27`. The project documentation lives in the backend repository. Each web app's CI checks out the backend repository only to start an API for its browser tests.
- **API contract without shared code:** the backend publishes an OpenAPI document (TD-12). Each frontend generates its own TypeScript types from it at development time with `openapi-typescript` (a dev dependency of each frontend, output committed). Types are copied, not imported, so there is no runtime coupling, and CI fails if the generated types are out of date.
- **Accepted duplication:** each frontend has its own small `fetch` wrapper, date formatting and design tokens (about 150 lines). Duplicating that is cheaper than coupling three deployables.

## TD-02 Delivery in vertical slices

- **Context:** the brief's Phases 6 (admin) and 7 (frontend) come after Phase 5 (core features), but each Phase 5 feature also includes "implement frontend functionality".
- **Decision:** each stage delivers the database, API, admin screens and public screens of one product area together, tested end to end. Phases 6 and 7 become completion passes (navigation, dashboards, consistency, polish), not the first time a screen is built.
- **Why:** an API without its screens hides design mistakes for months, and the owner can see working software after every stage.

## TD-03 Backend runtime: Node.js 24 LTS, Express 5, TypeScript, ESM

- **Options:** Express 5 (required by the brief), Fastify, NestJS.
- **Decision:** Express 5 as the brief specifies. Express 5 forwards rejected promises from async handlers to the error middleware, so no `asyncHandler` wrapper is needed.
- **TypeScript:** `strict` mode. Development runs with `tsx watch`; production runs the `tsc` build output on plain `node`. `"type": "module"`.
- **Node:** 24 (installed; LTS until April 2028). `engines` field and `.nvmrc` pin it.

## TD-04 Database access: Drizzle ORM

| Option | For | Against |
|---|---|---|
| Prisma | popular, good docs, generated client | schema language cannot express CHECK constraints, partial unique indexes or triggers without hand-editing SQL; row locks (`FOR UPDATE`) need raw SQL; heavier runtime and engine |
| **Drizzle** | schema in TypeScript; queries read like SQL; supports CHECK, partial indexes, `.for('update')`, transactions; small, no engine; generates reviewable SQL migrations | younger API, smaller ecosystem |
| Kysely + SQL migrations | very explicit, typed SQL | schema and types written separately; more code |
| raw `pg` | nothing hidden | no types, more boilerplate, injection risk if misused |

- **Decision:** Drizzle ORM with the `pg` (node-postgres) driver and `drizzle-kit` for migrations.
- **Why:** this product leans on database rules (CHECK constraints, partial unique indexes, row locks for event capacity, triggers that keep the audit log append-only). Drizzle expresses most of these in its schema and lets the rest live in plain SQL migrations. Its queries look like the SQL they produce, which helps someone learning both.
- **Consequence:** triggers and `GRANT`/`REVOKE` statements live in hand-written SQL migration files, reviewed like code.

## TD-05 Migrations and database roles

- `drizzle-kit generate` writes SQL migrations into `backend/database/migrations/`. Every file is read before it is committed, and hand-written migrations are added for triggers, functions and privileges.
- Two roles: **`mrn_owner`** owns the schema and runs migrations; **`mrn_app`** is what the running API uses (SELECT/INSERT/UPDATE/DELETE only, no DDL, not a superuser). Privileges are granted **inside migrations**, so no setup script can undo them. A test checks them (lesson from the old project).
- Migrations only move forward in shared environments. A rollback is a new migration. Each migration says whether it can be reversed safely.
- **PostgreSQL 18** locally (Docker). It has a native `uuidv7()`: time-ordered UUID keys that index well and do not reveal record counts. PostGIS depends on Q-15; the Docker image includes it, so turning it on is one migration.

## TD-06 Sessions: server-side, opaque tokens in HttpOnly cookies (not JWT)

| Option | For | Against |
|---|---|---|
| JWT access + refresh tokens | stateless checks | revocation needs a denylist; suspension and "sign out everywhere" are not instant; tokens in JS storage are an XSS target |
| **Server-side sessions** | instant revocation (suspension, password change, role change, sign out everywhere); nothing sensitive in the browser | one indexed database read per request |

- **Decision:** a 32-byte random token in an `HttpOnly; Secure; SameSite=Lax; Path=/` cookie. The database stores only its SHA-256 hash (`rider_sessions` / `staff_sessions`), with an idle expiry and an absolute expiry. About 100 lines of our own code; no session library. This follows the pattern the Lucia project documents.
- **Why:** the requirements say suspensions and revocations take effect on the next request (G18, D3, P7). Sessions do that natively.
- **Cost:** a primary-key lookup per request. Fine at this scale; a short in-memory cache can be added if measurements show a need.

## TD-07 Separate rider and staff identities (confirmed, Q-02)

- **Decision:** separate tables, sessions, cookies, sign-in endpoints and reset tokens for riders and staff. The API selects the audience by path (`/api/v1/admin/*` = staff). An optional, explicit link from a staff account to its holder's rider account feeds the no-self-review rule (G13).
- Public rider registration can only ever create a row in `users`; no request field can create or upgrade a staff account (tested, SA-5).

## TD-08 Password hashing: Argon2id

- **Decision:** `@node-rs/argon2`: Argon2id with OWASP-recommended parameters, verified in constant time. Prebuilt binaries, so no compiler is needed on the server.
- **Alternative:** `bcrypt`/`bcryptjs`, kept only as the fallback if the binding fails on the host (RK-09).

## TD-09 CSRF protection

- Cookies are `SameSite=Lax` and the API is same-origin (TD-10). State-changing requests additionally need:
  1. an `X-CSRF-Token` header equal to the token stored on the session (a synchronizer token). The token is returned by `GET …/auth/session` and rotated at sign-in;
  2. an `Origin` header (or `Referer` fallback) in the allowed list.
- Sign-in and password-reset requests have no session yet: they need the `Origin` check and rate limits.
- No library: about 30 lines of middleware, with tests.

## TD-10 Same-origin deployment through a reverse proxy

- Each frontend is served from its own host (for example `myridenepal.com` and `staff.myridenepal.com`). The host forwards `/api/*` to the backend. In development the Vite dev server does the same through `server.proxy`.
- **Why:** cookies stay first-party and host-only, CORS stays closed (no `Access-Control-Allow-Origin`), and no frontend ever needs an API URL in its bundle.
- **Fallback:** if a host cannot proxy, the backend allows exact origins from `CORS_ALLOWED_ORIGINS` with credentials. Off by default.

## TD-11 Validation with Zod at every trust boundary

- Request body, query and parameters are validated by Zod schemas declared on each route; unknown keys are stripped. Environment variables are validated at startup, so a missing secret stops the server with a clear message.
- The frontends use Zod too, through `@hookform/resolvers`, for form feedback only. The backend never trusts it.

## TD-12 API documentation from the validation schemas

- **Decision:** generate an OpenAPI 3.1 document from the same Zod schemas the routes validate with (`zod-openapi` or Zod's native JSON Schema export; chosen in Phase 4 after testing both with the pinned Zod version). It is served at `/api/docs` in non-production environments only, and also written to `backend/openapi.json`, which is committed.
- **Why:** documentation that is generated from the validators cannot drift from them (the old project's API docs did).

## TD-13 Background work: pg-boss (PostgreSQL), no Redis

- **Needed for:** sending email, scheduled jobs (automatic outdating, retention cleanup, deletion processing, evidence expiry, CMS scheduling), retrying file deletions.
- **Options:** BullMQ (needs Redis), node-cron in the API process (jobs lost on restart, run twice with two instances), **pg-boss** (a job queue and cron scheduler stored in PostgreSQL).
- **Decision:** pg-boss, run by a separate `worker` entry point of the backend (same package, same codebase: `npm run worker`).
- **Why:** durable, retries, schedules run exactly once even with several instances, and no new infrastructure (RK-13).

## TD-14 Logging and monitoring

- `pino` + `pino-http`: JSON logs with a request id. Passwords, tokens, cookies, emails and request bodies are redacted by configuration.
- Error monitoring is chosen with the host (Q-19). Until then: structured logs, `/health/live` and `/health/ready` (checks the database), and a written alerting plan.

## TD-15 Uploads and images

- `multer` with memory storage and limits: 8 MB, 1 file per field, and a request count limit.
- Every image is decoded by `sharp`, which rejects anything that is not a real JPEG, PNG or WebP, enforces pixel limits, and re-encodes to WebP. Re-encoding strips EXIF and GPS data (RK-08). Responsive sizes are generated (640, 1280).
- A storage interface with two implementations: local disk (development and test only; production refuses to start with it) and S3-compatible via `@aws-sdk/client-s3` (Q-13). Private files are served only through authorized endpoints or short-lived signed URLs.

## TD-16 Email

- `nodemailer` over SMTP. **Mailpit** in Docker for development, on its own ports. SMTP credentials come from settings (S8, encrypted) or from the environment.
- Emails are sent by the worker (TD-13), never inside a request, so a slow mail server cannot slow sign-up.

## TD-17 Frontend stack (admin and frontend)

| Concern | Choice | Why |
|---|---|---|
| Framework | React 19 + Vite + TypeScript strict | required |
| Routing | React Router (data/library mode) | standard and stable; framework mode only if Q-16 picks server rendering |
| Server state | TanStack Query | caching, retries, loading and error states without hand-written effects |
| Client state | React state and context; **no Zustand at first** | the brief allows it only when it is really needed; nothing needs it yet |
| Forms | React Hook Form + Zod | required; accessible error wiring |
| Styling | Tailwind CSS v4 with design tokens in `@theme` | tokens from `DESIGN_SYSTEM.md` become utilities; no runtime CSS-in-JS |
| Accessible primitives | Radix UI primitives, only for dialog, popover, dropdown menu and tabs (the controls that are hard to make accessible by hand) | focus management and ARIA done right; unstyled, so they follow the design system |
| Icons | `lucide-react` (Q-22) | one consistent set, tree-shaken |
| Maps | Leaflet 1.9 used directly through one small `useLeafletMap` hook per app (no `react-leaflet`: its Hippocratic-2.1 licence is not OSI-approved, see TD-24); tile URL from settings | free, BSD-2-Clause; works with any tile provider (RK-06) |
| Fonts | Fontsource (self-hosted) | no Google requests from visitors' browsers; works offline (F19) |
| Dates | `Intl.DateTimeFormat` / `Intl.RelativeTimeFormat` in `Asia/Kathmandu` | built into browsers; no date library |

## TD-18 Testing

| Layer | Tool | Notes |
|---|---|---|
| Backend unit + integration | Vitest | business rules, state transitions, policies |
| API | Vitest + Supertest against a **real PostgreSQL test database** | no database mocks: constraints, locks and triggers are part of the behaviour. Each test file runs in a transaction that is rolled back |
| Route authorization | one test that lists every registered route and fails if a route is unclassified or a staff route names no permission | the guard against forgotten checks (G15) |
| Components | Vitest + React Testing Library + `jsdom` | forms, states, permission-dependent UI |
| End to end | Playwright (Chromium, Firefox, WebKit, a mobile viewport) + `@axe-core/playwright` | against built apps and an isolated E2E database |
| Concurrency | tests that fire parallel requests (event capacity, last administrator, double moderation) | RK-07 |

Tests that fail are fixed, not skipped or weakened.

## TD-19 Rate limiting

- `express-rate-limit` per route group (sign-in 5/min per email+IP, sign-up and reset per IP per hour, contributions and uploads per rider per day, a general API limit).
- **Store:** in memory while there is one backend instance; switch to a PostgreSQL-backed store before running a second instance. That limit is written in the code as a `shortcut:` comment.

## TD-20 Security headers

`helmet` on the API (strict CSP `default-src 'none'`, `frame-ancestors 'none'`, nosniff, referrer policy, HSTS in production). The frontend hosts send their own CSP (`script-src 'self'`, map tile host in `img-src`). The configuration is in each app's deployment config.

## TD-21 Data conventions

- IDs: UUID (`uuidv7()` default in the database). Slugs for public URLs.
- Times: `timestamptz` in UTC; observation dates are `date`. Shown in `Asia/Kathmandu`.
- Money: `numeric(12,2)` with an `_npr` suffix. NPR only (no currency column), because no money moves through the platform (Q-01).
- Coordinates: `numeric(9,6)` latitude/longitude with a Nepal bounding-box CHECK; a PostGIS `geography` column generated from them if Q-15 is approved.
- Naming: tables plural `snake_case`; JSON API fields `camelCase` (Drizzle maps them).
- Soft delete only where restore is a requirement (editorial content). Rider data is really deleted (privacy).

## TD-22 Code quality tooling

ESLint 10 (flat config) + `typescript-eslint` + `eslint-plugin-react-hooks` + `eslint-plugin-jsx-a11y-x` (the maintained es-tooling fork; the original `eslint-plugin-jsx-a11y` does not support ESLint 10, and ESLint 9 is deprecated) in the React apps; Prettier. Run in CI and as npm scripts in each app. No git hooks at first (they slow every commit; CI catches the same problems).

## TD-23 Local development environment

`backend/scripts/dev-infra.sh` (`npm run db:up` / `db:down` in the backend) starts only **infrastructure**: `postgis/postgis:18-3.6` on port **5442** and Mailpit `v1.31.3` on ports **8035/1035**, bound to 127.0.0.1. These differ from the old project's ports, so both can run side by side. The three apps run with `npm run dev` in their own folders, on ports 4000 (API), 5273 (frontend) and 5274 (admin); E2E uses an API on 4001 (`npm run start:e2e` in the backend) and previews on 4273/4274. No app depends on the script at runtime.

**Changed during Phase 3:** the plan named a `docker-compose.yml`, but the Docker Compose plugin is not installed on the development machine (installing it needs `sudo`). The script uses plain `docker run`, so it works anywhere Docker does. `backend/database/init.sh` creates the roles and the three databases on first start and is reused by CI.

## Dependency budget (backend)

Runtime: `express`, `zod`, `drizzle-orm`, `pg`, `@node-rs/argon2`, `pino`, `pino-http`, `helmet`, `express-rate-limit`, `multer`, `sharp`, `nodemailer`, `pg-boss`, `@aws-sdk/client-s3` (only when Q-13 is decided), an OpenAPI generator (TD-12). Development: `typescript`, `tsx`, `drizzle-kit`, `vitest`, `supertest`, `eslint`, `prettier`, `@types/*`. Any addition needs a line in this file saying why.

## TD-24 Dependency verification (2026-11-27, before Phase 3)

Checked against the npm registry on 2026-11-27: latest version, licence, latest publish date, deprecation, Node engine range, and peer dependency ranges. Node 24.18 satisfies every engine range below. A security check (`npm audit`) runs after each install, and its result is recorded in the session report.

### Decisions the check changed

| Finding | Decision |
|---|---|
| `react-leaflet` 5.0.0 is licensed **Hippocratic-2.1** (not OSI-approved; usage restrictions) | Rejected. Use `leaflet` (BSD-2-Clause) directly through a small hook |
| `typescript` latest is **7.0.2** (native port), but `typescript-eslint` 8.74 needs `<6.1.0` and `openapi-typescript` 7.13 needs `^5.x` | Backend pins **TypeScript 6.0.3**; both frontends pin **5.9.3** (they need `openapi-typescript`). Revisit when the tools support 7 |
| `eslint-plugin-jsx-a11y` 6.10.2 (last release 2024-10) supports ESLint ≤ 9, and ESLint 9 is **deprecated** | Use ESLint 10 with `eslint-plugin-jsx-a11y-x` 0.2 (MIT, es-tooling, ESLint 9–10). If it proves unreliable, drop the lint rules and keep the axe tests, which cover the same ground at runtime |
| `leaflet` latest stable 1.9.4 is from 2023-05; 2.0 is in beta | Use 1.9.4 (stable, widely used); move to 2.x only after its stable release |

### Backend (Phase 3 installs marked ●; the rest are installed in the stage that needs them)

| Package | Version | Licence | Last release | Notes |
|---|---|---|---|---|
| ● express | 5.3.0 | MIT | 2026-10 | Node ≥ 18 |
| ● zod | 4.6.7 | MIT | 2026-10 | no dependencies |
| ● drizzle-orm | 0.45.4 | Apache-2.0 | 2026-10 | peer `pg >= 8` |
| ● pg | 8.23.1 | MIT | 2026-09 | |
| ● pino / pino-http | 10.4.0 / 11.0.0 | MIT | 2026-10 / 2025-10 | |
| ● helmet | 8.3.0 | MIT | 2026-07 | no dependencies |
| express-rate-limit | 8.7.5 | MIT | 2026-11 | Phase 4 |
| @node-rs/argon2 | 2.2.2 | MIT | 2026-10 | prebuilt binaries; S1 |
| multer | 2.5.0 | MIT | 2026-10 | S2 |
| sharp | 0.35.5 | Apache-2.0 | 2026-09 | Node ≥ 20.9; S2 |
| nodemailer | 10.6.1 | MIT-0 | 2026-11 | Phase 4 |
| pg-boss | 12.40.0 | MIT | 2026-11 | Node ≥ 22.12; Phase 4 |
| @aws-sdk/client-s3 | 3.1178.0 | Apache-2.0 | 2026-11 | only after Q-13 |
| zod-openapi | 6.0.3 | MIT | 2026-11 | peer `zod ^4`; preferred over `@asteasolutions/zod-to-openapi` 9.1.0 (last release 2026-07); final pick in Phase 4.6 |
| ● dev: typescript 6.0.3, tsx 4.23.15, drizzle-kit 0.31.11, vitest 5.1.2, supertest 7.3.2, eslint 10.15.0, typescript-eslint 8.74.0, @eslint/js 10.0.1, globals 17.14.0, prettier 3.10.1, @types/node, @types/express 5, @types/pg, @types/supertest | — | MIT / Apache-2.0 | all 2025-12 or newer | vitest needs Node 22/24/≥26 ✓ |

### Frontend and admin

| Package | Version | Licence | Last release | Notes |
|---|---|---|---|---|
| ● react / react-dom | 19.3.1 | MIT | 2026-10 | |
| ● react-router | 8.5.3 | MIT | 2026-11 | Node ≥ 22.22 for tooling ✓ |
| ● @tanstack/react-query | 5.108.4 | MIT | 2026-11 | |
| ● tailwindcss / @tailwindcss/vite | 4.3.5 | MIT | 2026-11 | peer vite ≤ 8 ✓ |
| ● lucide-react | 1.82.0 | ISC | 2026-11 | |
| ● @fontsource/barlow, @fontsource/barlow-condensed, @fontsource-variable/source-serif-4 | 5.3.x | OFL-1.1 | 2026 | admin uses Barlow only |
| react-hook-form / @hookform/resolvers | 7.92.0 / 7.0.9 | MIT | 2026-11 | first form (S1) |
| @radix-ui/react-dialog (and other primitives as needed) | 1.3.1 | MIT | 2026-10 | when the first dialog is built |
| leaflet | 1.9.4 | BSD-2-Clause | 2023-05 | S4 |
| openapi-typescript | 7.13.0 | MIT | 2026-02 | peer TS ^5 → frontends pin TS 5.9.3; Phase 4.6 |
| ● dev: vite 8.4.0, @vitejs/plugin-react 6.3.0, typescript 5.9.3, vitest 5.1.2, jsdom 31.0.1, @testing-library/react 16.3.3, @testing-library/jest-dom 8.0.0, @testing-library/user-event 14.6.13, eslint 10.15.0, typescript-eslint 8.74.0, eslint-plugin-react-hooks 7.1.1, eslint-plugin-react-refresh 0.5.8, eslint-plugin-jsx-a11y-x 0.2.0, prettier 3.10.1, @types/react, @types/react-dom | — | MIT | 2026 | |
| ● dev: @playwright/test 1.65.1 | — | Apache-2.0 | 2026-11 | |
| dev: @axe-core/playwright 4.14.1 | — | **MPL-2.0** (file-level copyleft; dev-only, never shipped in a bundle) | 2026-11 | first a11y E2E (S1) |

Versions are pinned **exactly** in the backend (`save-exact`) and with `^` in the frontends (their lockfiles pin the exact tree). Each app commits its `package-lock.json`.

### Install results (Phase 3, 2026-11-27)

| App | `npm audit` (all) | `npm audit --omit=dev` | Handling |
|---|---|---|---|
| backend | 4 moderate | 0 | **Accepted:** all four are one advisory (GHSA-67mh-4wv8-2f99) in the esbuild 0.18 that `drizzle-kit` (dev only) bundles through `@esbuild-kit`. It affects esbuild's dev server, which drizzle-kit never starts. The suggested "fix" downgrades drizzle-kit to 0.18 (breaking). Re-check on each drizzle-kit release |
| frontend, admin | 0 | 0 | `eslint-plugin-jsx-a11y-x` 0.2.0 pulled `minimatch` 10.2.6 (GHSA-g3hv-75wg-m249, moderate). A scoped npm `overrides` entry gives it `minimatch` 11.0.9, the version ESLint itself uses; lint verified working |

npm 12 blocks dependency install scripts by default; esbuild's postinstall was blocked, and nothing needed it (verified: tsx, Vite and Vitest run). Approve install scripts only per package, after reading them.
