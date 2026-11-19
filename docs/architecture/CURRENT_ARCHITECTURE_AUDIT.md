# Current Architecture Audit

Date: 2026-11-18 (session 9). Inspection only. State described: the **working trees on disk** (Option B, uncommitted), and where it matters, the **committed `HEAD`** on GitHub (what any deployment builds).

## 1. What exists

| Part | Where | Runs with | Port | Output |
|---|---|---|---|---|
| Laravel 12 API (`/api/v1`), Fortify + Sanctum, policies, actions, migrations | `web/` (repo root) | `composer dev` (`php artisan serve --port=8010`, `queue:listen`, `pail`, `--kill-others`) | 8010 | container `Dockerfile.vercel` + `Caddyfile` (FrankenPHP) |
| Local Postgres 17 + Mailpit | `web/supabase/config.toml` | `pnpm db:start` in `web` | 54322 / 54324 | — |
| Public website + rider platform (Vue 3) | `admin/src/apps/public/` | `pnpm dev:public` = `vite --mode public` | 5180 (preview 4180) | `admin/dist/public` |
| Staff dashboard + CMS (Vue 3) | `admin/src/apps/admin/` | `pnpm dev:admin` = `vite --mode admin` | 5181 (preview 4181) | `admin/dist/admin` |
| Code both apps use | `admin/src/shared/lib/` (API client, `format.ts`, `safeNext.ts`; 147 lines incl. tests) | — | — | — |

Both apps call **relative** `/api` and `/sanctum`; Vite proxies them to `API_TARGET` (default `http://127.0.0.1:8010`), so session cookies are first-party on each app's own origin.

## 2. Why stopping the admin dev server stops the public website

Traced in `admin/package.json` and `admin/vite.config.ts`:

1. `pnpm dev` (or `npm run dev`) in `admin` is `run-p dev:public dev:admin`. `run-p` (npm-run-all2) starts **both** Vite servers as children of **one** process. Ctrl-C sends SIGINT to that process group, and `run-p` also stops the remaining task when one exits. Stopping "the admin dev server" therefore stops the public one too. **This is the whole cause.** There is no runtime coupling between the two apps: no shared server, no cross-imports (ESLint forbids them), separate ports, separate builds.
2. Things that make it *feel* like one application:
   - one `package.json`, one lockfile, one `node_modules`, one `vite.config.ts` (the app is chosen by `--mode`); an upgrade or a broken dependency affects both;
   - one repo: every push would rebuild both deployments, and one CI workflow builds and tests both;
   - one `.env` folder for both (`admin/.env`, `admin/.env.local`), which still contain **stale Supabase variables** (`VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY`) that no code reads any more, and `admin/.env` also holds `database_name` / `database_password` (git-ignored, not bundled because not `VITE_`-prefixed, but a database password does not belong in a frontend repo);
   - one E2E suite (`admin/e2e/{public,admin}`) that starts Laravel from `../web` and both previews.
3. Shared real dependency: **the API**. Both apps need Laravel on :8010 (and Postgres). That dependency is intended and stays.

`pnpm dev:public` and `pnpm dev:admin` in separate terminals already run independently today; stopping one does not affect the other. The structural fix is to stop offering a combined command and to give each app its own project (see `PROPOSED_ARCHITECTURE.md`).

## 3. Deployment (Vercel) findings

No Vercel project is linked from this machine (no `.vercel/` folder, no Vercel CLI, no logs). **The exact failure message cannot be read here**; the owner needs to share the Vercel build/runtime log to confirm it. What the code proves:

**Vercel can run this API.** Current Vercel docs: a `Dockerfile.vercel` (or `Containerfile.vercel`) selects the Container preset; the container must listen on `$PORT`; files written in the container are not durable or shared; background workers stop when instances scale down ([Vercel PHP with Docker guide](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker), [changelog 2026-06-30](https://vercel.com/changelog/bring-your-dockerfile-to-vercel-functions)). Our `Dockerfile.vercel` and `Caddyfile` (`:{$PORT:80}`) follow that.

**What GitHub `HEAD` (what Vercel builds) contains:** the pre-Option-B layout (commit `ccc0f45` "fix for deployment"): Laravel + the old public SPA in `web`, a two-stage Dockerfile (Node build of `public/app`, then FrankenPHP). The Option B working tree has never been committed, so no deployment can include it.

Causes, in order of certainty:

| # | Cause | Evidence | Effect |
|---|---|---|---|
| V1 | **No production database exists.** Remote Supabase is out of scope by owner rule and nothing else is provisioned. `.env.example` defaults: `DB_HOST=127.0.0.1`, `DB_PORT=54322`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database` | `HEAD:.env.example`, `config/session.php` | every request that starts a session, including the SPA page on `HEAD`, needs Postgres → HTTP 500. `/api/v1/health` is the only route that could answer |
| V2 | Required runtime environment not set unless entered in Vercel: `APP_KEY` (encryption; Laravel throws without it), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE=true`, `DB_*`, `MAIL_*` | `.dockerignore` excludes every `.env*` (correctly) | 500 on the first encrypted cookie |
| V3 | Storage is the container disk: rider photos and media library files on `local` disk are lost when an instance is replaced and are not shared between instances | `FILESYSTEM_DISK=local`, `LARAVEL_STORAGE_PATH=/tmp/laravel-storage` | uploads "disappear"; decision D1 |
| V4 | Queue worker: `QUEUE_CONNECTION=database` needs a worker; on Vercel HTTP containers there is none (Daemons are a separate beta product) | `composer dev` runs `queue:listen` locally | queued mail/notifications never sent; use `sync` for mail or a Vercel Cron/Daemon later |
| V5 | Frontends: no `vercel.json` anywhere. A static Vite SPA on Vercel needs a rewrite of `/api/*` and `/sanctum/*` to the API origin and an SPA fallback to `index.html`; without it deep links 404 and API calls hit the static host | `find` for `vercel.json`: none | public/staff apps load but every API call fails |
| V6 | Under Option B both frontends share one repo root; Vercel reads `vercel.json` from the project's Root Directory, so two Vercel projects on the same root cannot have two different configs without moving each app into its own folder | Vercel project model | blocks "own deployment configuration" per app |
| V7 | Plan terms: Vercel Hobby is meant for personal, non-commercial use; container CPU/memory quotas on Hobby are not stated in the PHP guide | Vercel docs (check current terms) | owner decision, not a code issue |

None of these mean Vercel is incompatible. V1 and V2 are why a deployment of `HEAD` cannot serve pages; V5/V6 are why the frontends cannot work there yet; V3/V4 are production gaps.

## 4. Authentication boundaries (current)

| Aspect | Current implementation | Assessment |
|---|---|---|
| Identity store | one `users` table for riders and staff; staff = users with rows in `user_roles` → `roles` → `permissions` | rider and staff identities are the same record |
| Guards | `config/auth.php`: one `web` session guard; `config/sanctum.php` `guard => ['web']` | no staff guard |
| Login | `POST /api/v1/auth/login` (Fortify, `throttle:login`), used by **both** apps (`admin/src/apps/admin/features/authentication/staffAccess.ts` `signIn()`) | a rider's credentials authenticate a session on the staff origin; the staff app then shows "No staff access" (`NoAccessPage.vue`). The server refuses every `/admin` route (`can:staff` + per-route permission, proven by `RouteAuthorizationTest`), so there is **no privilege escalation today**, but the boundary is decided after authentication, partly in the UI |
| Registration | `POST /api/v1/auth/register` validates only `display_name`, `email`, `password`; creates a plain user | correct: no role or permission can be injected (extra fields are ignored) |
| Staff provisioning | invitations (`/admin/staff-invitations`, hash-only token, 72 h) **or** granting a role to any existing rider account on People; invitation to an existing email attaches staff roles to the rider account after its password | a rider password becomes a staff credential; no separate provisioning |
| Password reset | one broker; `ResetPassword::createUrlUsing` → `APP_URL/reset-password` (public site) for everyone, including staff and admin-triggered resets | staff reset on the public site |
| Sessions | same cookie name, same lifetime (`SESSION_MAX_MINUTES` 7 days via `SessionMaxAge`) for staff and riders; cookies are host-only (`SESSION_DOMAIN=null`), so the two origins do not share a cookie | staff sessions too long; no idle timeout for staff |
| Password confirmation | `password.confirm` (HTTP 423) on role/invitation routes | good |
| Deactivation / suspension | `EnsureNotSuspended`, `deactivated_at`, Fortify refusal | good, carries over |
| CSRF | Sanctum stateful cookies, `XSRF-TOKEN` per origin | good |
| CORS | `config/cors.php` allows `CORS_ALLOWED_ORIGINS` (default `APP_URL`) with credentials | unnecessary when every app proxies `/api` same-origin; can be empty in production |
| MFA | none (P12 "suggestion only") | weak point for staff |
| Staff powers on rider routes | `MaintenanceMode` lets staff through on the public API; some policies let staff view private/pending items on rider endpoints | must move to staff endpoints when guards split (break risk, see `MIGRATION_PLAN.md`) |

## 5. Backend organisation

- `routes/api.php` (166 lines) holds public, account, rider and staff routes in one file, grouped by middleware. Readable today, but the staff surface will triple with CMS and settings.
- Controllers: `Api/V1/*` (17) and `Api/V1/Admin/*` (14). Actions in `app/Actions/*`, policies per model, `Audit::record`, registries for permissions and settings. **This is good structure and stays.**
- Database: 13 migrations, check constraints, FKs, `mrn_owner` (DDL) vs `mrn_app` (DML) roles, append-only audit log. Stays.

## 6. Frontend code organisation

Measured, not estimated:

| Finding | Evidence | Impact |
|---|---|---|
| Pages call the API directly | 37 `.vue` files contain `api.get/post/put/patch/delete`; most: `TripPage.vue` 12, `PeoplePage.vue` 11, `ContributePage.vue` 8, `EventManagePage.vue` 6; only 3 service files exist (`profile.ts`, `verifications.ts`, `destinations.ts`), none in the staff app | URLs and payload shapes repeated per page; hard to test; renames touch many files |
| One page doing several jobs | `PeoplePage.vue` (274 lines): search, role grants, suspension, deactivation, reset links, session revocation, invitations; `SettingsPage.vue`: four groups switched with `v-if`; `MediaPage.vue`: upload form + library + edit + delete; `TripPage.vue`: stops, members, expenses, checklist, notes | the owner's main complaint; grows worse with CMS and settings groups |
| Types | one `types/api.ts` per app (211 / 205 lines); 10 type names defined in both apps (`Contribution`, `Destination`, `Guide`, `RideEvent`, `RideRoute`, `Segment`, `ServicePoint`, `PublishStatus`, `VerificationKind`, `VerificationStatus`) | acceptable (each app may need different fields), but should live with the feature that owns them |
| File size | largest: `HomePage.vue` 275, `PeoplePage.vue` 274, `ContributePage.vue` 222; nothing above 300 | size is not the problem; mixed responsibility is |
| Form handling | every form uses `fieldErrors(e)` from 422 responses + inline `.field-error`; no client schema library | consistent; keep (server validation is authoritative) |
| Loading / empty / error | public: `useAsync` + `StatusPanel`; staff: `StatusLine`; consistently used | good |
| Permission checks (UI) | `can()` in `staffAccess.ts` + `meta.permission` in the router + `navigation.ts`; three places read permissions but from one source | acceptable; route meta should be generated from feature route files |
| Hard-coded content | `HomePage.vue` (hero label, three headline lines, lead, two CTAs, hero photo + credit, "how knowledge is organised" heading + four layer texts, destinations heading, freshness section and its legend), `SiteFooter.vue` (tagline, disclaimer), header navigation | blocks CM16 and the dynamic-homepage requirement |
| Dependencies | runtime deps all used (`vue`, `vue-router`, `axios`, `lucide-vue-next`, `leaflet` (public only), Tailwind, two font packages) | none unused; Leaflet is public-only today, the staff map picker (L2) will need it too |
| Imports | aliases `@public`, `@admin`, `@shared`; ESLint forbids cross-app imports | good |

## 7. Coupling summary

| Coupling | Real or accidental | Keep? |
|---|---|---|
| Both apps → Laravel API | real | yes |
| Both apps → one `package.json`, Vite config, lockfile, `.env`, CI, E2E, repo | accidental (Option B tooling) | no |
| `pnpm dev` starts both | accidental | no |
| Staff login = rider login endpoint, one guard, one table | design choice from sessions 2–4 | no (owner requirement §3) |
| Staff reset links → public site | side effect of one broker | no |
| Homepage/footer copy in Vue files | not yet built | no |

## 8. Checks run this session (non-destructive)

| Check | Result |
|---|---|
| `php artisan test` (database `myridenepal_test`, `force="true"` in `phpunit.xml`) | 86 passed, 1289 assertions |
| `composer audit` | no advisories |
| `admin`: `pnpm type-check`, `oxlint .` and `eslint .` **without `--fix`**, `pnpm test:unit`, `pnpm build-only` (both apps) | pass; 14 unit tests; both builds |
| `pnpm audit --prod` | 1 high, already reviewed and ignored (documented) |
| E2E | **not run**: it writes to the local development database `myridenepal` (no isolated E2E database exists) and briefly toggles maintenance mode; last full run 64/64 (session 8) |
