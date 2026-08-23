# REFCONCEPT PROGRESS STATE

> Machine-maintained by the Orchestrator.

## Brand
RefConcept

## Milestone
WEB

## Overall Status
IN_PROGRESS

## Current Phase
PHASE_2_SELLER_ONBOARDING

## Current Task
P2-T001 — seller application intake: company/legal info, contacts, IBAN, documents.

## Last Completed Task
P1-T006 — Phase 1 gate verified by the Independent Test Agent (see `TEST_REPORT.md`).

## Next Task
Seller onboarding workflow: application, legal entity, contacts, bank details,
documents, versioned agreement acceptance, approval/rejection/suspension.

## Test State
PASS — 78 tests / 235 assertions, PHPStan level 6 clean, Pint clean, ESLint clean,
vue-tsc clean, design token guard clean, live end-to-end verified.

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

### PHASE_1_IDENTITY_RBAC_ORGANIZATIONS — DONE (2026-08-24)

```text
UPDATED_AT: 2026-08-24
COMMIT_OR_SNAPSHOT: phase-1-identity
PHASE: 1 — Identity / RBAC / Organizations
TASK: P1-T001 .. P1-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/* (identity, authentication, RBAC, organizations, audit)
  apps/api/app/Domains/Identity/** (models, enums, DTOs, actions, services, requests,
    controllers, resources, policies, middleware, notifications, tests)
  apps/api/app/Domains/Organizations/** (models, enums, policy, tenant isolation tests)
  apps/api/app/Domains/Audit/** (immutable audit log, redacting logger)
  apps/api/app/Support/{Concerns/HasUuidV7,Validation/{EmailRules,PasswordRules}}
  apps/api/app/Providers/AppServiceProvider.php (policies, gates, rate limiters, factories)
  apps/api/config/{auth,cors,refconcept}.php, routes/domains/identity.php
  apps/api/database/seeders/* , apps/api/database/factories/UserFactory.php
  scripts/sync.* (compiled-cache fix)
MIGRATIONS: 7 total — users, user_profiles, user_addresses, personal_access_tokens,
  email_verification_tokens, password_reset_tokens, login_attempts, user_sessions,
  consents, permissions, roles, role_permissions, user_roles, organizations,
  organization_users, audit_logs (+ cache and jobs)
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · npm run build/lint/typecheck
  · live HTTP end-to-end
TEST_RESULT: PASS (78 tests, 235 assertions)
BLOCKERS: none
NEXT_ACTION: Phase 2 — Seller Onboarding
```

**Endpoints delivered:** `POST /api/v1/auth/{register,login,logout,logout-all,
email/verify,email/resend,password/forgot,password/reset}`, `GET /api/v1/auth/me`,
`GET|PATCH /api/v1/profile`, `GET|POST|GET|PATCH|DELETE /api/v1/addresses`.

**Security properties proven by tests:** account-enumeration resistance on login,
password reset and token redemption; hashed single-use tokens; immediate effect of
suspension on live tokens; session revocation on password reset; append-only audit
log enforced by a database trigger; complete seller-to-seller isolation.

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
