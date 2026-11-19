# Product Specification

Status legend: **Built** (exists and verified), **Designed** (spec + schema plan, not built), **Later**.

> **Update 2026-11-18 (session 7):** architecture Option B: Laravel API in `web`, both frontends in `admin` (`src/apps/public`, `src/apps/admin`). The owner added mandatory scope (CMS and settings, admin permissions, destination mapping, Explore Along My Route, saved services): see `../audits/COMPLETE_PRODUCT_REQUIREMENTS_AUDIT.md` Part 2. Status per requirement is in that audit, not in the tables below.
>
> **Update 2026-11-18 (session 4):** the six product areas are now built on the Laravel API (public SPA + staff SPA): Explore, Routes (map, segments, services, segment reports), Rider Experiences (progressive form, photos, library, search, profiles), Events & Rides (hosting, joining, invitations, capacity, waitlist, updates, attendance), Preparation & Safety (guides with sources and expert review, checklists, emergency contacts), My Rides (personal and group trips). Not built: Offline Ride Pack (PWA). The tables below are the original plan.

## 1. Explore Nepal
| Capability | Status | Notes |
|---|---|---|
| Destination directory, search, province filter | Built (M1) | Client-side filter; server search past ~200 rows |
| Destination detail with freshness block | Built (M1) | Shows "Never confirmed" when `last_confirmed_on` is null |
| Attractions, permits, seasonal info, approximate costs | Designed | Each fact carries source + last-confirmed date |
| Services (fuel, mechanics, medical, ATM, connectivity, lodging) | Designed | `services` table shared with Routes |
| Rider experiences per destination | Designed | From Rider Experiences |

## 2. Routes
Route directory and detail with Leaflet/OpenStreetMap map, ordered segments, checkpoints, distance and time estimates, services along the way, segment-level road reports with observation dates. **Designed.** Never guarantees safety, access or availability.

## 3. Rider Experiences
Contribution types: full trip, road condition report, accommodation, fuel observation, mechanic/repair, recommendation, unexpected situation, practical observation. Progressive forms: a road report is 4 fields; a full trip unfolds in steps (where/when, bike, segments, costs, problems, lessons, photos). **Designed.** All enter `pending_review`.

## 4. Events & Rides
See `EVENT_SYSTEM.md`. **Designed.** Only verified organizers host.

## 5. Preparation & Safety
Motorcycle, personal and trip checklists; breakdown/accident preparation; emergency contacts (stored privately per rider). Safety-critical technical guidance requires an expert reviewer in the matching scope plus a cited source before publishing. **Designed.**

## 6. My Rides
Private trips (destinations, dates, routes, stops, bike, budget, saved services, checklist, contacts, notes) and group trips (owner/editor/viewer members). Group trips are private workspaces, never public events. **Designed.** Offline Ride Pack (PWA + IndexedDB) shows last sync time and staleness warnings: **Later**.

## Cross-cutting
- Accounts: email + password via Supabase Auth, email confirmation, password reset. **Built (M1)**, live auth flow not yet run against a real Supabase instance.
- Profiles: display name, username, home city, bio; email never shown. **Built (M1)**.
- Trust, verification, moderation: `CONTRIBUTION_TRUST_POLICY.md`, `USER_ROLES_AND_PERMISSIONS.md`. Verification tables and RLS **Built (M1)**; review UI **Designed**.
- Notifications: in-app first; email later. **Designed.**
- Admin app: separate application (`admin`). Staff sign-in, role guard, overview counts **Built (M1)**.
