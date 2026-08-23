#!/usr/bin/env bash
# Push apps/api from the host into the api container's source volume.
#
# Application source lives in a named volume rather than a bind mount because a
# Laravel boot costs ~22.8s over a Windows bind mount versus ~4.3s from the volume
# (docs/ADR/ADR-0002). `docker compose watch` does this continuously.
#
# `docker cp` only adds or overwrites, so host-owned directories are cleared first:
# otherwise a file deleted or renamed on the host keeps running in the container.
#
# On Linux the bind-mount penalty does not apply; overlay
# infra/docker/compose.bindmount.yml to edit files in place instead.
#
# Usage: scripts/sync.sh [--pull]
set -euo pipefail

cd "$(dirname "$0")/.."

container='refconcept-api'

# Directories whose contents come exclusively from the host and are safe to mirror.
# storage/ (runtime writes) and vendor/ (separate volume) are deliberately absent.
mirrored_dirs='app bootstrap config database public resources routes tests'

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

  # Compiled caches are build artifacts; left on the host they get pushed back on the
  # next sync and can shadow newly installed packages.
  rm -f apps/api/bootstrap/cache/*.php

  echo 'Pull complete.'
  exit 0
fi

echo "Syncing apps/api -> ${container}:/var/www/html"

clear_list=''
for dir in $mirrored_dirs; do
  clear_list="${clear_list} /var/www/html/${dir}"
done
docker compose exec -T api sh -c "rm -rf ${clear_list}"

docker cp 'apps/api/.' "${container}:/var/www/html"

# Writable runtime directories must exist and stay writable for php-fpm.
# bootstrap/cache is cleared, not just created: a stale packages.php copied in from the
# host hides freshly installed packages (this masked Sanctum's auth guard once already).
docker compose exec -T api sh -c \
  'rm -f bootstrap/cache/*.php && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache && chmod -R 0777 storage bootstrap/cache'

echo 'Sync complete.'
