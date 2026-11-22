# Routes, stretches and checkpoints in trips (F3)

Session 19, 2026-11-21. Written before the schema change. This note covers the smallest extension to the existing trip stops; it is not a second planning system.

## Current data model

- `trip_stops` holds one row per itinerary entry, ordered by `position` (no unique index on position). `day`, `overnight` and `note` are optional.
- A stop can link one record, each a nullable FK with `on delete set null`:
  - `destination_id` (may repeat: out and back)
  - `service_point_id` (once per trip, application check)
  - `place_id` (once per trip, partial unique index `trip_stops_one_place`)
  - `route_id`, which nothing in either app sets today (0 rows in dev and E2E).
- Route stretches are `route_segments`, ordered by `(route_id, position)`. Each has a start and end checkpoint: a name and optional coordinates, plus optional `distance_km`, `estimated_hours` and a recorded `path`.
- Checkpoints have no table of their own. Checkpoint *k* is the start of stretch *k*; the last checkpoint is the end of the last stretch.
- Staff "replace segments" keeps the ids of stretches they keep and deletes the rest. A deleted stretch would set its stop's link to null.
- Permissions come from `TripPolicy`: `edit` for owners and editors, `view` for every member. Staff have no access.

## Proposed data model (additive)

| Column | Type | Meaning |
|---|---|---|
| `route_segment_id` | uuid, null, FK `route_segments` on delete set null | the stretch, or the stretch whose start or end is the checkpoint |
| `route_part` | varchar(20), null, check in (`route`, `stretch`, `checkpoint_start`, `checkpoint_end`) | what the stop represents; null for every other stop |

- **Whole route:** `route_part = route`, `route_id` set, no segment.
- **Stretch:** `route_part = stretch`, with `route_id` and `route_segment_id`.
- **Checkpoint:** stored in one canonical form. It is the start of its stretch (`checkpoint_start`), except the final checkpoint, which is the end of the last stretch (`checkpoint_end`). "End of stretch 2" and "start of stretch 3" are therefore the same row.
- `route_part` stays when a link is nulled. The trip can then say "this stretch is no longer listed" instead of quietly turning the stop into something else.

## Compatibility

- Existing destination, place and service stops are unchanged: their `route_part` is null.
- The generic `POST /trips/{trip}/stops` still accepts `route_id`; a stop created that way is now marked `route_part = route`. No existing rows are affected (0 rows have `route_id`), and the migration backfills any it finds.
- Generic `PATCH` cannot change the route links of a route-part stop. Name, day, overnight and note stay editable.

## Duplicate prevention

- **Database:**
  - `trip_stops_one_route`: unique `(trip_id, route_id)` where `route_part = 'route'`.
  - `trip_stops_one_route_part`: unique `(trip_id, route_segment_id, route_part)` where `route_segment_id is not null`.
  - Nulled links drop out of both indexes, so a staff edit can never collide with them.
- **Application:**
  - 422 with the names of anything already on the trip. The whole request is refused; nothing is half-added.
  - Within one request, duplicates collapse to one row (a checkpoint picked twice counts once).
  - A trip holds either the whole route or some of its stretches, never both, so distance totals are not counted twice. Checkpoints may be added alongside either.

## Selection and ordering

- One request adds the whole route, or a set of stretches and checkpoints. Stops are appended after the trip's last stop, in riding order: checkpoint *k*, then stretch *k*, then checkpoint *k+1*.
- "Reversed" orders the stops the other way and names stretches "end to start". Distances and times are the recorded ones; nothing is recomputed.
- Reordering uses `PUT /trips/{trip}/stops/order` with every stop id of the trip. The list must match the trip exactly (otherwise 422, "reload"), and positions become 1..n in one transaction.

## Eligibility and authorization

- Only a **published** route is accepted, and every stretch must belong to it. Draft and archived routes answer 422. Stretches have no status of their own; they follow their route.
- If a route is later unpublished, members keep the stop names but get no route details (`linked_unavailable`). This is the existing rule for places and services.
- Adding, reordering and removing need `TripPolicy::edit` (owner or editor), so viewers get 403. Non-members get 403 and guests 401.
- The new rider routes sit in the existing verified, not-suspended group, so `RouteAuthorizationTest` covers them automatically.

## Not drawn, not invented

- The trip map shows checkpoints only where coordinates are recorded. Stretches are never drawn on the trip map; the route page draws recorded tracks.
- Missing distance, time or track is shown as missing. Totals are given only when every included part has a value.

## Migration and rollback

- `2026_11_21_100021_add_trip_route_parts` adds two nullable columns, one check constraint and two partial unique indexes, and backfills `route_part = route` where `route_id` is set and the stop has no other link.
- `down()` drops the indexes, the constraint and both columns. Route-part stops then remain as plain named stops with `route_id`; nothing else is lost. No data is destroyed on the way up.
