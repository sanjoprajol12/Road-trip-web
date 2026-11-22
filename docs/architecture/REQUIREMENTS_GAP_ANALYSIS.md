# Requirements Gap Analysis

Date: 2026-11-18 (session 9; N8 and N9 rows updated in session 10 after migration stages A1–A6). Compares **every** approved requirement with the code on disk. The row-by-row evidence (frontend, backend, database, authorization, tests) is in `docs/audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` (kept current through session 8); this document groups all of its rows by status, adds the three requirement sets introduced in the owner's architecture brief of today (N7–N9), and lists what would break during the migration.

## Sources

- **Master specification:** the owner's original master brief was **never saved as a file**. A search of the repos, `_backups/`, `~/Documents`, `~/Downloads` and `~/Desktop` found none. The baseline is rebuilt from `docs/product/{PRODUCT_SPECIFICATION,PRODUCT_VISION,EVENT_SYSTEM,CONTRIBUTION_TRUST_POLICY,USER_ROLES_AND_PERMISSIONS,USER_JOURNEYS,FEATURE_SCOPE}.md`, session reports 01–04, and the owner's audit brief (audit Part 1, "How to read this"). **If the owner still has the master brief, adding it to `docs/product/` is the only way to prove nothing was lost.**
- Additions: audit Part 2 (N1 settings, N2 CMS, N3 roles/permissions, N4 destination mapping, N5 Explore Along My Route, N6 saved services), and today's brief (N7 dynamic homepage, N8 independent applications, N9 separate rider and staff authentication).

## Totals

Counted by script over the audit tables (225 rows) plus the 36 new rows below (N7: 17, N8: 8, N9: 11).

| Status | Part 1 + Part 2 rows | New rows (N7–N9) | Total |
|---|---|---|---|
| Complete, tested | 81 | 6 | 87 |
| Complete, under-tested | 19 | 0 | 19 |
| Partial | 58 | 8 | 66 |
| Documented, not built | 15 | 0 | 15 |
| Missing | 45 | 22 | 67 |
| Unable to verify | 6 | 0 | 6 |
| Suggestion only | 1 (P12 staff MFA) | — | 1 |
| **Total** | **225** | **36** | **261** |

## 1. Not implemented

**Documented in an agreed document, not built (15):** A4 attractions, A6 permits, A8 seasons, A9 expenses, F8 saved services/guides/experiences, F19 Offline Ride Pack (agreed for a later milestone; still mandatory), G19 account deletion and data export (built and tested in session 25), H12 staleness thresholds, L4 nearby attractions, L9 seasonal information, L10 permits, L11 cost estimates, SV1–SV3 saved services (restored by the owner).

**Missing (45 from the audit):**
- Routes: B7 alternative routes (agreed via R14).
- Trust: H4 `last_confirmed_on` on contributions.
- Settings: S5 SEO defaults, S6 branding, S7 social links, S11 security/notification/feature settings.
- CMS (whole module): CM-X1 draft/published/archived, CM-X2 scheduling, CM-X3 ordering/visibility, CM-X4 SEO/slugs/redirects, CM-X5 preview, CM-X6 search/paging, CM-X7 validation/error states, CM1 FAQs, CM2 blog, CM3 categories, CM4 tags, CM5 authors, CM6 team, CM7 popups, CM8 notices, CM9 homepage sliders, CM10 banners, CM11 static pages, CM13 menus, CM14 content blocks, CM15 contact messages, CM16 public site reads CMS content.
- Mapping: L2 admin map picker, L13 public destination map with related places.
- Explore Along My Route (whole feature): R1–R3, R5–R16.

## 2. Implemented only partially (58 from the audit)

| Area | Rows | What is missing, in one line each |
|---|---|---|
| Explore | A3, A7, A11, A12, A14 | structured practical info; `rest` service kind and map; source per fact; outdated warning after threshold; cross-links and global search |
| Routes | B5, B6, B8, B10, B11, B12, B13 | per-segment time; difficulty; services on map; automatic outdated; dangerous category and queue priority; segment identity on change; route filters |
| Contributions | C12, C13, C14, C16 | drafts (Q2); moderation transitions, correction request, history; corroboration rule; automatic outdated |
| Events | D11, D13, D15, D17, D18 | participant list for participants and removal; new-date prompt; completion photos; rider event reports; multi-route events |
| Preparation | E1–E8, E13 | machinery built, **content missing** (must come from qualified people); review expiry |
| My Rides | F3, F6, F9, F10, F12 | segment-level stops and map; budget and split; trip contacts; per-member private notes; invitation consent |
| Trust | H1, H3, H5, H6, H9, H10, H11 | transition rules; structured sources; history visible to moderators; correction path; offence counts; dangerous handling; deletion policy |
| Quality | X5, X10, X11 | mobile re-sweep; trip-invitation notifications; CAPTCHA and staff MFA |
| Settings | S2, S8, S9, S10 | general settings not shown on the public site; mail settings UI; S3-compatible storage (D1); map settings (OSM hard-coded) |
| CMS | CM-X8 | per-item history panel for editors |
| Mapping | L1, L3, L5, L6, L7, L12, L14, R4 | geography column (D3); district/locality hierarchy; nearby services/routes; gallery; sources; coordinates required to publish; route geometry |

## 3. Implemented but not tested enough (19)

A5 routes from a destination, A13 dev-seed label, B2 route map, B4 checkpoint distances, C3 service observations, C4 tips/lessons, C6 associations, C8 motorcycle details, C9 duration/expenses, C10 problems/lessons, E14 "not an emergency service" wording, F4 stops, F5 motorcycle, F7 checklists, F15 shared parts, G20 password confirmation for role changes (UI), X4 error/loading/empty states (no failure-path E2E), X12 API docs (not regenerated), L8 description.

**Unable to verify (6):** E10 official emergency numbers (need sourced data), G8 meaning of identity verification (Q10), X6 accessibility (no axe/screen reader), X7 WebKit (system libraries), X8 CI (never pushed/run), X9 performance (never measured).

## 4. New requirements from today's brief

### N7. Dynamic homepage and site chrome

| ID | Requirement | Status | Evidence / gap |
|---|---|---|---|
| H-1 | Homepage heading, subheading, description, labels editable | Complete, tested | `home_sections` (hero/layers/freshness copy), staff Homepage page; CmsTest, E2E staff cms.spec (edit → publish → public API) |
| H-2 | Hero section and hero background image editable | Complete, tested | hero background: bundled photo (allow-list) or public media-library image, credit shown; validated (CmsTest) |
| H-3 | CTA text and links editable | Complete, tested | hero primary/secondary buttons: internal path or https only (javascript:/http refused, CmsTest) |
| H-4 | Featured destinations chosen by staff | Complete, tested | featured destinations chosen and ordered by staff; unpublished items drop out (CmsTest) |
| H-5 | Featured routes | Complete, under-tested | section type `featured_routes` (API + public component); no E2E yet |
| H-6 | Featured rider experiences | Complete, under-tested | section type `featured_experiences` (visible contributions only, dated); no E2E yet |
| H-7 | Promotional banners | Complete, under-tested | section type `banner` (site content only; D6 open for third-party promotion) |
| H-8 | Announcement notices | Complete, tested | `notices` (draft/published/archived, schedule), staff Notices page, public notice bar; CmsTest, E2E home.spec |
| H-9 | Homepage sliders | Complete, under-tested | section type `slides` (1–5 library images, pause control, no auto-advance under reduced motion); no E2E yet |
| H-10 | Section ordering and visibility | Complete, tested | order, visibility, schedule and archive per section, applied on publish (CmsTest) |
| H-11 | Navigation menus | Complete, tested | `menu_items` header/footer, staff Menus page, public header and footer read them; CmsTest, E2E home.spec |
| H-12 | Footer content | Complete, tested | settings group `footer` (tagline, required emergency disclaimer, note, credits); CmsTest |
| H-13 | Social links | Complete, tested | settings group `social` (https only), footer links; CmsTest |
| H-14 | SEO title and description | Partial | settings group `seo` sets the default title and description at runtime; crawler-visible tags wait for D4 (prerender) |
| H-15 | Default social-sharing image | Partial | settings `branding.share_image_media_id` sets og:image at runtime; crawler visibility D4 |
| H-16 | Draft / published / archived, scheduling, media selection, published-only public API, permission-protected writes | Complete, tested | drafts, publish (audited), schedule, archive, signed preview tokens, permission per action (`cms.home.edit` / `.publish`); CmsTest |
| H-17 | Loading, empty, error states for managed content | Complete, tested | homepage loading and error states; menus/notices fail soft (maintenance E2E caught a real bug, fixed) |

Built in session 10 (A8): `config/home.php`, `App\Support\HomeContent`, migration `2026_11_18_100012`, staff `features/cms/{home,menus,notices}`, public `features/home/sections/*`. CM8, CM9 (as slider sections), CM13, CM16, S5 (runtime), S6 (share image only), S7 are covered by these rows; the other CMS modules (pages, FAQs, blog, popups, team, authors, blocks, contact) are roadmap Stage 8.

### N8. Independent applications

| ID | Requirement | Status | Evidence / gap |
|---|---|---|---|
| I-1 | Public site: own entry point | Complete, tested | `web/frontend/{index.html,src/main.ts}`; built and E2E-tested on its own (48/48) |
| I-2 | Staff portal: own entry point | Complete, tested | `admin/{index.html,src/main.ts}`; staff E2E 22/22 |
| I-3 | Own development command each; stopping one never stops the other | Complete, tested | `pnpm dev` per app (`web/frontend`, `admin`), no combined command; verified 2026-11-18 by stopping each dev server while the other and the API kept answering, and a frontend survives an API outage (502 only) |
| I-4 | Own production build each | Complete, tested | `pnpm build` in each app → its own `dist/` |
| I-5 | Own environment configuration each | Complete, tested | each app has its own `.env.example` (only `API_TARGET`, nothing bundled); API production template `web/.env.production.example` |
| I-6 | Own deployment configuration each | Complete, under-tested | `web/frontend/vercel.json`, `admin/vercel.json` (rewrites, SPA fallback, CSP, headers), `scripts/check-deploy-config.mjs` refuses a placeholder API origin; API image builds and serves (`docker build -f Dockerfile.vercel`). Not deployed (owner AR4, AR5) |
| I-7 | Laravel keeps business logic; API routes organised per audience | Complete, tested | `routes/api/{public,rider,staff}.php`; `route:list` before/after identical (method, URI, name, action, middleware) |
| I-8 | Keep both repos and remotes | Partial | remotes unchanged; recovery archive `_backups/2026-11-18-pre-option-a/`; the owner must commit and push (GitHub `HEAD` is still pre-Option-B) |

### N9. Separate rider and staff authentication

| ID | Requirement | Status | Evidence / gap |
|---|---|---|---|
| SA-1 | Separate login interfaces | Complete, tested | staff portal signs in only at `/api/v1/admin/auth/login`; rider site at `/api/v1/auth/login`; StaffAuthTest, E2E access.spec |
| SA-2 | Separate authorization checks | Complete, tested | gates accept only `StaffAccount`; rider policies no longer grant staff powers; RouteAuthorizationTest (staff 401 on every rider route, riders 401 on every staff route) |
| SA-3 | Separate account provisioning; staff only through authorized admin processes | Complete, tested | staff accounts only by invitation or `mrn:staff:create-admin`; roles only on `staff_account_roles`; PermissionsTest, StaffAuthTest |
| SA-4 | No public staff registration | Complete, tested | no staff registration route (RouteAuthorizationTest classifies every route) |
| SA-5 | No accidental role assignment at registration; no escalation by request manipulation | Complete, tested | register validates three fields; role changes need a permission, no escalation beyond own permissions, password confirmation (FT:Permissions) |
| SA-6 | No frontend-only authorization | Complete, tested | every `/admin` route has `can:<permission>` (RouteAuthorizationTest, mutation-checked) |
| SA-7 | A rider's credentials never grant staff access | Complete, tested | separate `staff_accounts` table and passwords; rider credentials rejected at staff login (StaffAuthTest, E2E); carried-over staff start without a password |
| SA-8 | Separate staff session management (cookie, lifetime, logout) | Complete, tested | own cookie `*_staff`, table `staff_sessions`, idle 60 min, max 12 h, logout, sign out everywhere; StaffAuthTest cookie and expiry tests |
| SA-9 | Separate staff password reset | Complete, tested | broker `staff`, links to `ADMIN_URL/reset-password`; tokens do not cross audiences (StaffAuthTest) |
| SA-10 | Appropriate CSRF and CORS | Complete, under-tested | staff CSRF from the staff session; CORS closed by default (`CORS_ALLOWED_ORIGINS` empty) because every app proxies same-origin; not verified on a real host |
| SA-11 | Tests proving a rider cannot authenticate as staff | Complete, tested | StaffAuthTest (9 tests), RouteAuthorizationTest (4, mutation-checked), E2E "a rider account is refused at staff sign in" |

Plan: `PROPOSED_ARCHITECTURE.md` §5, `MIGRATION_PLAN.md` A6.

## 5. Accidentally omitted or moved into future scope

| Item | What happened | Status now |
|---|---|---|
| Saved services (F8) | moved to "future recommendations" in session 4 without an owner decision | **restored** by the owner (N6, SV1–SV3); still not built (roadmap Stage 11) |
| Offline Ride Pack (F19) | agreed "for a later milestone" in the specification | still agreed; roadmap Stage 14; **not** a recommendation |
| Staff MFA (P12) | listed as "suggestion only" | today's brief asks for separate staff session management and secure authentication; recommend promoting it (decision AR3) |
| CAPTCHA (X11) | suggestion | stays a suggestion unless the owner promotes it |
| "Brief only" items (C12 drafts, D17 event reports, E4 fuel planning, E10 emergency numbers, E13 review expiry) | raised in the owner's audit brief, never written in earlier documents | held as requirements waiting for confirmation (Q2); **not** dropped |
| `rest` service kind (A7, B8) | in the brief, not in the database enum | open; added with Stage 7 if confirmed |

Nothing in `FEATURE_SCOPE.md` "Explicitly out of scope" (payments, live tracking, live conditions, social feed, other vehicles, AI content, unapproved paid services, a general page builder, deployment execution) conflicts with an approved requirement.

## 6. Existing features that could break during the migration

| Feature (row) | Breaks in | Why | Guard |
|---|---|---|---|
| All public pages (A, B, C, D, E, F) | A2 | files move to `web/frontend` | 14 public E2E tests × 3 browsers unchanged |
| All staff screens (G, H, N1, N3) | A3 | files move to the `admin` root | 11 staff E2E tests × 2 browsers |
| Photo credits and licence file | A2 | `images/photos/LICENSES.md` path in CLAUDE.md | path updated; owner photo rule |
| API URLs, names and middleware | A5 | route file split | `route:list --json` diff must be empty |
| Staff sign-in (G3, G4, P1–P11) | A6 | new staff accounts, new passwords | staff must set new passwords (expected), E2E |
| No self-review (G13) | A6 | IDs from two tables | `rider_user_id` link + tests |
| Staff visibility of pending/private items (C18, D5, D16) | A6 | moved from rider endpoints to staff endpoints | feature tests before and after |
| Maintenance bypass for staff (S3) | A6 | staff session no longer exists on the public site | signed preview link |
| Last active administrator (G16, P8) | A6 | re-implemented on `staff_accounts` | ported tests |
| Audit log actors (H7, P9) | A6 | actor may be a rider or a staff account | `actor_type`, audit page shows both |
| Verification never grants staff rights (P10) | A6 | becomes structural | keep the test |
| Guide expert review (E12) | A6 | experts are riders, not staff | stays on the rider guard; test |
| Settings page (S1–S4, S9, S12) | A7 | one page becomes several | E2E region names kept; `/settings` redirects |
| People page actions (G18, P4–P7) | A7 | split into riders and staff modules | E2E unchanged |
| Homepage look (approved visual direction) | A8 | content moves to the database | seeded with the current copy; screenshots at 390/768/1440 |
