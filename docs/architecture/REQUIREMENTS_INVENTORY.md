# Requirements Inventory

Date: 2026-11-27 (session 1, discovery). Nothing is built yet: every row is **not started** and **not tested**.

## Where these requirements come from

This folder started empty. The requirements come from the existing Laravel/Vue implementation of My Ride Nepal at `/var/www/html/Road-trip/web/docs/`. They were read but not changed, and no code was copied. The owner confirmed on 2026-11-27 that this project is a **Node.js rebuild of that same product**.

| Code | Source document (in the existing project) |
|---|---|
| V | `product/PRODUCT_VISION.md` |
| S | `product/PRODUCT_SPECIFICATION.md` |
| F | `product/FEATURE_SCOPE.md` (incl. the owner's mandatory additions N1–N6 of 2026-11-18) |
| E | `product/EVENT_SYSTEM.md` |
| T | `product/CONTRIBUTION_TRUST_POLICY.md` |
| J | `product/USER_JOURNEYS.md` |
| R | `product/USER_ROLES_AND_PERMISSIONS.md`, `architecture/AUTHORIZATION_MODEL.md` |
| AU | `audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md`, `architecture/REQUIREMENTS_GAP_ANALYSIS.md` (row IDs reused here) |
| OD | `product/OWNER_DECISIONS_2026-11-22*.md` |
| MB | the master brief for this rebuild (pasted 2026-11-27) |

**Three caveats:**

1. **The original master brief for the product was never saved.** The existing project rebuilt its requirement baseline from the documents above, so a requirement that only existed in that lost brief cannot be recovered here (risk RK-01).
2. **Earlier owner decisions are not carried over automatically.** The owner chose a fresh start, so every decision recorded in the old project appears in `RISKS_AND_OPEN_QUESTIONS.md` as a question to confirm. Rows that depend on one are tagged `Q-nn`.
3. **Scope confirmed by the owner on 2026-11-27 (Q-01):** My Ride Nepal is a motorcycle travel discovery, trip-planning, road-information and rider-community platform. Bookings and payments are out of scope. Motorcycle details belong to individual trips (F5) and rider reports (C8); there is **no personal garage or vehicle ownership management**. How the rebuild brief's suggested modules map to this product is in §16.
4. **Separate rider and staff authentication confirmed (Q-02):** sections 10–12 apply as written.

## How to read the tables

- **Roles:** G guest · R signed-in rider (confirmed email, not suspended) · TC verified trip contributor · EX verified expert (per field) · OR verified event organizer · MOD moderator role · ADM administrator role · ST staff holding the named permission · SYS scheduled/system process.
- **Stage:** the delivery stage in `IMPLEMENTATION_ROADMAP.md` (F = foundation, S1–S16).
- **Tags:** `Q-nn` = blocked or shaped by an open question · `BO` = "brief only": first raised in the old owner's audit brief and never written into a specification, so it needs confirmation before it is built (it is not dropped).
- **Impl / Test:** `—` = not started. These two columns make this file the traceability matrix. When a row is built, write the module path in Impl and the test file in Test. A row is never marked done without a test that ran, or a written reason why no test applies.
- **Acceptance criteria:** the expected behaviour in each row is the summary. Detailed, testable acceptance criteria are written at the start of each stage, in that stage's section of `docs/architecture/acceptance/` (roadmap step 2 of every feature). They are not written for 250 rows up front, because many depend on open questions.

## Responsibilities per layer (applies to every row)

- **Backend:** validation (Zod), authorization (permission, ownership or claim checks), business rules, state transitions, database constraints, audit rows for staff decisions, and API tests.
- **Admin app:** staff screens that call the API. They hide controls the staff member lacks permission for, but that is UX only.
- **Frontend (public site):** guest and rider screens with loading, empty and error states, and freshness and estimate labels where content is dated.

---

## 1. Explore Nepal (destinations)

Backend: destinations module, sources, freshness. Admin: destination editor with map picker. Frontend: directory, detail, map.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| A1 | Destination directory: published destinations only, ordered, paginated | G | — | S4 | — | — |
| A2 | Search by name and filter by province (server-side search) | G | A1 | S4 | — | — |
| A3 | Destination detail with structured practical travel information | G | A1 | S4 | — | — |
| A4 | Attractions and points of interest at a destination (served by the places model, §8) | G | R2 | S8 | — | — |
| A5 | Routes that start or end at a destination are linked from it | G | B1 | S5 | — | — |
| A6 | Permits and entry requirements, each with source and last-confirmed date | G, ST | A11 | S4 | — | — |
| A7 | Facilities and services at a destination: fuel, food, rest, accommodation, repair, medical, ATM, connectivity (`rest` kind: Q-12) | G | B8 | S5 | — | — |
| A8 | Seasonal information (best seasons, closures), sourced and dated | G, ST | A11 | S4 | — | — |
| A9 | Estimated expenses, labelled as estimates, sourced and dated | G, ST | A11 | S4 | — | — |
| A10 | Rider experiences linked to the destination (visible statuses only) | G | C6 | S6 | — | — |
| A11 | Every fact carries a source (rider or official, with citation) and a last-confirmed date | G, ST | — | S4 | — | — |
| A12 | Freshness indicators: "Never confirmed" when no date; an outdated warning after a threshold (Q-06) | G | A11 | S4 | — | — |
| A13 | Development seed rows flagged `is_dev_seed` and labelled in the UI | G | — | S4 | — | — |
| A14 | Navigation between related content (destination ↔ routes ↔ places ↔ experiences) and global search | G | A1, B1 | S8 | — | — |

## 2. Destination mapping and location (N4)

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| L1 | Latitude/longitude on every destination, inside Nepal's bounding box; map marker | G, ST | — | S4 | — | — |
| L2 | Admin map picker to place and adjust the coordinates | ST | L1 | S4 | — | — |
| L3 | Location hierarchy: province, district, locality | G, ST | — | S4 | — | — |
| L4 | Nearby attractions (places within a distance) | G | R2, Q-15 | S8 | — | — |
| L5 | Nearby services | G | B8 | S5 | — | — |
| L6 | Nearby routes | G | B1 | S5 | — | — |
| L7 | Photo gallery: alt text and credit are required; only images of that place | G, ST | CM12 | S4 | — | — |
| L8 | Description | G, ST | — | S4 | — | — |
| L9 | Seasonal information (same as A8) | G | A8 | S4 | — | — |
| L10 | Permits and travel requirements (same as A6) | G | A6 | S4 | — | — |
| L11 | Estimated expenses (same as A9) | G | A9 | S4 | — | — |
| L12 | Source attribution and last-confirmed dates (same as A11) | G | A11 | S4 | — | — |
| L13 | The public destination page shows an interactive map with related places, services and routes | G | L1, L4–L6 | S8 | — | — |
| L14 | A destination cannot be published as a name-only record (minimum fields to publish: coordinates, province, description) | ST | L1, L3, L8 | S4 | — | — |

## 3. Routes and road information

Backend: routes, segments and services modules; route geometry import (GPX/GeoJSON). Admin: route and segment editor. Frontend: route directory, route page with map.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| B1 | Route directory and route detail | G | — | S5 | — | — |
| B2 | Interactive route map (Leaflet); draws only real recorded geometry, otherwise checkpoints only | G | B3 | S5 | — | — |
| B3 | A route is an ordered list of segments between checkpoints | G, ST | — | S5 | — | — |
| B4 | Distance per checkpoint and cumulative distance | G | B3 | S5 | — | — |
| B5 | Riding time per segment and total, labelled as estimates and never invented | G | B3, Q-14 | S5 | — | — |
| B6 | Road surface observations and a difficulty rating (scale: Q-07) | G, ST | B9 | S5 | — | — |
| B7 | Alternative routes between the same endpoints | G | R14 | S8 | — | — |
| B8 | Service points (fuel, food, accommodation, rest, repair, medical, ATM, connectivity) along a route, on the map | G, ST | B3 | S5 | — | — |
| B9 | Road reports per segment, with an observation date | R | C2 | S6 | — | — |
| B10 | Historical vs current: report age shown in words; nothing is ever labelled "current" or "live" | G | B9 | S6 | — | — |
| B11 | Dangerous road reports: the `dangerous` report category is moderated with priority | R, MOD | C17 | S6 | — | — |
| B12 | Segment integrity: a segment with reports cannot be deleted; reordering keeps segment identity | ST | B3, B9 | S5 | — | — |
| B13 | Route discovery: filter by destination, province, distance and difficulty | G | B1 | S5 | — | — |

## 4. Rider experiences and contributions

Backend: contributions module (one table, one moderation pipeline), media, reports, confirmations, corrections. Admin: moderation queue. Frontend: progressive forms, library, My contributions.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| C1 | Full trip reports | R | — | S6 | — | — |
| C2 | Road condition reports (4 fields: observed date, surface condition, note, optional photo) | R | B3 | S6 | — | — |
| C3 | Accommodation, fuel and mechanic observations | R | — | S6 | — | — |
| C4 | Tips and lessons: recommendation, practical observation, unexpected situation, "wish I had known" | R | — | S6 | — | — |
| C5 | Photos: real images only, re-encoded, EXIF/GPS removed, served only when the contribution is visible | R | CM12 | S6 | — | — |
| C6 | Linked to a destination, route, segment, service or place | R | A1, B3 | S6 | — | — |
| C7 | Observation date (required, not in the future) kept separate from the submission time | R | — | S6 | — | — |
| C8 | Motorcycle model and engine capacity, when given | R | — | S6 | — | — |
| C9 | Trip duration and expenses | R | C1 | S6 | — | — |
| C10 | Problems encountered and lessons learned | R | C1 | S6 | — | — |
| C11 | Progressive forms: a short road report; a full trip in steps (where/when, bike, segments, costs, problems, lessons, photos) | R | C1, C2 | S6 | — | — |
| C12 | Drafts, and editing before review (BO, Q-12) | R | C11 | S6 | — | — |
| C13 | Moderation lifecycle: approve, reject with reason, request correction, flag dangerous | MOD | H1 | S6 | — | — |
| C14 | Community corroboration: independent reports or confirmations on the same segment within a window (rule: Q-06) | R, SYS | H1 | S6 | — | — |
| C15 | Officially sourced status, with a citation | MOD | H3 | S6 | — | — |
| C16 | Contributions become outdated automatically by type threshold, or when a newer report covers the same segment (Q-06) | SYS | H12 | S6 | — | — |
| C17 | Reporting misleading or dangerous content; 3 open reports flag the item automatically | R | — | S6 | — | — |
| C18 | Ownership: authors see their own pending items and edit only while pending; nobody else can | R | — | S6 | — | — |
| C19 | "Verified trip contributor" label on contributions of verified riders | G | G5 | S6 | — | — |

## 5. Trust, moderation and data integrity

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| H1 | Status lifecycle: pending_review, published, community_corroborated, officially_sourced, outdated, rejected (final), flagged; only allowed transitions | MOD, SYS | — | S6 | — | — |
| H2 | Contributor attribution, observation date and submission date on every contribution | G | C7 | S6 | — | — |
| H3 | Structured source records (title, publisher, URL, accessed date), attachable to destinations, routes, places and contributions | ST | — | S4 | — | — |
| H4 | `last_confirmed_on` on contributions, updated by rider confirmations and staff confirmations | R, MOD | C14 | S6 | — | — |
| H5 | Moderation history per item, visible to moderators | MOD | H7 | S6 | — | — |
| H6 | Corrections never silently rewrite a rider's text: staff request a correction, the author supplies it, staff notes are kept in separate fields | R, MOD | C13 | S6 | — | — |
| H7 | Append-only audit history for staff decisions and security events | SYS | — | F | — | — |
| H8 | No unauthorized modification of any record (policy and ownership on every write) | all | G15 | all | — | — |
| H9 | Repeat offenders: contribution rights suspended with a reason, without blocking other use (Q-08) | MOD | — | S6 | — | — |
| H10 | Dangerous content handling: prioritised queue and a clear label | MOD | B11 | S6 | — | — |
| H11 | Deleting data keeps relationships intact (no orphans, no cascades that destroy other riders' data) | SYS | — | all | — | — |
| H12 | Staleness thresholds per content type, editable in trust settings (defaults: Q-06) | ADM | — | S6 | — | — |

## 6. Events and organized rides

Backend: events module; capacity is enforced in a transaction with a row lock. Admin: event oversight. Frontend: event discovery, hosting, joining, My events.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| D1 | Only riders with an approved, non-suspended organizer verification can host (checked live on every action) | OR | G7 | S10 | — | — |
| D2 | Organizer application: a statement of 20–2000 characters; pending until decided | R | G10 | S3 | — | — |
| D3 | Organizer rejection, suspension and revocation take effect on the next request | ADM | D2 | S3 | — | — |
| D4 | A suspended organizer's existing events become read-only to them; staff decide whether to cancel | OR, ST | D3 | S10 | — | — |
| D5 | Public and private events | OR | — | S10 | — | — |
| D6 | Join method: instant, or request-to-join with approval | R, OR | — | S10 | — | — |
| D7 | Capacity can never be exceeded, even under concurrent joins | SYS | — | S10 | — | — |
| D8 | Waitlist with automatic promotion of the first waitlisted rider when a place frees | SYS | D7 | S10 | — | — |
| D9 | Invitations: organizer invites; invitee accepts (approved, or waitlisted if full) or declines | OR, R | — | S10 | — | — |
| D10 | Organizer approves or declines join requests | OR | D6 | S10 | — | — |
| D11 | Participant management; who sees the participant list (Q-09) | OR, R | — | S10 | — | — |
| D12 | Organizer updates and announcements notify every non-cancelled participant | OR | X10 | S10 | — | — |
| D13 | Postponement, with a new date prompt | OR | D20 | S10 | — | — |
| D14 | Cancellation by the organizer or by staff (`events.cancel`), with notifications | OR, ST | X10 | S10 | — | — |
| D15 | Attendance (attended / did not attend) and completion; summary and photos after completion | OR | — | S10 | — | — |
| D16 | Visibility: drafts and private events are visible only to the organizer, involved riders and `events.view` staff | all | — | S10 | — | — |
| D17 | Rider reports about events, moderated by staff (BO, Q-12) | R, MOD | — | S10 | — | — |
| D18 | Several optional linked routes per event | OR | B1 | S10 | — | — |
| D19 | My events: organising and participating lists | R | — | S10 | — | — |
| D20 | Event status transitions: draft → published ⇄ full → completed; published/full → postponed → published; any non-completed → cancelled | OR, SYS | — | S10 | — | — |
| D21 | Participant status transitions as in the EVENT_SYSTEM table (invited, pending, approved, declined, waitlisted, cancelled, attended, did_not_attend) | OR, R, SYS | — | S10 | — | — |

## 7. Preparation and safety

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| E1 | Pre-ride preparation guides (content must come from qualified people: RK-05) | G, ST | — | S11 | — | — |
| E2 | Motorcycle, personal and equipment checklists (templates a rider can copy into a trip) | R | F7 | S11 | — | — |
| E3 | Weather preparation guide category (BO) | G | E1 | S11 | — | — |
| E4 | Fuel planning (fuel gaps along a route from service points) (BO, Q-12) | G | B8 | S11 | — | — |
| E5 | Accommodation planning guide | G | E1 | S11 | — | — |
| E6 | Emergency information | G | E1 | S11 | — | — |
| E7 | Breakdown guidance | G | E12 | S11 | — | — |
| E8 | Accident and injury guidance | G | E12 | S11 | — | — |
| E9 | Personal emergency contacts, visible to their owner only (staff included) | R | — | S9 | — | — |
| E10 | Official emergency numbers (police, ambulance, tourist police), only with a cited official source (BO, Q-12) | G | H3 | S11 | — | — |
| E11 | Every safety guide cites its sources | ST | H3 | S11 | — | — |
| E12 | Safety-critical guides publish only after review by an expert verified in that field who is not the author; editing the text clears the review | EX, ST | G6 | S11 | — | — |
| E13 | Last-reviewed date and a review expiry for safety content (BO, Q-12) | ST | E12 | S11 | — | — |
| E14 | Every safety screen says the platform is not an emergency service | G | — | S11 | — | — |

## 8. Places and Explore Along My Route (N5)

Backend: places module; geospatial queries (Q-15); routing provider adapter (Q-14). Admin: places editor, suggestion and correction queues. Frontend: journey planner map.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| R1 | The rider picks a start, a destination and a preferred route where alternatives exist | G, R | B1 | S8 | — | — |
| R2 | A places model distinct from destinations, segments, services, rider places and itinerary stops | — | — | S8 | — | — |
| R3 | Riders suggest hidden places (pending until reviewed); corrections to places go to a review queue | R, MOD | R2 | S8 | — | — |
| R4 | The complete route is shown on an interactive map | G | B2 | S8 | — | — |
| R5 | Places along the route, marked as on the direct route or as a detour | G | R2, Q-15 | S8 | — | — |
| R6 | Category filters | G | R5 | S8 | — | — |
| R7 | Distance from the route | G | R5 | S8 | — | — |
| R8 | Estimated detour distance and time, where the provider supports it; labelled as an estimate | G | Q-14 | S8 | — | — |
| R9 | Place details page | G | R2 | S8 | — | — |
| R10 | Save a place | R | SV1 | S9 | — | — |
| R11 | Add a place to a trip itinerary as a stop (once per trip) | R | F4 | S9 | — | — |
| R12 | Photos and rider experiences per place | G | C6 | S8 | — | — |
| R13 | Source and freshness for every place | G | A11 | S8 | — | — |
| R14 | Alternative routes, where the provider supports them | G | Q-14 | S8 | — | — |
| R15 | Works for any supported journey, not one hard-coded pair | G | R1 | S8 | — | — |
| R16 | Never invent distances, opening hours or travel times; label every estimate | G | — | S8 | — | — |

## 9. My Rides: trips, group trips and saved items

Backend: trips module (members, stops, expenses, checklists, notes, invitations), saved items. Staff have no access to trips (F17). Frontend: trip workspace.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| F1 | Create a private trip (private by default) | R | — | S9 | — | — |
| F2 | Trip dates and a day-by-day itinerary | R | F1 | S9 | — | — |
| F3 | Whole routes, single stretches and checkpoints as trip stops (each once per trip) | R | B3 | S9 | — | — |
| F4 | Stops and overnight accommodation, pointing at a destination, place, service, route part or free text | R | F1 | S9 | — | — |
| F5 | Motorcycle details for the trip | R | F1 | S9 | — | — |
| F6 | Planned and spent costs in NPR, totals and budget; splitting costs between members (Q-21) | R | F1 | S9 | — | — |
| F7 | Preparation checklists in a trip | R | F1 | S9 | — | — |
| F8 | Saved services, guides, experiences and places | R | SV1 | S9 | — | — |
| F9 | Emergency contacts in a trip; who sees them in a group trip (Q-10) | R | E9 | S9 | — | — |
| F10 | Private notes per member, and shared trip notes | R | F1 | S9 | — | — |
| F11 | Group trip workspaces | R | F1 | S9 | — | — |
| F12 | Invitations by username; membership only after consent (acceptance); invitations expire | R | F11 | S9 | — | — |
| F13 | Owner, editor and viewer permissions | R | F11 | S9 | — | — |
| F14 | Shared itinerary editing by editors | R | F13 | S9 | — | — |
| F15 | Shared stops, budget, checklists and notes | R | F13 | S9 | — | — |
| F16 | Removing or leaving a trip revokes access immediately | R | F11 | S9 | — | — |
| F17 | Private data isolation: other riders and staff get 404 | all | — | S9 | — | — |
| F18 | Trips are independent of events and need no organizer verification | R | — | S9 | — | — |
| F19 | Offline Ride Pack: PWA, trip data in IndexedDB, last-sync time, staleness warning (later milestone, still in scope) | R | F1 | S15 | — | — |
| SV1 | Save services (fuel, repair, accommodation, restaurants, medical, others) | R | B8 | S9 | — | — |
| SV2 | List saved items | R | SV1 | S9 | — | — |
| SV3 | Add a saved service to a trip itinerary | R | SV1, F4 | S9 | — | — |

## 10. Accounts, verification and authentication

Backend: rider auth, staff auth, sessions, verification claims. Frontend: sign-up, sign-in, reset, account. Admin: staff sign-in, rider management.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| J2 | Rider sign-up (name, email, password); confirmation email; the same answer whether or not the email exists | G | — | S1 | — | — |
| J3 | Profile: display name, unique username, home city, bio; email never shown publicly | R | J2 | S1 | — | — |
| J4 | Forgot / reset password: generic answer, single-use expiring token, all sessions end | G | J2 | S1 | — | — |
| G1 | Guests read published content without an account | G | — | S1 | — | — |
| G2 | Writes need a signed-in rider with a confirmed email who is not suspended or deactivated | R | J2 | S1 | — | — |
| G3 | Moderator permissions (default role) | MOD | P1 | S1 | — | — |
| G4 | Administrator permissions (every permission, including future ones) | ADM | P1 | S1 | — | — |
| G5 | Verified trip contributor status | R, MOD | G9 | S3 | — | — |
| G6 | Expert verification per field: mechanical, medical, road safety | R, ADM | G9 | S3 | — | — |
| G7 | Verified event organizer status | R, ADM | G9 | S3 | — | — |
| G8 | Identity verification: what it means and proves is undefined (Q-11) | R, MOD | Q-11 | S3 | — | — |
| G9 | Each claim has its own application and decision; one approval never implies another | R | — | S3 | — | — |
| G10 | Claim lifecycle: apply, approve, reject, suspend, revoke, reinstate; one open claim per kind and field | R, ST | G9 | S3 | — | — |
| G11 | Negative decisions need a written reason (≥ 10 characters) | ST | G10 | S3 | — | — |
| G12 | Only authorized reviewers decide (`verification.decide`; organizer and expert claims need `verification.decide_sensitive`) | ST | P1 | S3 | — | — |
| G13 | Nobody reviews their own claim, contribution, guide or place suggestion | ST | Q-02 | S3 | — | — |
| G14 | Riders never see who reviewed them | R | G10 | S3 | — | — |
| G15 | Every protected endpoint authorizes on the server; a test fails if any route is unclassified | all | — | F | — | — |
| G16 | The last active administrator cannot lose the role or be deactivated (row lock) | ADM | P1 | S1 | — | — |
| G17 | Private trips and private events are restricted at the API | all | — | S9, S10 | — | — |
| G18 | Account suspension with a reason; the rider's sessions end | ST | — | S1 | — | — |
| G19 | Account deletion and data export (rules: Q-04) | R | — | S13 | — | — |
| G20 | Role changes need password re-confirmation | ADM | P1 | S1 | — | — |
| — | Sign out of other sessions; list active sessions | R | — | S1 | — | — |
| — | Change password (current password required) | R | — | S1 | — | — |
| — | Login activity visible to the account holder (MB §6) | R, ST | — | S1 | — | — |

## 11. Separate rider and staff authentication (N9, confirmed Q-02)

Confirmed by the owner on 2026-11-27: riders register publicly; staff have a separate administrative sign-in with RBAC; public registration can never create a staff account.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| SA-1 | Separate sign-in screens and endpoints for riders and staff | — | — | S1 | — | — |
| SA-2 | Separate authorization: staff endpoints accept only staff sessions; rider endpoints only rider sessions | — | — | S1 | — | — |
| SA-3 | Staff accounts are created only by invitation or a server console command | ADM | — | S1 | — | — |
| SA-4 | No public staff registration route | — | — | S1 | — | — |
| SA-5 | Registration ignores extra fields; no privilege escalation by request manipulation | — | — | S1 | — | — |
| SA-6 | No frontend-only authorization | — | — | S1 | — | — |
| SA-7 | Rider credentials never grant staff access | — | — | S1 | — | — |
| SA-8 | Separate staff sessions: own cookie, shorter idle and absolute lifetime, logout, sign out everywhere | — | — | S1 | — | — |
| SA-9 | Separate staff password reset; tokens never cross audiences | — | — | S1 | — | — |
| SA-10 | CSRF protection on every state-changing request; CORS closed by default | — | — | F | — | — |
| SA-11 | Tests prove a rider cannot authenticate as staff | — | — | S1 | — | — |
| — | Explicit, audited link between a staff account and its holder's rider account (only feeds the no-self-review rule) | ST | G13 | S1 | — | — |

## 12. Staff users, roles and permissions (N3)

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| P1 | Permissions at module and action level (view, create, edit, delete, publish, approve, manage), from a code registry | ADM | — | S1 | — | — |
| P2 | Create roles and assign permissions; nobody can grant a permission they lack; built-in roles protected | ADM | P1 | S1 | — | — |
| P3 | Several roles per staff member; rights add up | ADM | P1 | S1 | — | — |
| P4 | Staff invitations: hashed single-use token, 72 h, password confirmation to send | ADM | — | S1 | — | — |
| P5 | Activate, deactivate and suspend staff, never yourself | ADM | — | S1 | — | — |
| P6 | Admin-triggered password reset sends a link; the admin never sees or sets the password | ADM | — | S1 | — | — |
| P7 | Admin revokes a person's sessions | ADM | — | S1 | — | — |
| P8 | Last-administrator protection (same as G16) | ADM | — | S1 | — | — |
| P9 | Audit log entries for every account, role and permission action | SYS | H7 | S1 | — | — |
| P10 | Staff roles stay separate from rider verification; no verification ever grants a staff permission | — | — | S1 | — | — |
| P11 | Enforced in backend middleware and service checks; tested at the API level | — | — | S1 | — | — |
| P12 | Staff MFA: authenticator app with recovery codes (mandatory or optional: Q-03) | ST | Q-03 | S14 | — | — |
| — | Audit log viewer with filters (`audit.view`) | ST | H7 | S1 | — | — |

## 13. Site settings (N1)

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| S1 | Settings framework: typed, grouped key/values, cached, audited; secrets encrypted at rest and masked in responses | ST | — | S2 | — | — |
| S2 | General: site name, tagline, description, contact email and phone, address, language, timezone, date formats; shown on the public site | ST | S1 | S2 | — | — |
| S3 | Maintenance mode, with a signed staff preview link | ST | S1 | S2 | — | — |
| S4 | Registration open or closed | ST | S1 | S2 | — | — |
| S5 | Default SEO and social-sharing metadata (crawler visibility: Q-16) | ST | S1 | S12 | — | — |
| S6 | Branding: logos (light and dark variants), favicon, icons, brand colours, default share image, placeholders | ST | CM12 | S12 | — | — |
| S7 | Social links (https only) | ST | S1 | S12 | — | — |
| S8 | SMTP: host, port, encryption, user, password (encrypted), sender; connection test, test email, delivery errors | ST | S1 | S2 | — | — |
| S9 | Storage: provider (local in development; S3-compatible in production), limits, MIME types, connection test, usage (Q-13) | ST | S1 | S2 | — | — |
| S10 | Maps: tile URL and attribution, default centre and zoom; geocoding and routing providers configured separately (Q-14) | ST | S1 | S12 | — | — |
| S11 | Security (session lifetimes, password policy, rate limits within safe bounds), notifications, feature switches, integrations | ST | S1 | S12 | — | — |
| S12 | Clearing the cache where one exists | ST | S1 | S2 | — | — |
| — | Trust settings: staleness thresholds, corroboration threshold and window (H12) | ST | S1 | S6 | — | — |
| — | Footer: tagline, the emergency disclaimer (required), credits (H-12) | ST | S1 | S12 | — | — |

## 14. CMS content and dynamic homepage (N2, N7)

Backend: CMS module (one entry pipeline for structured types), media library, menus, homepage sections, contact inbox. Frontend: everything editable is read from the API; nothing staff manage is hard-coded.

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| CM-X1 | Draft, published and archived states | ST | — | S12 | — | — |
| CM-X2 | Scheduled publishing and expiry | ST, SYS | CM-X1 | S12 | — | — |
| CM-X3 | Ordering and visibility | ST | — | S12 | — | — |
| CM-X4 | SEO title, description, slug; slug change creates a redirect | ST | — | S12 | — | — |
| CM-X5 | Preview before publishing (signed, short-lived preview token) | ST | — | S12 | — | — |
| CM-X6 | Search, filters and pagination in staff lists | ST | — | S12 | — | — |
| CM-X7 | Validation and error states | ST | — | S12 | — | — |
| CM-X8 | History of important changes per item | ST | H7 | S12 | — | — |
| CM1 | FAQs (question, answer, category, order) | ST, G | CM-X1 | S12 | — | — |
| CM2 | Blog posts | ST, G | CM3–CM5 | S12 | — | — |
| CM3 | Blog categories | ST | — | S12 | — | — |
| CM4 | Blog tags | ST | — | S12 | — | — |
| CM5 | Authors | ST | — | S12 | — | — |
| CM6 | Team members | ST, G | — | S12 | — | — |
| CM7 | Popups (dismissible, accessible, frequency-limited) | ST, G | — | S12 | — | — |
| CM8 | Notices and announcements (notice bar) | ST, G | — | S12 | — | — |
| CM9 | Homepage sliders (1–5 images, pause control, no auto-advance under reduced motion) | ST, G | CM12 | S12 | — | — |
| CM10 | Promotional banners (site content only, or third parties too: Q-18) | ST, G | Q-18 | S12 | — | — |
| CM11 | Static and custom pages (About, Privacy, Terms, custom), built from structured content blocks | ST, G | CM14 | S12 | — | — |
| CM12 | Media library: upload, alt text, credit, usage tracking; an image in use cannot be deleted | ST | S9 | S2 | — | — |
| CM13 | Navigation menus (header, footer) | ST, G | — | S12 | — | — |
| CM14 | Reusable content blocks (a fixed set of block types, not a page builder) | ST | — | S12 | — | — |
| CM15 | Contact form and staff inbox (new/open/done/spam), with retention (Q-05) | G, ST | — | S12 | — | — |
| CM16 | The public site reads all managed content from the API | G | — | S12 | — | — |
| H-1…H-17 | Dynamic homepage: editable hero (copy, image, CTAs: internal paths or https only), staff-chosen featured destinations, routes and experiences, banners, notices, sliders, section order, visibility and schedule, menus, footer, social links, SEO defaults, draft and publish, loading, empty and error states | ST, G | CM-X1 | S12 | — | — |

## 15. Cross-cutting quality and operations

| ID | Requirement and expected behaviour | Roles | Depends | Stage | Impl | Test |
|---|---|---|---|---|---|---|
| X1 | Validation of every request body, query and parameter (Zod) | — | — | F | — | — |
| X2 | Versioned migrations; constraints, foreign keys and indexes in the database | — | — | F | — | — |
| X3 | Least-privilege database role for the running app (DML only); a separate owner role for migrations | — | — | F | — | — |
| X4 | Loading, empty, error (with retry) and skeleton states on every data view | — | — | all | — | — |
| X5 | Responsive at 360–1440 px with no horizontal scroll | — | — | all | — | — |
| X6 | WCAG 2.2 AA accessibility (automated axe checks plus a manual keyboard and screen-reader pass) | — | — | all | — | — |
| X7 | Browser support: Chromium, Firefox and WebKit/Safari, current and previous versions | — | — | S16 | — | — |
| X8 | CI: lint, type-check, tests, build and dependency audit per app | — | — | F | `.github/workflows/ci.yml` in each repository; `npm run check` in each | local run passed 2026-11-27; GitHub run pending a remote |
| X9 | Performance budgets (Core Web Vitals, bundle size, API p95) | — | — | S16 | — | — |
| X10 | In-app notifications; email for important events (which events: settings S11) | R | — | S7 | — | — |
| X11 | Rate limiting and abuse protection; CAPTCHA if approved (Q-25) | — | — | F | — | — |
| X12 | API documentation (OpenAPI) generated from the same schemas the API validates with | — | — | F | — | — |
| I-1…I-8 | Three independent applications: own entry point, development command, build, environment and deployment configuration; business logic only in the backend (N8, MB §3) | — | — | F | partial: entry points, dev commands, builds, env files done; deployment configs (I-6) wait for the host (Q-19) | independence check 2026-11-27 (session-02 report); E2E per app |
| — | Health endpoints (liveness, readiness including the database) (MB §12) | — | — | F | `backend/src/modules/health` | `backend/tests/foundation.test.ts`; E2E smoke in both frontends |
| — | Structured logging without personal data; error monitoring strategy (MB §12) | — | — | F | — | — |
| — | Database backup and tested restore procedure (MB §4, §12) | — | — | S16 | — | — |
| — | Data retention jobs: contact messages, moderation evidence, deletion records (Q-05) | SYS | — | S13 | — | — |
| — | Privacy-conscious analytics (MB §8): no third-party trackers unless approved (Q-26) | — | — | S16 | — | — |
| LM-1 | Legacy data: audit the old Laravel database, identify relevant records, prepare a detailed migration mapping, identify duplicate, outdated and unnecessary data, and present the plan for approval. No import, production migration or destructive operation without explicit approval (Q-20) | ADM | S13 | S16 | — | — |

## 16. The rebuild brief's suggested modules, mapped to this product (Q-01)

| Brief module | What it is in this product | Status |
|---|---|---|
| `auth`, `users` | rider accounts + staff accounts | in scope |
| `riders` | rider profiles and verification claims | in scope |
| `rides` | **events** (organized rides) and **trips** (private planning) | in scope, two modules |
| `bookings` | nothing. Joining an event is a free registration, not a booking | out of scope (V, F) |
| `payments` | nothing. Trip expenses are a rider's own notes in NPR; no money moves | out of scope (V, F) |
| `vehicles` | motorcycle details on contributions (C8) and trips (F5). Motorcycles only | **no vehicles module**: a personal garage or vehicle ownership management is excluded by the owner (Q-01) |
| `notifications` | in-app + email (X10) | in scope |
| `reviews` | **contributions** (dated rider knowledge with moderation). No star ratings of businesses or people | in scope as contributions |
| `cms` | CMS, settings, homepage | in scope |

## Explicitly out of scope (from F, V)

Payments, bookings and ticketing · live GPS tracking, SOS and emergency dispatch · live road or weather conditions · followers, likes and algorithmic feeds · vehicles other than motorcycles · AI-generated travel content · paid APIs or services without approval · a general page builder · deployment without owner approval.
