# Database Design (Laravel migrations, PostgreSQL 17)

Authority: `web/database/migrations` (Laravel). Local database `myridenepal` (tests: `myridenepal_test`) inside the Supabase CLI Postgres container. Requests connect as `mrn_app`; migrations as `mrn_owner`.

## Tables
| Table | Purpose | Key constraints |
|---|---|---|
| `users` | accounts and public profile (display name, username, bio, home city), suspension | UUID id; unique email; username format check; display-name length check |
| `sessions`, `password_reset_tokens`, `cache*`, `jobs*` | Laravel infrastructure | sessions.user_id FK cascade |
| `user_roles` | staff roles (moderator, admin) | PK (user_id, role); enum check |
| `user_verifications` | claim-specific verification incl. organizer applications | kind/status enums; scope only for experts (check); statement 20–2000 chars; **one open claim per user/kind/scope** (partial unique index) |
| `audit_logs` | append-only trail (action, subject, before/after JSON, actor, IP) | no FK on actor (survives account deletion) |
| `destinations` | editorial destinations, freshness (`last_confirmed_on`), dev-seed flag | unique slug + format; photo needs alt and credit; elevation range; soft delete |
| `routes`, `route_segments` | routes as ordered segments between checkpoints (lat/lng optional) | unique slug; unique (route, position); positive distances |
| `service_points` | fuel, mechanics, medical, lodging, food, ATM, connectivity, attached to a destination or segment | must reference one of them |
| `contributions` | every kind of rider knowledge (trip, road report, accommodation, fuel, mechanic, recommendation, unexpected, observation), observed date, details JSON, moderation status/note, source | body length check; **road reports must reference a segment**, and that segment cannot be deleted while reports exist (FK restrict) |
| `contribution_media` | photos (private disk path, size, alt) | FK cascade |
| `contribution_reports` | rider reports of wrong information | one per reporter per contribution |
| `events`, `event_registrations`, `event_updates` | hosted rides and gatherings, one registration row per rider (invited → … → attended), organizer updates | end after start; capacity ≥ 1; unique (event, user) |
| `trips`, `trip_members`, `trip_stops`, `trip_expenses`, `trip_checklist_items` | private personal and group trips | member PK (trip, user); amounts ≥ 0; date order check |
| `guides` | preparation and safety guidance with sources and expert review | unique slug |
| `emergency_contacts` | private per rider | FK cascade |
| `notifications` | Laravel database notifications | UUID morph |

## Choices
- One `contributions` table (not separate experiences/road reports): one moderation pipeline and status set.
- Checkpoints are segment endpoints (no separate table) until something needs to reference a checkpoint on its own.
- Organizer applications are `user_verifications` rows of kind `event_organizer`.
- Invitations are `event_registrations` rows in status `invited`.
- No RLS (Laravel is the only client); integrity rules live in constraints, business rules in Laravel.

## Seed
`database/seeders/DatabaseSeeder.php`: the four development destinations (Pokhara, Bandipur, Mustang, Rara) with factual names, provinces and districts only, flagged `is_dev_seed`. No routes, prices, services or reports are seeded.
