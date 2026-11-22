#!/usr/bin/env sh
# Release step only (F1): runs migrations as the owner role, then verifies the application role's rights.
# The owner credentials come from this step's secrets (environment variables), never from the runtime .env, and are
# never printed. The web and scheduler runtimes must not have DB_MIGRATION_USERNAME / DB_MIGRATION_PASSWORD.
#   DB_MIGRATION_USERNAME=... DB_MIGRATION_PASSWORD=... sh scripts/release-migrate.sh
set -eu
cd "$(dirname "$0")/.."
[ -n "${DB_MIGRATION_USERNAME:-}" ] && [ -n "${DB_MIGRATION_PASSWORD:-}" ] || {
  echo "DB_MIGRATION_USERNAME and DB_MIGRATION_PASSWORD must be provided as secrets of this release step." >&2; exit 1; }
if grep -qE '^DB_MIGRATION_PASSWORD=.+' .env 2>/dev/null; then
  echo "Refusing: .env contains DB_MIGRATION_PASSWORD. Owner credentials must not be stored with the runtime configuration." >&2; exit 1
fi
php artisan migrate --database=pgsql_owner --force
# The checks connect as the application role (DB_USERNAME / DB_PASSWORD of this environment) and print no secrets.
php artisan mrn:security:check-database
