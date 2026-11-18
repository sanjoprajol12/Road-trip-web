# Backend Architecture (Laravel 12, `web/`)

## Request path
`routes/api.php` (`/api/v1`) → middleware (Sanctum stateful API, `throttle:*`, `auth:sanctum`, `verified`, `not-suspended`, `can:staff|admin`, `password.confirm`, `SessionMaxAge`, `SecurityHeaders`) → controller (validation) → policy check → action (transaction + audit) → API resource (response shape).

## Folders
| Folder | Contents |
|---|---|
| `app/Http/Controllers/Api/V1` | public and rider controllers: Auth, Profile, Session, Verification, Destination, Route, Contribution, Event, EventRegistration, Trip, TripPart, Guide, EmergencyContact, Notification, Health |
| `app/Http/Controllers/Api/V1/Admin` | staff controllers: Overview, Verification, Contribution (moderation), Event (oversight), Destination, Route (+segments), ServicePoint, Guide, User (search, roles, suspension), AuditLog |
| `app/Actions` | business rules in transactions: `Verification/ApplyForVerification`, `Verification/DecideVerification`, `Users/ChangeStaffRole`, `Users/SuspendUser`, `Contributions/ModerateContribution`, `Contributions/StoreContributionPhoto`, `Events/EventRegistrations`, `Events/EventCapacity`, `Fortify/*` |
| `app/Policies` | Verification, Contribution, Event, Trip, Guide (auto-discovered) |
| `app/Enums` | every closed value set (roles, claim kinds/statuses, contribution kinds/statuses, event/registration statuses, trip roles, guide categories) with transition rules |
| `app/Http/Resources` | response shapes; `PublicUserResource` never exposes email; `VerificationResource` never exposes the reviewer |
| `app/Http/Middleware` | `SecurityHeaders`, `EnsureNotSuspended`, `SessionMaxAge` |
| `app/Notifications` | `VerifyEmailAddress` (mail), `VerificationDecided`, `EventInvitation`, `EventChanged` (database) |
| `app/Support/Audit.php` | append-only audit writer |
| `config/mrn.php` | project settings (sign-up limit, absolute session lifetime, upload limits) |

## Conventions
- Validation in the controller (`$request->validate`) with readable messages; enums validated with `Rule::enum`.
- Every state change that matters is an action wrapped in `DB::transaction`, writing its audit row in the same transaction.
- Concurrency: rows that gate a decision are locked (`lockForUpdate`): verification decisions, event capacity, admin-role removal.
- IDOR: child records are always looked up through their parent (`$trip->stops()->whereKey($id)`), never by bare id.
- Errors: JSON for every `/api` request, `{message, errors?}`; 401/403/404/422/423/429 as usual; no stack traces outside debug.
- Notifications run synchronously today (`QUEUE_CONNECTION=database` is configured; no notification is queued yet).

## Tests (`tests/Feature`, PHPUnit, real PostgreSQL `myridenepal_test`)
AuthTest (17), VerificationTest (8), StaffAccessTest (7), EventTest (7), TripTest (4), ContributionTest (6), ContentTest (5). Run: `php artisan test`.
