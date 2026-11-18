# Design Progress

| Date | Screen | State | Evidence |
|---|---|---|---|
| 2026-11-18 | Home (hero, route diagram, destinations, freshness band) | Built | Screens at 390/768/1440: 0 overflow, 1 h1, 0 console errors. Fixed after first review: headline overlapping the bike at 1440 (max 11ch, smaller clamp), weak hero contrast on phones (extra scrim below md), freshness band merging with footer (border) |
| 2026-11-18 | Explore (search, province filter, photo plates, typographic plate for Mustang) | Built | same sweep |
| 2026-11-18 | Destination detail (+ not found) | Built | same sweep |
| 2026-11-18 | Sign in, sign up, forgot, reset, account | Built | layout sweep only; live auth not run |
| 2026-11-18 | 404 | Built | sweep |
| 2026-11-18 | Admin sign-in, no access, overview shell | Built | sweep at 3 widths; overview with live data not run (needs staff session) |

Open design questions for the owner: crimson + marigold palette, Archivo condensed headlines, the route-node motif. Screenshots: see the 2026-11-18 session report.
| 2026-11-18 (s2) | Explore as editorial index; crimson reduced; route line draw-in; signed-in header on phones | Built | 390/768/1440: 0 overflow, 1 h1, 0 console errors |
| 2026-11-18 (s2) | Account: verification section (claims, statuses with icon + words, reviewer's note, application form) | Built | E2E (Chromium, Firefox, Pixel 7) + screenshots at 390/1440 |
| 2026-11-18 (s2) | Admin: sidebar nav, verification queue, claim detail with history and decision flow, roles, audit log | Built | E2E (Chromium, Firefox) + screenshots at 390/1440, 0 overflow after fixing a sidebar width bug, a stretched header row and an escaping sr-only table header |
| 2026-11-18 (s4) | Web SPA moved into the Laravel repo (resources/app); new pages: Routes (Leaflet map, stretch-by-stretch reports), Experiences (library, detail, progressive contribute form with photos), Events (list, detail, host form, organizer workspace), Preparation (guides), My Rides (trip workspace), rider profiles, account hub (notifications, contributions, emergency contacts, password and sessions); header with six areas and a phone menu | Built | 13 pages × 390/768/1440: 0 overflow, 1 h1, 0 console errors (besides a real 404 for a missing rider) |
| 2026-11-18 (s4) | Admin SPA: overview, verification, moderation, destinations, routes + segment editor + service points, events oversight, guides, people (roles with password confirmation, suspension), audit log | Built | 10 pages × 390/1440 signed in: 0 overflow, 1 h1, 0 console errors |
