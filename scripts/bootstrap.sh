#!/usr/bin/env bash
# First-time RefConcept setup (Linux/macOS/CI). Idempotent.
set -euo pipefail

cd "$(dirname "$0")/.."

step() { printf '\n==> %s\n' "$1"; }

step 'Checking Docker'
docker version --format '{{.Server.Version}}' >/dev/null

step 'Preparing environment files'
[ -f .env ] || cp .env.example .env
[ -f apps/api/.env ] || cp apps/api/.env.example apps/api/.env

step 'Starting infrastructure'
docker compose up -d postgres redis minio mailpit minio-init

step 'Waiting for PostgreSQL and Redis'
for _ in $(seq 1 60); do
  if docker compose ps --format '{{.Service}} {{.Health}}' | grep -q 'postgres healthy' \
     && docker compose ps --format '{{.Service}} {{.Health}}' | grep -q 'redis healthy'; then
    break
  fi
  sleep 3
done

step 'Starting API containers'
docker compose up -d api nginx queue scheduler

step 'Syncing API source into the container'
# Source lives in a named volume for I/O speed (ADR-0002), so it must be pushed in.
bash scripts/sync.sh

step 'Installing PHP dependencies'
docker compose exec -T api composer install --no-interaction --prefer-dist

step 'Application key'
if grep -qE '^APP_KEY=\s*$' apps/api/.env; then
  docker compose exec -T api php artisan key:generate
else
  echo '  APP_KEY already set'
fi

step 'Running migrations'
docker compose exec -T api php artisan migrate --force

step 'Seeding'
docker compose exec -T api php artisan db:seed --force

step 'Installing frontend dependencies'
npm install

step 'Verifying health endpoint'
port="${API_PORT_HOST:-58000}"
for _ in $(seq 1 20); do
  if curl -fsS "http://localhost:${port}/api/health"; then
    printf '\n\nRefConcept is up. API http://localhost:%s\n' "$port"
    exit 0
  fi
  sleep 3
done

echo "Health endpoint did not respond on port ${port}. Check: make logs" >&2
exit 1
