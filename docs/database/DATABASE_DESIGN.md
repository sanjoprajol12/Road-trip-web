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

## Session 12 additions (2026-11-19, additive migrations)
- `2026_11_19_100013_create_trust_pipeline`: `contributions.last_confirmed_on`, `correction_request`, `correction_requested_at`, `author_correction`, `author_corrected_at`, `author_correction_reviewed_at` (all nullable); `contribution_reports.category` (check: dangerous, wrong, outdated, spam, offensive, other; default `other` for old rows), `resolution` (upheld/dismissed); `contribution_confirmations` (unique per contribution, rider, day); `sources` (title, publisher, url with http(s) check, accessed_on) and `sourceables` (source, morph type `destination`/`route`/`contribution`, id, position). Rollback: `down()` drops only these.
- `2026_11_19_100014_create_saved_items`: `saved_items` (user, item_type check destination/route/service/contribution, item_id; unique per rider and item; cascade on user delete); `trip_stops.service_point_id` (nullable FK, null on delete). Rollback: `down()` drops only these.
- Status transitions are enforced in `App\Enums\ContributionStatus::next()` (rejected is final).

## Session 13 additions (2026-11-19, additive migrations)
- `2026_11_19_100015_create_cms_content`: `cms_entries` (type check: page, faq, post, blog_category, blog_tag, author, team_member, popup, banner; unique (type, slug) where slug is set; status draft/published/archived; publish_at/expires_at with a check; jsonb `data` validated by `config/cms.php`; nullable FK to `media_assets`), `contact_messages` (status new/open/done/spam; `ip_hash` is an HMAC of the address, never the address). Syncs the permission registry.
- `2026_11_19_100016_create_trip_invitations_and_contribution_suspensions`: `trip_invitations` (one pending per trip and rider, 14-day `expires_at`, status pending/accepted/declined/cancelled), `contribution_suspensions` (until, reason, lift note, one appeal and its decision; separate from `users.suspended_until`).
- `media_usages` is now written (CMS entries, homepage sections, settings `*_media_id`), so the media library refuses to delete images in use. It was never written before session 13.
- Rollback: each `down()` drops only its own tables.
