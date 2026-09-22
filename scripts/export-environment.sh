#!/usr/bin/env bash
#
# Everything one environment holds, in a directory another can read back.
#
# A deploy ships code. This ships the rest: the database, and every file in both buckets —
# the catalogue, the customers' room photographs, the renders, the 3D models. It exists
# because "ürün bulunamadı" on a freshly deployed server is the first of a long list, and
# seeding the demo catalogue answers only the first of them.
#
# Read `docs/operations/MIRROR_AN_ENVIRONMENT.md` before running it. There is one decision in
# there that cannot be made by a script: the target's APP_KEY. Seller IBANs and the AI
# provider keys are encrypted with it, and a copy restored under a different one arrives with
# those columns full of bytes nothing can read — no error, no warning, just a bank account
# that will not decrypt on the day somebody is paid.
#
# Usage:  bash scripts/export-environment.sh [destination]
#
set -euo pipefail

# Git Bash on Windows rewrites anything that looks like a Unix path before it reaches the
# process — so `/tmp/refconcept.dump` arrives inside the container as `C:/Users/…`, which is a
# directory that does not exist there and an error that blames pg_dump. Harmless elsewhere.
export MSYS_NO_PATHCONV=1

DEST="${1:-mirror}"
POSTGRES="${POSTGRES_CONTAINER:-refconcept-postgres}"
MINIO="${MINIO_CONTAINER:-refconcept-minio}"
DB_NAME="${DB_NAME:-refconcept}"
DB_USER="${DB_USER:-refconcept}"

mkdir -p "$DEST/storage"

echo "→ veritabanı: $DB_NAME"

# Custom format: compressed, and restorable table by table if a restore goes wrong halfway.
# `--no-owner` because the role that owns these tables here is not the role that will own
# them there, and a restore that insists on it fails on the first CREATE.
docker exec "$POSTGRES" pg_dump \
  --username="$DB_USER" \
  --dbname="$DB_NAME" \
  --format=custom \
  --no-owner \
  --no-privileges \
  --file=/tmp/refconcept.dump

docker cp "$POSTGRES:/tmp/refconcept.dump" "$DEST/database.dump"
docker exec "$POSTGRES" rm -f /tmp/refconcept.dump

echo "→ kovalar"

# Straight off the disk rather than through the API: `mc mirror` needs an alias, a key and a
# running server on both ends, and the data is sitting in a volume either way.
for bucket in refconcept-private refconcept-public; do
  docker cp "$MINIO:/data/$bucket" "$DEST/storage/$bucket"
  echo "   $bucket"
done

# What the target needs to know it is restoring, and what it would otherwise have to guess.
cat > "$DEST/manifest.txt" <<EOF
RefConcept environment export
alındığı an : $(date -u +"%Y-%m-%dT%H:%M:%SZ")
veritabanı  : $DB_NAME
commit      : $(git rev-parse --short HEAD 2>/dev/null || echo "bilinmiyor")
EOF

du -sh "$DEST" | awk '{ print "→ toplam " $1 }'

echo
echo "Bu dizinde müşteri fotoğrafları, parola özetleri ve şifreli IBAN'lar var."
echo "Karşıya taşıdıktan sonra buradan silin."
