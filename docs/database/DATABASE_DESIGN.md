# Database Design

Status: **approved** direction (2026-11-27). Tables are created stage by stage (Phase 4 onward). The tables are derived from `docs/architecture/REQUIREMENTS_INVENTORY.md` (row IDs in brackets). Columns are listed where they carry a rule; the full DDL is written as migrations in the stage that needs each table, not up front.

## 1. Principles

1. **The database enforces integrity; the backend enforces business rules.** Foreign keys, CHECK constraints and unique and partial unique indexes catch whatever a bug in the service layer lets through. Permissions and workflows live in services.
2. **Real foreign keys instead of polymorphic links.** Where a row can point at one of several kinds of record (saved items, sources, trip stops), it gets one nullable FK column per kind plus `CHECK (num_nonnulls(...) = 1)`. Deleting a referenced row is then handled by the database (`RESTRICT` or `SET NULL`) instead of leaving orphans [H11].
3. **Media references are FKs too.** No image id is ever hidden inside JSON. An image in use cannot be deleted, because the FK says `ON DELETE RESTRICT` [CM12].
4. **Append-only history.** `audit_logs` can be inserted and read by the app role, never updated or deleted [H7].
5. **Least privilege.** The API connects as `mrn_app` (DML only). Migrations run as `mrn_owner`. Grants live inside migrations (TD-05) [X3].
6. **No invented data.** The only seed data is factual reference data (Nepal's 7 provinces and 77 districts, with the source cited in the migration) and clearly flagged development rows (`is_dev_seed = true`) [A13].

## 2. Conventions

| Item | Rule |
|---|---|
| Keys | `id uuid primary key default uuidv7()`; `audit_logs` and `login_events` use `bigint generated always as identity` (high volume, never exposed) |
| Names | plural `snake_case` tables; FK columns `<thing>_id`; check constraints `<table>_<rule>`; indexes `<table>_<cols>_idx` |
| Time | `created_at`/`updated_at timestamptz not null default now()`; business dates as `date` (`observed_on`, `starts_on`) |
| Enums | `text` + CHECK constraint (easier to change in a migration than PostgreSQL enum types) |
| Money | `numeric(12,2)` NPR, column suffix `_npr`, `CHECK (… >= 0)` |
| Coordinates | `latitude numeric(9,6)`, `longitude numeric(9,6)`, CHECK inside Nepal (26.3–30.5 N, 80.0–88.3 E); generated `geog geography(Point,4326)` if Q-15 approves PostGIS |
| Text limits | CHECK on length for every free-text field that riders write |
| Emails | stored lower-cased; unique index on the column |
| Soft delete | `deleted_at` only on editorial content (destinations, routes, places, guides, CMS); rider data is really deleted |
| Freshness | `last_confirmed_on date` on everything that states a fact; `is_dev_seed boolean not null default false` on editorial content |

## 3. Tables by module

### 3.1 Identity: riders [J2–J4, G1, G2, G18]
| Table | Purpose and rules |
|---|---|
| `users` | rider accounts and public profile. `email` unique; `password_hash`; `email_verified_at`; `display_name` (2–60); `username` unique, CHECK `^[a-z0-9_]{3,30}$`; `home_city`; `bio` (≤ 500); `status` active/suspended/deactivated + `status_reason`; `deletion_scheduled_for` [G19] |
| `rider_sessions` | `id` = SHA-256 of the cookie token; `user_id` FK cascade; `csrf_token_hash`; `idle_expires_at`; `absolute_expires_at`; `user_agent` (truncated); index on `user_id` |
| `rider_tokens` | one-time tokens: `purpose` email_verify / password_reset; `token_hash` unique; `expires_at`; `used_at`. A new token of a purpose voids older ones |
| `login_events` | sign-in history for both audiences: `account_type`, `account_id` (no FK, kept after deletion until retention), `outcome`, `ip_hmac`, `user_agent`, `occurred_at`. 90-day retention (Q-05) |

### 3.2 Identity: staff and RBAC [SA-*, P1–P12] (separate accounts, confirmed Q-02)
| Table | Purpose and rules |
|---|---|
| `staff_accounts` | `email` unique; `password_hash`; `name`; `status` active/suspended/deactivated; `linked_user_id` FK users, unique, `SET NULL` (only feeds no-self-review [G13]); `mfa_secret_enc`, `mfa_enrolled_at` [P12] |
| `staff_sessions`, `staff_tokens` | as for riders, own tables; shorter lifetimes |
| `staff_invitations` | `email`, `token_hash`, `invited_by_staff_id`, `expires_at` (72 h), `accepted_at`, `revoked_at`; partial unique: one open invitation per email |
| `staff_invitation_roles` | roles to grant on acceptance |
| `roles` | `slug` unique, `name`, `is_system` (built-in `admin`, `moderator` cannot be deleted) |
| `permissions` | `key` primary key (e.g. `events.cancel`), `module`, `description`; synced from the code registry by a migration |
| `role_permissions` | (`role_id`, `permission_key`); `admin` is checked in code as "every permission", so new permissions need no row |
| `staff_account_roles` | (`staff_account_id`, `role_id`), several per person [P3] |
| `staff_recovery_codes` | hashed one-time MFA recovery codes [P12, S14] |

### 3.3 Verification claims [G5–G14, D2, D3]
| Table | Purpose and rules |
|---|---|
| `verification_claims` | `user_id`; `kind` trip_contributor/event_organizer/expert_reviewer/identity; `field` mechanical/medical/road_safety, CHECK only for experts; `statement` (20–2000); `status` pending/approved/rejected/suspended/revoked; `decided_by_staff_id`, `decided_at`, `decision_reason` (≥ 10 for negative decisions). **Partial unique** (`user_id`, `kind`, `coalesce(field,'')`) where status in (pending, approved, suspended): one open claim per kind and field |

Claim history is in `audit_logs` (every decision writes an entry).

### 3.4 Geography and destinations [A1–A14, L1–L14]
| Table | Purpose and rules |
|---|---|
| `provinces`, `districts` | reference data (7 and 77 rows, source cited); `districts.province_id` FK |
| `destinations` | `slug` unique; `name`; `province_id`, `district_id` FKs; `locality`; `summary`; `description`; coordinates; `elevation_min_m` ≤ `elevation_max_m`; `status` draft/published/archived; `cover_media_id` FK restrict; freshness and dev-seed columns. **CHECK:** published ⇒ coordinates, province and description are not null [L14] |
| `destination_media` | gallery (`destination_id`, `media_id`, `position`); alt and credit live on the media row [L7] |
| `destination_facts` | structured practical information [A3, A6, A8, A9]: `kind` permit/season/expense/practical; `title`; `body`; `months smallint[]` (seasons, CHECK 1–12); `amount_min_npr` ≤ `amount_max_npr` (estimates); `last_confirmed_on`; `position` |
| `sources` | citations [A11, H3, E11]: `title`, `publisher`, `url` (CHECK `^https?://`), `accessed_on`, and **exactly one** of `destination_id`, `destination_fact_id`, `route_id`, `place_id`, `contribution_id`, `guide_id` (`num_nonnulls = 1`) |

### 3.5 Routes and services [B1–B13, L5, L6]
| Table | Purpose and rules |
|---|---|
| `routes` | `slug` unique; `name`; `summary`; `start_destination_id`, `end_destination_id` FKs; `difficulty` (Q-07); `status`; freshness and dev-seed columns |
| `route_segments` | `route_id` FK cascade; `position`; unique (`route_id`, `position`) **deferrable**, so reordering in one transaction works [B12]; `start_label`, `end_label`; start and end coordinates; `distance_km > 0`; `estimated_minutes` (staff-entered or null, never computed silently [B5, R16]); `surface`; `path` (recorded track: jsonb array, or a PostGIS `geography(LineString)` if Q-15). A segment with reports cannot be deleted (FK restrict from contributions) |
| `service_points` | `kind` fuel/food/accommodation/rest/repair/medical/atm/connectivity (`rest`: Q-12); `name`; coordinates; `destination_id` and/or `segment_id` (CHECK at least one); `details`; `status`; `last_confirmed_on` |

Checkpoints are segment endpoints; they get their own table only when something needs to reference a checkpoint on its own.

### 3.6 Places [R2, R3, R9, R12, R13, A4, L4]
| Table | Purpose and rules |
|---|---|
| `places` | `slug`; `name`; `category` viewpoint/lake/waterfall/temple/monastery/heritage/cultural/attraction/rest_stop/hidden_place/other; `summary`; `description`; coordinates **not null**; `destination_id` optional; `status` draft/pending_review/changes_requested/published/rejected/archived; `submitted_by_user_id` (rider suggestion, set null on delete); `created_by_staff_id`; `reviewed_by_staff_id`; `moderation_note`; `observed_on`; freshness; dev-seed |
| `place_media` | gallery |
| `place_corrections` | rider-proposed corrections: text, optional proposed coordinates, `status` pending/accepted/rejected, `review_note`, reviewer. Accepting never changes the place automatically: staff edit it |

"Along the route" [R5, R7]: with PostGIS, `ST_DWithin(place.geog, segment.path, :km * 1000)` plus `ST_LineLocatePoint` for ordering, over a GiST index on `places.geog`. Without PostGIS, a bounding-box prefilter in SQL plus a distance calculation in the service (Q-15).

### 3.7 Contributions and trust [C1–C19, H1–H12, B9–B11]
| Table | Purpose and rules |
|---|---|
| `contributions` | one table for every kind (one moderation pipeline). `kind` trip/road_report/accommodation/fuel/mechanic/recommendation/observation/unexpected/lesson; `author_id` FK set null **only** with `author_deleted_at` (CHECK); `title`; `body` (length CHECK); `details` jsonb (validated per kind by Zod: bike, duration, costs, problems, lessons) [C8–C10]; `observed_on` ≤ submission date in Asia/Kathmandu (CHECK) [C7]; `submitted_at`; `status` draft (Q-12)/pending_review/published/community_corroborated/officially_sourced/outdated/rejected/flagged; `moderation_note` (staff, separate from the rider's text) [H6]; links: `destination_id`, `route_id`, `segment_id` (restrict), `service_point_id`, `place_id`; **CHECK:** road_report ⇒ `segment_id` not null; `last_confirmed_on`, `staff_confirmed_on` [H4]; correction fields `correction_request`, `author_correction`, timestamps |
| `contribution_media` | photos (`contribution_id` cascade, `media_id`, `position`) |
| `contribution_reports` | rider reports [C17]: `category` dangerous/wrong/outdated/spam/offensive/other; `status` open/upheld/dismissed; unique (`contribution_id`, `reporter_id`) |
| `contribution_confirmations` | "still accurate" confirmations [C14, H4]: unique (`contribution_id`, `user_id`, `confirmed_on`); the author cannot confirm their own (service rule) |
| `contribution_suspensions` | contribution-only suspensions [H9, Q-08]: `user_id`, `until`, `reason`, `lifted_at`, `appeal`, `appeal_decision` |

Moderation history [H5] = the `audit_logs` entries for the contribution. Allowed status transitions are a table in the service, unit-tested; `rejected` is final.

### 3.8 Events [D1–D21]
| Table | Purpose and rules |
|---|---|
| `events` | `organizer_id` FK (null only on a cancelled event, CHECK); `title`; `category` ride/gathering + `subtype`; `description`; `starts_at` < `ends_at`; `meeting_point`; `visibility` public/private; `join_method` instant/approval; `capacity` null or ≥ 1; `waitlist_enabled`; `status` draft/published/full/postponed/completed/cancelled; `cancel_reason` |
| `event_routes` | (`event_id`, `route_id`) [D18] |
| `event_registrations` | one row per rider per event (unique (`event_id`, `user_id`)), `status` invited/pending/approved/declined/waitlisted/cancelled/attended/did_not_attend, `waitlisted_at` (for promotion order) |
| `event_updates` | organizer posts: `kind` update/postponement/cancellation; `body` |
| `event_reports` | rider reports about an event (Q-12, D17) |

**Capacity [D7, D8]:** join, accept, cancel and promote run in one transaction that first runs `SELECT … FROM events WHERE id = $1 FOR UPDATE`, then counts approved registrations. Two riders can never take the last place. Covered by a concurrency test.

### 3.9 Trips, saved items and emergency contacts [F1–F19, SV1–SV3, E9]
| Table | Purpose and rules |
|---|---|
| `trips` | `title`; `starts_on` ≤ `ends_on`; `motorcycle`; `shared_notes`; `budget_npr`; `created_by` |
| `trip_members` | PK (`trip_id`, `user_id`); `role` owner/editor/viewer; partial unique: one owner per trip; `private_notes` (only that member) [F10]; `shares_emergency_contacts` (Q-10) |
| `trip_invitations` | `trip_id`, `invitee_id`, `role`, `status` pending/accepted/declined/cancelled, `expires_at` (14 days); partial unique: one pending invitation per trip and rider [F12] |
| `trip_stops` | `trip_id`; `day`; `position`; `overnight`; `title` (free text) and **at most one** of `destination_id`, `place_id`, `service_point_id`, `route_id`, `segment_id` (`num_nonnulls ≤ 1`); `route_part` route/stretch/checkpoint_start/checkpoint_end; partial uniques: a place once per trip, a whole route once per trip, a route part once per trip [F3, R11] |
| `trip_expenses` | `status` planned/spent; `amount_npr` null only when planned (CHECK); `category`; `label` [F6] |
| `trip_checklist_items` | `label`, `done`, `position` [F7] |
| `saved_items` | `user_id` cascade, and exactly one of `destination_id`, `route_id`, `service_point_id`, `place_id`, `contribution_id`, `guide_id`; unique per rider and item [F8, SV1] |
| `emergency_contacts` | `user_id` cascade; `name`, `relationship`, `phone`, `notes`; only ever queried with `user_id = session user` [E9, F17] |

### 3.10 Guides [E1–E14]
| Table | Purpose and rules |
|---|---|
| `guides` | `slug`; `title`; `category` (preparation, weather, fuel, accommodation, emergency, breakdown, accident, …); `is_safety_critical`; `body`; `status`; `author_staff_id`; `expert_reviewer_user_id` + `expert_field` + `expert_reviewed_at` (cleared when the text changes); `last_reviewed_on`; `review_expires_on` (Q-12). **CHECK:** published and safety-critical ⇒ an expert review exists |
| `checklist_templates`, `checklist_template_items` | the checklists a rider copies into a trip [E2] |

### 3.11 Media, settings, notifications [CM12, S1–S12, X10]
| Table | Purpose and rules |
|---|---|
| `media_assets` | `storage_key` unique; `visibility` public/private; `mime`, `bytes`, `width`, `height`; `variants` jsonb (sizes); `alt`; `credit`; `licence`; uploader (`uploaded_by_staff_id` or `uploaded_by_user_id`) |
| `settings` | PK (`group`, `key`); `value` jsonb (validated per group by Zod); `value_encrypted bytea` for secrets (AES-256-GCM, key from the environment); `updated_by_staff_id`; every change audited with the secret masked |
| `notifications` | `user_id` cascade; `type`; `data` jsonb (ids and short text only); `read_at`; index (`user_id`, `read_at`, `created_at desc`) |

### 3.12 CMS [CM-X1–CM-X8, CM1–CM16, H-1–H-17]
| Table | Purpose and rules |
|---|---|
| `cms_entries` | one table for structured types: page/faq/post/blog_category/blog_tag/author/team_member/popup/banner/notice/content_block; `slug` unique per type; `status` draft/published/archived; `publish_at` < `expires_at`; `position`; `seo_title`, `seo_description`; `data` jsonb (published content) and `draft` jsonb (unpublished changes), both validated per type by Zod |
| `cms_entry_media`, `cms_entry_relations` | images used by an entry; post ↔ category/tag/author links (real FKs) |
| `cms_redirects` | `from_path` unique → `to_path`, created when a published slug changes [CM-X4] |
| `home_sections` | `type` (hero, featured destinations/routes/experiences, banner, slides, planner, tools, …); `position`; `visible`; schedule; `data` + `draft` |
| `home_section_items` | featured destinations, routes and experiences as FKs, so an unpublished item drops out by query |
| `menu_items` | `menu` header/footer; `parent_id`; `label`; `path` (internal path or https only, CHECK) |
| `contact_messages` | `name`, `email`, `subject`, `body`, `status` new/open/done/spam, `ip_hmac` (never the raw IP), `closed_at` (retention: Q-05) |

### 3.13 Audit and privacy [H7, P9, G19, Q-04, Q-05]
| Table | Purpose and rules |
|---|---|
| `audit_logs` | `occurred_at`; `actor_type` rider/staff/system; `actor_id` (no FK: survives account deletion); `action` (e.g. `contribution.rejected`); `subject_type`, `subject_id`; `data` jsonb (before/after of changed fields, secrets masked); `ip_hmac`; `request_id`. `mrn_app` has INSERT and SELECT only, and a trigger refuses UPDATE and DELETE. Redaction for deleted accounts (if Q-04 keeps it) only through one audited `SECURITY DEFINER` function |
| `account_deletion_requests`, `ownership_transfers`, `moderation_evidence`, `pending_file_deletions` | S13, shaped by Q-04 and Q-05; designed in detail at that stage |

pg-boss keeps its job tables in its own `pgboss` schema. That schema is created by a migration with the owner role, and the worker starts with schema migration switched off, so `mrn_app` never needs DDL.

## 4. Indexes (beyond primary and unique keys)

| Index | Serves |
|---|---|
| every FK column used in a join or a cascade | joins, and fast deletes on the parent |
| `destinations (status, province_id)`, `routes (status)`, `places (status, category)` | public lists |
| trigram GIN on `destinations.name`, `routes.name`, `places.name` (`pg_trgm`) | search [A2, A14] |
| `contributions (segment_id, status, observed_on desc)` | segment reports, corroboration, outdating |
| `contributions (status, submitted_at)` partial where status in (pending_review, flagged) | moderation queue |
| `event_registrations (event_id, status)`, `events (status, starts_at)` | capacity counts, upcoming events |
| `trip_members (user_id)` | "my trips" |
| `rider_sessions (user_id)`, `staff_sessions (staff_account_id)` | sign out everywhere |
| `audit_logs (subject_type, subject_id, occurred_at desc)`, `audit_logs (actor_type, actor_id)` | history panels, audit viewer |
| GiST on `places.geog`, `service_points.geog`, `route_segments.path` | near / along-the-route (Q-15) |

Further indexes are added only when `EXPLAIN ANALYZE` on realistic data shows a need.

## 5. Transactions and locking

| Use case | Protection |
|---|---|
| Event join / accept / cancel / waitlist promotion | `FOR UPDATE` on the event row, then count; one transaction |
| Removing the last administrator role or deactivating the last administrator | `FOR UPDATE` on the admin role assignments, then count active administrators |
| Moderation and claim decisions | `FOR UPDATE` on the item, re-check its status, so a repeated or stale decision returns 409 instead of overwriting |
| Trip ownership transfer, member removal | `FOR UPDATE` on the trip |
| Segment reorder | deferrable unique constraint, all positions updated in one transaction |
| Every staff decision | the change and its `audit_logs` row in the same transaction |

## 6. Migrations

- Location: `backend/database/migrations/`, generated by `drizzle-kit` from the Drizzle schema, plus hand-written SQL for triggers, functions, grants and reference data.
- Each migration is read before it is committed, runs as `mrn_owner`, and is tested on the test database by the test suite.
- Destructive changes (dropping or renaming columns) follow expand → migrate data → contract, across separate releases.
- The first migration creates the roles' grants, the extensions (`pg_trgm`, `postgis` if Q-15) and the audit table with its trigger.

## 7. Backup and recovery (to confirm with the host, Q-19)

| Item | Proposal |
|---|---|
| Automated backups | the managed host's daily backups with point-in-time recovery (target RPO ≤ 15 min where offered) |
| Independent copy | a nightly `pg_dump -Fc` with the owner role, stored in separate object storage, kept 30 days, encrypted |
| Files | object storage with versioning; a daily inventory compared against `media_assets` |
| Restore test | monthly: restore the latest dump into a scratch database, run the migration status check and row-count checks, record the result |
| Target RTO | 4 hours for a full restore |
| Runbook | `docs/deployment/BACKUP_AND_RESTORE.md` (written in Phase 9 with the real host) |

## 8. Open items

Still open: Q-04/Q-05 (privacy tables), Q-06 (thresholds stored in settings), Q-07 (difficulty values), Q-12 (draft status, `rest` kind, event reports, review expiry), Q-15 (PostGIS).
