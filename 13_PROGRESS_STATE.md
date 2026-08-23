# REFCONCEPT PROGRESS STATE

> Machine-maintained by the Orchestrator.

## Brand
RefConcept

## Milestone
WEB

## Overall Status
IN_PROGRESS

## Current Phase
PHASE_1_IDENTITY_RBAC_ORGANIZATIONS

## Current Task
P1-T001 — identity domain design: users, sessions/tokens, verification, reset.

## Last Completed Task
P0-T007 — Phase 0 gate verified by the Independent Test Agent (see `TEST_REPORT.md`).

## Next Task
Replace the framework's default `users` table with the RefConcept identity schema
(UUIDv7 primary keys, citext e-mail, UTC timestamps) and build the auth surface.

## Test State
PASS — 8 tests / 38 assertions, PHPStan level 6 clean, Pint clean, design token guard clean.

## Release State
NOT_APPROVED

## Blockers
None.

## External Go-Live Dependencies
- iyzico production account/keys
- QNB production merchant/keys
- production bank account/reconciliation source
- production cloud/DNS/storage
- legal/KVKK/e-commerce review
- accounting/tax/settlement review

## Immutable Rule
Flutter/mobile/AR work must not start before `WEB_RELEASE_APPROVED`.

---

## Phase Log

### PHASE_0_REPOSITORY_BOOTSTRAP — DONE (2026-08-23)

```text
UPDATED_AT: 2026-08-23
COMMIT_OR_SNAPSHOT: phase-0-bootstrap
PHASE: 0 — Repository Bootstrap & Design Foundation
TASK: P0-T001 .. P0-T007
STATUS: DONE
FILES_CHANGED:
  docker-compose.yml, Makefile, package.json, README.md, ARCHITECTURE.md, CHANGELOG.md,
  .gitignore, .dockerignore, .editorconfig, .gitattributes, .env.example,
  infra/docker/php/{Dockerfile,php.ini}, infra/docker/nginx/default.conf,
  infra/docker/postgres/init/001-init.sh, infra/docker/compose.bindmount.yml,
  scripts/{rc.ps1,bootstrap.ps1,bootstrap.sh,sync.ps1,sync.sh,check-design-tokens.mjs},
  .github/workflows/ci.yml,
  packages/ui/** (tokens.ts, tokens.css, theme.css, base.css, components/),
  apps/api/** (Laravel 13, Administration domain, health endpoint, Pest, PHPStan, Pint),
  apps/{storefront,seller-portal,admin-panel}/** (Nuxt 4 shells on the shared design system),
  docs/ADR/{ADR-0002,ADR-0003}, TEST_REPORT.md
MIGRATIONS: 3 baseline (users, cache, jobs) — replaced by the RefConcept schema in Phase 1
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · check-design-tokens.mjs
TEST_RESULT: PASS (8 tests, 38 assertions)
BLOCKERS: none
NEXT_ACTION: Phase 1 — Identity / RBAC / Organizations
```

**Environment verified:** PHP 8.3 · Laravel 13.26.1 · PostgreSQL 16 + pgvector 0.8.6 ·
Redis 7 · MinIO (S3) · Mailpit · Nuxt 4 × 3 apps · Node 24.

**Defects found and fixed in this phase:** P0-D001 (P1 — test suite pointed at the
development database), P0-D002, P0-D003, P0-D004. Detail in `TEST_REPORT.md`.

---

## Update Template
```text
UPDATED_AT:
COMMIT_OR_SNAPSHOT:
PHASE:
TASK:
STATUS:
FILES_CHANGED:
MIGRATIONS:
TESTS_RUN:
TEST_RESULT:
BLOCKERS:
NEXT_ACTION:
```
