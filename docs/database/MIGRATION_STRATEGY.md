# Migration Strategy

## One authority
Laravel migrations (`web/database/migrations`) are the only schema history. The Supabase-era SQL migrations are archived (not executed) in `legacy-supabase/`. The Supabase CLI no longer applies any migration; it only runs Postgres and Mailpit.

## How the switch was done (2026-11-18), without resetting anything
1. New databases `myridenepal` and `myridenepal_test` were created next to the old `postgres` database in the same local container. The old database (Supabase schema, 4 dev destinations, test-run audit rows, 0 users) is **untouched** and can be used to compare or roll back.
2. Roles `mrn_owner` (owns the schema, used only by `migrate`) and `mrn_app` (SELECT/INSERT/UPDATE/DELETE only) were created; `scripts/setup-local-db.sh` reproduces this idempotently.
3. Laravel migrations created the schema; the seeder re-created the 4 development destinations.
4. Supabase Auth, PostgREST and Kong were switched off after the Laravel replacement passed its tests.

## Rules
- Run migrations as the owner: `php artisan migrate --database=pgsql_owner`. Never give `mrn_app` DDL rights.
- Change shipped migrations only with a new migration (example: `2026_11_18_100007_keep_road_reports_attached_to_segments`).
- Never run anything against the remote Supabase project without the owner's explicit approval.

## Importing users from Supabase Auth (if ever needed; not done)
No real accounts exist locally and the remote project is out of scope. If the owner approves an import later:
1. Export from `auth.users`: `id`, `email`, `email_confirmed_at`, `encrypted_password`, `raw_user_meta_data->>'display_name'`.
2. Insert into `users` with the same UUID, `password = encrypted_password` (bcrypt `$2a$`), `email_verified_at = email_confirmed_at` (null stays null, so the rider must confirm again).
3. Users sign in with their old password; the app verifies bcrypt and re-hashes to Argon2id (verified with a real GoTrue hash and tested in `AuthTest`).
4. Import roles and verification rows by `user_id`. Rehearse on a disposable local copy first; rollback = delete the imported rows by id list.
