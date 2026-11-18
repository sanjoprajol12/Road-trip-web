# Legacy Supabase schema (reference only)

Archived on 2026-11-18 when the Laravel API took over the database. These files are **not executed**.

- `20261118000001…3_*.sql`: the Supabase-era schema with row-level security (RLS) policies keyed on `auth.uid()`.
- `seed.sql`: the old development seed.
- `tests/`: the RLS test suite (plain SQL, ran on a throwaway Postgres or local Supabase).

Every rule they enforced was ported to Laravel (policies, actions, validation, database constraints) and is covered by `tests/Feature/*Test.php`. The local `postgres` database inside the Supabase container still holds this old schema and its dev/test data, untouched, as a fallback; the app uses the separate `myridenepal` database.
