# My Ride Nepal

A motorcycle travel discovery, trip-planning, road-information and rider-community platform for Nepal: destinations, routes and road segments with dated rider reports, places along a route, private and group trip planning, organized rides, and preparation guides.

**Status:** Phase 3 (project foundation) complete on 2026-11-27. No product features yet. Next: Phase 4, the backend foundation.

## Three separate repositories

The platform is three **separate git repositories**, connected **only through the HTTP API**. Each has its own dependencies, lockfile, `.env`, `.gitignore`, tests, build, CI workflow and deployment, and its own README with setup steps.

| Project | Repository (`develop`) | Stack | Dev URL |
|---|---|---|---|
| API + project docs | `sanjoprajol12/Road-trip-web` | Node 24, Express 5, TypeScript, Drizzle ORM, PostgreSQL 18 (owns the database and its local Docker setup) | http://localhost:4000 |
| Public website | `sanjoprajol12/Road-trip-front` | React 19, Vite 8, TypeScript, Tailwind 4 | http://localhost:5273 |
| Staff portal | `sanjoprajol12/Road-trip-admin` | React 19, Vite 8, TypeScript, Tailwind 4 | http://localhost:5274 |

Locally they sit side by side as `backend/`, `frontend/` and `admin/`. The web apps reach the API through `/api` on their own origin: the Vite proxy in development, and a host rewrite in production. In each repository, `npm run check` runs what its CI runs. The web apps' CI also starts an API from this repository and runs their browser tests against it over HTTP.

## Documentation
- Architecture: `docs/architecture/SYSTEM_ARCHITECTURE.md` · Decisions: `docs/architecture/TECHNICAL_DECISIONS.md`
- Requirements and traceability: `docs/architecture/REQUIREMENTS_INVENTORY.md`
- Roadmap: `docs/architecture/IMPLEMENTATION_ROADMAP.md` · Open questions and risks: `docs/architecture/RISKS_AND_OPEN_QUESTIONS.md`
- Database: `docs/database/DATABASE_DESIGN.md` · API: `docs/api/API_DESIGN.md` · Security: `docs/security/SECURITY_ARCHITECTURE.md` · Design: `docs/design/DESIGN_SYSTEM.md`
- Session state: `../handoff.md` · session reports: `../reports/sessions/`
