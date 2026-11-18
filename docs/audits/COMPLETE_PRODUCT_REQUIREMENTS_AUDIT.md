# Complete Product Requirements Audit

Date: 2026-11-18 (session 5). Inspection only: no product code, schema or data was changed. Auditor: Claude Code, against the code on disk in `web/` and `admin/`.

## How to read this

**Baseline.** The original owner briefs (master brief, "Mandatory Application Separation" addendum, session-4 brief) were never saved as files. Session 1 recorded that no "Project Creation & Final Audit Master Standard" file exists. The baseline is therefore rebuilt from what was written down at the time, plus the owner's audit brief of today:

| Code | Source |
|---|---|
| S | `docs/product/PRODUCT_SPECIFICATION.md` (session 1 plan, updated session 4) |
| V | `docs/product/PRODUCT_VISION.md` |
| E | `docs/product/EVENT_SYSTEM.md` |
| T | `docs/product/CONTRIBUTION_TRUST_POLICY.md` |
| R | `docs/product/USER_ROLES_AND_PERMISSIONS.md`, `docs/architecture/AUTHORIZATION_MODEL.md` |
| J | `docs/product/USER_JOURNEYS.md` |
| F | `docs/product/FEATURE_SCOPE.md` |
| SR | session reports `docs/sessions/2026-11-18-session-0{1..4}-*.html` |
| B | owner's audit brief, 2026-11-18 (this session). **B only** = the requirement appears in no earlier document, so it was either agreed in conversation and never written down, or it is new. Owner to confirm (see the last section) |

**Status values** (each row uses exactly one):

| Status | Meaning |
|---|---|
| **Complete, tested** | built end to end (API + UI where a UI is expected), authorized, and covered by an automated test that ran this session |
| **Complete, under-tested** | built end to end, but no automated test exercises this specific behaviour (or only the backend half is tested) |
| **Partial** | some of the requirement is built; the gap column says what is not |
| **Documented, not built** | written in an agreed document, nothing in code |
| **Missing** | not built and not in any earlier document (B only) |
| **Unable to verify** | cannot be decided from code and tests alone (needs a person, a device, or an owner decision) |

**Path abbreviations.** `W:` = `web/resources/app/src/features/`, `A:` = `admin/src/features/`, `C:` = `web/app/Http/Controllers/Api/V1/`, `CA:` = `web/app/Http/Controllers/Api/V1/Admin/`, `M1`–`M8` = `web/database/migrations/2026_11_18_10000{1..8}_*.php`, `MU` = `web/database/migrations/0001_01_01_000000_create_users_table.php`. Tests: `FT:` = `web/tests/Feature/*Test.php` (test name in quotes), `E2E:` = `web/e2e/*.spec.ts`, `AE2E:` = `admin/e2e/*.spec.ts`.

**Test evidence this session.** `php artisan test`: 55 passed (381 assertions). Web SPA: type-check, lint, 6/6 unit tests, build pass. Admin SPA: type-check, lint, 12/12 unit tests, build pass. E2E results are in the session report `docs/sessions/2026-11-18-session-05-requirements-audit.html`.

## Summary

| Area | Rows | Complete, tested | Complete, under-tested | Partial | Documented, not built | Missing | Unable to verify |
|---|---|---|---|---|---|---|---|
| A Explore Nepal | 14 | 0 | 4 | 6 | 4 | 0 | 0 |
| B Routes and road information | 13 | 2 | 3 | 6 | 0 | 2 | 0 |
| C Rider experiences and contributions | 19 | 6 | 7 | 5 | 0 | 1 | 0 |
| D Events and organized rides | 21 | 9 | 5 | 6 | 1 | 0 | 0 |
| E Preparation and safety | 14 | 2 | 3 | 8 | 0 | 0 | 1 |
| F My Rides and group trips | 19 | 7 | 5 | 5 | 2 | 0 | 0 |
| G Accounts, roles, verification | 20 | 15 | 2 | 1 | 1 | 0 | 1 |
| H Trust, moderation, data integrity | 12 | 3 | 0 | 7 | 1 | 1 | 0 |
| X Technical quality (cross-cutting) | 12 | 3 | 1 | 3 | 1 | 0 | 4 |
| **Total** | **144** | **47** | **30** | **47** | **10** | **4** | **6** |

Headline: the platform's **trust and access core is real and tested** (accounts, roles, claim-specific verification, organizer-only hosting, event joining and capacity, private trips, moderation gate, audit log). The **content model is thinner than the specification**: destinations have no attractions, permits, seasons, costs or sources; routes have no difficulty, per-segment times or alternatives; trust labels (corroborated, outdated) are manual only; and several agreed My Rides items (saved services/guides, trip contacts, private notes, invitation acceptance) are missing. The Offline Ride Pack (agreed for a later milestone) is not started.

**One scope regression found.** `PRODUCT_SPECIFICATION.md` §6 lists "saved services" as part of My Rides. In session 4 it was moved to `FEATURE_SCOPE.md` "Future recommendations (not built; for owner approval)" #5 without a recorded owner decision. It is treated here as an agreed requirement that is not built (row F8).

---

## A. Explore Nepal

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| A1 | Destination directory and browsing [S1, J1] | Complete, under-tested | `W:explore/pages/ExplorePage.vue` | `C:DestinationController@index`, `GET /api/v1/destinations` | `destinations` (M2): status enum, slug check, soft delete | public read of `published` only (`Destination::published`) | FT:Content "guests see published destinations only" | no browser test of Explore (only session-1/4 overflow sweeps) | E2E: guest browses list, opens a destination, unknown slug shows not-found |
| A2 | Search and province filter [S1] | Complete, under-tested | `ExplorePage.vue` | `@index` `?q=` (name, district, LIKE escaped), `?province=` | province enum (7 provinces) | public | none for `q`/`province` | search covers name/district only; no test | feature test for search + province; decide whether summary/overview are searchable |
| A3 | Destination detail with practical travel information [S1, B] | Partial | `W:explore/pages/DestinationPage.vue` | `@show`, `GET /destinations/{slug}` | name, province, district, summary, overview (free text), elevation, lat/lng, photo + alt + credit (check constraint) | public | FT:Content (published only) | "practical information" is one free-text `overview`; no structured access, road, connectivity or best-time fields | define practical-info fields with the owner, add columns, admin form, detail sections |
| A4 | Attractions and points of interest [S1 "Designed", B] | Documented, not built | none | none | none | none | none | nothing exists | `destination_attractions` (name, kind, note, source, last_confirmed_on), admin CRUD, detail section, tests |
| A5 | Route connections from a destination [S1, B] | Complete, under-tested | `DestinationPage.vue` routes list | `@show` returns published routes starting or ending here | `routes.start_destination_id`/`end_destination_id` FKs (nullOnDelete) | public | none asserts the `routes` key | only start/end; a route passing through a destination is not linked | test; optional via-destinations (only if owner wants it) |
| A6 | Permits and entry requirements [S1 "Designed", B] | Documented, not built | none | none | none | none | none | nothing exists (Mustang, Rara and others need permits) | permit records with authority, fee note, source, last_confirmed_on; never invented: needs sourced content |
| A7 | Facilities and services at a destination: fuel, food, rest, accommodation, repair, medical, ATM, connectivity [S1, B] | Partial | not shown on `DestinationPage.vue` | `CA:ServicePointController` (staff CRUD); `@show` does **not** return a destination's service points | `service_points` (M2): kinds fuel, mechanic, medical, lodging, food, atm, connectivity; check: destination or segment | staff write (`can:staff`), public read of published | FT:Content "content management is staff only" | data can be stored against a destination but riders never see it; no `rest` kind | return published points in `@show`, render on detail page, add `rest` kind if wanted, test |
| A8 | Seasonal information [S1 "Designed", B] | Documented, not built | none | none | none | none | none | nothing exists | season fields (best months, closures, monsoon/snow notes) with source + last_confirmed_on |
| A9 | Estimated expenses [S1 "approximate costs", B] | Documented, not built | none | none (rider trip costs exist in `contributions.details.expenses_npr`, not aggregated) | none | none | none | no editorial cost estimates; no aggregation of rider-reported costs | decide: staff-sourced ranges vs. aggregate of rider reports (dated); then build |
| A10 | Rider experiences associated with a destination [S1, B] | Partial | `DestinationPage.vue` does not list contributions | `C:ContributionController@index?destination={slug}` works | `contributions.destination_id` FK (nullOnDelete) | visible statuses only | FT:Contribution "public search and profile" (search, not destination filter) | API filter exists, page does not use it | list recent visible contributions on the destination page with dates; test |
| A11 | Source attribution and last-confirmed date for each fact [S1 "each fact carries source + last-confirmed date", T] | Partial | `DestinationPage.vue` shows "Last confirmed" / "Never confirmed" | `DestinationResource` exposes `last_confirmed_on` | `last_confirmed_on` on destinations, routes, service points; **no source column** on any editorial table | staff set it | none | one date per record, not per fact; no source citation anywhere in editorial content | `source` (citation text/URL) on destinations, routes, segments, service points (and A4/A6/A8 records); show it |
| A12 | Freshness and outdated-content indicators [T "warn after 365 days", B] | Partial | age in words on `DestinationPage.vue`, "Never confirmed" on `ExplorePage.vue` | none | `last_confirmed_on` | n/a | `web/resources/app/src/lib/__tests__/format.spec.ts` (ageInWords) | no warning after the 365-day threshold the policy sets | warning state after threshold (owner to confirm thresholds, see H-section) |
| A13 | Dev-seed rows labelled in the UI [T8, CLAUDE.md] | Complete, under-tested | `is_dev_seed` used in 3 feature files (explore, routes) | resources expose `is_dev_seed` | `destinations.is_dev_seed`, `routes.is_dev_seed`; seeder flags 4 destinations | n/a | none | no test asserts the label | E2E assertion on a seeded destination |
| A14 | Search, filtering and navigation between related content [B] | Partial | destination → routes; route → destinations; experience → destination/route links | filters: destinations `q`/`province`, routes `destination`, contributions `kind`/`destination`/`route`/`segment`/`q` | FKs | public | FT:Contribution "public search and profile" | no global search; destination page does not link experiences or services; Routes page has no filter UI | add the missing links (A7, A10, B13); global search only if owner wants it |

## B. Routes and Road Information

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| B1 | Route directory and detail [S2] | Complete, tested | `W:routes/pages/RoutesPage.vue`, `RoutePage.vue` | `C:RouteController@index/@show`, `GET /routes`, `/routes/{slug}` | `routes` (M2): unique slug, positive distance/hours check, soft delete | public read of published; staff CRUD `CA:RouteController` | E2E:features "a rider files a road report on a specific stretch…" (opens a route page) | none | — |
| B2 | Interactive map [S2] | Complete, under-tested | `W:routes/components/RouteMap.vue` (Leaflet, OSM tiles, lazy-loaded) | segment coordinates in `RouteResource` | `route_segments.start_lat/lng`, `end_lat/lng` (nullable) | public | none specific to the map | segments without coordinates draw nothing; OSM tile usage policy limits heavy use (F future rec #8) | map test (renders markers/lines); tile provider decision before launch |
| B3 | Complete routes as ordered segments with checkpoints [S2] | Complete, tested | `RoutePage.vue` stretch list; `A:content/pages/RouteEditorPage.vue` | `CA:RouteController@replaceSegments`, `PUT /admin/routes/{id}/segments` | `route_segments` unique (route_id, position); checkpoints = segment endpoints (documented choice, DATABASE_DESIGN) | `can:staff` | FT:Content segment tests (11 references, incl. refusing to delete reported segments) | checkpoints have no own records (cannot attach services to a checkpoint itself) | none unless owner wants checkpoint records |
| B4 | Checkpoint distances [S2] | Complete, under-tested | `RoutePage.vue` | `RouteResource` | `routes.distance_km`, `route_segments.distance_km` (> 0) | staff | none | route total is entered separately, never checked against the sum of segments | derive or validate the total from segments |
| B5 | Estimated riding times [S2, B] | Partial | `RoutePage.vue` "Riding time" | `RouteResource.estimated_hours` | `routes.estimated_hours` only | staff | none | no per-segment time; total only | `route_segments.estimated_hours`, editor field, display |
| B6 | Road surface and difficulty observations [S2, B] | Partial | segment surface on `RoutePage.vue`; report surface in contribute form | `details.surface` in contributions (paved, mixed, gravel, dirt, blocked) | `route_segments.surface` enum (paved, mixed, gravel, dirt, unknown) | staff (segment), riders (report) | FT:Contribution "submission rules" | no difficulty field (segment or report); segment surface is editorial, not dated | difficulty scale (owner to define), dated on reports; show latest dated rider observation next to editorial surface |
| B7 | Alternative routes [B only] | Missing | none | none | none | none | none | nothing exists | owner to confirm scope; then route ↔ route "alternative to" link or shared start/end listing |
| B8 | Fuel, food, accommodation, rest, repair and medical stops along a route [S2, B] | Partial | services listed per stretch on `RoutePage.vue` | `RouteController@show` loads published `segments.servicePoints`; `CA:ServicePointController` | `service_points.route_segment_id` FK | staff write | FT:Content "content management is staff only" | no `rest` kind; services not drawn on the map | add `rest` if wanted; map markers for services with coordinates |
| B9 | Road reports per segment with observation dates [S2, J5] | Complete, tested | `W:experiences/pages/ContributePage.vue` (from a stretch), `RoutePage.vue` per-stretch list | `C:ContributionController@store` (`observed_on` required, ≤ today, `route_segment_id` required for road reports) | check `contributions_road_report_segment`; `observed_on` date; index (segment, observed_on) | `verified` + `not-suspended`, `throttle:contributions` | FT:Contribution "submission rules"; E2E:features road report | none | — |
| B10 | Historical vs current labels; never present an old report as live [V, T4, B] | Partial | `RoutePage.vue` "Not live conditions", "Ridden {date} ({age})", "marked outdated" | none | `observed_on`; status `outdated` | moderators set `outdated` | format unit test; E2E road report | nothing becomes outdated automatically: a 3-year-old report shows only its age, not the "outdated" state the policy defines (90 days or newer report) | automatic staleness rule (owner to confirm thresholds), applied in queries and labels, with tests |
| B11 | Dangerous road reports and moderation [B, J10 "flag dangerous"] | Partial | `A:moderation/pages/ModerationPage.vue`; report button on `ExperiencePage.vue` | `CA:ContributionController@decide`, `C:ContributionController@report` | `contribution_reports` (one per reporter) | staff moderate, not own | FT:Contribution "three reports flag…"; AE2E "a moderator publishes a pending contribution…" | no "dangerous" category on reports or reviews; dangerous items are not prioritized in the queue; flagging needs 3 reports | report reason category (wrong, dangerous, spam…); a single "dangerous" report jumps the queue; staff "dangerous" decision path |
| B12 | Route changes and segment integrity [B] | Partial | `RouteEditorPage.vue` | `@replaceSegments` (refuses to drop segments with reports); route/segment edits audited (`Audit::record` ×4) | M7: `contributions.route_segment_id` restrictOnDelete; unique (route, position) | staff | FT:Content segment tests | reordering or renaming a reported segment silently changes what old reports appear to describe | keep segment identity stable (rename/geometry change creates a note or new segment); show "route changed since this report" |
| B13 | Route discovery and filtering [B] | Partial | `RoutesPage.vue` has no filter controls | `@index?destination=` only | — | public | none | cannot filter by region, distance, surface, difficulty or season | filters once B5/B6 data exists |

## C. Rider Experiences and Contributions

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| C1 | Trip reports [S3] | Complete, tested | `ContributePage.vue` (kind trip, stepwise), `ExperiencePage.vue`, `ExperiencesPage.vue` | `C:ContributionController` store/show/index | `contributions.kind` enum incl. `trip` | write: verified, not suspended | FT:Contribution "submission rules", "pending is hidden until moderated…" | — | — |
| C2 | Road reports [S3] | Complete, tested | see B9 | see B9 | see B9 | see B9 | see B9 | — | — |
| C3 | Accommodation and service observations (accommodation, fuel, mechanic) [S3] | Complete, under-tested | `ContributePage.vue` kind picker | same endpoint | kinds `accommodation`, `fuel`, `mechanic` | same | submission rules (generic) | not linked to a service point record | optional `service_point_id` link; test per kind |
| C4 | Travel tips and lessons (recommendation, observation, unexpected, lessons, wish_known) [S3] | Complete, under-tested | `ContributePage.vue` | `details.lessons`, `details.wish_known` whitelisted | `details` jsonb | same | submission rules | — | test that unknown detail keys are refused (rule exists: `array:` whitelist) |
| C5 | Photos and media [S3] | Complete, tested | upload step in `ContributePage.vue`; gallery in `ExperiencePage.vue` | `@upload` (≤ 6, alt required), `StoreContributionPhoto` (decode, size, re-encode WebP, strips EXIF), `@media` (authorized stream) | `contribution_media` FK cascade; private disk | update policy (author, pending only); `throttle:uploads` | FT:Contribution "photo uploads are checked reencoded and access controlled" | browser upload not E2E | E2E upload |
| C6 | Destination and route associations [S3] | Complete, under-tested | `ContributePage.vue` pickers | validation: published destination/route; segment decides route | FKs nullOnDelete (segment restrict) | same | submission rules | — | — |
| C7 | Travel (observation) date and submission date [T3] | Complete, tested | shown as "Ridden {date}" and submitted date | `observed_on` required, not future, after 2000; `submitted_at` = created_at | `observed_on` date column | same | submission rules | — | — |
| C8 | Motorcycle model and engine capacity when provided [S3] | Complete, under-tested | `ContributePage.vue` bike step; `ExperiencePage.vue` facts | `details.motorcycle`, `details.engine_cc` (50–2500) | jsonb | same | submission rules (generic) | — | — |
| C9 | Trip duration and expenses [S3] | Complete, under-tested | same | `details.duration_days`, `details.expenses_npr` | jsonb | same | submission rules | expenses are one total (no category split) | only if owner wants a breakdown |
| C10 | Problems encountered and lessons learned [S3] | Complete, under-tested | same | `details.problems`, `details.lessons` | jsonb | same | submission rules | — | — |
| C11 | Progressive contribution forms (road report short, full trip in steps) [S3] | Complete, tested | `ContributePage.vue` | same endpoint | — | same | E2E road report (short path) | full-trip steps not E2E | E2E for the full-trip path |
| C12 | Drafting and editing contributions [B; S3 says all enter `pending_review`] | Partial | no edit screen; no draft save | `PATCH /contributions/{id}` exists (author, pending only) but **no SPA calls it**; edits are not audited | no `draft` status | `ContributionPolicy::update` | none for update | riders cannot edit a pending report from the UI; no drafts (B only) | edit screen for pending items (+ audit row); drafts only if owner confirms |
| C13 | Review and moderation lifecycle [T, J10] | Partial | `ModerationPage.vue` (status filter, decision, note, reports shown) | `CA:ContributionController@decide` → `ModerateContribution` (reason ≥ 10 for reject/flag, source required for officially sourced, audit row) | `status` enum (7), `moderation_note` (single, overwritten) | staff, not own | FT:Contribution "authors cannot moderate their own"; AE2E moderation publish | **no transition rules** (any status → any of 6, e.g. rejected → officially sourced); "request correction" (J10) not built; per-item history not shown; only the latest note is kept on the row | transition table like `VerificationStatus::next`; correction-request path; history panel from audit rows (H5) |
| C14 | Community corroboration [T "matched by independent reports on the same segment within a window"] | Partial | label shown | staff can set `community_corroborated` | status value | staff | none | manual only; no matching logic, no count of independent reports shown | rule (N independent authors, same segment, window) computed or suggested to moderators; owner to confirm N and window |
| C15 | Officially sourced information [T] | Complete, tested | "Official source" link on `ExperiencePage.vue` | source URL required for this status | `source_url` (500) | staff | FT:Contribution (officially_sourced case) | source is a bare URL (no publisher, date) | structured source (see H3) |
| C16 | Outdated contributions [T] | Partial | label + greyed state | staff can set `outdated` | status value | staff | none | manual only (see B10) | automatic rule (owner thresholds) |
| C17 | Reporting misleading or dangerous content [T] | Complete, tested | report form on `ExperiencePage.vue` | `@report` (reason 10–1000, one per rider, 3 open → flagged + audit) | `contribution_reports` unique (contribution, reporter) | verified, not suspended, not own, visible only | FT:Contribution "three reports flag a published contribution" | no reason category; reports are resolved in bulk by any non-flag decision | category (with B11); per-report dismissal |
| C18 | Contribution ownership and permissions [R] | Complete, tested | `AccountPage.vue` "My contributions" | `ContributionPolicy` view/update/report/moderate; `GET /users/me/contributions` | `author_id` FK | as listed | FT:Contribution "pending is hidden…", "authors cannot moderate…" | — | — |
| C19 | Verified trip contributor label on contributions [R] | Missing (UI unverified) | label code not found in experience pages by this audit | `PublicUserResource.verified` lists approved claims | `user_verifications` | — | none | data is sent; this audit did not find the label rendered on contribution cards | confirm rendering; add if absent; test |

## D. Events and Organized Rides

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| D1 | Only approved organizers host public events [E, R] | Complete, tested | `W:events/pages/EventFormPage.vue` | `C:EventController@store` | `events.organizer_id` | `EventPolicy::create` (approved `event_organizer`, not suspended, read live) | FT:Event "only verified organizers host and revocation stops them" | — | — |
| D2 | Organizer application and verification [J8] | Complete, tested | `W:verification` section on `AccountPage.vue`; `A:verification/pages/*` | `C:VerificationController@store`, `CA:VerificationController@decide` | `user_verifications` kind `event_organizer`; one open claim (partial unique index) | admin decides (`needsAdmin`) | FT:Verification (8 tests); E2E:verification; AE2E "an administrator approves, suspends…" | — | — |
| D3 | Organizer suspension, rejection and revocation [E] | Complete, tested | admin claim detail | `DecideVerification` state machine, new reason ≥ 10 | status enum | admin | FT:Verification "admin decisions follow the state machine…"; AE2E | — | — |
| D4 | Suspended organizer's existing events become read-only; staff decide [E] | Complete, tested | manage page refuses | `EventPolicy::manage` requires `create` | — | — | FT:Event "…revocation stops them" | no staff prompt listing affected events | optional: show the organizer's open events on the claim detail |
| D5 | Public and private events [E] | Complete, tested | form visibility field | `@index` lists public only; `EventPolicy::view` | `visibility` enum | private: organizer, involved riders, staff | FT:Event "drafts and private events are hidden from outsiders" | — | — |
| D6 | Instant joining and request-to-join [E] | Complete, tested | `EventPage.vue` join/request | `EventRegistrations::join` | `join_method` enum | verified, not suspended | FT:Event "instant join…", "approval join and organizer decisions" | approval path not E2E | E2E |
| D7 | Event capacity, safe under concurrency [E] | Complete, tested | capacity field | `EventCapacity` with `lockForUpdate` | `capacity` check ≥ 1 | — | FT:Event "instant join respects capacity…", "full event without waitlist refuses" | no concurrent-request test | parallel-join test |
| D8 | Waitlists with automatic promotion [E] | Complete, tested | `EventPage.vue` | `EventCapacity::settle` (oldest first) | `waitlist_enabled`, status `waitlisted` | — | FT:Event; E2E:features "…the waitlist moves up" | promoted riders are not notified | notification on promotion |
| D9 | Invitations (invite, accept, decline) [E] | Complete, under-tested | `EventManagePage.vue` invite by username; accept = join, decline = leave on `EventPage.vue` | `@invite` (+ `EventInvitation` notification, audit), `join` from `invited`, `leave` → `declined` | registration status `invited` | organizer (`manage`) | FT:Event (1 invitation reference) | accept/decline paths not asserted separately; not E2E | feature + E2E tests |
| D10 | Join approval and rejection [E] | Complete, tested | `EventManagePage.vue` | `EventRegistrations::decide` | — | organizer | FT:Event "approval join and organizer decisions" | decided riders are not notified | notifications |
| D11 | Participant management [E "participant list visible to participants and organizer"] | Partial | `EventManagePage.vue` | `GET /events/{id}/registrations` | — | `manage` only | FT:Event | participants cannot see the list (spec says they can); organizer cannot remove an approved rider | owner to confirm visibility; add participant-list view for involved riders; "remove participant" with reason |
| D12 | Event updates and announcements, notifying participants [E] | Complete, under-tested | `EventManagePage.vue` post update; updates on `EventPage.vue` | `@postUpdate`, `announce()` → `EventChanged` (database notification) | `event_updates` | organizer | none ("updates" not asserted) | in-app only (email later is documented) | feature test |
| D13 | Postponement [E] | Partial | status action on manage page | `@changeStatus` (message ≥ 10 required) | status `postponed`; `next()` | organizer | none | postponing does not ask for new dates; returning to published keeps old dates unless edited | require or prompt new dates; test |
| D14 | Cancellation (organizer and staff) [E] | Complete, tested | manage page; `A:events/pages/EventsPage.vue` | `@changeStatus`, `CA:EventController@cancel` (reason, audit, notifications) | status `cancelled` | organizer / staff | FT:Event "cancellation notifies…", "staff can cancel any event but riders cannot" | staff cancellation not E2E | — |
| D15 | Attendance and completion [E] | Partial | manage page attendance + complete | `decide` → attended/did_not_attend after start; `completed` with summary | statuses; `events.summary` | organizer | FT:Event (attended only; `did_not_attend`, `completed` not asserted) | "completed events may get a summary and photos": photos not built | tests; completion photos (reuse upload rules) |
| D16 | Event visibility and access control [E] | Complete, tested | — | `EventPolicy::view`; drafts hidden | — | — | FT:Event "drafts and private events are hidden…" | — | — |
| D17 | Event reporting and moderation [B; staff oversight E] | Partial | admin events list + cancel | `CA:EventController@index/@cancel` | — | staff | FT:Event staff cancel | riders cannot report an event; no staff note/flag other than cancel | event report endpoint + queue entry (mirrors C17) |
| D18 | Optional linked routes, several per event (`event_routes`) [E] | Partial | single route picker | `route_id` validation | `events.route_id` (single FK) | — | none | one route only | `event_routes` pivot if multi-route rides are needed |
| D19 | Rider's own events list (organizing / participating) [E, R] | Documented, not built (UI) | **no SPA screen calls it** | `GET /users/me/events` exists | — | signed in | none | riders cannot find events they joined except by browsing | "My events" section (Account or My Rides) |
| D20 | Event status transitions [E] | Complete, tested | manage page offers `next()` only | `EventStatus::next`, `full` derived in `settle` | status enum | organizer | FT:Event (published, full, cancelled) | postponed/completed transitions not asserted | transition tests |
| D21 | Participant status transitions (table in EVENT_SYSTEM) [E] | Complete, tested | — | `EventRegistrations` join/leave/invite/decide match the table | registration enum, unique (event, user) | — | FT:Event (most rows) | `did_not_attend`, invited → waitlisted when full not asserted | add the two cases |

## E. Preparation and Safety

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| E1 | Pre-ride preparation guidance [S5] | Partial | `W:preparation/pages/PreparationPage.vue`, `GuidePage.vue` | `C:GuideController`, `CA:GuideController` | `guides` (M6) category `preparation` | staff write | FT:Content "safety guides need a matching expert…" | machinery only: **no guide content exists** (must come from qualified people, owner rule) | owner/experts supply content |
| E2 | Motorcycle and equipment checklists [S5] | Partial | trip checklist sections motorcycle / personal / trip on `W:rides/pages/TripPage.vue` | `TripPartController` checklist | `trip_checklist_items.section` enum | trip editors | FT:Trip (1 checklist reference) | riders start from an empty list; no reviewed standard checklist | sourced template checklist (content from qualified people) copied into a trip |
| E3 | Weather preparation [B; guide category `weather`] | Partial | guides list | same | category `weather` | staff | none | category only, no content; no live weather by design (F) | content |
| E4 | Fuel planning [B] | Partial | fuel service points on routes; fuel expense category | — | `service_points.kind = fuel` | — | none | no "next fuel in N km" view along a route | derive gaps between fuel points from segment distances (after B8) |
| E5 | Accommodation planning [B] | Partial | trip stops with "overnight" | `TripPartController` stops | `trip_stops.overnight` | trip editors | FT:Trip stops | not linked to lodging service points | optional link stop → service point |
| E6 | Emergency information [S5] | Partial | category `emergency`; disclaimers in footer, preparation page, contacts | — | category | staff; needs medical expert review | none | no content | content (sourced) |
| E7 | Breakdown guidance [S5] | Partial | category `mechanical` | — | — | mechanical expert review before publish | FT:Content expert test | no content | content |
| E8 | Accident and injury guidance [S5] | Partial | category `medical` | — | — | medical expert review | FT:Content expert test | no content | content |
| E9 | Personal emergency contacts (private) [S5] | Complete, tested | `W:profiles/components/EmergencyContacts.vue` | `C:EmergencyContactController` (≤ 10, phone format) | `emergency_contacts` FK cascade | owner only; staff excluded | FT:Trip "emergency contacts are private" | — | — |
| E10 | Relevant official emergency numbers (police, ambulance, tourist police) [B] | Unable to verify | none | none | none | — | — | not built; requires sourced, dated official numbers (cannot be invented) | owner supplies sources; then a sourced guide or table with last_confirmed_on |
| E11 | Safety guide sources [S5, T7] | Complete, under-tested | "Sources" section on `GuidePage.vue` | `sources` required | `guides.sources` text (not null) | staff | none asserts "sources required" | free text, no per-source date | test; structured sources optional |
| E12 | Qualified expert review for safety-critical content [S5, T7] | Complete, tested | "I have reviewed this guide" on `GuidePage.vue` | `@expertReview`, `CA:GuideController@publish` refuses safety guides without matching review; text edits clear it | `expert_reviewed_by`, `expert_reviewed_at` | `GuidePolicy::expertReview` (matching scope, not author) | FT:Content "safety guides need a matching expert before publishing" | the expert can only approve: no "needs changes" with comments | reviewer comment / reject path |
| E13 | Last-reviewed dates and outdated safety information [B] | Partial | "Reviewed by a verified expert {date}" | — | `expert_reviewed_at` | — | none | no review expiry; an old review never shows as outdated | review interval (owner) + outdated label |
| E14 | Not presented as an emergency dispatch service [V, F] | Complete, under-tested | footer, `PreparationPage.vue`, `EmergencyContacts.vue` wording | — | — | — | none | — | E2E text check |

## F. My Rides and Group Trip Planning

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| F1 | Private trip creation [S6, J6] | Complete, tested | `W:rides/pages/RidesPage.vue` | `C:TripController@store` (owner member row) | `trips` (M5) | verified, not suspended | FT:Trip "trips are private to their members" | — | — |
| F2 | Trip dates and itinerary [S6] | Complete, tested | `TripPage.vue` | `@update`, stops with day/position | date-order check | editors | FT:Trip; E2E:features group trip | — | — |
| F3 | Routes and checkpoints in a trip [S6] | Partial | stop can reference a route | stops `route_id` (published) | `trip_stops.route_id` | editors | FT:Trip stops | cannot pick segments/checkpoints; no trip map (FEATURE_SCOPE future #5) | segment-level stops + map (owner to confirm depth) |
| F4 | Stops and accommodation [S6] | Complete, under-tested | `TripPage.vue` | stops CRUD | `trip_stops` overnight, note | editors | FT:Trip | — | — |
| F5 | Motorcycle details [S6] | Complete, under-tested | `TripPage.vue` | `motorcycle` (120) | `trips.motorcycle` | editors | none | one text field for the whole group trip | per-member bike only if owner wants it |
| F6 | Expenses and budgets [S6] | Partial | expenses list + total | expenses CRUD, `paid_by` must be a member | `trip_expenses` amount ≥ 0 | editors | FT:Trip (expenses) | no budget target, no per-person split | budget field + split view |
| F7 | Preparation checklists [S6] | Complete, under-tested | `TripPage.vue` sections | checklist CRUD | `trip_checklist_items` | editors | FT:Trip (1 reference) | see E2 | — |
| F8 | Saved services, guides and experiences [S6 "saved services"; B adds guides/experiences] | Documented, not built | none | none | none | — | none | **moved to "future recommendations" in session 4 without a recorded owner decision** | owner confirms; then trip ↔ saved items pivot |
| F9 | Emergency contacts in a trip [S6 "contacts"] | Partial | account-level only | account contacts | `emergency_contacts.user_id` | owner only | FT:Trip contacts | not attached to or shared within a trip | decide sharing model (members' contacts visible to trip members?) — privacy decision for owner |
| F10 | Private notes [S6 "notes", B "private notes"] | Partial | trip notes on `TripPage.vue` | `trips.notes` | text | visible to **all members** | none | no note only one member can see | per-member private notes (`trip_member_notes`) |
| F11 | Group trip workspaces [S6, J7] | Complete, tested | `TripPage.vue` members | members API | `trip_members` PK (trip, user) | `TripPolicy` | FT:Trip "group roles owner editor viewer"; E2E group trip | — | — |
| F12 | Member invitations (owner invites, members accept) [J7] | Partial | add by username | `@addMember` attaches immediately | no invited state | owner | FT:Trip | no acceptance, no notification: anyone can be added to a trip without consent | `invited` state + accept/decline + notification |
| F13 | Owner, editor and viewer permissions [S6] | Complete, tested | role controls | `TripPolicy` view/edit/manage | role enum | — | FT:Trip; E2E | no ownership transfer | optional |
| F14 | Shared itinerary editing [S6] | Complete, tested | `TripPage.vue` | stops CRUD | — | editors | FT:Trip | no conflict handling (last write wins) | acceptable for now; note |
| F15 | Shared stops, budgets, checklists and notes [S6] | Complete, under-tested | `TripPage.vue` | parts API | — | editors | FT:Trip | — | — |
| F16 | Member removal and access revocation [J7] | Complete, tested | remove/leave | `@removeMember` (owner cannot leave) | detach | owner or self | FT:Trip | — | — |
| F17 | Private data isolation (incl. staff) [R] | Complete, tested | — | lookups through the trip | — | staff have no access | FT:Trip "items of another trip cannot be reached…" | — | — |
| F18 | Independent of public events and organizer verification [F] | Complete, tested | — | `TripPolicy` has no verification check | — | — | FT:Trip (riders without claims) | — | — |
| F19 | Offline Ride Pack (PWA + IndexedDB, last-sync time, staleness warning) [S6 "Later", F "in scope … later milestone"] | Documented, not built | none | none | none | — | — | M8 not started | separate milestone after approval |

## G. Accounts, Roles and Verification

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| G1 | Guest access to published content [R] | Complete, tested | all public pages | public routes in `routes/api.php` | — | none needed | FT:StaffAccess "endpoint matrix for guest rider moderator admin" | — | — |
| G2 | Registered rider: confirmed email, not suspended, for writes [R] | Complete, tested | auth pages, router guard | `verified`, `not-suspended` middleware | `email_verified_at`, `suspended_until` (MU) | — | FT:Auth (17); E2E:auth (5) | email resend endpoint has no UI (`/auth/email/resend` not called) | "resend confirmation" button |
| G3 | Moderator permissions [R] | Complete, tested | admin SPA | `can:staff` | `user_roles` | — | FT:StaffAccess; AE2E access | — | — |
| G4 | Administrator permissions [R] | Complete, tested | admin People, Audit | `can:admin` | `user_roles` | — | FT:StaffAccess | — | — |
| G5 | Verified trip contributor status [R] | Complete, tested | account verification | apply/decide | kind `trip_contributor` | moderators decide | FT:Verification; AE2E "moderators decide trip contributor claims…" | label on contributions: see C19 | — |
| G6 | Field-specific expert verification (mechanical, medical, road safety) [R] | Complete, tested | apply with scope | scope check (experts only) | `scope` + check | admins decide | FT:Verification | — | — |
| G7 | Verified event organizer status [R] | Complete, tested | see D2 | | | | | — | — |
| G8 | Identity verification [SR1] | Unable to verify | kind exists in apply form | kind `identity` | enum | moderators | FT:Verification | approving it grants nothing and shows nothing; purpose not written down | owner: keep (and say what it means) or remove |
| G9 | Separate verification process per status [R] | Complete, tested | — | one row per kind (+scope) | partial unique index `uv_one_open` | — | FT:Verification "rider applies once per claim…" | — | — |
| G10 | Application, approval, rejection, suspension, revocation [R] | Complete, tested | both SPAs | `VerificationStatus::next` | status enum | — | FT:Verification; AE2E | — | — |
| G11 | Written reviewer reasons [R] | Complete, tested | admin decision dialog; rider sees reason | new reason ≥ 10 for negative decisions | `review_note` | — | FT:Verification "…need new reasons" | only the latest note on the row (history in audit) | — |
| G12 | Authorized reviewers only [R] | Complete, tested | — | `VerificationPolicy::decide` | — | — | FT:Verification "moderators decide trip contributor claims but not organizer or expert" | — | — |
| G13 | No self-review (claims, contributions, guides) [R] | Complete, tested | — | policies | — | — | FT:Verification "nobody reviews their own claim"; FT:Contribution "authors cannot moderate…"; GuidePolicy (not author) | — | — |
| G14 | Reviewer identity hidden from riders [R] | Complete, tested | — | `VerificationResource` | — | — | FT:Verification (`reviewed_by` asserted) | — | — |
| G15 | Backend authorization on every protected operation [R, CLAUDE.md] | Complete, under-tested | — | middleware + policies on every route group | — | — | FT:StaffAccess endpoint matrix (representative, not exhaustive) | the matrix does not enumerate all 96 routes | generate the matrix from `route:list` so a new unguarded route fails a test |
| G16 | Last administrator protection [R] | Complete, tested | — | `ChangeStaffRole::revoke` with row lock | — | — | FT:StaffAccess "the last administrator cannot be removed" | — | — |
| G17 | Private trip and private event restrictions [R] | Complete, tested | — | `TripPolicy`, `EventPolicy` | — | — | FT:Trip, FT:Event | — | — |
| G18 | Account suspension with reason, sessions ended [R] | Complete, tested | admin People | `SuspendUser` | `suspended_until`, `suspension_reason` | staff; only admins suspend staff; not self | FT:StaffAccess "suspension blocks writes…" | — | — |
| G19 | Account deletion and data export [K in audit checklist; F future #4] | Documented, not built | none | none (cascades exist) | FK cascades | — | — | not built | owner approval, then build (privacy) |
| G20 | Staff roles changed only with password confirmation [R] | Complete, under-tested | `admin/src/lib/usePasswordConfirm.ts` | `password.confirm` middleware | — | admin | FT:StaffAccess "role changes are admin only and need a recent password"; AE2E roles | — | — |

## H. Trust, Moderation and Data Integrity

| ID | Requirement | Status | Frontend evidence | Backend evidence | Database evidence | Authorization | Tests | Gap | Required work |
|---|---|---|---|---|---|---|---|---|---|
| H1 | Full status lifecycle: pending, published, corroborated, officially sourced, outdated, rejected, flagged [T] | Partial | labels in `W:experiences/labels.ts` | statuses exist; visibility rules correct | enum (M3) | — | FT:Contribution | no transition rules (C13); corroborated and outdated manual only (C14, C16) | transition table + automatic rules |
| H2 | Contributor attribution, observation and submission dates [T] | Complete, tested | experience pages | resources | `author_id`, `observed_on`, `created_at` | — | FT:Contribution | — | — |
| H3 | Source records [T "source (rider \| official + citation)"] | Partial | official source link | `source_url` | one URL column on contributions; none on editorial tables | — | officially sourced test | no citation text, publisher, or access date; editorial content has no source at all (A11) | `sources` structure shared by contributions and editorial records |
| H4 | Last-confirmed date on contributions [T freshness fields] | Missing | none | none | **no `last_confirmed_on` on `contributions`** | — | — | a later rider or moderator cannot mark a report "still true on {date}" | column + "confirm still accurate" action (rider or staff), audited |
| H5 | Moderation history per item (`moderation_actions`) [T] | Partial | moderation page shows current state only | every decision writes `audit_logs` | `audit_logs` append-only (M8) | audit log is **admin-only**, so moderators cannot see an item's history | FT:Content "the app database role cannot rewrite the audit log" | history exists but is invisible where decisions are made | per-item history endpoint for staff; panel in moderation page |
| H6 | Corrections without silently rewriting the rider's text [T5] | Partial | — | staff cannot edit body (no endpoint); author edits only while pending | — | — | FT:Contribution "…text is never edited" | "request correction from the author" not built; after publication an author cannot add a correction | correction request status + author addendum (original kept) |
| H7 | Audit history [R] | Complete, tested | `A:audit/pages/AuditLogPage.vue` | `Audit::record` in actions/controllers | `audit_logs` (no FK on actor) | admin read | FT:StaffAccess "audit log is readable by admins only"; FT:Content append-only | some writes are not audited (rider contribution edit, trip changes: private, acceptable) | audit contribution edits |
| H8 | No unauthorized modifications [R] | Complete, tested | — | policies; parent-scoped lookups | `mrn_app` DML only | — | FT:Trip IDOR, FT:StaffAccess matrix, FT:Content DB role | — | — |
| H9 | Repeat offender handling [T "suspend contribution rights"] | Partial | admin People suspension | `SuspendUser` (whole account) | `suspended_until` | staff | FT:StaffAccess | no count of rejected/flagged items per rider shown to staff; suspension is all-or-nothing | show offence counts on People; owner to decide if contribution-only suspension is still wanted |
| H10 | Dangerous content handling [B, J10] | Partial | see B11 | see B11 | — | — | — | see B11 | see B11 |
| H11 | Data deletion and relationship integrity [B] | Partial | — | soft delete on destinations/routes; segment restrict | FKs; `contributions.author_id` **cascadeOnDelete** | — | FT:Content segment test | deleting an account deletes all of that rider's published knowledge; no decision recorded | owner decision: keep anonymized contributions on account deletion vs. delete |
| H12 | Staleness defaults "to confirm with owner" [T] | Documented, not built | — | — | — | — | — | thresholds never confirmed, never implemented | owner confirms numbers; implement with B10/C16/A12 |

## X. Technical Quality (cross-cutting)

| ID | Requirement | Status | Evidence | Gap | Required work |
|---|---|---|---|---|---|
| X1 | Request validation on every endpoint [CLAUDE.md] | Complete, tested | `$request->validate` in every write controller; FT validation tests in Auth, Verification, Contribution | — | — |
| X2 | Migrations and constraints [CLAUDE.md] | Complete, tested | 11 migrations, checks, FKs, partial unique index; `php artisan test` builds from zero | — | — |
| X3 | Least-privilege DB role [CLAUDE.md] | Complete, tested | FT:Content "the app database role cannot change the schema" | — | — |
| X4 | Error, loading and empty states [J] | Complete, under-tested | `web/resources/app/src/lib/useAsync.ts`, `StatusLine` in admin; session-4 sweeps | not re-checked this session; no automated failure-state tests | E2E with API failures |
| X5 | Mobile responsiveness | Partial | session-4 sweep: 13 public × 3 widths, 10 admin × 2 widths, 0 overflow; E2E on Pixel 7 | not re-run this session; new screens since then: none | re-run sweep after next UI stage |
| X6 | Accessibility (WCAG AA) | Unable to verify | labels, skip links, focus, contrast tokens (session 1–4) | no axe, no screen reader | axe in E2E; manual screen-reader pass |
| X7 | WebKit / Safari | Unable to verify | — | system libraries missing (needs sudo) | owner installs deps |
| X8 | CI on GitHub | Unable to verify | workflows exist in both repos | never run | owner pushes |
| X9 | Performance (Lighthouse/CWV) | Unable to verify | code-splitting, lazy Leaflet | never measured | Lighthouse on production build |
| X10 | Notifications | Partial | in-app (database) for verification, events; 1 mail notification (email link) | trip additions, waitlist promotion, registration decisions not notified; email digests documented as later | add missing notifications |
| X11 | Rate limiting and abuse | Partial | limits per SECURITY_REQUIREMENTS #6, tested | CAPTCHA, MFA for staff not built (future recs) | owner approval |
| X12 | API documentation | Complete, under-tested | Scribe at `/docs` | not regenerated this session | regenerate after the next API change |

---

## Architecture decision check (section 6 of the brief)

**Implemented today (verified on disk):**
- `web/`: Laravel 12 API (`app/`, `routes/api.php`, `database/`) **and** the public/rider Vue SPA in `web/resources/app`, built to `web/public/app` and served by Laravel.
- `admin/`: staff-only Vue SPA (`admin/src/features/{verification,moderation,content,events,guides,users,audit,dashboard,authentication}`), proxying `/api` to the same Laravel app.

**Decision history (from written records only):**
1. Session 1 ("Mandatory Application Separation" addendum): `web` = public site, `admin` = staff app, as two separate apps.
2. Session 4 brief: per the session-4 report, "the first brief put all Vue code in `admin`" (Laravel only in `web`). The work started that way, and both SPAs were briefly merged into `admin`.
3. Mid-session 4, a peer Claude session ("road-trip-87") reported an owner decision to the contrary. Because it conflicted with the brief, the owner was asked directly and chose **public site in `web`, `admin` staff-only, no Inertia**. The merge was reversed from a snapshot.
4. Recorded as owner decision 4 in `docs/product/FEATURE_SCOPE.md`, in `docs/architecture/SYSTEM_ARCHITECTURE.md`, in `handoff.md` §1/§4, in `CLAUDE.md` and in the session-4 report.

**Assessment.** The current structure matches the latest recorded owner answer. But that answer exists only in documents written by Claude; the question and the owner's own words were not saved. Today's brief describes the other structure as "earlier approved". **Flagged for owner confirmation.** Nothing was restructured.

---

## Requirements that need an owner decision before work starts

| # | Question | Rows |
|---|---|---|
| 1 | Confirm the architecture: public SPA in `web` (current) or in `admin` (first session-4 brief)? | Architecture |
| 2 | Were alternative routes, contribution drafts, event reporting by riders, official emergency numbers, fuel planning and review expiry agreed earlier (they appear only in today's brief)? | B7, C12, D17, E10, E4, E13 |
| 3 | Is "saved services / guides / experiences" in scope now (spec) or a future recommendation (session 4)? | F8 |
| 4 | Staleness thresholds: road 90 days, fuel/ATM/connectivity 180, lodging/mechanic 365, destinations warn after 365? Corroboration: how many independent riders, within what window? | B10, C14, C16, A12, H12 |
| 5 | Destination practical info: which structured fields (access, permits, seasons, costs, attractions) and who supplies sourced content? | A3, A4, A6, A8, A9 |
| 6 | Route difficulty scale: what levels and who sets them (staff, riders, both)? | B6, B13 |
| 7 | Participant list: visible to other participants (spec) or organizer only (built)? | D11 |
| 8 | Trip emergency contacts: may group members see each other's contacts? | F9 |
| 9 | Account deletion: keep a rider's published reports anonymized, or delete them? | H11, G19 |
| 10 | Identity verification: what does it mean for riders, or should it be removed? | G8 |
| 11 | Repeat offenders: is contribution-only suspension still wanted, or is account suspension enough? | H9 |
