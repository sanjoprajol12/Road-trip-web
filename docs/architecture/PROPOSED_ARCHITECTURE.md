# Proposed Architecture

Date: 2026-11-18 (session 9). **Proposal only: nothing here is implemented.** It replaces Option B (decided 2026-11-18, session 7) **only if the owner approves** decision AR1 below; until then Option B stays the documented architecture.

## 1. Options considered

| Option | Description | Meets "independent dev, build, env, deploy config per app"? | Cost |
|---|---|---|---|
| **B (current)** fixed in place | keep both apps in `admin`, remove the combined `pnpm dev`, add per-app `.env.public`/`.env.admin` | dev and build: yes. **Own deployment config: no.** Vercel reads `vercel.json` from a project's Root Directory; both apps share the `admin` root, `package.json` and lockfile, and every push to `admin` rebuilds both | smallest |
| **B′** two self-contained folders inside `admin` (`admin/public-site/`, `admin/staff-portal/`, each with its own `package.json`) | yes, but two apps in a repo named "admin", and the shared code would have to become a package or be copied | medium |
| **A (recommended)** public app in `Road-trip-web`, staff app in `Road-trip-admin`, Laravel stays at the `web` root | **yes, all six points**, and matches the owner's consultancy and ERP projects (`*-web` + `*-portal`) | medium: one move per app, 147 lines of shared code copied |
| A′ Laravel moved to `web/backend/` | same as A | high: every path in CI, Docker, docs, E2E, CLAUDE.md symlinks changes for no functional gain |
| Inertia public site (consultancy/ERP style) | public pages rendered by Laravel | **no**: the public site would only run inside the API process | rewrite |

**Recommendation: Option A.** Laravel does not move; the public app moves to `web/frontend/`; the staff app moves to the root of `admin/`.

## 2. Repository responsibilities

```
Road-trip-web/  (repo web, remote unchanged)
├── app/ bootstrap/ config/ database/ routes/ tests/   Laravel 12 API, unchanged location
│   └── routes/api/{public,rider,staff}.php             split of routes/api.php (same URLs)
├── frontend/                                          PUBLIC website + rider platform (Vue 3 SPA)
│   ├── package.json  pnpm-lock.yaml  vite.config.ts  tsconfig*.json  eslint.config.ts
│   ├── .env.example  vercel.json  playwright.config.ts
│   ├── index.html
│   ├── public/                                       static images (photos + LICENSES.md)
│   ├── e2e/                                          public E2E (moved from admin/e2e/public)
│   └── src/
│       ├── main.ts  App.vue
│       ├── app/{router,layouts}/
│       ├── components/{navigation,ui}/
│       ├── features/<feature>/{pages,components,services,types,composables}/
│       └── lib/{api,format.ts,safeNext.ts,useAsync.ts}  (own copy of the 147 lines)
├── docs/  supabase/  scripts/
├── Dockerfile.vercel  Caddyfile  .dockerignore        API container; .dockerignore adds frontend/
└── package.json                                       Supabase CLI only (unchanged)

Road-trip-admin/  (repo admin, remote unchanged)
├── package.json  pnpm-lock.yaml  vite.config.ts  tsconfig*.json  eslint.config.ts
├── .env.example  vercel.json  playwright.config.ts
├── index.html  public/
├── e2e/                                              staff E2E
└── src/
    ├── main.ts  App.vue
    ├── app/{router,layouts,navigation}/
    ├── components/ui/                                DataTable, FormField, FormDialog, ConfirmDialog, StatusLine, PageHeader
    ├── features/<module>/…                           see §8
    └── lib/{api,format.ts,safeNext.ts,usePasswordConfirm.ts}
```

Why this is better than today: each repo builds, tests and deploys exactly one frontend (plus the API in `web`); a broken staff dependency or push cannot touch the public site; each app has one `package.json`, one `.env`, one `vercel.json`; the layout is the one the owner already uses in two other projects. Cost: the API client, `format.ts` and `safeNext.ts` (147 lines with tests) exist twice; owner rule "no shared code between repos" already forbids a package.

## 3. Development, build and deployment commands

| App | Folder | Start | Port | Build | Output | Preview |
|---|---|---|---|---|---|---|
| Database + Mailpit | `web` | `pnpm db:start` | 54322, 54324 | — | — | — |
| API | `web` | `composer dev` | 8010 | container `Dockerfile.vercel` | image | — |
| Public website | `web/frontend` | `pnpm dev` | 5180 | `pnpm build` | `web/frontend/dist` | `pnpm preview` :4180 |
| Staff portal | `admin` | `pnpm dev` | 5181 | `pnpm build` | `admin/dist` | `pnpm preview` :4181 |

Each frontend needs only the API. Stopping either frontend never stops the other; stopping the API stops data for both (intended). `composer dev` keeps `--kill-others` only for its own three Laravel processes.

**Environment per app** (no secrets in either frontend, owner rule):
- `web/.env`: everything server-side (DB, mail, keys, `APP_URL` = public origin, `ADMIN_URL` = staff origin, `SANCTUM_STATEFUL_DOMAINS`).
- `web/frontend/.env` and `admin/.env`: only `API_TARGET` (dev proxy target, not `VITE_`, never bundled). The stale `VITE_SUPABASE_*` entries and the database password in `admin/.env` are removed.
- Production: the API origin lives in each app's `vercel.json` rewrite (not secret).

**Deployment** (three Vercel projects, owner creates them; free tier fit is owner decision AR4):

| Vercel project | Repo | Root Directory | Preset | Config |
|---|---|---|---|---|
| API | Road-trip-web | `/` | Container (`Dockerfile.vercel`) | env vars in Vercel; Ignored Build Step skips builds when only `frontend/` or `docs/` changed |
| Public | Road-trip-web | `frontend` | Vite | `frontend/vercel.json`: rewrite `/api/(.*)` and `/sanctum/(.*)` → API origin, SPA fallback to `/index.html`, security headers (CSP) |
| Staff | Road-trip-admin | `/` | Vite | `admin/vercel.json`: same rewrites, SPA fallback, stricter CSP, `X-Robots-Tag: noindex` |

Rewrites keep cookies first-party on each app's host, so CORS stays closed and `SESSION_DOMAIN` stays host-only. A build guard (idea from ERP's `productionApiUrlGuard`) fails a production build whose `vercel.json` still points at a local API.

Production prerequisites that are not code (from `CURRENT_ARCHITECTURE_AUDIT.md` §3): a Postgres host (AR5), `APP_KEY` and env vars, durable storage (D1), mail (S8), no queue worker (mail sent synchronously or via cron).

## 4. API structure

Same URLs as today (no breaking change for clients), split by audience:

| File | Prefix | Middleware | Contents |
|---|---|---|---|
| `routes/api/public.php` | `/api/v1` | none / throttles | health, site, files, published content, CMS reads (`/home`, `/menus/{location}`, `/pages/{slug}`, `/faqs`, `/posts`…) |
| `routes/api/rider.php` | `/api/v1/auth`, `/api/v1/users/me`, rider writes | `auth:rider` (Sanctum, `web` guard only), `verified`, `not-suspended` | rider auth, profile, trips, events, contributions, saved items |
| `routes/api/staff.php` | `/api/v1/admin` | `staff.session`, `auth:staff`, `staff.active`, `can:<permission>` | staff auth (`/admin/auth/*`) and every staff module, one group per module |

`RouteAuthorizationTest` keeps classifying every route and gains two rules: every `/admin` route (except `/admin/auth/login|forgot|reset|invitations/accept`) uses `auth:staff`; no rider or public route accepts the staff guard.

## 5. Authentication separation

**Recommended model (decision AR2): separate staff accounts.** Riders stay in `users`; staff get their own table, guard, session cookie, login, password reset and provisioning. This is the consultancy project's isolation (own `admin` guard and `AdminUser` table) plus the ERP project's per-area session cookie.

| Concern | Rider | Staff |
|---|---|---|
| Table / model | `users` / `User` (unchanged) | `staff_accounts` / `StaffAccount` (email unique, display name, password nullable until set, `deactivated_at`, `last_login_at`, optional `rider_user_id`) |
| Guard | `web` (session), Sanctum `guard => ['web']` | `staff` (session, provider `staff_accounts`) |
| Session cookie | `mrn_session`, max 7 days (`SESSION_MAX_MINUTES`) | `mrn_staff_session`, idle timeout and max age from settings (`security.staff_idle_minutes`, default 60; `security.staff_max_hours`, default 12) |
| Login | `POST /api/v1/auth/login` (Fortify, `users` only) | `POST /api/v1/admin/auth/login` (own controller, `staff_accounts` only, same generic error for wrong password and non-staff email, `throttle:login`) |
| Registration | `POST /api/v1/auth/register` (unchanged: 3 fields, creates a `users` row only) | **none**. First administrator: `php artisan mrn:staff:create-admin` (interactive, server only). Everyone else: invitation |
| Invitation | — | `POST /admin/staff-invitations` (`users.invite`, password confirmation, cannot grant beyond own permissions) → email link to `ADMIN_URL/accept-invitation` → sets the **staff** password. Never attaches to a rider account |
| Password reset | broker `users`, link to `APP_URL/reset-password` | broker `staff` (own token table), link to `ADMIN_URL/reset-password`; admins can trigger it, never see a password |
| Roles / permissions | none (rider verification claims stay rider attributes and grant nothing) | `staff_account_roles` → `roles` → `permissions` (existing registry and gates, moved to `StaffAccount`) |
| Logout | invalidates rider session | invalidates staff session; "sign out everywhere" deletes the staff account's sessions |
| MFA | not required | recommended: Fortify's built-in two-factor feature (TOTP; its libraries are already installed with Fortify) — decision AR3 |
| Rider-route powers | — | none. Things staff did through rider endpoints move to staff endpoints: viewing private events and pending contributions, maintenance bypass (replaced by a signed preview link) |

Why a separate table rather than ERP's "same table, separate cookie": with one table, a rider promoted to staff signs in to the staff portal **with the rider password**, which is exactly what the owner wants to rule out ("a rider's credentials must not automatically grant staff access"; "separate account provisioning"). A separate table makes that structurally impossible.

**Self-review rule stays enforced.** Today `ContributionPolicy` refuses `author_id === $user->id`. With two tables, the link is `staff_accounts.rider_user_id`, set only when the staff member proves the rider account (rider password during invitation acceptance or from the staff profile). Moderation, verification and guide-review policies refuse items authored by the linked rider. Unlinked staff who also ride is a policy risk the owner accepts or forbids (AR2b).

**CSRF and CORS.** Sanctum CSRF cookie per origin (unchanged). CORS `allowed_origins` empty in production (every call is same-origin through the rewrite); local dev also proxies, so it can be empty everywhere except tooling.

**Tests that must exist before the switch is accepted** (feature tests unless marked):
1. rider credentials at `/admin/auth/login` → 422 with the same message as a wrong password; no staff session cookie set;
2. staff credentials at `/auth/login` → 422 (unless the same email also has a rider account, which then signs into the rider account only);
3. a rider session cookie on any `/admin/*` route → 401 (route-wide, generated from the route list);
4. a staff session on any rider write route → 401;
5. `register` with extra fields (`role`, `roles`, `is_admin`, `permissions`, `staff`) creates only a `users` row and no staff account;
6. a staff reset token cannot reset a rider password and the reverse;
7. invitation tokens: expired, reused, replaced, granted-beyond-own-permissions → refused (existing tests ported);
8. deactivated staff refused at login and mid-session; last active administrator protected (ported);
9. staff session expires after the idle timeout;
10. E2E (staff app): a rider signs in on the staff login → refused, stays on the sign-in page.

## 6. Database responsibilities

One Postgres database, owned by Laravel migrations (`--database=pgsql_owner`); `mrn_app` keeps DML only. New tables by stage (see `MIGRATION_PLAN.md`): `staff_accounts`, `staff_account_roles`, `staff_password_reset_tokens`, staff session rows (same `sessions` table, `user_id` + new `guard` column, or a second table `staff_sessions`), `audit_logs.actor_type`; CMS tables in §7. Nothing is dropped until the owner confirms the new model works; `user_roles` stays readable until then.

## 7. CMS and dynamic homepage

**Principle:** code owns structure, behaviour and styling (section components, layout, animation, accessibility); the database owns copy, images, links, order, visibility and publication. Content is **structured fields validated per type**, never stored HTML.

**Homepage model**

| Table | Purpose |
|---|---|
| `home_sections` | `id`, `type` (enum from a registry `config/home.php`: `hero`, `intro`, `layers`, `featured_destinations`, `featured_routes`, `featured_experiences`, `banner_slot`, `notice_slot`, `freshness`, `text`), `position`, `is_visible`, `draft_data` jsonb, `published_data` jsonb, `status` (draft / published / archived), `publish_at`, `expires_at`, `updated_by`, timestamps |
| `home_section_items` | `section_id`, `item_type` (destination / route / contribution / media), `item_id`, `position` (featured lists; public response drops items that are no longer published) |
| `home_slides` (CM9) | hero slider slides: media, heading, text, CTA, position, schedule |
| `banners` (CM10), `notices` (CM8), `menus` + `menu_items` (CM13), `pages` (CM11), `faqs`, `posts`… | one module each, per the audit Part 2 |
| `settings` groups `branding`, `social`, `seo`, `footer` | logos, colours (contrast-checked), social links, default title/description, default share image, footer text |

The registry defines each section type's fields (e.g. `hero`: `eyebrow` ≤ 60, `lines` 1–3 × ≤ 40, `lead` ≤ 300, `primary_cta` {label ≤ 30, target internal route or https URL}, `secondary_cta`, `background` media id with alt + credit + licence, `photo_credit`), exactly like `config/settings.php`. Laravel validates against it; unknown fields are refused.

**Flow:** staff edit a section (draft) → "Preview" opens the public homepage with a signed 30-minute token → "Publish homepage" copies every draft into `published_data` in one transaction, audited → `GET /api/v1/home` returns published, visible, in-schedule sections in order with resolved media URLs and featured items, cached until the next publish → `HomePage.vue` renders a component per section type (`HeroSection.vue`, `LayersSection.vue`, …). Unknown or broken sections are skipped; an API failure shows the existing `StatusPanel` error state.

**Day one looks identical:** the migration seeds the current homepage copy (owner-approved text already in `HomePage.vue`, `SiteFooter.vue`, header) as published rows, so nothing changes visually until staff edit it. This is the owner's existing copy, not invented content.

**Hard-coded today → managed after**: hero eyebrow, three headline lines, lead paragraph, both CTAs, hero photo and credit; layers heading + four texts; destinations heading (and which destinations are featured: today the first ones the API returns); freshness heading, text, legend; footer tagline, disclaimer, links; header menu; social links (none yet); SEO title/description and share image (none yet; crawler visibility = D4).

**Admin side:** `features/cms/home/` (section list with order and visibility, one editor component per section type, preview, publish), and one module per CMS entity (§8). Media is picked from the media library (existing `media_assets`, licence and credit already enforced).

## 8. Feature-based organisation (both apps)

Rules:
- `features/<module>/pages/` = one file per route; `components/` = forms, tables, dialogs used by those pages; `services/<module>.ts` = every API call of the module (pages never call `api.*`); `types.ts` = that module's API types; `composables/` only when state is shared by two or more components.
- **Validation** stays server-side (Laravel Form Requests, 422 → `fieldErrors`). Client-side checks are limited to `required`/`maxlength`/`type` attributes. No schema library (no new dependency, no duplicated rules).
- A module gets a detail page when an item has more than one editable concern; a create/edit **dialog** when the form has ≤ 6 fields; a **page** otherwise.
- Each feature exports `routes.ts` (path, component, `meta.permission`, nav label); the router and the navigation read these, so permission keys live in one place per module.
- Shared admin primitives in `components/ui/`: `DataTable` (sorting, paging, empty/loading/error), `FormField`, `FormDialog`, `ConfirmDialog`, `StatusLine`, `PageHeader`, `SettingsTabs`.

Staff portal modules (target):

```
src/features/
├── dashboard/                    pages/OverviewPage.vue
├── auth/                         pages/{SignIn,ForgotPassword,ResetPassword,AcceptInvitation}Page.vue, services/auth.ts
├── riders/                       pages/{RiderList,RiderDetail}Page.vue
│                                 components/{RiderContributions,RiderVerifications,RiderReports,AccountStatusPanel,ModerationHistory}.vue
├── staff/                        pages/{StaffList,StaffDetail,Invitations}Page.vue, components/{InviteDialog,StaffRolesPanel,SessionsPanel}.vue
├── roles/                        pages/{RoleList,RoleEdit}Page.vue, components/PermissionMatrix.vue
├── verification/                 pages/{Queue,ClaimDetail}Page.vue
├── moderation/                   pages/{Queue,ContributionDetail}Page.vue, components/{DecisionDialog,ReportList,HistoryPanel}.vue
├── destinations/                 pages/{DestinationList,DestinationEdit}Page.vue
│                                 components/{DestinationForm,LocationPicker,AttractionsPanel,ServicesPanel,PhotosPanel,SeasonsPanel,PermitsPanel,ExpensesPanel,SourcesPanel}.vue
├── routes/                       pages/{RouteList,RouteEdit}Page.vue, components/{SegmentEditor,RouteGeometryEditor}.vue
├── services/                     pages/ServicePointList.vue, components/ServicePointDialog.vue
├── events/                       pages/{EventList,EventDetail}Page.vue
│                                 components/{ParticipantsTable,JoinRequests,Waitlist,OrganizerPanel,EventHistory,CancelDialog}.vue
├── guides/                       pages/{GuideList,GuideEdit}Page.vue
├── cms/
│   ├── home/                     pages/HomeContentPage.vue, components/sections/{Hero,Layers,Featured,Freshness,Text}Editor.vue
│   ├── sliders/ banners/ popups/ notices/ menus/ pages/ faqs/ posts/ post-categories/ post-tags/ authors/ team/ contact-messages/
│   │                             each: pages/<X>ListPage.vue (+ <X>EditPage.vue or components/<X>Dialog.vue), services, types
├── media/                        pages/MediaLibraryPage.vue, components/{UploadForm,MediaDetailsDialog,MediaPicker}.vue
├── settings/                     pages/{General,Branding,Social,Seo,Mail,Storage,Map,Security,Maintenance,Registration}SettingsPage.vue
│                                 components/SettingsLayout.vue (tabs), composables/useSettingsGroup.ts, services/settings.ts
└── audit/                        pages/AuditLogPage.vue
```

Today's splits this implies: `PeoplePage.vue` → `riders/` + `staff/` (+ `roles/` already separate); `SettingsPage.vue` → one page per group; `MediaPage.vue` → library page + upload form + details dialog; `content/` → `destinations/`, `routes/`, `services/`. Public app: `TripPage.vue` → stops, members, expenses, checklist and notes components; `HomePage.vue` → one component per section type.

## 9. Shared code strategy

- Between repos: **nothing shared** (owner rule). The API client, `format.ts`, `safeNext.ts` are copied once (147 lines incl. tests) and evolve per app.
- Inside each app: `components/ui` and `lib/` are the only cross-feature imports; features do not import each other's internals (ESLint `no-restricted-imports` per feature, as the current cross-app rule does).
- Types: per feature, generated by hand from the Laravel resources; the Scribe OpenAPI file can be used to check them in CI later.

## 10. Owner decisions this proposal needs

| # | Decision | Recommendation |
|---|---|---|
| AR1 | Replace Option B by Option A (public app in `web/frontend`, staff app at the `admin` root) | approve |
| AR2 | Separate staff accounts table, guard, cookie, login, reset and provisioning; existing staff get new staff accounts and set a new staff password by email | approve |
| AR2b | May a person hold both a rider and a staff account? Must they link them (needed for the self-review rule)? | yes, link required to moderate anything |
| AR3 | Staff two-factor sign-in (TOTP, Fortify built-in, no new package) mandatory for administrators | approve for administrators |
| AR4 | Hosting plan: Vercel Hobby terms (personal, non-commercial) vs. another plan; three projects | owner |
| AR5 | Production Postgres provider (with PostGIS if D3 is approved) | owner (remote Supabase stays out of scope) |
| D4 | Crawler-visible SEO for the public SPA (prerender) | needed for the SEO part of the homepage work |
