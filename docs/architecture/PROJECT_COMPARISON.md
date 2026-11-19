# Project Comparison: My Ride Nepal vs. the consultancy and ERP projects

Date: 2026-11-18 (session 9, investigation only). Every path and command below was read on disk; nothing was copied or changed in the other projects.

## Projects found

| Project | Location | Repos (git remote `origin`) | Accessible |
|---|---|---|---|
| My Ride Nepal | `/var/www/html/Road-trip/web`, `/var/www/html/Road-trip/admin` | `Road-trip-web`, `Road-trip-admin` | yes |
| Consultancy | `/var/www/html/consultancy/web`, `/var/www/html/consultancy/portal` | `consultancy-web`, `consultancy-admin` (remote name `neworigin`) | yes |
| ERP | `/var/www/html/erp/erp-web`, `/var/www/html/erp/erp-portal` | `erp-web`, `erp-portal` | yes |
| Others in `/var/www/html` (`Real-estate`, `realestate`, `Travel`, `lions`, `sainly`, `surpluslink`, `vision`, `bots`) | listed, not inspected | — | not needed: the two projects the owner named already show the pattern |

## The pattern both reference projects share

Both use the **same two-repo shape**, and it is the shape the My Ride Nepal repo names suggest:

- **`*-web` = Laravel backend at the repo root + the public website frontend** (Inertia, in `resources/`), served by Laravel.
- **`*-portal` = the staff/admin SPA only** (Vue 3 + Vuetify + vue-router + Pinia), its own `package.json`, `vite.config.ts`, `.env`, dev port and build, talking to the Laravel API by URL (`VITE_API_URL`).

My Ride Nepal (Option B) differs: `web` is the API only, and **both** frontends live in `admin`, built from one `package.json` and one `vite.config.ts` with `--mode public|admin`.

## Evidence per project

### Consultancy

- `web/vite.config.ts`: `laravel-vite-plugin` with **six entry points** (`resources/ts/app.ts`, `front.ts`, `consultant.ts`, `mypr.ts`, `student.ts`, `agent.ts`); dev server `consultancy.local:5173`. Laravel renders them with Inertia (`inertiajs/inertia-laravel`, blade views `app`, `agent`, `consultant`).
- `web/composer.json` `dev`: `concurrently php artisan serve, queue:listen, pail, npm run dev --kill-others` (one command, everything stops together; same trap as ours, just in the backend repo).
- `web/config/auth.php`: **separate guards and separate user tables**: `web`/`users`, `admin`/`admins` (`AdminUser`), `mypr`, `consultant`, `student`, `agent`, each a session guard with its own provider.
- `web/routes/`: `api.php`, `admin.php`, `front.php`, plus folders `agent/`, `consultant/`, `mypr/`, `student/`, `mobile/` (one route file per audience).
- `web/app/Http/Controllers/{Admin,Agent,Consultant,Front,Mobile,Mypr,Student}/…` (controllers grouped by audience, then by module, e.g. `Admin/Cms/Faq/FaqController.php`).
- `portal/`: standalone SPA. `package.json` scripts `dev`, `build --mode production`, `deploy-portal` (build + `aws s3 sync` to a Wasabi bucket), `deploy-applycan` (staging). `.env.example`: `VITE_API_URL`, `VITE_SITE_KEY`, and `GOOGLE_CAPTCHA_SECRET_KEY=` (a **secret-looking key in a frontend env file**: an anti-pattern, not to copy). `src/`: `api/ components/<domain>/ composable/<domain>/ services/ store/ views/ navigation/ plugins/{1.pinia,2.router,iconify}`, `@casl/vue` for permission checks.

### ERP

- `erp-web/`: Laravel 13 at the root, Inertia public site (`resources/js/{Pages,Layouts,Components,api,services,types}`), `vite.config.js` one entry (`resources/js/app.ts`), CORS origins built from `APP_URL`.
- Routes split by audience and module: `routes/api.php` (v1 entry), `routes/admin/v1/{academics,cms,finance,rbac,platform,…}.php` (21 files), `routes/mobile/v1`, `routes/shared/v1`, `routes/cms.php`, `routes/web.php`.
- Auth: one `users` provider, **two session guards** (`web` and `PlatformSession::GUARD`), selected per request by `app/Http/Middleware/SelectAuthenticationSession.php`, which also **renames the session cookie** (`<cookie>_platform`) for the platform area. `AuthenticatePortal.php` refuses Bearer tokens on the browser portal before authentication. Platform staff have a separate login (`routes/web.php` `admin/platform/login`, always two-factor, invite links `admin/platform/invite/{staff}/{token}`).
- `erp-portal/`: standalone SPA like consultancy's. `vite.config.ts` has a **production build guard** (`productionApiUrlGuard()` fails a production build whose `VITE_API_URL` points at a dev API) and an optional dev proxy (`VITE_DEV_PROXY_TARGET`). Scripts: `dev`, `build` (after `scripts/check-toolchain.mjs`), `test:unit`, `test:e2e` (Playwright).
- `erp-portal/src` organisation: `views/pages/<module>/` with **one list page + one form per entity** (`cms/blog/Blog.vue` 370 lines + `BlogForm.vue` 550 lines; `cms/faq/Faq.vue` + `FaqForm.vue` + `faq/category/…`), `services/<module>/<Entity>Service.ts` classes extending `BaseAPIService` (`CmsAPIService` sets base `/api/v1/admin`), `types/<module>/`, `navigation/`, `plugins/2.router/guards.ts`, co-located `*.spec.ts`.
- Site settings: **one page with tabs** (`views/pages/site-setting/SiteSetting.vue`, 262 lines) and one component per tab (`components/{General,Branding,Smtp,Storage,Seo,Social,Security,Advanced,Tax,…}.vue`, up to 371 lines each).

## Comparison table

| Area | My Ride Nepal (now) | Consultancy / ERP | Recommended approach |
|---|---|---|---|
| Project structure | `web` = Laravel API only; `admin` = both Vue apps (`src/apps/public`, `src/apps/admin`, `src/shared`) from one `package.json` | `*-web` = Laravel + public site (Inertia); `*-portal` = admin SPA only | Repo per audience, like theirs: `web` = Laravel (root) + public SPA in `web/frontend/`; `admin` = admin SPA only. Public stays a static SPA (not Inertia) so it deploys independently of the API |
| Frontend separation | separate entries, ports and `dist/` folders, but one toolchain, one lockfile, one Vite config switched by `--mode`, one `pnpm dev` (`run-p`) that starts and stops both | portal fully separate from the public site (own repo, toolchain, env, deploy); consultancy's six web entries share one Vite server | Each frontend its own project folder with its own `package.json`, lockfile, `vite.config.ts`, `.env`, `vercel.json`, E2E |
| Backend separation | one `routes/api.php` (166 lines), controllers `Api/V1` and `Api/V1/Admin` | routes per audience and module (`routes/admin/v1/*.php`); controllers per audience/module | Split `routes/api.php` into `routes/api/{public,rider,staff}.php`; keep `Api/V1` and `Api/V1/Admin` controller split; per-module folders when a module has more than ~3 controllers |
| Development commands | `composer dev` (API :8010) in `web`; `pnpm dev` in `admin` = both apps; `pnpm dev:public`/`dev:admin` exist but are not documented as the normal path | `composer dev` runs Laravel + Vite together (kill-others); `pnpm dev` in portal | Three independent commands: `composer dev` (API), `pnpm dev` in `web/frontend` (public :5180), `pnpm dev` in `admin` (staff :5181). No command starts two apps |
| Authentication | one `web` session guard, one `users` table; staff are users with roles; staff app signs in through the rider endpoint `/api/v1/auth/login`; same session lifetime (7 days) for both | consultancy: separate guard **and table** per audience; ERP: one table, separate guard **and session cookie** per area, separate staff login with 2FA | Separate staff guard, staff session cookie, staff login/reset endpoints, staff accounts in their own table (consultancy's isolation + ERP's cookie selection). Details: `PROPOSED_ARCHITECTURE.md` §5 |
| Route organization (frontend) | one router file per app (`app/router/index.ts`, 19 and 25 routes), navigation in `app/navigation.ts` with permission keys | `plugins/2.router/{index,guards}.ts` + `navigation/` per app; CASL for UI permission checks | Keep one router per app but let each feature export its own `routes.ts`; keep the current `can()` + `meta.permission` (CASL adds a dependency for what `can()` already does) |
| Feature organization | `features/<feature>/{pages,components,services}`; only 3 `services/` files exist; 37 `.vue` files call `api.*` directly; types in one `types/api.ts` per app | `views/pages/<module>/` + `services/<module>/` + `types/<module>/` (ERP); clear service layer, list+form pairs | Feature folders (ours) **plus** ERP's service and type discipline: `features/<f>/{pages,components,services,types,composables}`; no inline `api.*` in pages |
| Settings management | registry `config/settings.php` + `App\Support\Settings` (validated, encrypted secrets, cached, audited); one staff page `SettingsPage.vue` (145 lines) switching on four groups | ERP: one `SiteSetting.vue` with tabs + one component per group | Keep our backend registry (stronger than ERP's). Frontend: one route and page per group under a Settings layout with tabs; shared `useSettingsGroup()` composable |
| CMS management | not built (only media library); homepage copy hard-coded in `HomePage.vue` | ERP: one list + form per CMS entity (blog, categories, faq, menu, page, popup, sliders, team, testimonial…), `CmsAPIService` base, `routes/cms.php` with `can:cms.view` | Adopt ERP's one-entity-one-module shape, but with structured, validated content (no free HTML), draft/published/archived and a homepage section model. See `PROPOSED_ARCHITECTURE.md` §7 |
| Shared components | `src/shared/lib` (API client 71 lines, `format.ts` 29, `safeNext.ts` 4); UI components per app (`StatusLine`, `ConfirmDialog` admin; `StatusPanel`, `PlacePhoto`, `VerifiedBadges` public) | Vuetify supplies tables, dialogs, forms; `components/table/CustomTable.vue`, `BaseDrawer.vue` in ERP; auto-imports | Each app owns its UI kit. Add the missing admin primitives once (`DataTable`, `FormField`, `PageHeader`, `FormDialog`) instead of repeating markup; no Vuetify, no auto-import (explicit imports are easier to trace) |
| Deployment | nothing deployed from the current tree; GitHub `HEAD` still has the pre-Option-B layout; `Dockerfile.vercel` (FrankenPHP) for the API; frontends "static on Vercel" in docs only, no `vercel.json` | consultancy portal: build + S3 sync; ERP: `deploy/` (nginx, scripts), `DEPLOYMENT_GUIDE.md`; Laravel on servers | Three Vercel projects (API container from `web` root, public from `web/frontend`, staff from `admin`), each with its own config in its own folder |
| Tests | Laravel 86 feature tests on real Postgres; Vitest 14; Playwright 64 (both apps in `admin/e2e`) | ERP: Vitest specs next to code, Playwright, toolchain tests, PHPStan; consultancy: few frontend tests | Keep ours; move public E2E next to the public app; adopt ERP's production API URL guard idea as a build check |

## What the reference projects do better, and where they do not

**Better, worth adopting**
1. **One repo = one deployable frontend.** A change to the staff portal can never rebuild or break the public website, and each repo has one `package.json`, one `.env`, one deploy config. This is the owner's stated goal.
2. **Audience-separated authentication** (consultancy: own guard and table for admins; ERP: own guard, own session cookie, own staff login with 2FA and invitations).
3. **Routes split by audience and module** (`routes/admin/v1/<module>.php`), so the staff surface is reviewable file by file.
4. **Service layer per module** in the portal (`services/<module>/<Entity>Service.ts`, `types/<module>/`): pages do not build URLs.
5. **One list page + one form component per CMS entity**: nothing grows into a "CMS.vue".
6. **ERP's production build guard** against a dev API URL.

**Not better, do not adopt**
1. **Inertia for the public site**: it renders through Laravel, so the public site could not be deployed or run without the API process, which contradicts "must not depend on" anything but the API. (Its one advantage, server-rendered meta tags for SEO, is decision D4 and can be met by prerendering.)
2. **`composer dev` with `--kill-others` starting Vite too**: the same "stop one, lose all" behaviour the owner is complaining about.
3. **Secrets in frontend env files** (`GOOGLE_CAPTCHA_SECRET_KEY` in `consultancy/portal/.env.example`). Our rule: no frontend secrets at all.
4. **Vuetify, CASL, auto-imports, many plugins**: large dependency surface for things our app already does with Tailwind and `can()`; not in the approved stack.
5. **Very large form components** (ERP `BlogForm.vue` 550, `Smtp.vue` 370, `Storage.vue` 371 lines): the split is right, the size is not a model.
6. **Settings without a typed registry** (ERP tabs post free-form groups): our `config/settings.php` registry with encrypted, masked secrets is stronger and stays.
