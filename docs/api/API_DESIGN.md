# API Design

Status: **approved** direction (2026-11-27). The generated OpenAPI document (`backend/openapi.json`, TD-12) will be the exact reference. This file sets the conventions and the endpoint map.

## 1. Basics

| Item | Rule |
|---|---|
| Base path | `/api/v1` |
| Format | JSON (`application/json; charset=utf-8`); uploads use `multipart/form-data` |
| Field names | `camelCase` |
| Dates | ISO 8601: timestamps in UTC with `Z` (`2026-11-27T08:15:00Z`); plain dates as `YYYY-MM-DD` |
| IDs | UUID strings; public pages use slugs |
| Auth | session cookie (rider or staff, by path); `X-CSRF-Token` header on POST/PUT/PATCH/DELETE |
| Versioning | breaking changes go to `/api/v2` and run in parallel until both frontends have moved; additive changes (new fields, new endpoints) stay in v1 |

## 2. Audiences (route classification)

Every route belongs to exactly one class. A test fails if any route is unclassified (G15).

| Class | Path prefix | Guard |
|---|---|---|
| public | `/api/v1/...` (GET) | none; published content only |
| rider-account | `/api/v1/auth/*`, `/api/v1/me/*` | rider session (unconfirmed or suspended riders can still read and manage their own account) |
| rider-write | `/api/v1/me/*`, `/api/v1/...` (unsafe methods) | rider session + confirmed email + active + (for contributions) not contribution-suspended |
| staff | `/api/v1/admin/*` | staff session + the route's named permission (+ password confirmation for privilege changes) |
| system | `/health/*` | none, no data |

Rider sessions get 401 on every `/admin` route, and staff sessions get 401 on every rider route (SA-2).

## 3. Responses

**Single resource:** `{ "data": { … } }`

**List:**
```json
{ "data": [ … ], "meta": { "page": 2, "perPage": 20, "total": 134 } }
```
Paging uses `?page=` and `?perPage=` (default 20, max 100). Filters are query parameters named after the field (`?province=gandaki&status=published`), and sorting uses `?sort=name` or `?sort=-observedOn`. Unknown parameters are rejected with 400, so a typo fails loudly. Cursor paging is added only to a list that measurably needs it (notifications, audit log).

**Error:**
```json
{
  "error": {
    "code": "validation_failed",
    "message": "Some fields need attention.",
    "fields": { "observedOn": ["Cannot be in the future."] },
    "requestId": "01JD…"
  }
}
```

| Status | `code` | When |
|---|---|---|
| 400 | `validation_failed` / `bad_request` | invalid body, query or parameters |
| 401 | `unauthenticated` | no valid session for this audience |
| 403 | `forbidden` / `email_unconfirmed` / `account_suspended` / `contribution_suspended` / `csrf_failed` / `password_confirmation_required` | signed in but not allowed |
| 404 | `not_found` | does not exist **or** the caller may not know it exists (another rider's trip, a private event) |
| 409 | `conflict` / `invalid_transition` / `already_decided` | state changed, duplicate, illegal transition |
| 413 | `payload_too_large` | upload limits |
| 422 | `business_rule` | well-formed but breaks a rule (event full, last administrator) |
| 429 | `rate_limited` | with a `Retry-After` header |
| 500 | `internal_error` | message is generic; details only in logs, linked by `requestId` |

Responses are shaped by a **response schema per endpoint**, which lists the allowed fields. A field is sent only if it is in that schema, so emails, reviewer identities and private notes cannot leak by accident (RK-08).

## 4. Behaviour rules

- **Idempotency through constraints:** joining an event twice, saving an item twice or confirming twice on the same day returns the existing state, never a duplicate row.
- **Concurrent decisions:** state-changing staff actions re-check the current status under a row lock; a stale decision returns 409 `already_decided`.
- **Ownership:** child records are always looked up through their parent and the session user (`/me/trips/:tripId/stops/:stopId` → trip membership first). A foreign id answers 404.
- **Estimates:** values from a routing provider or straight-line maths carry a `kind: "estimate" | "recorded" | "staff"` marker, so the UI can label them (R16).
- **Freshness:** every fact or observation returns `observedOn` and/or `lastConfirmedOn`, never an "is current" flag (B10).
- **Caching:** public GET responses send `Cache-Control: public, max-age=60` plus an ETag; anything that depends on the session sends `private, no-store`.

## 5. Endpoint map (by stage)

Paths are listed without `/api/v1`. "R" = rider session, "perm" = staff permission. This is the plan; it is finalised stage by stage.

### Foundation
| Method | Path | Who |
|---|---|---|
| GET | `/health/live`, `/health/ready` | anyone |
| GET | `/docs` (non-production only) | anyone |

### S1 Rider authentication and account
| Method | Path | Who |
|---|---|---|
| GET | `/auth/session` | anyone (rider or `null`, + CSRF token) |
| POST | `/auth/register`, `/auth/login`, `/auth/logout` | anyone / R |
| POST | `/auth/email/verify`, `/auth/email/resend` | anyone / R |
| POST | `/auth/password/forgot`, `/auth/password/reset` | anyone |
| PUT | `/me/password` | R (current password) |
| POST | `/auth/confirm-password` | R |
| GET, PATCH | `/me/profile` | R |
| GET | `/me/sessions`, `/me/login-activity` | R |
| DELETE | `/me/sessions/others`, `/me/sessions/:id` | R |
| GET | `/riders/:username` | public profile |

### S1 Staff authentication, staff, roles, audit
| Method | Path | Permission |
|---|---|---|
| GET | `/admin/auth/session` | staff session or `null` |
| POST | `/admin/auth/login`, `/admin/auth/logout`, `/admin/auth/password/forgot`, `/admin/auth/password/reset`, `/admin/auth/confirm-password` | — |
| POST | `/admin/auth/invitations/accept` | invitation token |
| POST, DELETE | `/admin/auth/rider-link` | staff session (+ rider password) |
| GET, POST | `/admin/staff`, `/admin/staff/invitations` | `users.view` / `users.invite` |
| PATCH | `/admin/staff/:id/status`, PUT `/admin/staff/:id/roles` | `users.activate`, `users.roles.manage` (+ confirmation) |
| POST | `/admin/staff/:id/password-reset`, `/admin/staff/:id/sessions/revoke` | `users.password_reset`, `users.sessions.revoke` |
| GET, POST, PATCH, DELETE | `/admin/roles`, `/admin/roles/:id` | `roles.manage` |
| GET | `/admin/permissions` | `roles.manage` |
| GET | `/admin/riders`, `/admin/riders/:id` | `users.view` |
| POST | `/admin/riders/:id/suspend`, `/reinstate`, `/deactivate`, `/password-reset`, `/sessions/revoke` | `users.suspend`, `users.activate`, … |
| GET | `/admin/audit-logs` | `audit.view` |
| GET | `/admin/overview` | `overview.view` |

### S2 Settings and media
| Method | Path | Permission |
|---|---|---|
| GET | `/site` (public settings: name, branding, menus, notices, maintenance flag) | public |
| GET, PUT | `/admin/settings/:group` | `settings.<group>.manage` |
| POST | `/admin/settings/mail/test`, `/admin/settings/storage/test` | same |
| POST | `/admin/preview-token` | staff session |
| GET, POST, PATCH, DELETE | `/admin/media`, `/admin/media/:id` | `media.manage` |

### S3 Verification claims
| Method | Path | Who |
|---|---|---|
| GET, POST | `/me/verification-claims` | R |
| GET | `/admin/verification-claims` | `verification.view` |
| POST | `/admin/verification-claims/:id/decision` | `verification.decide` / `verification.decide_sensitive` |

### S4–S5 Destinations, routes, services
| Method | Path | Who |
|---|---|---|
| GET | `/destinations`, `/destinations/:slug` (facts, sources, media, nearby) | public |
| GET | `/routes`, `/routes/:slug` (segments, services, recent reports) | public |
| GET | `/provinces`, `/districts` | public |
| CRUD | `/admin/destinations…`, `/admin/destinations/:id/facts…`, `/admin/sources…` | `content.destinations.manage` |
| CRUD | `/admin/routes…`, `/admin/routes/:id/segments…`, PUT `/admin/routes/:id/segments/order`, POST `/admin/routes/:id/segments/:segId/track` (GPX/GeoJSON) | `content.routes.manage` |
| CRUD | `/admin/service-points…` | `content.services.manage` |

### S6 Contributions and moderation
| Method | Path | Who |
|---|---|---|
| GET | `/contributions` (filters: destination, route, segment, place, kind), `/contributions/:id` | public (visible statuses) |
| GET, POST | `/me/contributions` | R (write) |
| PATCH, DELETE | `/me/contributions/:id` | author, while draft or pending |
| POST | `/me/contributions/:id/media` | author |
| POST | `/contributions/:id/reports`, `/contributions/:id/confirmations` | R (write) |
| POST | `/me/contributions/:id/correction` | author, when a correction was requested |
| GET | `/admin/moderation/queue` | `contributions.moderate` |
| POST | `/admin/contributions/:id/decision` (approve, reject, request_correction, flag, mark_official, mark_outdated, confirm) | `contributions.moderate` |
| GET | `/admin/contributions/:id/history` | `contributions.moderate` |
| POST, DELETE | `/admin/riders/:id/contribution-suspension` | `users.suspend` |

### S7 Notifications
| Method | Path | Who |
|---|---|---|
| GET | `/me/notifications` (cursor), `/me/notifications/unread-count` | R |
| POST | `/me/notifications/read` (ids or all) | R |

### S8 Places and Explore Along My Route
| Method | Path | Who |
|---|---|---|
| GET | `/places`, `/places/:slug` | public |
| GET | `/journeys/along?routeId=…&maxDetourKm=…&categories=…` | public |
| GET | `/journeys/alternatives?from=…&to=…` | public (needs Q-14) |
| POST | `/me/place-suggestions`, PATCH `/me/place-suggestions/:id` | R (write) |
| POST | `/places/:id/corrections` | R (write) |
| CRUD | `/admin/places…`; GET/POST `/admin/place-suggestions…`, `/admin/place-corrections…` | `places.manage`, `places.publish`, `places.review` |

### S9 Trips, saved items, emergency contacts
| Method | Path | Who |
|---|---|---|
| GET, POST | `/me/trips` | R |
| GET, PATCH, DELETE | `/me/trips/:tripId` | member (owner to delete) |
| CRUD | `/me/trips/:tripId/stops`, `/expenses`, `/checklist`, PUT `/me/trips/:tripId/stops/order` | editor+ (viewer reads) |
| PUT | `/me/trips/:tripId/my-notes` | member, own notes only |
| GET, POST, DELETE | `/me/trips/:tripId/members`, `/invitations` | owner |
| GET | `/me/trip-invitations`; POST `/me/trip-invitations/:id/accept`, `/decline` | invitee |
| POST | `/me/trips/:tripId/leave` | member (not the sole owner) |
| GET, POST, DELETE | `/me/saved-items` | R |
| CRUD | `/me/emergency-contacts` | R (own only) |

### S10 Events
| Method | Path | Who |
|---|---|---|
| GET | `/events`, `/events/:id` | public (public events) / involved riders |
| POST, PATCH | `/me/hosted-events`, `/me/hosted-events/:id` | verified organizer |
| POST | `/me/hosted-events/:id/publish`, `/postpone`, `/cancel`, `/complete`, `/updates`, `/invitations` | organizer |
| POST | `/me/hosted-events/:id/registrations/:regId/decision`, `/attendance` | organizer |
| POST | `/events/:id/join`, `/events/:id/cancel-registration`, `/me/event-invitations/:id/accept`, `/decline` | R (write) |
| GET | `/me/events?role=organizing|participating` | R |
| GET, POST | `/admin/events`, `/admin/events/:id/cancel` | `events.view`, `events.cancel` |

### S11 Preparation and safety
| Method | Path | Who |
|---|---|---|
| GET | `/guides`, `/guides/:slug`, `/checklist-templates` | public |
| CRUD | `/admin/guides…`; POST `/admin/guides/:id/publish` | `guides.manage`, `guides.publish` |
| GET, POST | `/me/expert-reviews`, `/me/expert-reviews/:guideId` | verified expert in the guide's field, not the author |

### S12 CMS and homepage
| Method | Path | Who |
|---|---|---|
| GET | `/home`, `/pages/:slug`, `/faqs`, `/blog`, `/blog/:slug`, `/team`, `/menus`, `/notices`, `/popups` | public (published, within schedule) |
| POST | `/contact` | anyone (rate limited; CAPTCHA if Q-25) |
| CRUD | `/admin/cms/:type…`, POST `/admin/cms/:type/:id/publish`, `/archive`, `/schedule` | `cms.<module>.manage` + `cms.content.publish` |
| GET, PUT | `/admin/home/sections…`, POST `/admin/home/publish` | `cms.home.edit`, `cms.home.publish` |
| CRUD | `/admin/menus…`, `/admin/notices…` | `cms.menus.manage`, `cms.notices.manage` |
| GET, PATCH, DELETE | `/admin/contact-messages…` | `contact.view`, `contact.manage` |

### S13 Privacy
| Method | Path | Who |
|---|---|---|
| GET | `/me/export` | R (password confirmed) |
| POST, DELETE | `/me/deletion-request` | R (password confirmed) |
| GET | `/admin/deletion-requests` | `users.deletions.view` |

## 6. Rate limits (initial values)

| Route group | Limit |
|---|---|
| `POST /auth/login`, `/admin/auth/login` | 5 per minute per email + IP |
| `POST /auth/register` | 5 per hour per IP |
| password forgot / email resend | 5 per 10 minutes per email + IP |
| contribution create | 30 per day per rider |
| reports, confirmations | 30 per day per rider |
| uploads | 60 per hour per account |
| `POST /contact` | 5 per hour per IP |
| everything else | 120 per minute per session or IP |

## 7. Documentation

The OpenAPI document is generated from the route schemas (TD-12), served at `/api/docs` outside production, and committed as `backend/openapi.json`. Both frontends generate their types from it. CI regenerates it and fails if the committed copy is out of date.
