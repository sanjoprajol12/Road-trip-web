# Implementation Roadmap

Status: **approved** (2026-11-27). The owner answered Q-01, Q-02, Q-20 and Q-22 and approved the architecture; Phase 3 is in progress. Revised on approval: dependency verification added to Phase 3 (done, TD-24), and a legacy-data stage S16 added (Q-20).

**Order:** each stage depends only on earlier ones. Each stage is a vertical slice: database, API, admin screens and public screens together (TD-02). No time estimates are given: they would be guesses. Sizes are relative (S/M/L/XL).

## How every stage runs (the brief's per-feature workflow)

1. Re-read the stage's inventory rows and answer its open questions.
2. Write acceptance criteria in `docs/architecture/acceptance/Sn-<name>.md`.
3. Design the migration; review the SQL.
4. Backend: schemas → policy → service → routes; API tests including 401/403/404/IDOR and validation.
5. Regenerate OpenAPI; regenerate the types in both frontends.
6. Admin and public screens with every state (§8 of the design system); component tests.
7. E2E for the stage's main journeys; axe checks.
8. Update the docs, the inventory's Impl/Test columns, `handoff.md`, and the session report.
9. **Exit gate:** all tests pass in all three apps, lint and type-check are clean, `npm audit` has no unaccepted high findings, and the owner has seen the stage working.

---

## Phase 3: Project foundation (M): **done 2026-11-27** (WebKit waits for the owner's `sudo` step; the first commit waits for the owner)

| # | Task | Done when |
|---|---|---|
| 3.0 | Verify every Phase 3 dependency (maintenance, licence, security, compatibility) | done 2026-11-27: TD-24; `npm audit` after each install |
| 3.1 | One git repository per project (backend → Road-trip-web, frontend → Road-trip-front, admin → Road-trip-admin, branch `develop`); docs in the backend repository; **no root `package.json`** (owner direction 2026-11-27) | first commit pushed to each repository's `develop` |
| 3.2 | Local infrastructure owned by the backend (`backend/scripts/dev-infra.sh`, `backend/database/init.sh`): `postgis/postgis:18` on 5442 with `myridenepal_dev`, `_test`, `_e2e` and the roles `mrn_owner` / `mrn_app`; Mailpit on 8035/1035 | done with `scripts/dev-infra.sh` (no Compose plugin on this machine, TD-23); runs next to the old project's containers |
| 3.3 | `backend/`: Express 5 + TS strict + ESM, env schema, pino, helmet, error handler, request id, `/health/live` and `/health/ready`, Drizzle client, Vitest + Supertest, ESLint + Prettier, `.env.example`, README | `npm run dev`, `npm test`, `npm run build`, `npm start` all work; the readiness endpoint reports the database |
| 3.4 | `frontend/`: Vite + React 19 + TS strict, React Router, TanStack Query, Tailwind v4 with the design tokens, fonts, `/api` proxy, Vitest + RTL, ESLint (jsx-a11y), Playwright config, `.env.example`, README | dev, test, build and preview work; one page renders a live `/health` result (real data, not a placeholder) |
| 3.5 | `admin/`: the same set-up, its own port, the staff layout shell | as for the frontend |
| 3.6 | CI: `.github/workflows/ci.yml` in each repository running its `npm run check`; the web apps' workflows also start an API from the backend repository and run their browser tests against it over HTTP | each repository's `npm run check` passes locally; workflow files parse; GitHub runs after the first push |
| 3.7 | Playwright browsers installed, including WebKit system libraries (needs `sudo`, so the owner runs one command) | a smoke E2E runs in Chromium, Firefox and WebKit |
| 3.8 | Independence check: stop any one app and the others keep working | written in the session report |

## Phase 4: Backend foundation (L)

| # | Task |
|---|---|
| 4.1 | First migrations: roles and grants, extensions (`pg_trgm`, PostGIS if Q-15), `audit_logs` with its append-only trigger, `provinces`/`districts` reference data (source cited), the pg-boss schema |
| 4.2 | `validate()` middleware, error types, response helpers, pagination helper |
| 4.3 | Session middleware (audience by path), CSRF middleware, rate limiter |
| 4.4 | Authorization core: permission registry, `requireStaff`, `requireRider*`, policy helpers, the **route-matrix test** |
| 4.5 | Audit service (`audit.record(tx, …)`) and its privilege test |
| 4.6 | OpenAPI generation and `/api/docs`; type generation in both frontends; CI check for drift |
| 4.7 | Worker entry point with pg-boss; mail transport (Mailpit) |
| 4.8 | Database privilege test: `mrn_app` cannot run DDL or update or delete audit rows |

---

## Phase 5–7: Product stages (vertical slices)

| Stage | Scope (inventory rows) | Needs answers | Size |
|---|---|---|---|
| **S1 Identity and access** | J2–J4, G1, G2, G3, G4, G15, G16, G18, G20, SA-1…SA-11, P1–P11, login activity, sessions; admin: sign-in, staff, roles, riders, audit log; frontend: sign-up, sign-in, reset, account, profile, sessions | Q-02, Q-17, Q-27 | XL |
| **S2 Settings and media** | S1–S4, S8, S9, S12, CM12; maintenance mode and preview token; public `/site` | Q-13 (interface only) | L |
| **S3 Verification claims** | G5–G14, D2, D3 | Q-11 | M |
| **S4 Destinations** | A1–A3, A6, A8, A9, A11–A13, H3, L1–L3, L7–L12, L14; admin map picker | Q-06 (warning threshold), Q-15 | L |
| **S5 Routes and services** | A5, A7, B1–B6, B8, B12, B13, L5, L6; GPX/GeoJSON import | Q-07, Q-12 (`rest`), Q-14 (B5) | L |
| **S6 Contributions and trust** | A10, B9–B11, C1–C19, H1, H2, H4–H6, H9–H12; trust settings; moderation queue | Q-06, Q-08, Q-12 (drafts) | XL |
| **S7 Notifications** | X10; in-app centre; email through the worker; notification settings | — | M |
| **S8 Places and Explore Along My Route** | A4, A14, B7, L4, L13, R1–R9, R12–R16 | Q-14, Q-15 | XL |
| **S9 My Rides** | E9, F1–F18, R10, R11, SV1–SV3, G17 (trips) | Q-10, Q-21 | XL |
| **S10 Events** | D1, D4–D21, G17 (events) | Q-09, Q-12 (event reports) | XL |
| **S11 Preparation and safety** | E1–E8, E10–E14; checklist templates | Q-12, Q-23 (content) | M |
| **S12 CMS and dynamic homepage** | S5–S7, S10, S11, CM-X1…CM-X8, CM1–CM16, H-1…H-17, footer | Q-16, Q-18, Q-05 (contact) | XL |
| **S13 Privacy: export, deletion, retention** | G19, retention jobs | Q-04, Q-05 | L |
| **S14 Staff MFA** | P12 | Q-03 | M |
| **S15 Offline Ride Pack** | F19 | Q-28 | L |
| **S16 Legacy data audit and migration plan** | LM-1: read-only audit of the old Laravel database (local copies only); relevant records; field-by-field mapping to the new schema; duplicate, outdated and unnecessary data; a written plan **presented for approval**. The import itself is a separate, approved step | owner approval of the plan; access approval for any non-local database | M |

**Why this order:**

- Identity, settings and media come first because everything else needs them.
- Claims (S3) come before the content that uses them (organizer → events, expert → guides).
- Destinations, routes and contributions come before places, because the along-the-route queries need routes and the places pages show contributions.
- Notifications (S7) come before trips and events, which are the main senders.
- The CMS comes after the product pages it features.
- Privacy (S13) comes last among features, because it touches every table.
- The legacy-data plan (S16) comes after the schema is complete, because a mapping is only meaningful against the final tables. The old app stays running as a reference until the new platform replaces it.

**Phases 6 and 7 (admin and public completion passes)** run after S12: navigation and information-architecture review, the admin overview dashboard with real counts, consistency between screens, empty-state copy, and performance work (code splitting check, image sizes, bundle budget).

## Phase 8: Integration and quality assurance (L)

- Full E2E on built apps in Chromium, Firefox, WebKit and a mobile viewport, against the isolated E2E database.
- Concurrency suite (capacity, last administrator, double decisions).
- Accessibility: axe on every page, plus a manual keyboard and screen-reader pass.
- Performance: Lighthouse on production builds (targets LCP < 2.5 s, CLS < 0.1, INP < 200 ms on a mid-range phone profile); API p95 < 300 ms on realistic data volumes; JS budget for the initial route < 200 KB gzipped (maps loaded separately).
- Security: OWASP ZAP baseline, ASVS L2 review of authentication, sessions and access control, dependency audit.
- Regression: every inventory row has Impl and Test filled in, or a written reason.

## Phase 9: Final audit (M)

The audit described in the brief (§15): architecture, functionality, security, frontend, backend, testing and operations. Each check is marked passed (with evidence), failed or unverified. Critical and high issues are fixed before the project is called ready. The deployment runbook, backup and restore runbook and monitoring plan are written for the host chosen under Q-19.

## Not scheduled until approved

CAPTCHA (Q-25), analytics (Q-26), a second language (Q-17), a routing provider (Q-14), splitting costs between trip members (Q-21), identity verification (Q-11).
