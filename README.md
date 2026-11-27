# My Ride Nepal: backend (API)

Express 5 + TypeScript (6.0) + Drizzle ORM + PostgreSQL 18. Independent app: its own dependencies, config, tests and build. Design: `docs/architecture/SYSTEM_ARCHITECTURE.md` §4, `docs/api/API_DESIGN.md`. **This repository also holds the project documentation** (`docs/`, `handoff.md`, `reports/`, `CLAUDE.md`) for all three repositories.

## Prerequisites

- Node 24 (`.nvmrc`), npm 12
- Docker, for the local database and mail server. This project owns them:
  `cp .env.infra.example .env.infra` (set three passwords), then `npm run db:up` (PostgreSQL 18 + PostGIS on 127.0.0.1:5442, Mailpit on 8035/1035). `npm run db:down` stops them; `bash scripts/dev-infra.sh reset` deletes the local data. `database/init.sh` creates the roles and databases on first start.

## Setup

```bash
npm ci
cp .env.example .env            # set MRN_APP_PASSWORD from .env.infra
cp .env.test.example .env.test  # same password; database myridenepal_test
```

`DATABASE_URL` always uses the **app role `mrn_app`** (DML only). Migration credentials never go in these files (TD-05).

## Commands

| Command                                       | What it does                                                                                                         |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `npm run dev`                                 | API on http://localhost:4000 with reload (`tsx watch`)                                                               |
| `npm test`                                    | Vitest + Supertest against the real `myridenepal_test` database (refuses any other database)                         |
| `npm run lint` / `typecheck` / `format:check` | ESLint, `tsc --noEmit`, Prettier                                                                                     |
| `npm run build` then `npm start`              | compile to `dist/` and run it with plain Node                                                                        |
| `npm run start:e2e`                           | run the build against the isolated `myridenepal_e2e` database on :4001 (`.env.e2e`), for the web apps' browser tests |
| `npm run check`                               | everything CI runs: lint, typecheck, format, tests, build, production audit                                          |

## Endpoints so far

| Path                   | Purpose                                                 |
| ---------------------- | ------------------------------------------------------- |
| `GET /health/live`     | the process is up (no database)                         |
| `GET /health/ready`    | the database answers; 503 otherwise                     |
| `GET /api/v1/health/*` | the same, reachable through the frontends' `/api` proxy |

Errors use one JSON shape: `{ "error": { "code", "message", "fields?", "requestId" } }`. Every response carries `X-Request-Id`.

## Layout

`src/config` env schema · `src/database` Drizzle client · `src/middleware` error handling · `src/common` shared errors · `src/modules/<area>` features · `tests/` API tests.

## Troubleshooting

- `Invalid environment configuration`: the message lists each missing or invalid variable.
- `permission denied for schema public` when creating tables as `mrn_app`: expected; tables are created by migrations with the owner role (Phase 4).
- npm 12 blocks dependency install scripts by default; esbuild's is not needed (its platform binary ships as a package).

## Known dependency advisory (accepted)

`drizzle-kit` (dev only) bundles an old esbuild (GHSA-67mh-4wv8-2f99, moderate) that affects esbuild's development server, which drizzle-kit never starts. Not shipped; `npm audit --omit=dev` is clean. Re-check when drizzle-kit updates.
