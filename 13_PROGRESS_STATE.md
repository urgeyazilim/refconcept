# REFCONCEPT PROGRESS STATE

> Machine-maintained by the Orchestrator.

## Brand
RefConcept

## Milestone
WEB

## Overall Status
IN_PROGRESS

## Current Phase
PHASE_8

## Current Task
Not started — Phase 7 is closed and Phase 8 has not begun.

## Last Completed Task
P7-T007 — Phase 7 gate verified end to end (see `TEST_REPORT.md`).

## Next Task
Phase 8 — AI Room & Design, shipping its own UI slice alongside its API.

## Test State
PASS — 473 backend tests / 1455 assertions, 24 Playwright E2E journeys across all three
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

### PHASE_4_IMPORT_PRICE_INVENTORY — DONE (2026-08-24)

```text
UPDATED_AT: 2026-08-24
COMMIT_OR_SNAPSHOT: phase-4-commerce
PHASE: 4 — Import / Price / Inventory and the seller API foundation
TASK: P4-T001 .. P4-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/app/Domains/Imports/**  (ImportBatch, ImportRow, two enums, SpreadsheetReader,
    ImportColumnMapper, ImportStorage, ProductImportRunner, SellerImportController,
    ProductImportTest)
  apps/api/app/Domains/Pricing/**  (PriceList, PriceListItem, PriceHistory, PriceBook,
    SellerPriceController, PricingTest)
  apps/api/app/Domains/Inventory/**  (StockLocation, StockItem, StockMovement,
    StockReservation, three enums, InsufficientStock, InventoryLedger,
    SellerInventoryController, ReleaseExpiredReservationsCommand, InventoryLedgerTest)
  apps/api/app/Domains/Partners/**  (ApiCredential, ApiRequestLog, CredentialIssuer,
    AuthenticatePartner, SellerApiCredentialController, PartnerStockController,
    PartnerApiTest)
  apps/api/routes/domains/commerce.php, routes/api.php, routes/console.php,
    bootstrap/app.php
  apps/api/database/migrations/0001_01_01_0000{13,14}_*
  apps/api/database/seeders/DemoCatalogSeeder.php
  apps/api/composer.json  (openspout/openspout for streaming CSV and XLSX)
  packages/ui/src/runtime/types.ts
  apps/seller-portal/app/pages/{prices,stock,integrations}.vue
  apps/seller-portal/app/pages/imports/{index,[id]}.vue
  apps/seller-portal/app/layouts/default.vue
  tests/e2e/bulk-import.spec.ts
MIGRATIONS: 2 (pricing and inventory; imports and API credentials) — append-only
  triggers on price_history and stock_movements, CHECK constraints on stock balances,
  partial unique indexes for one default price list, one default stock location and
  one live hold per reference
TESTS_RUN: php artisan test · phpstan level 6 · pint · npm run lint/typecheck
  · check-design-tokens.mjs · playwright (15 journeys)
TEST_RESULT: PASS (290 tests, 878 assertions; 15 E2E)
BLOCKERS: none
NEXT_ACTION: Phase 5 — Projects / Rooms / Design Versions
```

**Import is three steps on purpose.** Upload parses the file once into `import_rows`;
validation reads those rows and writes nothing to the catalogue; commit applies the
ones that passed, row by row in its own transaction. A seller sees exactly what will
happen — how many created, how many updated, which lines are wrong and why — before
anything happens, because there is no undo for a catalogue.

**Stock is a ledger, not a number.** `stock_movements` is the record and `stock_items`
is a snapshot of it, written inside the same locked transaction. Every path takes
`SELECT … FOR UPDATE` and decides from what it reads under the lock; a CHECK
constraint refuses an over-reserved balance even for a caller who forgets to.

**A campaign never overwrites the everyday price.** Campaign prices live in their own
list with a time window, so ending one restores yesterday's prices because nothing
overwrote them — there is no "put it back" step for anybody to forget.

**Partner credentials are not user tokens.** A key/secret pair belongs to a system,
carries its own scopes, is rate-limited per credential, and is revocable without
logging anybody out. The secret is hashed and shown exactly once.

### PHASE_5_PROJECTS_ROOMS_DESIGNS — DONE (2026-08-24)

```text
UPDATED_AT: 2026-08-24
COMMIT_OR_SNAPSHOT: phase-5-projects
PHASE: 5 — Projects / Rooms / Design Versions
TASK: P5-T001 .. P5-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/app/Domains/Projects/**  (Project, ProjectMember, ProjectStatusHistory,
    Room, RoomMedia, RoomConstraint, Design, DesignVersion, DesignAsset, six enums,
    ProjectPolicy, RoomPhotoStorage, DesignVersionTree, DesignVersionRefused,
    ProjectController, ProjectMemberController, RoomController, RoomMediaController,
    DesignController, three test suites)
  apps/api/app/Domains/Catalog/Enums/RoomType.php   (vocabulary shared with the catalogue)
  apps/api/app/Providers/AppServiceProvider.php     (super-admin bypass excluded for
    customer projects)
  apps/api/app/Domains/Sellers/Services/DocumentStorage.php  (Phase 2 route-name fix)
  apps/api/routes/domains/projects.php, routes/api.php
  apps/api/database/migrations/0001_01_01_000015_create_project_and_room_tables.php
  apps/api/database/factories/ProjectFactory.php
  packages/ui/src/runtime/types.ts
  apps/storefront/app/pages/projects/**  (list, project, room, design tree, invitation)
  apps/storefront/app/components/RoomPhotoGallery.vue
  apps/storefront/app/layouts/{default,account}.vue
  tests/e2e/project-journey.spec.ts, tests/e2e/support/sellers.ts (PNG fixture encoder)
MIGRATIONS: 1 (projects, members, status history, rooms, room media, constraints,
  designs, versions, assets) — CHECK constraints tying a measured room to its
  measurements and a finished version to its completion time or its failure reason;
  partial unique indexes for one live membership per person and one render per version
TESTS_RUN: php artisan test · phpstan level 6 · pint · npm run lint/typecheck
  · check-design-tokens.mjs · playwright (18 journeys)
TEST_RESULT: PASS (353 tests, 1045 assertions; 18 E2E)
BLOCKERS: none
NEXT_ACTION: Phase 6 — AI Gateway Foundation
```

**The privacy tier this phase introduces.** A room photograph shows what somebody owns,
how they live and often who they live with. It goes on the private disk under a random
key, no response ever carries a URL or a path, and a link is a separate request that
runs the ownership check and expires in five minutes. The models have no `url()` method
at all, because there is nowhere to point one.

**Platform staff are excluded from the super-admin bypass here.** RefConcept has exactly
one blanket authorization override, which is right for operational tables and would have
been silently wrong for this one. It now skips customer projects, and both directions
are asserted — staff cannot open a project, and can still work the moderation queue.

**The original is immutable, structurally.** Renders live in `design_assets` and
photographs in `room_media`, with different writers. No code path could write an AI
render over a customer's own photograph, which is a stronger guarantee than everybody
remembering not to.

**Designs are a tree.** Every version records the one it came from, numbers are chosen
under a row lock and never reused, only a finished version may be branched from, and a
finished version never changes. Generation itself is Phase 6 and Phase 8; the shape
they fill in is real and fully tested now.

### PHASE_6_AI_GATEWAY_FOUNDATION — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-6-ai-gateway
PHASE: 6 — AI Gateway Foundation
TASK: P6-T001 .. P6-T008
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000016_create_ai_gateway_tables.php
  apps/api/app/Domains/Ai/Enums/**            (AiTask, AiModality, AiJobStatus, AiFailureKind)
  apps/api/app/Domains/Ai/Models/**           (AiProvider, AiProviderCredential, AiModel,
    AiCostRate, PromptTemplate, PromptVersion, AiTaskRoute, AiJob, AiRequest, AiUsage, AiFailure)
  apps/api/app/Domains/Ai/Contracts/AiProvider.php
  apps/api/app/Domains/Ai/Services/**         (AiGateway, AiCall, AiResult, AiJobDispatcher,
    PromptRenderer, StructuredOutputValidator, ProviderRegistry, GeneratedImageStore)
  apps/api/app/Domains/Ai/Providers/**        (FakeAiProvider, OpenAiProvider, GoogleAiProvider)
  apps/api/app/Domains/Ai/Jobs/RunAiJob.php
  apps/api/app/Domains/Ai/Exceptions/AiJobRefused.php
  apps/api/app/Domains/Ai/Policies/**         (AiTaskRoutePolicy, AiJobPolicy)
  apps/api/app/Domains/Ai/Http/**             (AiJobController, AdminAiConfigController,
    AdminAiPromptController, AdminAiObservabilityController, AiJobResource)
  apps/api/app/Domains/Ai/Tests/**            (AiGatewayTest, AiAdminConsoleTest,
    AiJobPrivacyTest, ProviderAdapterTest)
  apps/api/tests/Unit/Ai/StructuredOutputValidatorTest.php
  apps/api/app/Providers/AppServiceProvider.php  (AI policies; AiJob excluded from the bypass)
  apps/api/routes/domains/ai.php, routes/api.php
  apps/api/config/services.php, apps/api/.env.example
  apps/api/database/seeders/{AiGatewaySeeder,DatabaseSeeder}.php
  apps/api/tests/Helpers.php                  (makeAiRoute, makeAiJob)
  apps/admin-panel/app/pages/ai/index.vue, apps/admin-panel/app/layouts/default.vue
  tests/e2e/ai-console.spec.ts, tests/e2e/support/accounts.ts
MIGRATIONS: 11 tables — ai_providers, ai_provider_credentials, ai_models, ai_cost_rates,
  prompt_templates, prompt_versions (immutability trigger), ai_task_routes (one active
  per task, kill switch), ai_jobs, ai_requests, ai_usage, ai_failures
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (413 backend tests / 1250 assertions; 20 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 7 — Credit Economy
```

**Nothing in the application names a model.** Which provider, which model, which prompt
version, what timeout, how many retries, what a call may cost and whether the feature
runs at all are all rows in `ai_task_routes`. Moving a task onto a cheaper model, or
taking it off the site entirely, is configuration — no deploy, and it is audited.

**All the policy is in one place.** Adapters translate one call into one answer and
classify what came back; retries, fallback, cost ceilings, recording and structured-output
validation are the gateway's. An adapter that also retried would be a second home for the
retry rule, and the second home is always the one that drifts.

**Failures are values, not exceptions.** A timeout, a rate limit and a safety refusal
mean three different things: retry the same model, retry the same model, and go straight
to a different provider. An exception would carry the message and throw away the
classification that decides all three. A safety refusal warrants a fallback and not a
retry — providers draw the line in different places.

**The cost ceiling is checked before the call.** An estimate that passes and then
overshoots has protected nothing. Prices live in `ai_cost_rates` with a validity window,
so a job run in March keeps reporting March's price; a rate may only start after the one
it replaces, refused by the endpoint before the CHECK constraint has to.

**A published prompt cannot be edited.** A PostgreSQL trigger refuses the UPDATE, and the
test asserts it both through the API and directly against the table. Improving a prompt
means version 2, which leaves version 1 readable beside every job that ran against it.

**An AI job is a second door into a customer's home,** so it is excluded from the
super-admin bypass alongside projects and rooms. Platform staff get the operational view —
task, model, timings, cost, failure kind, the rendered prompt — and never the payload.
Asserted in both directions: an admin reading a customer's job gets a 403, and the same
admin can still see that renders are failing this morning.

**An image URL never enters the prompt text.** It travels as an attachment, because a URL
pasted into a prompt is a URL a model can repeat back inside an answer somebody else
reads — and this one points at a photograph of somebody's living room.

**Continuous integration never spends a lira.** `FakeAiProvider` answers deterministically
from the call fingerprint and can be scripted to produce any failure on demand, so the
retry, fallback, cost-cap and kill-switch paths are provoked exactly rather than hoped
about.

### PHASE_7_CREDIT_ECONOMY — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-7-credits
PHASE: 7 — Credit Economy
TASK: P7-T001 .. P7-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000017_create_credit_tables.php
  apps/api/app/Domains/Credits/Enums/**      (CreditTransactionType, CreditLotSource,
    ReservationStatus)
  apps/api/app/Domains/Credits/Models/**     (CreditWallet, CreditLot, CreditTransaction,
    CreditReservation, CreditPackage, CreditPromotion, CreditPromotionRedemption)
  apps/api/app/Domains/Credits/Services/**   (CreditLedger, PromotionRedeemer,
    CreditExpirySweeper)
  apps/api/app/Domains/Credits/Exceptions/** (InsufficientCredits, PromotionRefused)
  apps/api/app/Domains/Credits/Console/SweepExpiredCreditsCommand.php
  apps/api/app/Domains/Credits/Http/Controllers/** (CreditWalletController,
    AdminCreditController)
  apps/api/app/Domains/Credits/Tests/**      (CreditLedgerTest, CreditConcurrencyTest,
    CreditPromotionTest, CreditHttpTest, AiJobCreditsTest)
  apps/api/app/Domains/Ai/Services/AiJobCredits.php   (hold → settle for AI jobs)
  apps/api/app/Domains/Ai/Services/AiJobDispatcher.php, Jobs/RunAiJob.php
  apps/api/routes/domains/credits.php, routes/api.php, routes/console.php
  apps/api/bootstrap/app.php
  apps/api/database/seeders/{CreditEconomySeeder,DatabaseSeeder}.php
  apps/storefront/app/pages/account/credits.vue
  apps/storefront/app/layouts/{default,account}.vue
  apps/admin-panel/app/pages/credits/index.vue, app/layouts/default.vue
  tests/e2e/credit-economy.spec.ts
MIGRATIONS: 7 tables — credit_packages, credit_wallets, credit_lots,
  credit_transactions (append-only trigger), credit_reservations, credit_promotions,
  credit_promotion_redemptions
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (473 backend tests / 1455 assertions; 24 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 8 — AI Room & Design
```

**The ledger is the authority; the wallet is a snapshot.** Every movement is an immutable
row carrying the balance it produced, and `credit_wallets` exists only so a page load is
one row rather than a sum over a year. They are written inside one locked transaction, so
they cannot drift — and `reconcile()` puts the check on the support screen rather than in
a report nobody runs.

**Append-only by trigger, not by convention.** An Eloquent guard is a suggestion a raw
query walks straight past, and this is the table a customer's complaint gets settled
against. A mistake is corrected with a compensating entry, which is how a mistake in any
ledger is corrected.

**Credits expire in lots, and the soonest deadline is spent first.** A balance cannot
expire; a grant can. Spending the long-lived credits first would silently destroy the ones
with a date on them, and the customer would see a balance drop for no reason they could
find.

**A hold is not a charge.** An AI job reserves before it runs and consumes or releases
afterwards, so a render that failed because a provider timed out costs the customer
nothing. Three attempts against a flaky provider is one charge: the retry is our decision
and our cost.

**Every mutating path is idempotent on a caller-supplied reference.** A client retrying a
request whose response it never saw is the normal case, not the exceptional one, and
answering it with a second charge is the failure worth engineering against.

**The direction of each movement is a CHECK constraint.** A "consume" that adds credits is
not a rounding error, it is free money — and it would balance perfectly in every report,
which is exactly why the database refuses it rather than trusting the application.

**An adjustment demands a reason, in the schema.** It is the one movement that happens
because a person decided it should, and "why do I have forty fewer credits than yesterday"
needs an answer better than "somebody ran a script". The reason reaches the customer's own
statement, not only an internal log.

**Promotion codes assume somebody is attacking them.** The promotion row is locked before
its redemptions are counted, so two simultaneous claims cannot both pass; an unknown code,
an ended campaign and an exhausted budget all give one identical refusal so the endpoint
cannot be used to enumerate live campaigns; and redemption is rate-limited per account and
requires a verified e-mail, without which a promotion is a free-credit machine for anybody
willing to type a different address each time.

**Credit tables restrict deletion rather than cascading.** A financial record outlives the
account it belonged to, which is also what tax retention requires. Erasing an account
therefore means anonymising the person and keeping the money — an explicit, audited
procedure that belongs to Phase 21 rather than a side effect of a foreign key.

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
