# Risks and Open Questions

Date: 2026-11-27 (session 1). The owner chose a **fresh start** for decisions, so every decision recorded in the existing Laravel project appears below as a question. The earlier answer is given for reference, together with a recommendation. **Blocks** names the first roadmap stage that cannot start without an answer.

## Open questions

### Decided by the owner (2026-11-27)

| # | Question | Decision |
|---|---|---|
| Q-01 | Product scope | Bookings and payments are **out of scope**. My Ride Nepal is a motorcycle travel discovery, trip-planning, road-information and rider-community platform. Motorcycle details belong to individual trips and rider reports. **No personal motorcycle garage or vehicle ownership management.** |
| Q-02 | Rider and staff accounts | **Separate authentication boundaries.** Riders: public registration and normal user features. Staff: a separate administrative sign-in with RBAC and stricter security. Public rider registration can never create a staff account. |
| Q-20 | The existing Laravel/Vue application | It **stays available as a reference** during development: never deleted, modified or migrated automatically. The Node platform replaces it after successful testing and deployment. Before any data import: (1) audit the old database, (2) identify relevant records, (3) prepare a detailed migration mapping, (4) identify duplicate, outdated and unnecessary data, (5) present the plan for approval. No production migration or destructive operation without explicit approval. Roadmap stage S16, inventory row LM-1. |
| Q-22 | Design direction | General direction **approved**, with the primary page background changed from warm stone `#f4f2ee` to **plain white `#ffffff`**; subtle neutral surfaces for secondary sections; consistent typography; deliberate accent colours; clean spacing; minimal shadows. Applied in `DESIGN_SYSTEM.md` §3 and §5. Lucide icons and no emojis stay as proposed. |
| — | Technical architecture | Approved: Node + Express + TypeScript; React + TypeScript + Vite for both frontends; PostgreSQL + Drizzle; server-side sessions in secure HttpOnly cookies; pg-boss; OpenAPI generated from the backend validation schemas; independently managed apps; no shared runtime package. **Condition:** every dependency is verified for maintenance, licensing, security and compatibility before it is installed (done 2026-11-27: `TECHNICAL_DECISIONS.md` TD-24). |

### Needed before the stage that depends on them

| # | Question | Earlier answer | Recommendation | Blocks |
|---|---|---|---|---|
| Q-03 | Staff MFA: mandatory, optional, or none? | Mandatory before launch, 14-day enrolment grace, TOTP + recovery codes | Mandatory before launch (TOTP + recovery codes). Staff can publish content and suspend people | S14 (design S1 so that it fits) |
| Q-04 | Account deletion: cooling-off period? What happens to a rider's published contributions, the events they organise and the group trips they own? | 30 days; the rider chooses per contribution (delete / keep anonymously / keep anonymously without photos); events and trips are handed over with consent, otherwise cancelled or deleted with notifications | Keep the earlier rules. They are well reasoned, but they are the most complex feature in the product. A simpler first version (cooling-off + delete everything + anonymise only the audit log) is possible if the owner wants it | S13 |
| Q-05 | Retention periods: spam and closed contact messages, moderation evidence, deletion records, login activity, audit log | spam 30 days, closed 12 months, open never auto-deleted; evidence ≤ 30 days; deletion records 1 year; audit log kept | Keep these, plus login activity 90 days. The audit log needs a period too (legal question, Q-24) | S12, S13 |
| Q-06 | Staleness thresholds and corroboration rule | Road condition 90 days or a newer report on the same segment; fuel/ATM/connectivity 180; accommodation/mechanic 365; destination facts warn after 365. Corroboration threshold and window were configurable ("to confirm with owner") | Use these as defaults, editable in trust settings. Owner to confirm the corroboration count (suggest 3 distinct riders within 30 days) | S6 |
| Q-07 | Route difficulty scale | never decided | Three levels (easy / moderate / demanding) with a written definition of each, set by staff, never computed | S5 |
| Q-08 | May moderators suspend only someone's right to contribute (they keep reading, trips and events)? | Yes, with reason, end date and one appeal | Yes | S6 |
| Q-09 | Who sees an event's participant list? | Public events: the count is public; the list only for participants and the organizer | Same | S10 |
| Q-10 | In a group trip, who sees each member's emergency contacts? | undecided | Each member chooses to share their contacts with the trip; nobody else sees them by default | S9 |
| Q-11 | What does "identity verification" mean and prove, and how is it checked? | never defined | Leave it out until defined. Checking ID documents creates sensitive-data handling the product does not otherwise need | S3 |
| Q-12 | Confirm the "brief only" items: contribution drafts (C12), rider reports about events (D17), fuel planning (E4), official emergency numbers (E10, only with an official source), review expiry for safety guides (E13), and a `rest` service kind | held as requirements waiting for confirmation | Confirm all. Each is small | S5, S6, S10, S11 |
| Q-13 | Production file storage (photos, media library) | undecided; S3-compatible suggested (Cloudflare R2, Backblaze B2, AWS S3) | An S3-compatible provider. Development uses the local disk; production never does | S2 (interface), launch (provider) |
| Q-14 | Routing and geocoding providers (riding times, detours, alternative routes) | undecided (OpenRouteService, GraphHopper, self-hosted OSRM/Valhalla, Nominatim within policy, or none) | Start with **none**: distances come from recorded tracks, times are left out unless staff entered them. Add a provider when R8/R14 are built. Free tiers have quotas and terms to check | S8 (R8, R14), B5 |
| Q-15 | Use PostGIS for "near" and "along the route" queries? | Planned, not activated | Yes, if the chosen PostgreSQL host offers it (most managed hosts do). It replaces hand-written distance maths with tested functions | S4 (schema), S8 |
| Q-16 | Search engines and link previews: the public site is a client-rendered SPA. Prerender public pages at build time, use server rendering, or accept no crawler-visible content? | Prerender or a meta endpoint (undecided) | Decide before S12. Prerendering detail pages needs a build step that can read the API; server rendering changes how the frontend is deployed (TD-17) | S12 |
| Q-17 | Languages: English only, or English and Nepali? | English only for now (undecided) | English first, with all UI text kept in one place per app, so translation can be added later without rewriting screens | S1 |
| Q-18 | Promotional banners: site content only, or third-party promotion? | undecided (third-party promotion conflicts with "no payments") | Site content only | S12 |
| Q-19 | Hosting, budget, production email and error-monitoring providers | Candidate host chosen, no budget approved | Decide after the foundation is built; nothing is bought or deployed without approval | Launch |
| Q-21 | Should group trip members be able to split costs between members? | Listed as missing; never specified | Leave it out until specified. Totals per member are enough at first | S9 |
| Q-23 | Who writes and checks the real content: destinations, routes, permits, safety guides, emergency numbers? | open; content "must come from qualified people" | The owner names content owners. The platform launches with whatever is verified, not with invented content | Launch |
| Q-24 | Legal: privacy policy and terms text, Nepal's Individual Privacy Act 2075, liability wording for safety and road information | not addressed | Get legal review before launch. The code supports it (export, deletion, retention), but the wording is legal work | Launch |
| Q-25 | CAPTCHA on sign-up, password reset and contact form? | suggestion only | Yes, a privacy-friendly provider (for example Cloudflare Turnstile or self-hosted ALTCHA), after launch traffic shows abuse, or before launch if the owner prefers | X11 |
| Q-26 | Analytics: none, self-hosted privacy-friendly, or a third party? | not addressed | None at first; self-hosted (for example Umami or Plausible CE) if needed. No cookies and no personal data | S16 |
| Q-27 | Password and session policy: minimum lengths (riders 8, staff 12?), session idle and absolute lifetimes | riders ≥ 8; staff lowered from 12 to 8 by the owner; rider 120 min idle / 7 days; staff 60 min / 12 h | Riders ≥ 10, staff ≥ 12, with a check against breached passwords (k-anonymity, Q-25 scope). Lifetimes as before | S1 |
| Q-28 | Offline Ride Pack: which data goes offline (trip only, or the routes, segments and places in it), and how much storage per device? | "later milestone, still in scope" | The trip and everything it references, read-only offline | S15 |

## Risks

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| RK-01 | **The original master brief was never saved.** Requirements were reconstructed by the old project, so something may be missing | medium / medium | The owner reviews `REQUIREMENTS_INVENTORY.md`, which becomes the single written baseline |
| RK-02 | **Scope is large for one developer** (about 250 requirements, 16 stages, three apps) | high / high | Vertical slices, strict stage order, and optionally an owner-defined launch subset. No stage starts before the previous one is tested |
| RK-03 | **Safety liability:** an old road report read as current, or a wrong repair or first-aid instruction | medium / high | Observation dates and age in words everywhere; automatic outdating; expert review plus a source for safety content; "not an emergency service" notices; legal wording (Q-24) |
| RK-04 | **Two implementations of the same product** (Laravel and Node) drift apart or confuse future sessions | medium / medium | This repo never edits the old one (Q-20: it stays as a read-only reference until the Node platform replaces it); CLAUDE.md states which folder is which |
| RK-15 | **Fast-moving toolchain:** TypeScript 7 (native port) is out but `typescript-eslint` and `openapi-typescript` do not support it yet; ESLint 9 is deprecated and `eslint-plugin-jsx-a11y` does not support ESLint 10 | high / low | Pinned compatible versions (TD-24); a maintained fork for the a11y lint rules; revisit at each stage start |
| RK-16 | **Licence surprises in transitive dependencies** (e.g. `react-leaflet` uses the non-OSI Hippocratic licence) | medium / medium | Licence check for every direct dependency before install (TD-24); `react-leaflet` rejected; a licence report per app in Phase 8 |
| RK-05 | **No real content at launch** (destinations, routes, guides must be verified by people) | high / high | Q-23. Development seed rows are flagged and labelled. Empty states are designed as first-class screens |
| RK-06 | **Map tiles and routing services:** the public OpenStreetMap tile server's usage policy forbids heavy use; routing APIs have quotas or costs | high / medium | Tile URL in settings, so it can move to a provider or self-hosted tiles; routing optional (Q-14); attribution always shown |
| RK-07 | **Concurrency bugs** in event capacity, waitlist promotion, last-administrator protection and moderation decisions | medium / high | Transactions with row locks (`SELECT … FOR UPDATE`), unique constraints, and concurrent-request tests |
| RK-08 | **Privacy leaks:** emergency contacts, private trips, photo GPS metadata, reviewer identity, rider email | medium / high | Ownership checks through the parent record; response schemas that list allowed fields; photos re-encoded; tests for 404 on foreign records |
| RK-09 | **Native dependencies** (`sharp`, the Argon2 binding) fail to install on the chosen host | low / medium | Both ship prebuilt binaries for Linux x64/arm64; check on the host in Phase 3 |
| RK-10 | **SEO** of a client-rendered public site (Q-16) | medium / medium | Decide before S12; the API design does not depend on the choice |
| RK-11 | **npm supply chain** (a malicious or abandoned package) | medium / high | Few dependencies, each justified in `TECHNICAL_DECISIONS.md`; lockfiles committed; `npm audit` in CI; exact versions in the backend |
| RK-12 | **Learning curve:** the owner is learning; clever abstractions would make the code hard to own | medium / medium | Plain module structure, explicit SQL-like queries, comments on why, one way of doing each thing |
| RK-13 | **Hosting cost unknown** (Q-19) | — / medium | Design runs on one small server + managed PostgreSQL; no Redis or queue service needed (TD-13) |
| RK-14 | **Account deletion complexity** (Q-04) can produce bugs that destroy other riders' data | medium / high | Database checks that refuse unsafe deletes; processing switched off by default; dry-run tests on a copy |

## Lessons carried over from the existing project (facts, not decisions)

These come from the old project's handoff and audit notes. They are the problems it actually ran into:

- A local database setup script re-granted UPDATE/DELETE on the audit table after migrations, silently breaking append-only. → Privileges are set **in migrations** and verified by a test.
- WebKit could not run without system libraries. → Plan the Playwright WebKit setup in Phase 3, not at the end.
- CI never ran because the repositories were never pushed. → CI is part of Phase 3, run locally with the same script until a remote exists.
- Several "complete" rows had no test of the specific behaviour. → The Impl/Test columns in the inventory, and the rule that a row is done only with a test that ran.
