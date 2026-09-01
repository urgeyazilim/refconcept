#!/usr/bin/env bash
#
# What every RefConcept PHP container does before it starts serving.
#
# Four containers share this image — the API, two queue workers and the scheduler — and all
# four need the same three things: an application key, the caches built from the environment
# they were actually given, and a database that is answering. Doing it here rather than in a
# deploy script means a container that restarts at three in the morning comes back correctly
# without anybody running anything.
#
# Migrations run here too, but only in the container told to run them, and only under a lock
# — see the section at the bottom. The alternative, a one-shot `migrate` service, needs a
# Coolify-specific key that plain `docker compose` rejects, and a production file that cannot
# be validated or run locally is a production file nobody can test before it matters.

set -euo pipefail

php_artisan() {
    php artisan "$@" --no-interaction
}

# --- the key -----------------------------------------------------------------

# Missing APP_KEY is not a warning, it is a silent data loss: every encrypted column —
# provider credentials, seller payout IBANs — becomes unreadable, and a *generated* one is
# worse than none, because it would be a different key on every container and every restart.
if [ -z "${APP_KEY:-}" ]; then
    echo "refconcept: APP_KEY tanımlı değil. Şifreli alanlar okunamaz; kurulum durduruluyor." >&2
    echo "refconcept: 'php artisan key:generate --show' ile üretip ortam değişkeni olarak verin." >&2
    exit 1
fi

# --- wait for the database ---------------------------------------------------

# A worker that starts a second before PostgreSQL is accepting connections dies, and under a
# restart policy it dies repeatedly with an error that reads like a misconfiguration rather
# than like a race. Thirty attempts at one second is longer than any cold start here.
if [ "${REFCONCEPT_WAIT_FOR_DB:-1}" = "1" ]; then
    attempt=0

    until php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 5432, getenv("DB_DATABASE"));
        try { new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]); }
        catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        attempt=$((attempt + 1))

        if [ "$attempt" -ge 30 ]; then
            echo "refconcept: veritabanına 30 denemede ulaşılamadı." >&2
            exit 1
        fi

        sleep 1
    done
fi

# --- caches ------------------------------------------------------------------

# Built here rather than at image build time, and that is the whole reason this file exists.
# `config:cache` freezes the values of every env() call into a PHP array, so a cache baked
# during the build would carry the build machine's environment — an empty database password
# and a localhost API URL — into production, and `env()` would return null forever after.
#
# Cleared first: an image rebuilt from a layer that happened to contain a stale cache would
# otherwise serve it.
php_artisan config:clear
php_artisan config:cache
php_artisan route:cache
php_artisan event:cache

# Deliberately not `view:cache`: this application renders JSON and its only Blade files are
# mail templates, which compile on first use in a fraction of the time this would take on
# every container start.

# --- schema ------------------------------------------------------------------

# Only the web container carries this, and it starts before the workers do.
#
# `--isolated` takes a lock through the cache driver, so if this ever runs in two places at
# once — a restart during a deploy, a second replica — the second one steps aside instead of
# applying half a migration on top of the first.
#
# Reference data is re-seeded on every boot, not only the first. The role to permission map
# lives in code and the grants live in rows, and the seeder reconciles them with `sync()`, so
# a permission removed from the enum is removed from the role too. A release once shipped a
# screen staff could not open because the code was right and the environment was stale.
#
# The seeders are idempotent and the migrator skips what it has already applied, so a
# container that restarts at three in the morning does this and changes nothing.
if [ "${REFCONCEPT_MIGRATE_ON_BOOT:-0}" = "1" ]; then
    # The extensions, before the first migration that needs one.
    #
    # PostgreSQL runs `/docker-entrypoint-initdb.d` once, on an empty data directory, and
    # that is where these used to be created. It does not survive contact with a platform:
    # Coolify creates an *empty directory* for a bind mount rather than mapping repository
    # content into it, so the script was never there and the very first migration failed on
    # `type "citext" does not exist`. Nothing said the mount was empty; the symptom appeared
    # four layers away, in a schema change.
    #
    # Done here instead because this is the one place that runs on every boot, in every
    # environment, and always before the migrator. `IF NOT EXISTS` makes it a no-op the
    # other ninety-nine times, and it repairs a database restored from a plain dump into a
    # fresh volume — which initdb would also have skipped.
    PGPASSWORD="$DB_PASSWORD" psql \
        --host="$DB_HOST" \
        --port="${DB_PORT:-5432}" \
        --username="$DB_USERNAME" \
        --dbname="$DB_DATABASE" \
        --set ON_ERROR_STOP=1 \
        --quiet <<'SQL'
CREATE EXTENSION IF NOT EXISTS "vector";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "citext";
SQL

    php_artisan migrate --force --isolated

    for seeder in RolesAndPermissionsSeeder PlatformSettingsSeeder CommissionSeeder; do
        php_artisan db:seed --class="$seeder" --force
    done
fi

# --- storage -----------------------------------------------------------------

# Only meaningful when the local disk is in use. Room photographs live on S3 in production,
# so this is a no-op there and the right thing on a machine configured without it.
if [ "${FILESYSTEM_DISK:-s3}" = "public" ]; then
    php_artisan storage:link || true
fi

exec "$@"
