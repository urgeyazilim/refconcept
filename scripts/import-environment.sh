#!/usr/bin/env bash
#
# Reads back what `export-environment.sh` wrote, onto the server that is to become the copy.
#
# Destructive on purpose: the database is dropped and rebuilt. A mirror that merges into
# whatever was already there is not a mirror, and the half-merged result is worse than either
# side — rows from two environments with the same ids meaning different things.
#
# Run it on the target, beside the export directory.
#
# Usage:  bash scripts/import-environment.sh [source]
#
set -euo pipefail

# Git Bash on Windows rewrites anything that looks like a Unix path before it reaches the
# process — so `/tmp/refconcept.dump` arrives inside the container as `C:/Users/…`, which is a
# directory that does not exist there and an error that blames pg_dump. Harmless elsewhere.
export MSYS_NO_PATHCONV=1

# SKIP_STORAGE=1 restores the database and leaves the buckets alone: for refreshing the rows
# on a target whose files are already current, and for proving the restore works without
# replacing a gigabyte to find out.
SRC="${1:-mirror}"
POSTGRES="${POSTGRES_CONTAINER:-refconcept-postgres}"
MINIO="${MINIO_CONTAINER:-refconcept-minio}"
API="${API_CONTAINER:-refconcept-api}"
DB_NAME="${DB_NAME:-refconcept}"
DB_USER="${DB_USER:-refconcept}"

if [ ! -f "$SRC/database.dump" ]; then
  echo "$SRC/database.dump yok." >&2
  exit 1
fi

cat "$SRC/manifest.txt" 2>/dev/null || true

echo
read -r -p "$DB_NAME veritabanı SİLİNİP yerine bu kopya kurulacak. Devam? (evet) " answer
[ "$answer" = "evet" ] || { echo "Vazgeçildi."; exit 1; }

echo "→ veritabanı yeniden kuruluyor"

docker cp "$SRC/database.dump" "$POSTGRES:/tmp/refconcept.dump"

# Disconnect everything first: a restore cannot drop a database somebody is holding open, and
# the queue workers hold it open for as long as they are running.
docker exec "$POSTGRES" psql --username="$DB_USER" --dbname=postgres -c \
  "select pg_terminate_backend(pid) from pg_stat_activity where datname = '$DB_NAME' and pid <> pg_backend_pid();" >/dev/null

docker exec "$POSTGRES" psql --username="$DB_USER" --dbname=postgres -c "drop database if exists \"$DB_NAME\";" >/dev/null
docker exec "$POSTGRES" psql --username="$DB_USER" --dbname=postgres -c "create database \"$DB_NAME\";" >/dev/null

# The extensions live in the database, not in the cluster, so a fresh one has none of them
# and the restore fails partway through with errors that scroll past.
docker exec "$POSTGRES" psql --username="$DB_USER" --dbname="$DB_NAME" -c \
  "create extension if not exists vector; create extension if not exists pg_trgm; create extension if not exists citext;" >/dev/null

docker exec "$POSTGRES" pg_restore \
  --username="$DB_USER" \
  --dbname="$DB_NAME" \
  --no-owner \
  --no-privileges \
  /tmp/refconcept.dump

docker exec "$POSTGRES" rm -f /tmp/refconcept.dump

if [ "${SKIP_STORAGE:-0}" = "1" ]; then
  echo "→ kovalar atlandı (SKIP_STORAGE=1)"
else

echo "→ kovalar"

for bucket in refconcept-private refconcept-public; do
  [ -d "$SRC/storage/$bucket" ] || { echo "   $bucket pakette yok, atlandı"; continue; }

  docker exec "$MINIO" rm -rf "/data/$bucket"
  docker cp "$SRC/storage/$bucket" "$MINIO:/data/$bucket"
  echo "   $bucket"
done

fi

echo "→ önbellekler"

docker exec "$API" php artisan config:clear >/dev/null 2>&1 || true
docker exec "$API" php artisan cache:clear >/dev/null 2>&1 || true

echo
echo "→ şifreli sütunlar bu APP_KEY ile açılıyor mu"

# The one thing that silently does not work. Seller IBANs and the AI provider keys are
# encrypted with APP_KEY; restored under a different one they are bytes nothing can read, and
# nothing says so until somebody is paid or a design is run.
docker exec "$API" php artisan tinker --execute="
\$provider = \App\Domains\Ai\Models\AiProvider::query()->with('credentials')->where('code','openai')->first();
\$credential = \$provider?->activeCredential();

if (\$credential === null) {
    echo '  openai: kimlik bilgisi yok'.PHP_EOL;
} else {
    try {
        \$secret = \$credential->secret_encrypted;
        echo '  openai: '.(is_string(\$secret) && \$secret !== '' ? 'açıldı (…'.\$credential->secret_hint.')' : 'boş').PHP_EOL;
    } catch (\Throwable \$e) {
        echo '  openai: AÇILAMADI — APP_KEY kaynaktakiyle aynı değil'.PHP_EOL;
    }
}
" 2>&1 | grep -E "openai:" || echo "  kontrol edilemedi"

echo
echo "Bitti."
