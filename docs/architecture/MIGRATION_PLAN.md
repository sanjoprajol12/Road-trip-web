# Migration Plan: independent apps, separate staff authentication, modular frontends, dynamic homepage

Date: 2026-11-18 (session 9). **Status (session 10): A0 replaced by a recovery archive (no commits allowed), A1–A6 done and verified; A7 and A8 next.** Stages A0–A8 implement `PROPOSED_ARCHITECTURE.md`; after them the product roadmap (`docs/audits/REMAINING_IMPLEMENTATION_ROADMAP.md`) continues from Stage 4. Stage A8 replaces the homepage part of roadmap Stages 8 and 9.

Rules for every stage (from CLAUDE.md, unchanged): nothing destructive; new columns nullable or defaulted; old structures retired only after the replacement is verified **and the owner confirms**; every new endpoint has a policy/permission, validation, an audit row for staff decisions, and a feature test; the full check list runs at the end of each stage; `handoff.md` and a session report are updated. **No commits by Claude**: each recovery point below is a commit **the owner makes**.

## Order and dependencies

```
A0 decisions + owner commit of current tree
 ├─ A1 one-command fix (optional, only if A2 is delayed)
 ├─ A2 public app → web/frontend ─┐
 ├─ A3 staff app → admin root    ─┼─ A4 deployment config (no deploy)
 │                                │
 ├─ A5 API route split ───────────┴─ A6 staff authentication separation
 │                                       │
 └──────────────────────────────────── A7 frontend module restructure
                                          │
                                         A8 dynamic homepage + CMS foundation
                                          │
                                     roadmap Stage 4 (trust pipeline) onward
```

A2 and A3 can run in either order but in one session each. A6 needs A5 (route files) and A3 (staff app location). A7 runs after A6 so the staff `auth/`, `staff/` and `riders/` modules are built once, on the new auth.

---

## A0. Decisions and recovery point

**Done differently (session 10):** the owner decided AR1/AR2 in the brief; no commit was allowed, so the recovery point is `/var/www/html/Road-trip/_backups/2026-11-18-pre-option-a/web-admin-working-trees.tgz`. Committing remains the owner's step.

- **Objective:** owner decides AR1–AR5 (`PROPOSED_ARCHITECTURE.md` §10); owner commits both working trees as they are (Option B, sessions 7–8) so GitHub holds a recovery point. Today `HEAD` is the pre-Option-B layout and the only other copy is `/var/www/html/Road-trip/_backups/2026-11-18-pre-option-b/` (pre-Option-B, so it does not contain sessions 7–8 work).
- **Files:** none (owner `git commit` + `git push` in `web` and `admin`).
- **Risk:** without this commit, a failed move in A2/A3 can only be undone from the working tree. **Do not start A2 before it.**
- **Acceptance:** both repos' `HEAD` = current Option B tree; CI result recorded (first CI run ever).

## A1. One-command fix (only if A2/A3 are postponed)

**Superseded by A2/A3 (session 10).**

- **Objective:** stopping one dev server never stops the other, without moving anything.
- **Files:** `admin/package.json` (`dev` no longer `run-p` both; `dev:public` / `dev:admin` stay), `admin/README.md`, `docs/architecture/DEVELOPMENT_SETUP.md`.
- **Dependencies / DB:** none. **Risk:** none. **Backward compatibility:** `pnpm dev:public` and `pnpm dev:admin` unchanged.
- **Tests:** none needed (scripts only); start both, stop one, the other still answers.
- **Rollback:** restore the script line. **Acceptance:** two terminals, two servers, independent.

## A2. Public app into `web/frontend`

**Done (session 10):** public E2E 48/48 in `web/frontend`, old copy removed.

- **Objective:** the public website is its own project inside Road-trip-web.
- **Files affected:** move `admin/src/apps/public/**` → `web/frontend/src/**` (and `public/` static images incl. `images/photos/LICENSES.md`, `index.html`); copy `admin/src/shared/lib/**` → `web/frontend/src/lib/` (147 lines); new `web/frontend/{package.json,pnpm-lock.yaml,vite.config.ts,tsconfig*.json,eslint.config.ts,vitest.config.ts,playwright.config.ts,.env.example}`; move `admin/e2e/public/**` + the parts of `admin/e2e/support/local.ts` it uses → `web/frontend/e2e/`; `web/.dockerignore` adds `frontend/`; `web/.github/workflows/ci.yml` gains a frontend job; `web/CLAUDE.md`, `admin/CLAUDE.md`, `DEVELOPMENT_SETUP.md`, `SYSTEM_ARCHITECTURE.md`, photo-licence path in CLAUDE.md; aliases `@public/*` → `@/*`.
- **Dependencies:** same packages as today, now in two lockfiles (no new dependency); `npm-run-all2` only if a script needs it.
- **Risks:** CLAUDE.md says "No Vue app here" for `web` (must be rewritten, not left contradictory); Laravel `public/` vs frontend `public/` confusion (folder name `frontend/` avoids it); the Docker image must not contain `frontend/` (`.dockerignore` + check `docker build` context size); `APP_URL`/`SANCTUM_STATEFUL_DOMAINS` unchanged (ports stay 5180/4180).
- **Database impact:** none.
- **Backward compatibility:** URLs, ports and API calls unchanged; `admin/src/apps/public` stays in place until the new project passes every check (then removed with owner approval: it is a deletion of files with content).
- **Required tests:** in `web/frontend`: type-check, lint, unit tests (format, safeNext, public specs), build; public E2E on Chromium, Firefox, Pixel 7 (14 tests); Laravel tests unchanged (86).
- **Rollback:** delete `web/frontend` (new files only) and keep using `admin/src/apps/public`; nothing else changed until removal.
- **Acceptance:** `cd web/frontend && pnpm dev` serves the site on :5180 with only the API running; stopping it does not affect the staff app; same E2E results as before the move.

## A3. Staff app to the `admin` root

**Done (session 10):** staff E2E 22/22, old copies removed.

- **Objective:** Road-trip-admin contains only the staff portal.
- **Files:** `admin/src/apps/admin/**` → `admin/src/**`; `admin/src/shared/lib/**` → `admin/src/lib/`; `admin/vite.config.ts` (single app, no `--mode` switch), `admin/package.json` scripts (`dev`, `build`, `preview`, `test:unit`, `test:e2e`), `admin/eslint.config.ts` (cross-app rule replaced by a per-feature rule later), `admin/playwright.config.ts` (admin projects only; `webServer` still starts Laravel from `../web`), `admin/e2e/admin/**` → `admin/e2e/**`; `admin/.env.example` (only `API_TARGET`); `admin/.env` and `.env.local`: **owner** removes the stale `VITE_SUPABASE_*` entries and the database password (they are the owner's files).
- **Dependencies:** `leaflet` stays in admin only if the map picker (roadmap Stage 7, L2) needs it then; otherwise removed from admin (public keeps it).
- **Risks:** E2E helper duplication (each repo has its own `e2e/support`); CI paths.
- **DB:** none. **Backward compatibility:** :5181/:4181 unchanged.
- **Tests:** type-check, lint, unit tests (staffAccess, navigation), build, staff E2E Chromium + Firefox (11 tests).
- **Rollback:** git (`HEAD` from A0).
- **Acceptance:** `cd admin && pnpm dev` = staff portal only; `admin/src/apps/` and `admin/src/shared/` gone (after owner approval); no reference to the public app in `admin/`.

## A4. Deployment configuration (no deployment)

**Done (session 10):** `vercel.json` ×2 + guard, `.env.production.example`, `TRUSTED_PROXIES`, CORS closed by default; the container build failure (Scribe config with `--no-dev`) found by reproduction and fixed; image serves `/api/v1/health` against a local database.

- **Objective:** each of the three deployables carries its own configuration; nothing is deployed and no plan is bought.
- **Files:** `web/frontend/vercel.json`, `admin/vercel.json` (rewrites `/api/(.*)`, `/sanctum/(.*)` to the API origin placeholder, SPA fallback, security headers, staff `noindex`), a build check that refuses a production build with a placeholder or local API origin (ERP idea), `web/Dockerfile.vercel` unchanged except `.dockerignore`; Ignored Build Step command documented for the API project; `docs/architecture/DEPLOYMENT_READINESS.md` rewritten (three projects, env var list incl. `APP_KEY`, `SESSION_SECURE_COOKIE=true`, `CORS_ALLOWED_ORIGINS` empty, `QUEUE_CONNECTION=sync` until a worker exists).
- **Dependencies:** owner decisions AR4 (plan), AR5 (database), D1 (storage), mail provider. **Risk:** CSP in `vercel.json` must allow the map tile host (S10).
- **DB:** none locally; production DB is AR5.
- **Tests:** `vercel build` can be run locally only if the owner installs the CLI (not required); otherwise: both builds + `docker build -f Dockerfile.vercel .` succeed; a container started locally against the local DB answers `/api/v1/health` 200 and refuses a request without `APP_KEY` cleanly.
- **Rollback:** delete the new config files. **Acceptance:** documented, reproducible build for each project; the owner can create the three Vercel projects without further code changes.

## A5. API route split (same URLs)

**Done (session 10):** identical `route:list`.

- **Objective:** routes grouped by audience as in `PROPOSED_ARCHITECTURE.md` §4.
- **Files:** `routes/api.php` → `routes/api/{public,rider,staff}.php` loaded from `bootstrap/app.php`; `tests/Feature/RouteAuthorizationTest.php` (lists unchanged).
- **Risks:** route names (`api.` prefix, `password.update` used by Fortify) and middleware order must stay identical. **DB:** none.
- **Backward compatibility:** `php artisan route:list --json` before and after must be identical (method, URI, name, middleware); that diff is the test.
- **Tests:** route-list diff empty; 86 feature tests. **Rollback:** restore `routes/api.php`.
- **Acceptance:** identical route list; three files, each reviewable alone.

## A6. Staff authentication separation

**Done (session 10):** migration `2026_11_18_100011`, 96 feature tests incl. `StaffAuthTest`; no feature flag was needed (the old path is gone; `user_roles` kept as history).

- **Objective:** separate staff accounts, guard, session cookie, login, password reset and provisioning (decisions AR2, AR2b, AR3).
- **Files (backend):** migration `create_staff_accounts` (`staff_accounts`, `staff_account_roles` FK → `roles.slug`, `staff_password_reset_tokens`, `staff_invitations.staff_account_id`, `audit_logs.actor_type` default `'user'`, `sessions.guard` nullable or a `staff_sessions` table), `app/Models/StaffAccount.php`, `config/auth.php` (guard `staff`, provider `staff_accounts`, broker `staff`), middleware `UseStaffSession` (cookie `mrn_staff_session`, idle/max age), `EnsureStaffActive`; `Api/V1/Admin/Auth/{Login,Logout,Session,PasswordReset,ConfirmPassword,AcceptInvitation}Controller.php`; gates and permission checks move from `User` to `StaffAccount` (`AppServiceProvider`, `Permissions`, `ChangeStaffRole`, `StaffInvitationController`, `UserController` → split into `RiderController` + `StaffAccountController`); policies with mixed rider/staff checks split: `VerificationPolicy`, `ContributionPolicy`, `EventPolicy`, `GuidePolicy`, `MaintenanceMode` (staff bypass replaced by a signed preview link); `console.php` `mrn:staff:create-admin` (replaces `mrn:grant-admin` for staff); `Audit::record` writes `actor_type`.
- **Files (frontend):** staff app `features/auth/*` calls `/admin/auth/*`; forgot/reset pages in the staff app; public app loses nothing (it never had staff features).
- **Data migration (non-destructive):** for each user with a `user_roles` row: create a `staff_accounts` row (same email and display name, **no password**, `rider_user_id` = that user), copy roles to `staff_account_roles`, then send a staff password-set email. `user_roles` rows stay untouched until the owner confirms (then a later migration retires them). Locally `admin@local.test` becomes a staff account without password: set it with `php artisan mrn:staff:create-admin` or the reset email in Mailpit.
- **Existing features that break if this is done carelessly (each needs a test before and after):**
  1. moderators/admins signing in to the staff app with their rider password (intended to stop; owner must expect new staff passwords);
  2. self-review refusal (`ContributionPolicy::moderate`, `VerificationPolicy`, `GuidePolicy::expertReview` "not author") → must use `rider_user_id`;
  3. staff seeing pending contributions, private events and drafts through rider endpoints → staff endpoints;
  4. maintenance bypass for staff on the public site → signed preview link;
  5. audit log actor names (old rows have `actor_type = user`) → audit page resolves both types;
  6. "verification never grants staff rights" → becomes structural; keep the test;
  7. last-active-administrator protection → re-proved on `staff_accounts`;
  8. invitations to existing emails → now always create a staff account;
  9. guide expert review is a **rider** capability (verified experts), not staff: stays on the rider guard.
- **Risks:** largest stage; session table shared by two guards (use distinct cookie names and a guard column so "sign out everywhere" only affects one side); password confirmation (`password.confirm`) must check the staff guard on staff routes.
- **Tests:** the ten tests in `PROPOSED_ARCHITECTURE.md` §5; all existing Permissions, StaffAccess, RouteAuthorization tests ported; E2E staff sign-in, rider refused at staff login, invitation flow, reset flow via Mailpit.
- **Rollback:** feature flag `MRN_STAFF_GUARD=false` keeps the old path (staff routes on `auth:sanctum` + `user_roles`) until the owner confirms; the new tables are additive and can stay empty.
- **Acceptance:** rider credentials never open a staff session (tests 1–4); staff sessions use their own cookie and lifetime; every staff route requires the staff guard (route test); no regression in the 86 existing tests (ported, assertions not weakened).

## A7. Frontend module restructure (behaviour-preserving)

- **Objective:** one responsibility per page; services and types per feature (`PROPOSED_ARCHITECTURE.md` §8).
- **Files:** staff app: `PeoplePage.vue` → `riders/` + `staff/`; `SettingsPage.vue` → one page per group + `SettingsLayout.vue` + `useSettingsGroup.ts`; `MediaPage.vue` → library + upload form + details dialog; `content/` → `destinations/`, `routes/`, `services/`; `components/ui/{DataTable,FormField,FormDialog,PageHeader}.vue`; per-feature `routes.ts` feeding router and navigation. Public app: `TripPage.vue` → five panels; `HomePage.vue` → one component per section; `services/` for every feature that calls the API (37 pages today).
- **Dependencies:** none new. **DB:** none.
- **Risks:** E2E locators (labels, roles, region names) must stay the same: they are the regression net; route paths stay (`/settings` redirects to `/settings/general`).
- **Tests:** existing E2E unchanged and green; unit tests for `useSettingsGroup`, `DataTable` paging/sorting, per-feature route permission metadata (every route has a permission that exists in the registry snapshot).
- **Rollback:** per-module (each module is one change set).
- **Acceptance:** no `.vue` file calls `api.*` directly; no page owns more than one module's data; E2E unchanged.

## A8. Dynamic homepage and CMS foundation

- **Objective:** everything ordinary on the homepage, header, footer and SEO defaults is edited in the staff portal (`PROPOSED_ARCHITECTURE.md` §7); the CMS rules CM-X1–X8 exist once and every later CMS module reuses them.
- **Files (backend):** `config/home.php` (section type registry), migrations `create_home_sections`, `create_home_section_items`, `create_home_slides`, `create_menus` (+ `menu_items`), `create_notices`, `create_banners`; settings groups `branding`, `social`, `seo`, `footer` added to `config/settings.php`; `Support/Cms/{Publishing,PreviewToken}.php` (status transitions, schedule, signed preview); `Api/V1/HomeController` (`GET /home`), `Api/V1/MenuController` (`GET /menus/{location}`), `Admin/Cms/{HomeSection,HomeSlide,Menu,Notice,Banner}Controller`; permissions `cms.home.edit`, `cms.home.publish`, `cms.menus.manage`, `cms.notices.*`, `cms.banners.*`, `settings.{branding,social,seo,footer}.manage`; seed migration with **the current copy** from `HomePage.vue`, `SiteFooter.vue`, `SiteHeader.vue` as published rows.
- **Files (frontend):** public `features/home/{services/home.ts,sections/*.vue}`, header/footer read `/site` + `/menus`; staff `features/cms/{home,menus,notices,banners,sliders}/`, `features/settings/pages/{Branding,Social,Seo,Footer}SettingsPage.vue`, `features/media/components/MediaPicker.vue`.
- **Dependencies:** media library (done), permissions (done); **D4** decides whether SEO tags are visible to crawlers (prerender); D6 for banner scope.
- **Risks:** layout/animation must stay in code (owner's approved visual direction); text lengths bounded by the registry so the design does not break; reduced-motion for sliders; no raw HTML anywhere.
- **Database impact:** additive tables only; seeded rows are the owner's existing copy (not `is_dev_seed`, not invented).
- **Backward compatibility:** day one renders the same homepage (screenshot comparison at 390/768/1440).
- **Tests:** feature: draft invisible publicly, publish copies drafts atomically and is audited, schedule and expiry, unknown fields refused, featured item that becomes unpublished disappears, preview token expiry/forgery, permission per action; E2E: staff change the hero heading and image → publish → public homepage shows it (CM16 acceptance), reorder and hide a section, menu change appears in header.
- **Rollback:** public homepage keeps a code fallback only for **structure** (sections render nothing when data is missing); tables can be left unused.
- **Acceptance:** none of the items in `PROPOSED_ARCHITECTURE.md` §7 "Hard-coded today" remains in a Vue file; the admin → public flow passes in E2E.

## After A8

Roadmap Stage 4 (trust pipeline, no decision needed) resumes, then Stages 5–14 as in `REMAINING_IMPLEMENTATION_ROADMAP.md`. Remaining CMS modules (pages, FAQs, blog, popups, team, contact messages: CM1–CM7, CM11, CM14, CM15) are built on A8's foundation in roadmap Stage 8; remaining settings screens (mail S8, map S10, security S11) in Stage 9.
