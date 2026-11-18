#!/usr/bin/env sh
# Applies every migration + seed to a throwaway Postgres container and runs the RLS tests.
# Touches no Supabase project and no shared database. Container is removed on exit.
set -eu
cd "$(dirname "$0")/.."
name="mrn-db-test-$$"
docker run -d --rm --name "$name" -e POSTGRES_PASSWORD=test postgres:17-alpine >/dev/null
trap 'docker stop "$name" >/dev/null' EXIT
# TCP only answers once the image's init server has handed over to the real one.
tries=0
until docker exec "$name" psql -U postgres -h 127.0.0.1 -qtAc 'select 1' >/dev/null 2>&1; do
  tries=$((tries + 1)); [ "$tries" -gt 60 ] && { echo 'Postgres did not start' >&2; exit 1; }
  sleep 1
done
cat tests/auth_stub.sql migrations/*.sql seed.sql tests/rls.test.sql \
  | docker exec -i "$name" psql -U postgres -h 127.0.0.1 -v ON_ERROR_STOP=1 -q
