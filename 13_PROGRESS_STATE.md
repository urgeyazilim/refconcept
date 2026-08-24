# REFCONCEPT PROGRESS STATE

> Machine-maintained by the Orchestrator.

## Brand
RefConcept

## Milestone
WEB

## Overall Status
IN_PROGRESS

## Current Phase
PHASE_4

## Current Task
Not started — Phase 3 is closed and Phase 4 has not begun.

## Last Completed Task
P3-T009 — Phase 3 gate verified end to end (see `TEST_REPORT.md`).

## Next Task
Phase 4, per `04_WEB_PHASE_PLAN.md`, shipping its own UI slice alongside its API.

## Test State
PASS — 213 backend tests / 657 assertions, 12 Playwright E2E journeys across all three
apps, PHPStan level 6, Pint, ESLint, vue-tsc and the design token guard all clean.

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

## Plan Amendment (2026-08-24)

The phase plan deferred every screen to Phase 20, which would leave the product
looking like a shell through eighteen phases and hide integration problems until the
end. **From now on each phase ships its own UI slice** alongside its API. Phase 1's
screens were backfilled: registration, sign-in, e-mail verification, password reset,
account profile, address book and the legal pages.

Phase 20 keeps its original job — the full storefront experience, approved-design
parity and the complete customer journey — but it will polish real screens rather
than build them from nothing.

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

### PHASE_2_SELLER_ONBOARDING — DONE (2026-08-24)

```text
UPDATED_AT: 2026-08-24
COMMIT_OR_SNAPSHOT: phase-2-seller-onboarding
PHASE: 2 — Seller Onboarding
TASK: P2-T001 .. P2-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/*seller* (applications, sellers, profile detail,
    documents, agreements, acceptances, status history, onboarding steps)
  apps/api/app/Domains/Sellers/** (11 models, 6 enums, workflow, checklist,
    document storage, policies, controllers, requests, resources, notifications, tests)
  apps/api/app/Support/ValueObjects/Iban.php
  apps/api/app/Domains/Identity/Console/GrantRoleCommand.php
  apps/api/database/seeders/SellerAgreementsSeeder.php
  apps/seller-portal/** (login, dashboard, onboarding wizard)
  apps/admin-panel/** (login, review queue, application review, seller administration)
  packages/ui/src/runtime/** (shared API and auth composables, typed API contracts)
  tests/e2e/seller-onboarding.spec.ts
MIGRATIONS: 10 seller tables with CHECK constraints, partial unique indexes and an
  immutability trigger on agreement acceptances
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · npm run build/lint/typecheck
  · playwright (9 journeys)
TEST_RESULT: PASS (135 tests, 389 assertions; 9 E2E)
BLOCKERS: none
NEXT_ACTION: Phase 3 — Catalog / PIM
```

**Security properties proven by tests:** IBANs validated by mod-97 and encrypted at
rest with only the last four digits ever returned; onboarding documents on the private
disk under random keys, served by short-lived signed URL after a policy check;
agreement acceptances immutable and checksummed; every decision carrying a mandatory
reason enforced by both the application and a database constraint; complete
seller-to-seller isolation across applications, documents and seller records.

### PHASE_3_CATALOG_PIM — DONE (2026-08-24)

```text
UPDATED_AT: 2026-08-24
COMMIT_OR_SNAPSHOT: phase-3-catalog
PHASE: 3 — Catalog / PIM and the product lifecycle
TASK: P3-T001 .. P3-T009
STATUS: DONE
FILES_CHANGED:
  apps/api/app/Domains/Catalog/** (Category, Brand, Attribute, AttributeValue, Color,
    Material, Style, PublicCatalogController)
  apps/api/app/Domains/Products/** (Product, ProductSku, ProductDimension, ProductMedia,
    ProductAttributeValue, ProductModeration, ProductStatusHistory, four enums,
    ProductPolicy, ProductCompleteness, ProductModerationWorkflow, ProductImageStorage,
    SellerProductController, ProductMediaController, AdminProductModerationController,
    ProductResource, three form requests, ProductLifecycleTest, ProductMediaTest)
  apps/api/config/{filesystems.php,refconcept.php}   (s3-public disk for imagery)
  apps/api/routes/domains/catalog.php
  apps/api/database/migrations/0001_01_01_0000{10,11,12}_*  (taxonomy, products, listings)
  apps/api/database/seeders/{CatalogTaxonomySeeder,DemoAccountsSeeder,DemoCatalogSeeder,
    DatabaseSeeder}.php + database/seeders/assets/products/*.webp
  packages/ui/src/runtime/{types.ts,useMoney.ts}, packages/ui/src/components/RcStatusPill.vue
  apps/seller-portal/app/pages/products/{index,new,[id]}.vue
  apps/seller-portal/app/components/{ProductMediaManager,ProductSkuEditor}.vue
  apps/admin-panel/app/pages/products/{index,[id]}.vue
  apps/storefront/app/pages/catalog/{index,[slug]}.vue
  apps/storefront/app/components/ProductCard.vue
  tests/e2e/product-lifecycle.spec.ts, tests/e2e/support/sellers.ts, support/hydration.ts
  scripts/generate-catalog-imagery.mjs, scripts/optimize-imagery.mjs
MIGRATIONS: 3 (catalog taxonomy, products, seller listings) — materialised category paths,
  a maintained tsvector, a partial unique index for the single cover image, and CHECK
  constraints tying published_at to an approved moderation status
TESTS_RUN: php artisan test · phpstan level 6 · pint · npm run lint/typecheck
  · check-design-tokens.mjs · playwright (12 journeys, three apps)
TEST_RESULT: PASS (213 tests, 657 assertions; 12 E2E)
BLOCKERS: none
NEXT_ACTION: Phase 4
```

**The gate this phase exists for:** a listing reaches a customer only after a reviewer
approves it, and it leaves again the moment anything changes. Visibility takes three
independent conditions — approved by moderation, set active by the seller, and
carrying at least one *purchasable* offer — and the third delegates to the SKU scope,
which also asks whether the offering seller may still trade. Repeating a simpler
condition anywhere would be how a suspended seller's listings stay on sale.

**Money:** every amount is an integer of minor units from the form field to the
database column. The one conversion in the system lives in `useMoney.ts` and is
exercised by a round trip in the end-to-end run: a seller types "48.900,00", the wire
carries `4890000`, and the storefront renders the server's own formatting.

**Imagery:** product photographs are the only anonymously-readable store in the
system, on their own bucket, under random keys, with the file extension derived from
the decoded image type rather than from the uploaded filename. SVG is refused.

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
