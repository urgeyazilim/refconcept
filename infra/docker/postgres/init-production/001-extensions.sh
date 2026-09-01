#!/bin/bash
# RefConcept PostgreSQL bootstrap — production.
#
# Separate from the development script next door, which also creates a `_test` database.
# A test database on a production server is a second copy of the schema that nothing writes
# to, nothing backs up and nobody notices — until somebody points a job at it.
#
# Runs exactly once, when the data directory is empty. On every later start PostgreSQL skips
# this directory entirely, which is why the extensions are also asserted by the health
# endpoint: a database restored from a dump into a fresh volume would otherwise come up
# without them and fail at the first embedding query rather than at boot.
#
#   vector   — product matching and embedding retrieval
#   pg_trgm  — hybrid search, the fuzzy half
#   citext   — case-insensitive unique e-mail addresses
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	CREATE EXTENSION IF NOT EXISTS "vector";
	CREATE EXTENSION IF NOT EXISTS "pg_trgm";
	CREATE EXTENSION IF NOT EXISTS "citext";
EOSQL

echo "refconcept: extensions ready in ${POSTGRES_DB}"
