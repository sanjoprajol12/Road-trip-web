#!/usr/bin/env bash
# Local development infrastructure (TD-23): PostgreSQL 18 + PostGIS on 5442, Mailpit on 8035 (UI) / 1035 (SMTP).
# Plain `docker run` so it works without the Compose plugin. Ports differ from the old Laravel project.
# Usage: scripts/dev-infra.sh up | down | status | reset   (reset deletes the local database volume)
set -euo pipefail
cd "$(dirname "$0")/.."

DB=mrn-node-db; MAIL=mrn-node-mail; VOL=mrn-node-db-data; NET=mrn-node
[ -f .env.infra ] || { echo "Copy .env.infra.example to .env.infra and set the passwords first." >&2; exit 1; }
set -a; . ./.env.infra; set +a
: "${POSTGRES_SUPERUSER_PASSWORD:?}" "${MRN_OWNER_PASSWORD:?}" "${MRN_APP_PASSWORD:?}"

running() { [ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" = "true" ]; }
exists()  { docker inspect "$1" >/dev/null 2>&1; }

up() {
  docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET" >/dev/null
  if exists "$DB"; then running "$DB" || docker start "$DB" >/dev/null; else
    docker run -d --name "$DB" --network "$NET" --restart unless-stopped \
      -e POSTGRES_PASSWORD="$POSTGRES_SUPERUSER_PASSWORD" \
      -e MRN_OWNER_PASSWORD="$MRN_OWNER_PASSWORD" -e MRN_APP_PASSWORD="$MRN_APP_PASSWORD" \
      -p 127.0.0.1:5442:5432 -v "$VOL":/var/lib/postgresql \
      -v "$PWD/database/init.sh":/docker-entrypoint-initdb.d/10-init.sh:ro \
      postgis/postgis:18-3.6 >/dev/null
  fi
  if exists "$MAIL"; then running "$MAIL" || docker start "$MAIL" >/dev/null; else
    docker run -d --name "$MAIL" --network "$NET" --restart unless-stopped \
      -p 127.0.0.1:8035:8025 -p 127.0.0.1:1035:1025 axllent/mailpit:v1.31.3 >/dev/null
  fi
  printf 'Waiting for PostgreSQL'
  for _ in $(seq 1 60); do
    docker exec "$DB" pg_isready -U postgres -q 2>/dev/null && { echo " ready."; return; }
    printf '.'; sleep 2
  done
  echo " timed out." >&2; exit 1
}

case "${1:-up}" in
  up) up ;;
  down) docker stop "$DB" "$MAIL" >/dev/null 2>&1 || true; echo "stopped" ;;
  status) docker ps --filter "name=mrn-node-" --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' ;;
  reset)
    read -r -p "Delete the local database volume $VOL? [y/N] " a; [ "$a" = y ] || exit 1
    docker rm -f "$DB" >/dev/null 2>&1 || true; docker volume rm "$VOL" >/dev/null; echo "reset; run 'up' again" ;;
  *) echo "usage: $0 up|down|status|reset" >&2; exit 2 ;;
esac
