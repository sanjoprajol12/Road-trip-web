# API Documentation

- Generated reference (Scribe, from routes and validation rules): `web/public/docs/index.html`, served at `/docs` by Laravel. OpenAPI: `public/docs/openapi.yaml`; Postman: `public/docs/collection.json`. Regenerate with `php artisan scribe:generate` (no live requests are made).
- Base path `/api/v1`. JSON in, JSON out. Errors `{message, errors?}`.
- Auth: Sanctum SPA cookies. Call `GET /sanctum/csrf-cookie`, then send `X-XSRF-TOKEN` on POST/PUT/PATCH/DELETE (axios does this automatically).
- Status codes: 401 not signed in · 403 not allowed (also unconfirmed email, suspended account) · 404 not found or not visible · 422 validation (field errors) · 423 password confirmation needed · 429 rate limited.
- Pagination: Laravel paginator (`data`, `links`, `meta` or `current_page`/`last_page`/`total`).

## Endpoint groups (96 routes)
| Group | Endpoints |
|---|---|
| Health | `GET /health` (database round trip) |
| Auth | `GET auth/session`, `POST auth/register`, `GET auth/verify-email/{id}/{hash}`, `POST auth/email/resend`, `POST auth/login`, `POST auth/logout`, `POST auth/forgot-password`, `POST auth/reset-password`, `PUT auth/password`, `POST auth/confirm-password`, `GET auth/confirmed-password-status` |
| Public | `GET destinations`, `destinations/{slug}`, `routes`, `routes/{slug}`, `contributions` (filters: kind, destination, route, segment, q), `contributions/{id}`, `media/{id}`, `events`, `events/{id}`, `guides`, `guides/{slug}`, `riders/{username}` |
| Me | `GET/PATCH users/me`, `GET users/me/sessions`, `DELETE users/me/sessions/others`, `GET/POST users/me/verifications`, `GET users/me/contributions`, `GET users/me/events`, `GET users/me/notifications`, `POST users/me/notifications/read`, CRUD `users/me/emergency-contacts` |
| Contributions | `POST contributions`, `PATCH contributions/{id}`, `POST contributions/{id}/media`, `POST contributions/{id}/reports` |
| Events | `POST events`, `PATCH events/{id}`, `POST events/{id}/status`, `POST events/{id}/join`, `POST events/{id}/leave`, `GET events/{id}/registrations`, `POST events/{id}/registrations/{reg}`, `POST events/{id}/invitations`, `POST events/{id}/updates` |
| Trips | `GET/POST trips`, `GET/PATCH/DELETE trips/{id}`, `POST trips/{id}/members`, `PATCH/DELETE trips/{id}/members/{user}`, `POST/PATCH/DELETE trips/{id}/{stops|expenses|checklist}[/{itemId}]` |
| Guides | `POST guides/{id}/expert-review` |
| Admin (staff) | `GET admin/overview`; `GET admin/verifications`, `GET admin/verifications/{id}`, `POST admin/verifications/{id}/decision`; `GET admin/contributions`, `POST admin/contributions/{id}/decision`; `GET admin/events`, `POST admin/events/{id}/cancel`; CRUD `admin/destinations`, `admin/routes` (+ `PUT admin/routes/{id}/segments`), `admin/service-points`, `admin/guides` (+ `POST admin/guides/{id}/publish`); `GET admin/users`, `POST/DELETE admin/users/{id}/suspension` |
| Admin (administrators) | `POST admin/users/{id}/roles`, `DELETE admin/users/{id}/roles/{role}` (both need password confirmation), `GET admin/audit-logs` |
