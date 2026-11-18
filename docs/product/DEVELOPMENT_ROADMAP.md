# Development Roadmap

| # | Milestone | Status |
|---|---|---|
| M0 | Inspection, product docs, design system | Done (session 1) |
| M1 | Foundation: apps, tokens, layouts, auth screens, Explore | Done (session 1) |
| M1.5 | Local database, real auth, E2E, CI | Done on Supabase (session 2); **rebuilt on Laravel** (session 4) |
| M2 | Verification and organizer workflow, roles, audit | Done (session 2); **ported to Laravel** (session 4) |
| — | Architecture change: Laravel 12 API in `web`, public SPA in `web`, staff SPA in `admin`, Supabase as Postgres only | **Done locally (session 4)** |
| M3 | Routes, segments, services, road reports, moderation, photo uploads, maps | **Done locally (session 4)** |
| M4 | My Rides: personal and group trips (members, stops, budget, checklist), emergency contacts | **Done locally (session 4)** |
| M5 | Events: hosting, instant/approval joining, invitations, capacity, waitlist, updates, cancellation, attendance, staff oversight | **Done locally (session 4)** |
| M6 | Rider experiences: progressive contribution form, library, search, profiles | **Done locally (session 4)** |
| M7 | Preparation and safety: guides with sources and expert review; checklists in trips | **Done locally (session 4)**; no guide content written (must come from qualified people) |
| M8 | PWA Offline Ride Pack | Not started (was always planned for later; not in the session-4 scope list) |
| M9 | Final audit and launch readiness | Partially done; see `../audits/PROJECT_AUDIT_CHECKLIST.md` and `../architecture/DEPLOYMENT_READINESS.md` |

## Verification track (still open)
Screen-reader pass by a person; Lighthouse (performance and accessibility) on production builds; axe; WebKit (needs `sudo pnpm exec playwright install-deps webkit`); CI on GitHub; external security review.
