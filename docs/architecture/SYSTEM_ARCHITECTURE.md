# System Architecture

Status: **approved** by the owner on 2026-11-27. The reasons behind each choice are in `TECHNICAL_DECISIONS.md` (TD-nn).

**Relationship to the old application (Q-20):** the Laravel/Vue app in `/var/www/html/Road-trip` stays available as a read-only reference. This platform replaces it after successful testing and deployment. Data moves only through the approved plan from stage S16; nothing is imported automatically.

## 1. What the system is

My Ride Nepal is a motorcycle travel planning, rider knowledge and community platform for Nepal. Travel knowledge is organized by **destinations, routes, road segments, services, places, events and real journeys**, each dated and attributed. It is not a social feed.

```
Destination ──< Route ──< Segment ──< Observation (road report, service note, experience)
     │                        │
     └── Places ──────────────┴── Services            Rider ── Trips (private / group)
                                                       Organizer ── Events
```

## 2. Context

```
     Guests / riders (browser)                     Staff (browser)
              │                                          │
              ▼                                          ▼
 ┌──────────────────────────┐            ┌──────────────────────────┐
 │ frontend/  (public site) │            │ admin/  (staff portal)   │
 │ React SPA, static files  │            │ React SPA, static files  │
 │ host proxies /api/* ─────┼──┐      ┌──┼── host proxies /api/*    │
 └──────────────────────────┘  │      │  └──────────────────────────┘
                               ▼      ▼
                 ┌───────────────────────────────────┐
                 │ backend/  Express 5 API  /api/v1  │──► SMTP (mail provider / Mailpit)
                 │  rider routes │ staff routes      │──► Object storage (S3-compatible)
                 │  public reads │ (/api/v1/admin)   │──► Routing / geocoding (optional, Q-14)
                 ├───────────────────────────────────┤
                 │ backend worker (pg-boss jobs)     │
                 └────────────────┬──────────────────┘
                                  ▼
                       PostgreSQL 18 (+ PostGIS, Q-15)
                       app role: mrn_app (DML only)

 Browsers also load map tiles directly from the tile provider set in settings (S10).
```

**Boundaries:**

- The two frontends contain **no business rules and no secrets**. They render API data and send user intent. Hiding a button is UX, not security.
- The backend is the **only** process that talks to the database, storage, mail and external providers. Provider keys never reach a browser.
- The admin app is not trusted more than the public site: every staff request is authorized again by the backend (permission + ownership + no-self-review).
- The frontends never import from each other or from the backend. The API contract is the OpenAPI document (TD-01, TD-12).

## 3. The three applications

| | backend | admin | frontend |
|---|---|---|---|
| Purpose | REST API, business rules, jobs | staff portal: moderation, content, CMS, settings, people | public website and rider platform |
| Users | — | staff accounts | guests, riders |
| Runtime | Node 24, Express 5 | static SPA | static SPA (Q-16 may add prerendering) |
| Dev port | 4000 | 5274 | 5273 |
| Dev command | `npm run dev` (API), `npm run worker` | `npm run dev` | `npm run dev` |
| Talks to | PostgreSQL, SMTP, storage, providers | backend via `/api` proxy | backend via `/api` proxy |
| Deploys as | a Node service + a worker process | static files + proxy rule | static files + proxy rule |

Each has its own `package.json`, `package-lock.json`, `tsconfig.json`, `.env.example`, lint, tests, build and README. Stopping one never stops another. A frontend whose API is down shows its error states, not a blank page.

## 4. Backend structure

```
backend/
├── src/
│   ├── server.ts              # starts HTTP; graceful shutdown
│   ├── worker.ts              # starts pg-boss jobs and schedules
│   ├── app.ts                 # builds the Express app (used by server and tests)
│   ├── config/                # env schema (Zod), loaded once
│   ├── database/              # Drizzle client, schema index, transaction helper
│   ├── middleware/            # request id, sessions, csrf, rate limits, errors, validate()
│   ├── common/                # authz (requirePermission, policies), audit, pagination, errors, storage, mail
│   ├── modules/
│   │   ├── health/
│   │   ├── auth-rider/        # sign-up, confirm email, sign-in, reset, sessions
│   │   ├── auth-staff/        # staff sign-in, reset, invitations, MFA (S14)
│   │   ├── accounts/          # rider profiles, account controls, deletion/export (S13)
│   │   ├── staff/             # staff accounts, roles, permissions, audit log viewer
│   │   ├── verification/      # claims: trip contributor, organizer, expert, identity
│   │   ├── settings/
│   │   ├── media/             # uploads, media library, usages
│   │   ├── destinations/
│   │   ├── routes/            # routes, segments, service points, geometry import
│   │   ├── places/            # places, suggestions, corrections, along-the-route queries
│   │   ├── contributions/     # contributions, moderation, reports, confirmations, trust jobs
│   │   ├── sources/           # citations attached to destinations, routes, places, contributions
│   │   ├── notifications/
│   │   ├── trips/             # trips, members, invitations, stops, expenses, checklists, notes
│   │   ├── saved-items/
│   │   ├── events/
│   │   ├── guides/            # preparation and safety guides, checklist templates
│   │   ├── emergency-contacts/
│   │   ├── cms/               # entries, homepage sections, menus, notices, contact inbox
│   │   └── privacy/           # retention jobs, deletion processing (S13)
│   └── types/
├── database/migrations/       # SQL, reviewed and committed
├── tests/                     # integration + API tests (unit tests sit next to code)
└── openapi.json               # generated, committed
```

**Inside a module** (only the files the module needs):

```
modules/events/
├── events.routes.ts      # paths, validate(schema), requireRider/requirePermission, → controller
├── events.controller.ts  # thin: read req, call service, shape response
├── events.service.ts     # business rules, transactions, audit, state transitions
├── events.policy.ts      # who may do what to which event (pure functions, unit-tested)
├── events.schemas.ts     # Zod: request and response shapes (also feed OpenAPI)
├── events.queries.ts     # Drizzle queries, only where they are shared or complex
├── events.table.ts       # Drizzle table definitions for this module
└── events.service.test.ts
```

No repository layer for its own sake: services call Drizzle directly, and a `*.queries.ts` file appears only when a query is reused or long. Controllers never touch the database.

**Request pipeline:** `request id → logger → helmet → body limits → session lookup (by path audience) → CSRF check (unsafe methods) → rate limit → route: validate → authorize → controller → service → response schema → error handler`.

**Errors:** services throw typed errors (`NotFound`, `Forbidden`, `Conflict`, `ValidationFailed`, `RateLimited`). The single error handler maps them to the response format in `API_DESIGN.md`. Unknown errors become a 500 with the request id; details go to the log only.

## 5. Frontend structure (both apps)

```
src/
├── main.tsx, App.tsx        # providers: QueryClient, Router, auth session
├── app/routes.tsx           # route table, lazy-loaded pages, guards (UX only)
├── layouts/                 # PublicLayout / AccountLayout (frontend); StaffLayout (admin)
├── pages/                   # one folder per route, thin: compose features
├── features/<area>/         # components, hooks (useEvents…), api.ts, schemas.ts per product area
├── components/ui/           # design-system primitives (Button, Field, Dialog, Table, StatusPanel…)
├── lib/api/                 # fetch wrapper (CSRF header, errors), generated OpenAPI types
├── lib/format/              # dates in Asia/Kathmandu, age in words, NPR, distances
├── styles/                  # tokens (@theme), base styles
└── test/                    # test setup, helpers
```

- API calls live only in `features/*/api.ts`, wrapped in TanStack Query hooks. Components never call `fetch`.
- Session: one `useSession()` query (`GET …/auth/session`). Route guards redirect for UX; the API decides.
- Admin navigation and controls are built from the permission list in the staff session, so a staff member never sees a screen that would answer 403.
- Pages are lazy-loaded per route (code splitting); maps are loaded only on pages that show one.

## 6. Cross-cutting concerns

| Concern | Where | Design |
|---|---|---|
| Authentication | `auth-rider`, `auth-staff`, session middleware | `SECURITY_ARCHITECTURE.md` §2 |
| Authorization | `common/authz`, `*.policy.ts` | permission registry + policies + ownership through parent records (§3 there) |
| Audit | `common/audit` | `audit.record(tx, …)` inside the same transaction as the change; append-only table |
| Transactions | `database/tx.ts` | services open one transaction per use case; row locks for capacity, last admin, moderation decisions |
| Jobs | `worker.ts` + `modules/*/jobs.ts` | email, outdating, retention, CMS scheduling, deletion processing, file-deletion retries |
| Notifications | `notifications` | in-app rows + email via the worker; which events trigger email comes from settings |
| Files | `common/storage` + `media` | local disk (development only) or S3-compatible; images re-encoded |
| Settings | `settings` | typed groups validated by Zod; secrets encrypted (AES-256-GCM, key in the environment); 60 s in-memory cache |
| Time | everywhere | stored in UTC, shown in Asia/Kathmandu; observation dates are plain dates |

## 7. Environments

| | Development | Test / CI | Production |
|---|---|---|---|
| Database | Docker `postgis/postgis:18`, port 5442, db `myridenepal_dev` | `myridenepal_test` (API tests), `myridenepal_e2e` (Playwright) | managed PostgreSQL (Q-19) |
| Mail | Mailpit (8035 UI) | Mailpit or captured transport | provider (Q-19) |
| Storage | local disk | local temp directory | S3-compatible (Q-13); local disk refused |
| Secrets | `.env` (git-ignored), from `.env.example` | CI secrets | host secret store; migration credentials only in the deploy step |

## 8. Deployment shape (nothing is deployed without approval)

- **Backend:** one Node process for HTTP and one for the worker, behind HTTPS. Migrations run as a separate deploy step with the owner role. Health endpoints for the host's checks.
- **Frontends:** static `dist/` folders on any static host or CDN, with a rewrite of `/api/*` to the backend and an SPA fallback to `index.html`. Each has its own security headers.
- **Scaling path:** add backend instances behind the proxy after moving rate limits to PostgreSQL (TD-19). Sessions, jobs and files are already shared through PostgreSQL and object storage. Nothing needs Redis until measurements show it.

## 9. What is deliberately not here

No microservices, no message broker, no Redis, no GraphQL, no shared package, no server-side rendering (unless Q-16 chooses it), no state library. Each would need a measured reason.
