# Feature Scope

Full requirement list with status: `../audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md`. Order of work: `../audits/REMAINING_IMPLEMENTATION_ROADMAP.md`.

## In scope
**Earlier agreed baseline:** six areas in `PRODUCT_SPECIFICATION.md` (Explore Nepal, Routes, Rider Experiences, Events & Rides, Preparation & Safety, My Rides incl. saved services), rider accounts and profiles, claim-specific verification, moderation with audit trail, trust and freshness rules (`CONTRIBUTION_TRUST_POLICY.md`), private group trips, notifications, staff application, PWA Offline Ride Pack (later milestone).

**Confirmed by the owner on 2026-11-18 (mandatory additions):**
1. **CMS and site settings** managed in the staff app: general, branding, social, SMTP/mail (encrypted, masked, testable), storage (local and S3-compatible, durable on Vercel), map (tiles, geocoding and routing as separate capabilities), and additional settings (integrations, security, notifications, registration, SEO, features, cache).
2. **CMS content:** FAQs, blog posts, categories, tags, authors, team members, popups, notices, homepage sliders, promotional banners, static/custom pages, media library, navigation menus, reusable content blocks, contact messages. Draft/published/archived, scheduling, ordering, SEO and slugs, preview, search, audit. The public website reads all of it from the API. A structured page + content-block system, not a page-builder framework.
3. **Admin users, roles and permissions:** staff creation and invitations, activation/suspension, roles with module- and action-level permissions, multiple roles, admin-triggered password reset and session revocation, audit logs. Enforced in Laravel and tested at API level. Kept separate from rider verification statuses.
4. **Destination mapping and location management:** coordinates with map marker and admin map picker, province/district/locality hierarchy, nearby attractions/services/routes, photos, seasons, permits, estimated expenses, sources and last-confirmed dates; interactive map on the public page.
5. **Explore Along My Route** (first-class): pick start, destination and route; see the journey on a map with places to discover along it (direct vs detour, category filters, distance, provider-based detour estimates), open, save and add to a trip itinerary, with photos, experiences, sources and freshness. Works for any supported journey.
6. **Saved services restored** to My Rides (it had been moved to future recommendations in session 4 without an owner decision).

## Explicitly out of scope
| Excluded | Why |
|---|---|
| Payments, bookings, ticketing | Owner restriction; liability |
| Live GPS tracking, emergency dispatch, SOS | Cannot be delivered reliably; dangerous if implied |
| Live road or weather conditions | Only dated observations; no live feeds |
| Follower systems, likes, algorithmic feed | Not a social network |
| Vehicles other than motorcycles | Product focus |
| AI-generated travel content | Fabrication risk; category L of the audit stays "not applicable" until AI is proposed |
| Paid APIs or services without approval | Owner restriction: free/open-source first, ask before any cost (applies to storage, routing, geocoding, tiles) |
| A general page-builder framework | Owner: structured pages and content blocks are enough initially |
| Deployment execution | Owner decides hosting steps |

## Boundaries that are easy to blur
- **Group trip vs event:** a group trip is a private planning workspace of invited members. An event is hosted by a verified organizer, may be public, has registration rules and capacity.
- **Verified rider vs expert:** trip-contributor verification says nothing about mechanical, medical or road-safety expertise. Expert reviewer status is per scope.
- **Verification vs staff permission:** no rider verification ever grants an administrative permission.
- **Published vs true:** publication means "passed moderation", never "confirmed fact".
- **Staff-curated vs rider-contributed:** destinations/routes/places are editorial records with a publish status; rider contributions and rider-submitted places go through moderation.
- **Destination vs place vs service vs segment vs itinerary stop:** destinations are areas riders travel to; places are points of interest (attractions, viewpoints, cultural sites, rider-suggested places); services are practical stops (fuel, repair, lodging, food stop, medical, ATM, connectivity, rest); segments are stretches of a route; itinerary stops are a rider's private plan entries that may point at any of them.
- **Map tiles vs geocoding vs routing:** three separate providers and settings; changing tiles gives no routing.
- **Estimate vs fact:** distances and times from a routing provider or a straight line are labelled estimates; nothing is invented.

## Owner decisions (2026-11-18)
1. Folders `web` and `admin`; GitHub repo names and remotes unchanged.
2. No shared code between the repos (no third repository or shared package). Under Option A each app keeps its own small `src/lib`.
3. Laravel 12 is the only backend (Fortify, Sanctum, Scribe, axios approved). Supabase is PostgreSQL only; the remote Supabase project is out of scope.
4. ~~Architecture Option B~~ (session 7) **replaced on 2026-11-18 by Option A (owner direction):** Laravel API at the `web` root, public website in `web/frontend/`, staff portal alone in `admin`, each independently runnable, built and deployed. Option B text kept for history: `web` = Laravel backend only, no frontend; `admin` = both Vue apps, `src/apps/public` (public website and rider platform) and `src/apps/admin` (staff dashboard and CMS), separate routes, layouts, navigation and guards.
5. Row-level security dropped in favour of Laravel policies plus database constraints.
6. Visual direction approved with refinements.
7. Vercel selected as the hosting target (session 5).
8. The six additions above are mandatory scope.
9. 2026-11-18 (owner): separate rider and staff authentication (own staff accounts, guard, session, login, reset, provisioning; explicit account linking only; staff 2FA optional and off until the owner decides), independent applications, dynamic homepage and full CMS are mandatory scope.

## Decisions still open
See Part 4 of the audit: D1 storage provider (+ `league/flysystem-aws-s3-v3`), D2 routing/geocoding providers, D3 PostGIS, D4 crawler-visible SEO for the SPA, D5 languages, D6 banner policy, D7 contact retention; Q2, Q4, Q6–Q11 from the first audit.

## Still open (operational)
- Push both repos so CI can run.
- WebKit system libraries (`sudo pnpm exec playwright install-deps webkit`).
- Production database (with PostGIS), domain, mail provider, backups, storage.
- Real content: destinations, routes, places, permits, guides written or checked by qualified people.

## Future recommendations (not in scope until approved)
1. CAPTCHA (privacy-friendly provider) on sign-up, reset and contact, and a breached-password check.
2. Two-factor authentication for staff accounts.
3. Email notification digests in addition to in-app notifications, using the queue.
4. Queued jobs for notifications and photo processing; Redis once more than one app server runs.

Moved into scope since the last version: Offline Ride Pack (always agreed, Stage 14), account deletion/export (Stage 13), saved items (N6), map tile provider (map settings, S10), automatic outdating (trust policy, Stage 6).
