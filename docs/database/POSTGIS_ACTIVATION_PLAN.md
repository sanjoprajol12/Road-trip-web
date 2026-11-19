# PostGIS pre-activation audit and plan (proposal, not applied)

Session 14, 2026-11-19. Inspection only: every query below was read-only (`select`, `show`, `docker ps`, `docker inspect`). **No database, extension, migration, Docker image, volume or configuration was changed.** PostGIS stays disabled until the owner approves this plan (decision D3).

## 1. Current environment (observed)

| Item | Finding |
|---|---|
| PostgreSQL | **17.12** (`select version()`), x86_64, gcc 15.2 |
| Container / image | `supabase_db_my-ride-nepal`, image **`public.ecr.aws/supabase/postgres:17.12.0.000`**, port 54322; data volume `supabase_db_my-ride-nepal` at `/var/lib/postgresql/data`. Mailpit `public.ecr.aws/supabase/mailpit:v1.31.3`. No docker-compose file: the Supabase CLI (`supabase start -x kong`, `supabase/config.toml`, `major_version = 17`) manages the containers |
| PostGIS packages | **Installed in the image**: `pg_available_extensions` lists `postgis 3.3.7`, `postgis_raster`, `postgis_sfcgal`, `postgis_topology`, `postgis_tiger_geocoder` (3.3.7), `address_standardizer` 3.3.7, `pgrouting` 3.4.1 |
| Available PostGIS versions | `3.3.7` (default), `3.3.7next`, `unpackaged`: only 3.3.7 is usable |
| Installed now | **Not installed in any database.** `myridenepal`, `myridenepal_test`, `myridenepal_e2e`: only `plpgsql`. `postgres` db: plpgsql, uuid-ossp, pgcrypto, pg_stat_statements, supabase_vault |
| Installation requirement | `postgis` is **not trusted** (`trusted = f`, `superuser = t`). Supabase's `supautils` lists it in `supautils.privileged_extensions`; members of `supautils.privileged_role = supabase_privileged_role` may create it, and it then runs as `supabase_admin`. The local `postgres` role is **not** a superuser but **is** a member of `supabase_privileged_role`, so `postgres` can run `create extension postgis`. `mrn_owner` and `mrn_app` are not members and **cannot** |
| Roles | `postgres` (no superuser; createdb, createrole, bypassrls; member of mrn_owner, mrn_app, supabase_privileged_role), `supabase_admin` (superuser), `mrn_owner` (no special attributes; owns the three databases and `public`; CREATE on db and public), `mrn_app` (no DDL: USAGE on public only, DML by default privileges) |
| search_path | server default `"$user", public, extensions`; **Laravel pins `search_path` to `public`** for both `pgsql` and `pgsql_owner` (`config/database.php` lines 98, 114). No `extensions` schema exists in the three project databases |
| Databases | `myridenepal` (dev, 12 MB), `myridenepal_test` (PHPUnit, RefreshDatabase), `myridenepal_e2e` (Playwright), all owned by `mrn_owner`, created by `scripts/setup-local-db.sh` |
| Geographic columns today | No `geometry`/`geography`/`point` types anywhere. Coordinates are `numeric(9,6)`: `destinations.latitude/longitude`, `service_points.latitude/longitude`, `route_segments.start_lat/start_lng/end_lat/end_lng`. Routes have no geometry; a route is its ordered segments |
| Data volume | dev: 4 destinations (0 with coordinates), 0 service points, 0 routes, 0 segments. E2E: 4 destinations, 0 segments (test rows are removed by the specs) |
| Distance code today | `App\Support\Geo` (haversine + point-to-segment in PHP, bounding-box prefilter in SQL), used by `JourneyController` |
| Client tools | host `psql`/`pg_dump` **18.6**; container `pg_dump` **17.12**; PHP 8.3.36 CLI with `pdo_pgsql` (libpq 18.6); production image `dunglas/frankenphp:1-php8.4-alpine` with `pdo_pgsql`; Laravel **12.73.0** |
| CI | both repos' workflows use **`postgres:17`** service images, which **do not contain PostGIS** |

## 2. Compatibility findings

| Area | Finding | Consequence |
|---|---|---|
| PostgreSQL 17 / PostGIS 3.3.7 | Works in this Supabase image (Supabase builds it for 17). Upstream PostGIS lists PostgreSQL 17 support from **3.5**; 3.3.x on 17 is a Supabase build. Everything this plan needs (`geography`, `ST_DWithin`, `ST_Distance`, `ST_MakePoint`, `ST_LineLocatePoint`, `ST_GeomFromGeoJSON`, GiST) exists in 3.3 | Fine locally. Pin tests to these functions; verify again if the image is upgraded |
| Supabase image | No image change needed locally: the packages are already there | Activation is one SQL statement per database, no container rebuild, no volume change |
| Role permissions | `mrn_owner` (the migration role) cannot create the extension | Activation must be an **infrastructure step run as `postgres`** (setup script), not a Laravel migration. The Laravel migration only checks that PostGIS exists and fails with a clear message if not |
| Laravel 12 | `Blueprint::geography($col, $subtype, 4326)` and `geometry()` exist; `storedAs()` works on pgsql. `migrate:fresh` (RefreshDatabase) skips `spatial_ref_sys` (`dont_drop` default) and extension-owned types (`pg_depend deptype 'e'`); it drops views only with `--drop-views` (not used) | Compatible. Never add `--drop-views` or `$dropViews = true` (it would try to drop `geometry_columns`/`geography_columns`) |
| search_path | Laravel connects with `search_path = public` | If PostGIS is created in an `extensions` schema (Supabase convention), Laravel must use `public,extensions`, or every PostGIS call fails with "type geography does not exist" |
| PHP driver | `pdo_pgsql` needs nothing extra. Geography columns come back as hex EWKB strings | Select coordinates explicitly (`ST_Y`, `ST_X`, `ST_AsGeoJSON`) and add new geography columns to model `$hidden`, so raw hex never reaches an API response |
| Dev/E2E/test parity | All three databases are on the same server: activate in all three with the same script | Parity holds locally. A database without PostGIS fails the guard migration immediately (intended) |
| CI | `postgres:17` lacks PostGIS | **Incompatible**: CI must switch to a PostGIS image (for example `postgis/postgis:17-3.5`; tags must be checked at change time) and run `create extension` before migrations. That gives PostGIS 3.5 in CI and 3.3.7 locally: a minor version gap to cover with the parity tests below |
| Production | Production Postgres is undecided (AR5) | The chosen provider must offer PostGIS on PostgreSQL 17 and allow the migration role (or an admin step) to create it. Confirm before choosing |
| Migration ordering | The extension must exist before the first geography migration; `Permissions::sync()` pattern unchanged | Infrastructure step first, then migrations `100017`, `100018` |

## 3. Backup and restore plan (commands; none were run)

Use the **container's pg_dump 17.12** (matches the server; host tools are 18.6). The local superuser connection is the one `scripts/setup-local-db.sh` uses (`$SUPER`). Backups go to `/var/www/html/Road-trip/_backups/db/` (outside both repositories, never committed).

```sh
STAMP=$(date +%Y%m%d-%H%M)
DIR=/var/www/html/Road-trip/_backups/db/$STAMP && mkdir -p "$DIR"
C=supabase_db_my-ride-nepal

# Development and E2E databases (custom format, compressed)
docker exec $C pg_dump -U postgres -d myridenepal     -Fc -Z 6 > "$DIR/myridenepal.dump"
docker exec $C pg_dump -U postgres -d myridenepal_e2e -Fc -Z 6 > "$DIR/myridenepal_e2e.dump"

# Schema snapshots (plain SQL, for diffing before/after)
docker exec $C pg_dump -U postgres -d myridenepal     --schema-only > "$DIR/myridenepal.schema.sql"
docker exec $C pg_dump -U postgres -d myridenepal_e2e --schema-only > "$DIR/myridenepal_e2e.schema.sql"
docker exec $C pg_dumpall -U postgres --roles-only --no-role-passwords > "$DIR/roles.sql"

# Integrity: readable archive, checksums
for f in "$DIR"/*.dump; do docker exec -i $C pg_restore --list < "$f" > /dev/null && echo "ok $f"; done
sha256sum "$DIR"/* > "$DIR/SHA256SUMS"
```

Isolated restoration (a new scratch database; never restore over an existing one):

```sh
docker exec $C psql -U postgres -c "create database myridenepal_restore_check owner mrn_owner"
docker exec -i $C pg_restore -U postgres -d myridenepal_restore_check --no-owner --role=mrn_owner --exit-on-error < "$DIR/myridenepal.dump"
```

Data integrity comparison (row counts per table, source vs restore; the outputs must be identical):

```sh
COUNTS="select table_name, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', table_name), false, true, '')))[1]::text
        from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by 1"
docker exec $C psql -U postgres -d myridenepal               -At -c "$COUNTS" > "$DIR/counts.source"
docker exec $C psql -U postgres -d myridenepal_restore_check -At -c "$COUNTS" > "$DIR/counts.restore"
diff "$DIR/counts.source" "$DIR/counts.restore" && echo "row counts match"
# Remove only the scratch database afterwards:
docker exec $C psql -U postgres -c "drop database myridenepal_restore_check"
```

Optional cold copy of the whole volume (only with the database stopped, `pnpm db:stop`): `docker run --rm -v supabase_db_my-ride-nepal:/data:ro -v "$DIR":/backup alpine tar czf /backup/volume.tgz -C /data .`

## 4. Proposed schema (additive; nothing created)

Extension placement: `create schema if not exists extensions; create extension if not exists postgis with schema extensions;` in each project database, run as `postgres`; `grant usage on schema extensions to mrn_owner, mrn_app;` Laravel `search_path` becomes `public,extensions` for both connections. (Keeps PostGIS objects out of `public`, like hosted Supabase.)

**Existing latitude/longitude columns stay the source of truth.** New geography columns are **generated** from them, so no backfill job, no drift, and no change to any write path:

| Table | New column | Type | Why |
|---|---|---|---|
| `destinations` | `location` | `geography(Point, 4326)` generated stored: `case when latitude is not null and longitude is not null then ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography end` | Metres directly in `ST_DWithin`/`ST_Distance`; spherical accuracy; matches the WGS84 coordinates staff already enter |
| `service_points` | `location` | same | same |
| `route_segments` | `path` | `geography(LineString, 4326)` nullable, written by a future GeoJSON/GPX import; when null, queries use the straight line start to end (today's behaviour) | Real road shape when known. `ST_LineLocatePoint` (position along a route) has no geography variant: cast `path::geometry` in that query only |
| `places` (new) | see below | | Viewpoints, lakes, temples and rider-submitted places (R2, R5–R13) |

`places` (new table): `id uuid pk`, `category` (check: viewpoint, lake, waterfall, temple, heritage, attraction, food, fuel, rest_stop, lodging, repair, medical, hidden_place, other), `name varchar(120)`, `slug` unique, `summary varchar(400)`, `description text`, `latitude numeric(9,6) not null`, `longitude numeric(9,6) not null`, `location geography(Point,4326)` generated as above (not null), `destination_id` FK nullable (null on delete), `route_segment_id` FK nullable, `observed_on date`, `last_confirmed_on date`, `status` (check: draft, pending_review, published, rejected, outdated, archived), `submitted_by` FK users nullable (rider submissions), `reviewed_by_staff_id` FK staff_accounts nullable, `moderation_note text`, `is_dev_seed bool default false`, timestamps. Checks: `latitude between 26.3 and 30.5`, `longitude between 80.0 and 88.3` (Nepal bounding box with a margin). Sources use the existing `sources` + `sourceables` (morph alias `place`); photos use the media library with `media_usages`; moderation follows the Stage 4 rules (pending, no self-review, audit).

Indexes: `GIST (location)` on destinations, service_points, places; `GIST (path)` on route_segments; `btree (category, status)` on places. Geographic relationships stay explicit FKs (destination, segment); "near" is a query (`ST_DWithin(p.location, s.path_or_chord, :metres)`), not a stored relationship, so it never goes stale.

Why not `geometry(Point, 4326)`: distances in degrees would need casts or a projection. Why not a projected SRID: Nepal spans two UTM zones (44N, 45N); one projection would distort one side. At this data size `geography` is the simplest correct choice.

## 5. Migration strategy

1. **Backups** (section 3), verified, before anything else.
2. **Infrastructure step** (owner-run or approved, as `postgres`): add to `scripts/setup-local-db.sh`, for each of `myridenepal`, `myridenepal_test`, `myridenepal_e2e`: `create schema if not exists extensions; create extension if not exists postgis with schema extensions; grant usage on schema extensions to mrn_owner, mrn_app;`. Idempotent, safe to repeat.
3. **Laravel config:** `search_path` `public,extensions` on `pgsql` and `pgsql_owner`; geography columns in model `$hidden`.
4. **Migration `2026_11_xx_100017_add_geography_columns`** (owner role): guard (`select 1 from pg_extension where extname = 'postgis'`, else throw "Enable PostGIS first: see POSTGIS_ACTIVATION_PLAN.md"); add the generated `location` columns (each rewrites a small table under an ACCESS EXCLUSIVE lock: milliseconds today); add `route_segments.path`; GiST indexes. `down()`: drop these indexes and columns only.
5. **Migration `100018_create_places`**: table, checks, indexes, `Permissions::sync()` for new permissions (`places.manage`, `places.moderate`). `down()`: drop `places` only.
6. **Backfill:** none needed for generated columns. Validate: `select count(*) filter (where latitude is not null and longitude is not null) = count(location) from destinations` (same for service_points) must be true; `select count(*) from destinations where location is not null and not ST_Intersects(location, ST_MakeEnvelope(80,26.3,88.3,30.5,4326)::geography)` must be 0.
7. **Feature flag:** `GEO_ENGINE=php|postgis` (config `mrn.geo_engine`, default `php`). `JourneyController` keeps the current PHP path and gains a PostGIS query; switch per environment after the parity tests pass. Remove the PHP path only after a release on `postgis`.
8. **Rollback:** set `GEO_ENGINE=php`; then `php artisan migrate:rollback --step=2 --database=pgsql_owner` (drops `places`, the new columns and indexes). **The extension is never dropped by a rollback or a migration.** Removing it is a separate, manual owner decision, only after `select * from pg_depend where refobjid = (select oid from pg_extension where extname = 'postgis')` shows no project objects. Worst case: restore the verified dump into a new database and switch `.env` to it.

## 6. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Version gap: PostGIS 3.3.7 local (Supabase build on PG 17) vs 3.5 in CI vs unknown in production | Use only functions present in 3.3; run the same feature tests on both; record `postgis_full_version()` in the health check output for staff; re-check on image upgrades |
| Docker image change (`supabase start` pulling a newer image) | Not needed now; pin the Supabase CLI version (already in `package.json`); back up before any CLI upgrade |
| Volume/data loss | Verified custom-format dumps plus checksums before activation; optional cold volume copy; activation does not touch existing rows |
| Permission failure (`mrn_owner` cannot create the extension) | Activation in the setup script as `postgres`; guard migration with a clear message |
| `search_path` mismatch ("type geography does not exist") | Config change in step 3, covered by a test that runs `select ST_Distance(...)` on the app connection |
| Migration locks | Generated columns rewrite the table: negligible now (4 rows). In production with data, run during low traffic; create GiST indexes `concurrently` in a non-transactional migration (`$withinTransaction = false`) |
| Invalid coordinates (swapped lat/lng, outside Nepal) | Existing request validation (lat 26–31, lng 80–89) plus check constraints on `places`; `NOT VALID` then `VALIDATE` checks on existing tables; envelope validation query in step 6 |
| Performance | GiST indexes; `ST_DWithin` (index-aware) instead of `ST_Distance < x`; `EXPLAIN ANALYZE` on the journey query with seeded test data before switching the flag |
| E2E differences | E2E database activated by the same script; E2E journey spec runs with `GEO_ENGINE=postgis` once flipped; CI image switched in the same change |
| Raw EWKB in API output | `$hidden` on models; resources already pick explicit fields; a test asserts no `location` key in public JSON |
| Production migration | Provider must support PostGIS on 17 (AR5); rehearse on a restored copy; backup immediately before; feature flag stays `php` until verified |
| `migrate:fresh` with `--drop-views` | Never used; documented here and in `DEVELOPMENT_SETUP.md` when activated |

## 7. Test plan

- **Guard:** migration fails with the clear message on a database without PostGIS (run in a scratch database).
- **Schema:** generated `location` is null when either coordinate is null and correct when both are set; check constraints refuse coordinates outside Nepal; GiST indexes exist (`pg_indexes`).
- **Parity:** `JourneyTest` and `GeoTest` scenarios run under both `GEO_ENGINE=php` and `postgis`: same places, same order, distances within 1 %.
- **API hygiene:** no geography column in any public JSON (destinations, journeys, saved items).
- **Authorization:** `RouteAuthorizationTest` covers new places routes; rider-submitted places enter `pending_review`; no self-review.
- **Rollback drill:** in a restored scratch copy: migrate, rollback, migrate again; the extension remains; row counts unchanged.
- **E2E:** journey spec on `myridenepal_e2e` with PostGIS; full suites in Chromium, Firefox, Pixel 7 and the staff suite.
- **CI:** both workflows on the PostGIS service image.

## 8. Exact changes that would be required (after approval)

1. `web/scripts/setup-local-db.sh`: the three extension statements per database (as `postgres`).
2. `web/config/database.php`: `search_path` `public,extensions` (both connections).
3. `web/.github/workflows/ci.yml` and `admin/.github/workflows/ci.yml`: PostGIS service image plus `create extension` before migrations.
4. Migrations `100017_add_geography_columns`, `100018_create_places` (with guards, `down()` without extension removal).
5. `config/mrn.php`: `geo_engine` (env `GEO_ENGINE`, default `php`); `JourneyController` PostGIS branch; model `$hidden`.
6. Tests listed in section 7; docs: `DATABASE_DESIGN.md`, `DEVELOPMENT_SETUP.md`, `DEPLOYMENT_READINESS.md`.
7. Later (Stage 5/7 work, separate approval for scope): places admin screens, rider submissions, GeoJSON/GPX import for `route_segments.path`, map picker.

## 9. Confirmation

During this audit **no database was modified, no extension was created, no migration was run, no Docker image, container, volume or Supabase configuration was changed, and no backup or restore was executed.** Only read-only queries and file reads were used. Waiting for the owner's explicit approval before step 1.
