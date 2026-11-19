#!/usr/bin/env sh
# Creates the Laravel databases and least-privilege roles inside the LOCAL Supabase Postgres.
# Safe to run more than once. Reads passwords from .env (DB_PASSWORD, DB_MIGRATION_PASSWORD).
# Refuses any host other than 127.0.0.1: never point this at a remote database.
set -eu
cd "$(dirname "$0")/.."
env_val() { grep -E "^$1=" .env | tail -1 | cut -d= -f2-; }
HOST=$(env_val DB_HOST); PORT=$(env_val DB_PORT)
[ "$HOST" = "127.0.0.1" ] || { echo "DB_HOST must be 127.0.0.1 (local only)" >&2; exit 1; }
APP_PW=$(env_val DB_PASSWORD); OWNER_PW=$(env_val DB_MIGRATION_PASSWORD)
[ -n "$APP_PW" ] && [ -n "$OWNER_PW" ] || { echo "Set DB_PASSWORD and DB_MIGRATION_PASSWORD in .env first" >&2; exit 1; }
SUPER="postgresql://postgres:postgres@$HOST:$PORT"

psql "$SUPER/postgres" -v ON_ERROR_STOP=1 -q -v owner_pw="$OWNER_PW" -v app_pw="$APP_PW" <<'SQL'
select format('create role mrn_owner login password %L nosuperuser nocreaterole nobypassrls', :'owner_pw') where not exists (select 1 from pg_roles where rolname = 'mrn_owner') \gexec
select format('create role mrn_app login password %L nosuperuser nocreatedb nocreaterole nobypassrls', :'app_pw') where not exists (select 1 from pg_roles where rolname = 'mrn_app') \gexec
select format('alter role mrn_owner password %L', :'owner_pw') \gexec
select format('alter role mrn_app password %L', :'app_pw') \gexec
grant mrn_owner to postgres;
select 'create database myridenepal owner mrn_owner' where not exists (select 1 from pg_database where datname = 'myridenepal') \gexec
select 'create database myridenepal_test owner mrn_owner' where not exists (select 1 from pg_database where datname = 'myridenepal_test') \gexec
select 'create database myridenepal_e2e owner mrn_owner' where not exists (select 1 from pg_database where datname = 'myridenepal_e2e') \gexec
SQL

for db in myridenepal myridenepal_test myridenepal_e2e; do
  psql "$SUPER/$db" -v ON_ERROR_STOP=1 -q <<SQL
revoke all on database $db from public;
grant connect on database $db to mrn_app;
alter schema public owner to mrn_owner;
revoke create on schema public from public;
grant usage on schema public to mrn_app;
set role mrn_owner;
alter default privileges in schema public grant select, insert, update, delete on tables to mrn_app;
alter default privileges in schema public grant usage, select on sequences to mrn_app;
grant select, insert, update, delete on all tables in schema public to mrn_app;
grant usage, select on all sequences in schema public to mrn_app;
SQL
done
echo "Local databases and roles are ready (myridenepal, myridenepal_test, myridenepal_e2e; mrn_owner, mrn_app)."
