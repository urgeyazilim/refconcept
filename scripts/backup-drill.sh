#!/usr/bin/env bash
#
# Backup and restore drill.
#
# A backup nobody has restored is a hope, not a backup. This script takes a real dump of
# the development database, restores it into a throwaway database, and compares row counts
# on the tables whose loss would actually hurt. It then removes both the dump and the
# throwaway database, so running it costs nothing and leaves nothing.
#
# Run it before a release and after any change to the schema, the extensions, or the
# database image. The failure it is designed to catch is the quiet one: a dump that
# restores with errors nobody reads, into a database missing an extension the schema needs,
# producing a database that looks present and is not usable.
#
#   bash scripts/backup-drill.sh
#
set -euo pipefail

DB="${REFCONCEPT_DB:-refconcept}"
USER="${REFCONCEPT_DB_USER:-refconcept}"
DRILL="${DB}_restore_drill"
DUMP="/var/tmp/${DB}-drill.dump"

# Git Bash rewrites container paths that look like Windows paths; this stops it.
export MSYS_NO_PATHCONV=1

psql() { docker compose exec -T postgres psql -U "$USER" "$@"; }

cleanup() {
  psql -d postgres -c "DROP DATABASE IF EXISTS ${DRILL};" >/dev/null 2>&1 || true
  docker compose exec -T postgres rm -f "$DUMP" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# The tables worth comparing: losing any of these silently is the scenario the drill exists
# for. Money and history first — a catalogue can be re-imported, a ledger cannot.
TABLES=(ledger_entries ledger_lines orders seller_orders payment_transactions audit_logs products users)

echo "→ dumping ${DB}"
docker compose exec -T postgres pg_dump -U "$USER" -d "$DB" -Fc -f "$DUMP"

echo "→ creating ${DRILL}"
psql -d postgres -c "DROP DATABASE IF EXISTS ${DRILL};" >/dev/null
psql -d postgres -c "CREATE DATABASE ${DRILL} OWNER ${USER};" >/dev/null

# The extensions come first and are not in the dump's control: pgvector, pg_trgm and citext
# are installed per database, and a restore into a database without them fails halfway
# through with errors that scroll past.
psql -d "$DRILL" -c "CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS citext;" >/dev/null

echo "→ restoring into ${DRILL}"
docker compose exec -T postgres pg_restore -U "$USER" -d "$DRILL" --no-owner --exit-on-error "$DUMP"

echo "→ comparing"
failed=0

for table in "${TABLES[@]}"; do
  before=$(psql -d "$DB" -t -c "SELECT count(*) FROM ${table};" | tr -d ' \r\n')
  after=$(psql -d "$DRILL" -t -c "SELECT count(*) FROM ${table};" | tr -d ' \r\n')

  if [ "$before" = "$after" ]; then
    printf '   %-22s %s\n' "$table" "$before"
  else
    printf '   %-22s %s → %s  MISMATCH\n' "$table" "$before" "$after"
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then
  echo "✖ restore drill failed: the restored database does not match the original."
  exit 1
fi

echo "✔ restore drill passed — every table restored with the same row count."
