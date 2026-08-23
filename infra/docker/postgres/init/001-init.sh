#!/bin/bash
# RefConcept PostgreSQL bootstrap.
#
# Creates the test database alongside the development one and installs the same
# extension set in both, so the test suite exercises the real engine features:
#   vector   — Phase 9 product matching / embedding retrieval
#   pg_trgm  — Phase 10 hybrid search (fuzzy text)
#   citext   — case-insensitive unique e-mail handling
set -euo pipefail

TEST_DB="${POSTGRES_DB}_test"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	SELECT 'CREATE DATABASE ${TEST_DB} OWNER ${POSTGRES_USER}'
	WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${TEST_DB}')\gexec
EOSQL

for db in "$POSTGRES_DB" "$TEST_DB"; do
	psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-EOSQL
		CREATE EXTENSION IF NOT EXISTS "vector";
		CREATE EXTENSION IF NOT EXISTS "pg_trgm";
		CREATE EXTENSION IF NOT EXISTS "citext";
	EOSQL
	echo "extensions ready in ${db}"
done
