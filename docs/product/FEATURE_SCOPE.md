# Feature Scope

## In scope (initial product)
Six areas in `PRODUCT_SPECIFICATION.md`, rider accounts and profiles, claim-specific verification, moderation with audit trail, separate admin app, PWA offline Ride Pack (later milestone).

## Explicitly out of scope
| Excluded | Why |
|---|---|
| Payments, bookings, ticketing | Owner restriction; liability |
| Live GPS tracking, emergency dispatch, SOS | Cannot be delivered reliably; dangerous if implied |
| Live road or weather conditions | Only dated observations; no live feeds |
| Follower systems, likes, algorithmic feed | Not a social network |
| Vehicles other than motorcycles | Product focus |
| AI-generated travel content | Fabrication risk; category L of the audit stays "not applicable" until AI is proposed |
| Paid APIs or services | Owner restriction: free/open-source first, ask before any cost |
| Deployment, hosting, domains | Owner decides; not in this phase |

## Boundaries that are easy to blur
- **Group trip vs event:** a group trip is a private planning workspace of invited members. An event is hosted by a verified organizer, may be public, has registration rules and capacity.
- **Verified rider vs expert:** trip-contributor verification says nothing about mechanical, medical or road-safety expertise. Expert reviewer status is per scope.
- **Published vs true:** publication means "passed moderation", never "confirmed fact".
- **Staff-curated vs rider-contributed:** destinations/routes are editorial records with `publish_status`; rider contributions have `content_status` and moderation history.

## Owner decisions (2026-11-18)
1. Folders `web` and `admin`; GitHub repo names unchanged.
2. No shared code between the repos.
3. Laravel 12 is the only backend (Fortify, Sanctum, Scribe, axios approved). Supabase is PostgreSQL only; the remote Supabase project is out of scope.
4. The public site and rider platform live in `web` with the API; `admin` is staff-only.
5. Row-level security dropped in favour of Laravel policies plus database constraints.
6. Visual direction approved with refinements.

## Still open
- Push both repos so CI can run (admin E2E needs read access to Road-trip-web).
- WebKit system libraries (`sudo pnpm exec playwright install-deps webkit`).
- Hosting, domain, production mail provider, backups (cost decisions).
- Real content: destinations, routes, guides written or checked by qualified people.

## Future recommendations (not built; for owner approval)
1. Offline Ride Pack (PWA + IndexedDB) as originally planned (M8).
2. CAPTCHA (e.g. a privacy-friendly provider) on sign-up and reset, and a breached-password check.
3. Two-factor authentication for staff accounts.
4. Account deletion and data export for riders (privacy).
5. Saved destinations/routes ("save for later") and trip stops linked to route segments on a trip map.
6. Email notifications (digest) in addition to in-app notifications, using the queue.
7. Moving notifications and photo processing to queued jobs, and Redis once more than one app server runs.
8. A self-hosted or paid map tile provider before heavy use (OSM tile policy).
9. Automatic "outdated" marking for road reports after N days or a newer report on the same segment.
