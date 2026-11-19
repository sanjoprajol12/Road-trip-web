# Remaining Implementation Roadmap

Date: 2026-11-18 (session 7; replaces the session-6 version). Built from `COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md`: row IDs A1–X12 are the earlier agreed baseline (Part 1), S/CM/P/L/R/SV rows are the requirements the owner confirmed on 2026-11-18 (Part 2). Decision codes (D1–D7, Q2–Q11) are in Part 4 of the audit.

**Labels.** **Agreed** = Part 1 baseline. **New (confirmed)** = Part 2. **Brief only** = Part 1 items still waiting for the owner (Q2). **Suggestion** = mine, not scheduled.

**Rule for every stage:** policy/permission + validation + audit row for staff decisions + feature test on every new endpoint; full check list from `CLAUDE.md`; `handoff.md`; session report. No destructive migrations: new columns nullable or defaulted, backfills in separate migrations, old structures retired only after the replacement is verified.

**Session 9 (2026-11-18):** an architecture migration (A0–A8 in `docs/architecture/MIGRATION_PLAN.md`: independent apps, separate staff authentication, modular frontends, dynamic homepage) is proposed to run before Stage 4. It waits for owner decisions AR1–AR5; A8 replaces the homepage part of Stages 8 and 9.

## Stages

| Stage | Contents | Rows | Label | Depends on | Blocked by |
|---|---|---|---|---|---|
| **M. Architecture migration (Option B)** (**done, session 7**) | Public SPA from `web/resources/app` into `admin/src/apps/public`; staff app into `admin/src/apps/admin`; `src/shared` for code both apps use; one Vite/TS/ESLint/Vitest/Playwright setup with separate builds (public :5180/:4180, admin :5181/:4181); web E2E moved to `admin/e2e/public`; Laravel stops serving the SPA (fallback, CSP branch, Node build stage removed); frontend tooling removed from `web`; CI, commands and docs updated | Part 3 | New (confirmed) | — | — |
| **1. Wire what already exists** (**done, session 8**) | Destination page shows services and experiences (A7, A10); "My events" (D19); edit pending contribution + audit (C12, H7); email resend (G2); notifications for waitlist promotion and registration decisions (D8, D10); missing tests (§ Testing below); route-list authorization matrix (G15, P11) | A7 A10 D19 C12 H7 G2 D8 D10 G15 | Agreed | M | — |
| **2. Permissions foundation** (**done, session 8**) | `roles`, `permissions` (code registry + sync command), `permission_role`, `role_user`; seed system roles Administrator/Moderator equal to today's rights; backfill from `user_roles`; gates switched to permissions; last-admin rule re-proved; multiple roles; staff invitations; activation; admin password reset; admin session revocation; role matrix UI | P1–P11 | New | 1 | — |
| **3. Settings, media and storage foundation** (**done for the local disk, session 8**; S3-compatible part waits for D1) | `settings` registry (typed, cached, encrypted secrets, masked, audited); `/site` public endpoint; maintenance flag middleware; registration switch; `media_assets` + `media_usages` with license/credit; `disk` column on file rows; storage settings with connection test; local disk now, S3-compatible after D1 with `mrn:storage:migrate` copy command | S1 S3 S4 S9 S11 S12 CM12 | New | 2 (permissions) | D1 for S3-compatible part |
| **4. Trust pipeline** | Moderation transitions (C13); correction request + author addendum (H6); report categories, dangerous-first queue, per-report dismissal (B11, C17, H10); moderation history for moderators (H5); `last_confirmed_on` + "still accurate" (H4); structured `sources` + pivot, shared with editorial records (H3, A11, L12); offence counts (H9) | C13 H6 B11 C17 H10 H5 H4 H3 A11 L12 H9 | Agreed | 2 | — |
| **5. Location foundation** | Enable PostGIS (D3); geography columns + GiST indexes on destinations, service points; `districts`/`localities` with cited source; `places` model with categories and rider submissions (pending, moderated with Stage 4 rules); `routes.geometry` and segment geometry with GeoJSON/GPX import and admin editing; map settings (tile, attribution, center/zoom) with tile-host allow-list; geocoding/routing **proxy endpoints** in Laravel (provider plugged in after D2) | L1 L3 R2 R3 R4 S10 | New | 3 (settings), 4 (moderation rules for rider places) | D3; D2 for provider parts |
| **6. Freshness rules** | Staleness thresholds in queries and labels (B10, C16, A12, H12); corroboration rule (C14); guide review expiry if confirmed (E13) | B10 C16 A12 H12 C14 E13 | Agreed | 4 | Q4 (Q2 for E13) |
| **7. Destination and route depth** | Admin map picker (L2); destination map + nearby attractions/services/routes (L4–L6, L13); gallery (L7); seasons, permits, cost estimates with sources (L9–L11, A3–A9); publish requires coordinates (L14); per-segment time, difficulty (B5, B6); route filters (B13); services on map (B8); distance check (B4); segment identity on change (B12) | L2 L4–L7 L9–L11 L13 L14 A3–A9 B4–B6 B8 B12 B13 | Agreed + New | 3 (media), 4 (sources), 5 (geometry) | Q6 for difficulty; content from owner |
| **8. CMS content** | Shared CMS behaviour (CM-X1–X8: status workflow, schedule/expiry, ordering, SEO/slugs/redirects, preview tokens, search/paging, audit); modules in this order: pages + content blocks + menus (CM11, CM14, CM13), FAQs (CM1), notices (CM8), team (CM6), blog with categories/tags/authors (CM2–CM5), home slides (CM9), banners (CM10), popups (CM7), contact messages (CM15); public app reads all of it (CM16) | CM-X1–X8 CM1–CM16 | New | 2, 3 | D4 for crawler-visible SEO; D6 banners; D7 retention |
| **9. Site settings screens** | General (S2), branding with contrast and SVG checks (S6), social (S7), SEO defaults (S5), mail with test connection/email and failure log (S8), remaining groups (S11) | S2 S5–S8 S11 | New | 3, 8 (media for logos/OG) | D4 for S5 |
| **10. Explore Along My Route** | Journey selection (R1); route display with alternatives (R4, R14); places along route, direct vs detour, filters, straight-line distance (R5–R7); detour distance/time from provider only (R8, R16); place detail with photos, experiences, source, freshness (R9, R12, R13); save places (R10); add to trip (R11); any journey (R15) | R1 R4–R16 B7 | New | 5, 6, 7 | D2 for times/alternatives (curated-only mode works without it) |
| **11. My Rides and events completion** | `saved_items` + saved services and other items (SV1–SV3, F8, R10 if not done in 10); trip stops linked to places/services (R11, SV3); trip invitations with consent (F12); private notes (F10); trip contacts (F9); segment-level stops (F3); budget (F6); event participant list and removal (D11); postponement dates (D13); completion photos (D15); multi-route events (D18); event reports (D17) | SV1–SV3 F3 F6 F8–F12 D11 D13 D15 D17 D18 | Agreed + New | 1, 3, 5 | Q7, Q8; Q2 for D17 |
| **12. Safety content workflow** | Expert "return with comments" (E12); checklist templates (E2); sourced official emergency numbers (E10); fuel planning along a route (E4) | E2 E4 E10 E12 | Agreed / Brief only | 7, 10 | experts, sourced content, Q2 |
| **13. Verification track and privacy** | axe in E2E, screen reader, WebKit, Lighthouse, CI (X6–X9); account deletion/export (G19, H11); privacy policy page (via CM11) | X6–X9 G19 H11 | Agreed | 8 | owner steps, Q9 |
| **14. Offline Ride Pack** | PWA + IndexedDB: trip, saved items, route geometry, places; last-sync time and staleness warnings | F19 | Agreed (later milestone) | 6, 10, 11 | owner scheduling |

Why this order: permissions (2) come before any new admin module, because every CMS and settings endpoint needs a permission; storage and media (3) come before branding, CMS images and destination galleries; sources (4) come before destination facts that must cite them; PostGIS and geometry (5) come before nearby places and route discovery; existing gaps (1, 4, 6) are not skipped to build new modules.

## Gaps by category (cross-reference)

### Critical product functionality
Stage 1 items; destination depth (Stage 7); Explore Along My Route (Stage 10); saved services (Stage 11); CMS public integration (CM16, Stage 8); Offline Ride Pack (Stage 14).

### Missing business workflows
Moderation transitions, correction requests, report categories (Stage 4); freshness and corroboration (Stage 6); trip invitations with consent, private notes, trip contacts (Stage 11); event participant management, postponement dates, completion photos (Stage 11); CMS publish/schedule/preview (Stage 8); staff invitations and admin password reset (Stage 2); contact inbox (Stage 8).

### Security and authorization
Permission model with code registry and generated route test (Stage 2, P1/P11); encrypted, masked, audited secrets for mail, storage, map and integration keys (Stage 3, S1); provider keys only server-side through proxies (Stage 5); SVG sanitizing and upload checks for brand assets (Stage 9); preview tokens signed and short-lived (Stage 8); trip consent (Stage 11); contact-form abuse limits (Stage 8); CAPTCHA and staff MFA remain suggestions (future recs) unless the owner promotes them.

### Data integrity and database
`sources` + pivot (Stage 4); `last_confirmed_on` on contributions (Stage 4); PostGIS geography + GiST, districts/localities, places, geometry (Stage 5); `disk` column on files and storage migration command (Stage 3); `roles`/`permissions` backfill with `user_roles` retired only after verification (Stage 2); slug redirects (Stage 8); `saved_items` uniqueness (Stage 11); account deletion policy (Stage 13).

### Frontend and user experience
Public: destination map and nearby, journey explorer, CMS pages, menus, notice bar, popups (accessible), home slides (reduced motion), branding via CSS variables. Admin: permission matrix, settings pages, media library, map picker, geometry editor, CMS editors with preview.

### Testing and quality
Feature tests: destination search/province, event updates, postponement, completion, `did_not_attend`, invitation accept/decline, guide sources, detail keys (Stage 1); every new endpoint gets allowed + refused tests; route/permission matrix generated from `route:list`. E2E: Explore browse + not-found, approval joining, invitations, staff cancellation, photo upload, full-trip contribution, verified badge, guide review (Stage 1); admin change appears on public site (CM16); journey explorer with seeded dev journeys (R15); popup keyboard behaviour (CM7). axe in E2E (Stage 13).

### Operational readiness
Production Postgres **with PostGIS** (D3); storage provider (D1); routing/geocoding providers (D2); mail provider (S8 settings make switching easier, but credentials still come from the owner); domain; backups; monitoring; deployment of the two static SPAs with `/api` and `/sanctum` proxied to Laravel (Option B; Vercel rewrites are one way, an owner deployment decision); map tile provider before heavy use (S10).

## Suggestions (mine, not requirements, not scheduled)
- Staff MFA (P12) and CAPTCHA on sign-up/contact: strongly recommended before launch.
- Ownership transfer for group trips; conflict warning for concurrent trip edits.
- Global search across destinations, routes, places and experiences.
- Via-destinations for routes passing through a place.
