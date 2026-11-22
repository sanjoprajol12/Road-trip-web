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

## Session 16 additions (2026-11-20, additive migration `2026_11_20_100017_create_places`)
- `places`: slug (unique), name, category (check: viewpoint, lake, waterfall, temple, monastery, heritage, cultural, attraction, rest_stop, hidden_place, other), summary, description, `latitude`/`longitude numeric(9,6) not null` with a Nepal bounding check (26.3–30.5, 80.0–88.3), optional destination, cover `media_asset_id` + `gallery` jsonb (media ids, tracked in `media_usages`), status (draft, pending_review, changes_requested, published, rejected, archived), `moderation_note`, `submitted_by` (rider), `created_by_staff_id`, `reviewed_by_staff_id`, observed_on, last_confirmed_on, published_at, is_dev_seed. Sources through `sourceables` (alias `place`). No PostGIS: the activation plan adds a generated geography column from these coordinates later.
- `place_corrections`: rider-suggested corrections (text, optional proposed position with the same check), status pending/accepted/rejected, review note, reviewer.
- `contributions.place_id` (nullable FK, null on delete); `saved_items` type check now includes `place`.
- Permissions synced: `places.manage`, `places.publish` (not added to the moderator defaults; administrators hold every permission).
- Rollback: `down()` drops the two tables and the column, removes saved `place` rows and restores the old saved-items check.

## Session 17 additions (2026-11-20, additive migration `2026_11_20_100018_add_trip_places_and_segment_paths`)
- `trip_stops.place_id` (nullable FK, null on delete) with a partial unique index `trip_stops_one_place (trip_id, place_id) where place_id is not null`: a place can be a stop once per trip.
- `route_segments.path` (jsonb, nullable): the real recorded track `[[lat, lng], ...]` (2–5000 points, all inside Nepal), imported by staff from GPX or GeoJSON. Null means no real geometry: maps draw no line, only checkpoints; straight-line distance maths falls back to the start-to-end chord and is labelled as such.
- Rollback: `down()` drops the column, the index and the FK only.

## Session 19 additions (2026-11-21, additive migration `2026_11_21_100021_add_trip_route_parts`)
- `trip_stops.route_segment_id` (nullable FK to `route_segments`, null on delete) and `trip_stops.route_part` (varchar 20, check `route`, `stretch`, `checkpoint_start`, `checkpoint_end`; null for other stops). Design: `docs/architecture/TRIP_ROUTE_PARTS.md`.
- Partial unique indexes: `trip_stops_one_route (trip_id, route_id) where route_part = 'route'` (a whole route once per trip) and `trip_stops_one_route_part (trip_id, route_segment_id, route_part) where route_segment_id is not null` (a stretch or checkpoint once per trip). A checkpoint is stored once: the start of its stretch, or the end of the last stretch.
- Backfill: stops with only `route_id` become `route_part = 'route'` (0 rows in dev and E2E at migration time).
- Rollback: `down()` drops both indexes, the check and both columns; route stops then stay as named stops with `route_id`. Rollback and re-apply verified on `myridenepal_e2e`.

## Session 20 additions (2026-11-21, additive migration `2026_11_21_100022_add_trip_expense_status`)
- `trip_expenses.status` (varchar 10, default `planned`, check `planned` / `spent`). Existing rows became `planned`; the UI had always called them estimates.
- `trip_expenses.amount_npr` is now nullable: a planned cost may be unknown. The check `trip_expenses_spent_amount` (`status = 'planned' or amount_npr is not null`) keeps spent rows complete. The existing `amount_npr >= 0` check is unchanged. All amounts are NPR; there is no currency column.
- Rollback: `down()` refuses while any row has no amount, so it never invents one. Otherwise it drops both checks and the column and restores NOT NULL. Rollback and re-apply verified on `myridenepal_e2e`.
- No schema change for days, overnight stops or notes. Per-day totals are computed from `trip_stops.day` and recorded route values.

