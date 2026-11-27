#!/usr/bin/env bash
# Runs once, when the database volume is first created (docker-entrypoint-initdb.d). Also used by CI.
# Creates the two roles (TD-05) and the three databases. Table privileges are NOT granted here:
# they live in migrations, so no setup script can undo them (lesson from the old project).
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username postgres \
  -v owner_pw="$MRN_OWNER_PASSWORD" -v app_pw="$MRN_APP_PASSWORD" <<'SQL'
CREATE ROLE mrn_owner LOGIN PASSWORD :'owner_pw';
CREATE ROLE mrn_app LOGIN PASSWORD :'app_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
SQL

for db in myridenepal_dev myridenepal_test myridenepal_e2e; do
  psql -v ON_ERROR_STOP=1 --username postgres <<SQL
CREATE DATABASE ${db} OWNER mrn_owner;
REVOKE ALL ON DATABASE ${db} FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE ${db} TO mrn_app;
\c ${db}
-- pg_trgm (search) is created here because extensions need a superuser. PostGIS waits for Q-15.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO mrn_app;
SQL
done
