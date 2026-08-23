#!/usr/bin/env bash
# Push apps/api from the host into the api container's source volume.
#
# Application source lives in a named volume rather than a bind mount because a
# Laravel boot costs ~22.8s over a Windows bind mount versus ~2.5s on the container
# filesystem (docs/ADR/ADR-0002). `docker compose watch` does this continuously.
#
# On Linux the bind-mount penalty does not apply; overlay
# infra/docker/compose.bindmount.yml to edit files in place instead.
#
# Usage: scripts/sync.sh [--pull]
set -euo pipefail

cd "$(dirname "$0")/.."

container='refconcept-api'

if ! docker ps --filter "name=${container}" --format '{{.Names}}' | grep -qx "${container}"; then
  echo "Container '${container}' is not running. Start it with: make up" >&2
  exit 1
fi

if [ "${1:-}" = '--pull' ]; then
  echo "Pulling ${container}:/var/www/html -> apps/api"

  # vendor/ is a separate volume holding thousands of files and is never pulled.
  docker compose exec -T api sh -c 'ls -A /var/www/html' | tr -d '\r' | while read -r entry; do
    [ -z "$entry" ] && continue
    [ "$entry" = 'vendor' ] && continue
    docker cp "${container}:/var/www/html/${entry}" apps/api/
  done

  echo 'Pull complete.'
  exit 0
fi

echo "Syncing apps/api -> ${container}:/var/www/html"
docker cp 'apps/api/.' "${container}:/var/www/html"

# Writable runtime directories must exist and stay writable for php-fpm.
docker compose exec -T api sh -c \
  'mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache && chmod -R 0777 storage bootstrap/cache'

echo 'Sync complete.'
