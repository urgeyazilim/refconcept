# REFCONCEPT PROGRESS STATE

> Machine-maintained by the Orchestrator.

## Brand
RefConcept

## Milestone
WEB

## Overall Status
IN_PROGRESS

## Current Phase
PHASE_21

## Current Task
Not started — Phase 20 is closed; Phases 12 and 13 remain deferred (see below) and Phase 21 has not begun.

## Last Completed Task
P20-T006 — Phase 20 gate verified end to end (see `TEST_REPORT.md`).

## Next Task
Phase 21 — Hardening.

## Test State
PASS — 775 backend tests / 2467 assertions, 76 Playwright E2E journeys across all three
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

## Deferred Phases

### PHASE_12_IYZICO and PHASE_13_QNB — DEFERRED (2026-08-25)

Deferred at the product owner's instruction, and blocked in the same way regardless: both
phases are specified in `06_SECURITY_PAYMENT_FINANCE_RULES.md` as "implement against the
**current official documentation at coding time**", and both need sandbox credentials and a
signed merchant contract that appear in this file's own External Go-Live Dependencies list.
Writing an adapter against remembered documentation would produce something that looks
finished, passes its own tests, and fails on the first real transaction.

Nothing is blocked by the deferral. Phase 11 delivered the provider-agnostic parts — the
five-method `PaymentGateway` contract, the gateway registry, the webhook inbox, the
idempotency layer and the payment state machine — so each adapter is an isolated addition
whose seams already exist and are exercised by the test provider. The marketplace
settlement contract is declared for the same reason.

**To pick these up:** add the adapter under `app/Domains/Payments/Gateways/`, register it in
`AppServiceProvider`, fill in its `config/payments.php` block, and enable it. No other code
has to change.

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

### PHASE_8_AI_ROOM_AND_DESIGN — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-8-design-engine
PHASE: 8 — AI Room & Design
TASK: P8-T001 .. P8-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000018_create_design_generation_tables.php
  apps/api/app/Domains/Projects/Enums/{GenerationStage,RenderQuality}.php
  apps/api/app/Domains/Projects/Models/{RoomAnalysis,DesignPlan,DesignVersionEvent}.php
  apps/api/app/Domains/Projects/Services/{DesignGenerationPipeline,RoomAnalyser,
    PlacementValidator,DesignVersionLauncher}.php
  apps/api/app/Domains/Projects/Jobs/GenerateDesignVersion.php
  apps/api/app/Domains/Projects/Exceptions/DesignGenerationFailed.php
  apps/api/app/Domains/Projects/Services/RoomPhotoStorage.php  (storeRenderFromRef/Url)
  apps/api/app/Domains/Projects/Http/Controllers/DesignController.php  (+ progress)
  apps/api/app/Domains/Ai/Services/{AiJobDispatcher,AiResult,GeneratedImageStore,
    AiGateway,StructuredOutputValidator}.php
  apps/api/app/Domains/Ai/Providers/{OpenAiProvider,GoogleAiProvider,FakeAiProvider}.php
  apps/api/app/Support/Text/TurkishText.php   (+ the three import services)
  apps/api/bootstrap/app.php                  (credit and gateway refusals rendered)
  apps/api/app/Domains/Projects/Tests/DesignGenerationTest.php
  apps/api/tests/Unit/Support/TurkishTextTest.php
  apps/storefront/app/pages/projects/[id]/rooms/[roomId]/designs/[designId].vue
  packages/ui/src/runtime/types.ts
  tests/e2e/design-generation.spec.ts, tests/e2e/project-journey.spec.ts
MIGRATIONS: 3 tables — room_analyses (one current per room), design_plans (immutable by
  trigger), design_version_events; design_versions gains render_quality and
  credit_reservation_id
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (493 backend tests / 1528 assertions; 26 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 9 — Product Matching
```

**Three steps, one charge.** Read the room, decide the layout, draw it — and the credits
are held once when the version is created and settled once when it finishes. Charging per
step would mean somebody paying for an analysis and a plan and then getting nothing
because the render failed, which is indefensible however defensible each charge looks.

**The analysis is cached against the photograph, not the design.** A room does not change
because somebody tried a second style, so the second render reuses the first reading — one
fewer provider call, and the quote drops the step so nobody is billed for a call that will
not happen.

**The model is good at style and bad at arithmetic.** `PlacementValidator` refuses a
2600mm sofa against a 2200mm wall and records why. The render would have looked fine — an
image is not to scale — while the shopping list contained furniture that does not fit
through the customer's living room.

**Every step announces itself.** A render takes the better part of a minute and a spinner
that says nothing is indistinguishable from one that has hung. `design_version_events` is
append-only, carries nothing sensitive, and turns "it is slow" into "it is slow at the
render step".

**Provider images are staged privately, not publicly.** The first form of
`GeneratedImageStore` wrote to the public bucket; that was wrong, and it is fixed here.
What passes through it is a render of the inside of somebody's home, and a random key does
not make an anonymously-readable copy acceptable. Images now land on the private disk, the
pipeline copies what it wants and discards the staged copy.

**A real Turkish bug, found and fixed.** `mb_strtolower('İ')` produces an i plus a
combining dot, not a plain i. A spreadsheet column headed "İndirimli fiyat" folded to
"i ndirimli fiyat", matched no alias, and the discount prices silently never arrived.
`TurkishText` now folds before lowercasing, in one place, with tests.

### PHASE_9_PRODUCT_MATCHING — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-9-matching
PHASE: 9 — Product Matching
TASK: P9-T001 .. P9-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000019_create_matching_tables.php
  apps/api/app/Domains/Matching/Enums/{EmbeddingSource,MatchStatus,FeedbackVerdict}.php
  apps/api/app/Domains/Matching/Models/{ProductEmbedding,DesignMatch,DesignMatchFeedback}.php
  apps/api/app/Domains/Matching/Services/{ProductEmbedder,CandidateQuery,ShoppingListBuilder}.php
  apps/api/app/Domains/Matching/Console/EmbedCatalogueCommand.php
  apps/api/app/Domains/Matching/Http/Controllers/DesignMatchController.php
  apps/api/app/Domains/Matching/Tests/{ProductMatchingTest,ShoppingListTest}.php
  apps/api/app/Domains/Ai/Enums/AiTask.php            (TextEmbedding)
  apps/api/app/Domains/Ai/Services/{AiResult,AiGateway}.php
  apps/api/app/Domains/Ai/Providers/{OpenAiProvider,GoogleAiProvider,FakeAiProvider}.php
  apps/api/app/Domains/Projects/Enums/GenerationStage.php  (match stage)
  apps/api/app/Domains/Projects/Services/DesignGenerationPipeline.php
  apps/api/routes/domains/projects.php, routes/console.php, bootstrap/app.php
  apps/api/database/seeders/AiGatewaySeeder.php
  apps/storefront/app/pages/projects/[id]/rooms/[roomId]/designs/[designId].vue
  tests/e2e/design-generation.spec.ts, tests/e2e/auth-journey.spec.ts
MIGRATIONS: 4 tables — product_embeddings (pgvector 768, HNSW cosine index),
  design_extracted_objects, design_matches, design_match_feedback
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (521 backend tests / 1592 assertions; 27 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 10 — Search / Favorites / Cart
```

**Narrow first, then rank.** Category, stock, budget and width are applied in SQL before
anything is scored. A model asked to respect "no wider than 2200mm" will sometimes; a
`WHERE width_mm <= 2200` always does. What is left for the vector is the part that is
genuinely a matter of resemblance.

**A category that does not match anything returns nothing.** Silently dropping the filter
would let the search fall back to the nearest products in the whole catalogue — which is
how a plan asking for a chandelier ends up recommending a wardrobe, with nothing looking
wrong.

**The rerank is optional in the strongest sense.** If the model call fails, the list built
from similarity is returned unchanged. A customer with a slightly worse-ordered shopping
list is far better off than one with no shopping list.

**Prices are snapshots.** A customer who comes back next week sees the list they were
shown, with today's price beside it when the two differ. Hiding the change would be the
wrong kind of tidy — the difference is the most useful thing the row can tell them.

**Feedback is the only honest signal.** Similarity scores are the system marking its own
homework. Each verdict names the part of the pipeline it blames — wrong size is a filter
bug, wrong style is a modelling problem — and the one thing it changes automatically is
that a rejected product is not suggested again for that spot.

**Embedding is hashed, so a nightly pass over an unchanged catalogue costs one query.**
The text embedded is assembled from what describes the product, in a fixed order, with the
seller's name and delivery terms deliberately left out: two sofas from the same shop must
not be similar *because* of the shop.

### PHASE_10_SEARCH_FAVORITES_CART — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-10-shopping
PHASE: 10 — Search / Favorites / Cart
TASK: P10-T001 .. P10-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000020_create_cart_and_favorite_tables.php
  apps/api/app/Domains/Commerce/Enums/{CartStatus,LineIssue}.php
  apps/api/app/Domains/Commerce/Models/{Cart,CartItem,Favorite}.php
  apps/api/app/Domains/Commerce/Services/{CartService,CatalogSearch}.php
  apps/api/app/Domains/Commerce/Exceptions/CartRefused.php
  apps/api/app/Domains/Commerce/Http/Controllers/{CartController,FavoriteController}.php
  apps/api/app/Domains/Commerce/Tests/{CartRaceTest,SearchAndFavoritesTest}.php
  apps/api/app/Domains/Catalog/Http/Controllers/PublicCatalogController.php  (hybrid + facets)
  apps/api/app/Domains/Inventory/Services/InventoryLedger.php  (reservationsFor)
  apps/api/app/Domains/Ai/Providers/GoogleAiProvider.php  (embedding width, normalisation)
  apps/api/database/seeders/AiGatewaySeeder.php
  apps/api/routes/domains/shopping.php, routes/api.php, bootstrap/app.php
  apps/storefront/app/pages/{cart,favorites}.vue
  apps/storefront/app/pages/catalog/[slug].vue, app/layouts/default.vue
  tests/e2e/shopping.spec.ts
MIGRATIONS: 3 tables — favorites, carts (one open per customer, partial unique index),
  cart_items (one line per SKU); plus a trigram index on products.name
TESTS_RUN: php artisan test · phpstan level 6 · pint --test · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (561 backend tests / 1697 assertions; 32 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 11 — Checkout / Payment Core
```

**Stock is not held while something sits in a basket.** Holding it would mean a browser
tab left open for a week keeps a sofa off the market, and a marketplace's job is to sell
the sofa. The hold is taken at checkout, for fifteen minutes, by the ledger built in Phase
4 — all of a basket or none of it, with rows locked in a fixed order so two baskets queue
rather than deadlock.

**A price is snapshotted when a line is added, and never silently changed.** Revalidation
reports: a rise is shown with both figures and has to be accepted, a fall blocks nothing,
something sold out is removed and said so. Charging a customer more than they were shown
is the failure this whole mechanism exists to prevent.

**The basket is grouped by seller** because that is what a marketplace basket is — several
parcels from several shops, arriving on different days — and the seller is recorded on the
line so it keeps saying who was selling something after the offer is withdrawn.

**Search is three methods fused by rank**, not by score: a trigram similarity, a `ts_rank`
and a cosine distance are numbers on unrelated scales and adding them is arithmetic without
meaning. Reciprocal rank fusion asks each only for an ordering.

**The vector ranks but does not decide.** Measured against the live embedding model, pure
nonsense sits about 0.35 from its nearest product and a real keyword match about 0.30 — six
hundredths is not a margin to build a search box on. So a query with no lexical footing
returns nothing, which costs the purely semantic case and is far better than answering
gibberish with a page of sofas.

**Facets are counted before pagination and exclude their own filter**, so a count tells a
customer what is behind a filter they have not clicked yet — which is the only thing a
facet count is for.

### PHASE_11_CHECKOUT_PAYMENT_CORE — DONE (2026-08-25)

```text
UPDATED_AT: 2026-08-25
COMMIT_OR_SNAPSHOT: phase-11-payments
PHASE: 11 — Checkout / Payment Core
TASK: P11-T001 .. P11-T008
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000021_create_payment_tables.php
  apps/api/app/Domains/Payments/Enums/{PaymentStatus,CheckoutStatus,CheckoutPurpose}.php
  apps/api/app/Domains/Payments/Contracts/{PaymentGateway,MarketplaceSettlementGateway}.php
  apps/api/app/Domains/Payments/Gateways/FakePaymentGateway.php
  apps/api/app/Domains/Payments/Models/{CheckoutSession,PaymentIntent,PaymentTransaction,
    PaymentWebhookEvent,IdempotencyKey}.php
  apps/api/app/Domains/Payments/Services/{CheckoutService,PaymentProcessor,CheckoutFulfiller,
    GatewayRegistry,WebhookInbox,WebhookProcessor,PaymentRequest,PaymentResult,RefundRequest,
    RefundResult,CancelRequest,CancelResult,WebhookEvent,GatewayResult,SellerGatewayProfile}.php
  apps/api/app/Domains/Payments/Http/Controllers/{CheckoutController,PaymentWebhookController,
    FakeGatewayController}.php
  apps/api/app/Domains/Payments/Http/Middleware/EnsureIdempotentRequest.php
  apps/api/app/Domains/Payments/Jobs/ProcessPaymentWebhook.php
  apps/api/app/Domains/Payments/Console/ExpireCheckoutSessionsCommand.php
  apps/api/app/Domains/Payments/Exceptions/{CheckoutRefused,GatewayUnavailable}.php
  apps/api/app/Domains/Payments/Tests/{PaymentCoreTest,CheckoutHttpTest}.php
  apps/api/app/Domains/Commerce/Services/CartService.php  (own-hold revalidation)
  apps/api/app/Domains/Inventory/Services/InventoryLedger.php  (stock projection)
  apps/api/app/Domains/Products/Models/{Product,ProductSku}.php  (isOffered / isListable)
  apps/api/resources/views/payments/fake-challenge.blade.php
  apps/api/config/payments.php, routes/domains/payments.php, routes/api.php,
    routes/console.php, bootstrap/app.php, app/Providers/AppServiceProvider.php
  packages/ui/src/runtime/types.ts  (checkout and payment types)
  apps/storefront/app/pages/checkout/{index,return}.vue
  apps/storefront/app/pages/cart.vue, app/pages/account/credits.vue
  tests/e2e/checkout.spec.ts, tests/e2e/support/catalog.ts, tests/e2e/shopping.spec.ts
MIGRATIONS: 5 tables — checkout_sessions (one live per purpose per customer),
  payment_intents (one live per session, unique gateway external id),
  payment_transactions (append-only, trigger-enforced), payment_webhook_events
  (deduped on provider event id and body fingerprint), idempotency_keys
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (594 backend tests / 1804 assertions; 38 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 12 — iyzico
```

**Everything here can be asked the same question twice.** That is the phase in one
sentence. Providers retry: they send the same webhook four times, they call back after the
browser already returned, they time out with the money already taken. A payment system
that is not idempotent end to end does not fail loudly — it charges somebody twice, and
nobody notices until the reconciliation.

**The checkout session freezes what is being paid for.** Between pressing "pay" and the
bank answering there is a redirect, a 3DS page and often minutes, and in those minutes the
seller can reprice and the address book can be edited. So the session copies the totals and
the address text in and stops asking anybody.

**The status is only ever written through one method, and only along declared
transitions.** News arrives out of order — a late `failed` for a payment that has since
captured is dropped, deliberately, because the alternative is a record saying we were not
paid while the money sits in the account.

**A webhook is stored before it is understood.** The endpoint writes a row and answers 200;
the meaning is worked out on a worker. Doing the domain work inline is how a slow database
turns into a provider retry, which turns into a second delivery, which turns into a
customer credited twice. Duplicates are answered 200 for the same reason: a provider told
that a duplicate failed will resend it forever.

**Two duplicate defences, not one.** The inbox refuses a second copy of the same delivery,
keyed on the provider's event id *and* a fingerprint of the raw body. But a provider may
also send two genuinely different events saying the same thing, and those are duplicates by
no fingerprint — the state machine stops those, because captured→captured is not a
transition.

**Card data never enters this codebase.** Not the PAN, not the CVV, not the expiry. The
customer types it on the provider's own page or into its SDK and we receive a token. That
is the line between being in PCI-DSS scope and not, and no debugging convenience is worth
crossing it. The processor also redacts provider responses before storing them, belt and
braces.

**Two defects found by the payment journeys, both older than this phase.** A basket that
took the last of the stock into checkout and then reloaded was emptied and told the thing
it was buying was sold out — by its own hold. And `product_skus.stock_quantity`, which the
catalogue's list query reads, was written only by the seller's own stock endpoint, so
buying the last unit left the listing advertising stock until a seller happened to open the
stock page. Both are fixed with tests; detail in `TEST_REPORT.md`.

### PHASE_14_BANK_TRANSFER — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-14-bank-transfer
PHASE: 14 — Bank Transfer
TASK: P14-T001 .. P14-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000022_create_bank_transfer_tables.php
  apps/api/app/Domains/Payments/Enums/BankTransferStatus.php
  apps/api/app/Domains/Payments/Gateways/BankTransferGateway.php
  apps/api/app/Domains/Payments/Models/{BankTransfer,PaymentBankAccount,PaymentReceipt}.php
  apps/api/app/Domains/Payments/Services/{BankTransferService,ReceiptStorage,CheckoutService}.php
  apps/api/app/Domains/Payments/Http/Controllers/{BankTransferController,AdminPaymentController,
    CheckoutController}.php
  apps/api/app/Domains/Payments/Console/ExpireBankTransfersCommand.php
  apps/api/app/Domains/Payments/Exceptions/CheckoutRefused.php
  apps/api/app/Domains/Payments/Tests/{BankTransferTest,BankTransferHttpTest}.php
  apps/api/app/Domains/Identity/Enums/{Permission,SystemRole}.php  (payments.view / payments.settle)
  apps/api/app/Domains/Inventory/Services/InventoryLedger.php  (extendHolds)
  apps/api/database/seeders/{BankAccountSeeder,DatabaseSeeder}.php
  apps/api/config/payments.php, routes/domains/payments.php, routes/console.php, bootstrap/app.php,
    app/Providers/AppServiceProvider.php
  packages/ui/src/runtime/types.ts
  apps/storefront/app/pages/checkout/index.vue
  apps/storefront/app/pages/checkout/transfer/[reference].vue
  apps/admin-panel/app/pages/payments/index.vue, app/layouts/default.vue
  tests/e2e/bank-transfer.spec.ts
MIGRATIONS: 3 tables — payment_bank_accounts, bank_transfers (reference unique for all time,
  one live and one settled per intent), payment_receipts
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (619 backend tests / 1894 assertions; 42 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 15 — Orders / Seller Orders
```

**The reference is the whole mechanism.** A transfer arrives at the bank as a line on a
statement with a name and an amount and very little else; the code the customer types into
the description field is the only thing tying that line to an order. So it is unique for
all time rather than merely among live transfers, and it is drawn from an alphabet with no
0/O and no 1/I/L — a character pair that looks identical in one bank's font is a payment
nobody can match.

**Short and over payments are states, not flags.** People transfer the wrong figure
constantly: a typo, an intermediary bank's fee taken in transit, two orders paid in one go.
A boolean "paid?" forces an operator to decide privately whether 4.997,50₺ is close enough,
and leaves no trace of the decision. `short_paid` releases nothing and states the
difference; `over_paid` releases the order and records a surplus somebody owes back.

**Stock is held for the transfer window, not the card window.** Two days rather than
fifteen minutes, because a customer told their goods are reserved and then losing them
overnight has been lied to. That is a real cost borne against a payment that may never
arrive, which is why the window is configured rather than generous and why an unpaid
transfer is expired promptly — and why expiring one releases its stock itself rather than
waiting for the checkout sweeper's separate clock to agree.

**Confirmation happens once**, enforced three ways: a row lock and a state check that
refuses the second operator with a sentence, and a partial unique index behind both.
Reading a payment and settling one are separate permissions, because answering "did it
arrive" is a support job and deciding that it did releases goods and cannot be undone.

**The receiving IBAN is stored in plain text**, a deliberate exception to the rule that
IBANs are encrypted at rest. That rule protects sellers' payout details, which are personal
data. These are the platform's own accounts, printed on the checkout page for every
customer to copy — encrypting a number we publish would be theatre.

### PHASE_15_ORDERS_SELLER_ORDERS — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-15-orders
PHASE: 15 — Orders / Seller Orders
TASK: P15-T001 .. P15-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000023_create_order_tables.php
  apps/api/app/Domains/Orders/Enums/{OrderStatus,SellerOrderStatus}.php
  apps/api/app/Domains/Orders/Models/{Order,SellerOrder,OrderItem,OrderStatusChange}.php
  apps/api/app/Domains/Orders/Services/{OrderFactory,OrderNumbers,OrderStatusService}.php
  apps/api/app/Domains/Orders/Http/Controllers/{OrderController,SellerOrderController}.php
  apps/api/app/Domains/Orders/Notifications/{SellerOrderPlaced,SellerOrderShipped}.php
  apps/api/app/Domains/Orders/Exceptions/OrderRefused.php
  apps/api/app/Domains/Orders/Tests/{MultiSellerOrderTest,OrderHttpTest}.php
  apps/api/app/Domains/Payments/Services/CheckoutFulfiller.php  (the order seam)
  apps/api/routes/domains/orders.php, routes/api.php, bootstrap/app.php
  packages/ui/src/runtime/types.ts
  apps/storefront/app/pages/account/orders/{index,[number]}.vue
  apps/storefront/app/layouts/account.vue
  apps/seller-portal/app/pages/orders/index.vue, app/layouts/default.vue
  tests/e2e/multi-seller-order.spec.ts, tests/e2e/support/catalog.ts
MIGRATIONS: 4 tables + 1 sequence — orders (one per checkout, enforced by a unique index),
  seller_orders (one per seller per order), order_items, order_status_history (append-only)
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (645 backend tests / 1963 assertions; 46 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 16 — Commission / Ledger / Settlement
```

**A marketplace order is two things at once, and the schema says so.** The customer bought
one thing: they paid once, for a basket, and will ask about it by one number. The sellers
each received a separate instruction: their own parcel, their own warehouse, their own
courier, their own money. Modelling only the first leaves every seller screen filtering a
shared table by hand; modelling only the second leaves a customer with three orders they
never placed.

**Everything on a line is a snapshot.** The product name, the SKU code, the price, the tax
rate and the commission are copied at the moment of the order. A product renamed next month
must not change what an invoice from last month says it was, and a seller who renegotiates
their rate must not retroactively change what they earned. An order is a record of an
event, not a view over the current state of the catalogue.

**The master status is derived, never set.** It is computed from the seller orders after
every change, because a summary that can be written independently of what it summarises
will eventually disagree with it — and then nobody can tell which of the two is lying.
`partially_shipped` exists for the same reason the split does: telling a customer their
order has shipped while two parcels are still on shelves is technically true and
practically a lie.

**One payment makes one order**, guaranteed by a unique index on the checkout session
rather than by the caller being careful. A confirmation delivered four times reaches the
factory four times and produces one order — the same defence that protects the credit load
beside it.

**A seller cannot cancel what has already left.** What happens after a parcel is in a van
is a return, with a different set of rights; allowing "cancel" there would leave the money
and the goods in disagreement. Cancelling before that puts the stock back on the shelf,
because the stock left when the payment was captured and a warehouse that disagrees with
the ledger only reveals it weeks later as a sale nobody can fulfil.

### PHASE_16_COMMISSION_LEDGER_SETTLEMENT — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-16-finance
PHASE: 16 — Commission / Ledger / Settlement
TASK: P16-T001 .. P16-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000024_create_finance_tables.php
  apps/api/app/Domains/Finance/Enums/{LedgerAccount,SettlementStatus}.php
  apps/api/app/Domains/Finance/Models/{CommissionRule,LedgerAccountRow,LedgerEntry,LedgerLine,
    Settlement,SettlementItem}.php
  apps/api/app/Domains/Finance/Services/{Ledger,JournalLine,CommissionResolver,CommissionDecision,
    OrderAccounting,SettlementEligibility,SettlementService}.php
  apps/api/app/Domains/Finance/Http/Controllers/{AdminFinanceController,SellerEarningsController}.php
  apps/api/app/Domains/Finance/Console/BuildSettlementsCommand.php
  apps/api/app/Domains/Finance/Exceptions/SettlementRefused.php
  apps/api/app/Domains/Finance/Tests/{FinancialInvariantTest,FinanceHttpTest}.php
  apps/api/app/Domains/Orders/Services/{OrderFactory,OrderStatusService}.php  (the finance hooks)
  apps/api/database/seeders/{CommissionSeeder,DatabaseSeeder}.php
  apps/api/config/refconcept.php, routes/domains/finance.php, routes/api.php, routes/console.php,
    bootstrap/app.php
  packages/ui/src/runtime/types.ts
  apps/seller-portal/app/pages/earnings.vue, app/layouts/default.vue
  apps/admin-panel/app/pages/finance/index.vue, app/layouts/default.vue
  tests/e2e/settlement.spec.ts, tests/e2e/support/catalog.ts
MIGRATIONS: 7 tables — commission_rules, ledger_accounts, ledger_entries, ledger_lines
  (append-only, deferred balance trigger), seller_balances, settlements, settlement_items
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (678 backend tests / 2063 assertions; 50 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 17 — Shipping / Return / Refund
```

**A marketplace's cash is mostly a liability.** It holds money it does not own: some of
what a customer pays is commission and the rest is owed to sellers. So a sale posts a debit
to cash and credits to each seller's payable plus commission revenue. Posting the whole
amount as income and the payouts as expenses would balance perfectly and describe a
completely different business — one that is enormously profitable right up until it pays
its sellers.

**Every entry balances, twice over.** Checked in the service with a message naming the
figures, and again by a **deferred constraint trigger** that runs at commit — the only way
to express "this must balance" in a database, because an entry is built line by line and
can only be judged as a whole.

**Nothing is ever edited.** Both tables refuse UPDATE and DELETE outright. A mistake is
corrected by a reversing entry, so the mistake and the correction both stay visible; that
is the difference between a ledger and a table of numbers.

**The commission hierarchy has six rungs and the first is the snapshot.** Campaign, seller
+category, seller, category, platform default — resolved once, at order time, and copied
onto the line. Re-resolving later would let a rate change rewrite what a seller earned last
quarter. The decision carries the rule that produced it, because "why is my commission 14%"
is the question sellers ask most and "because of the September campaign" is an answer they
can act on.

**Building, approving and paying are three separate acts.** Building is arithmetic and can
be re-run; approving commits the money into a clearing account so it cannot be counted
twice; paying is a person recording that a transfer left, with the bank's own reference.
Collapsing them would turn a mistake in the arithmetic into a bank transfer.

**A seller sees four figures, not one** — ready, pending, in payout, paid — because the
money genuinely is in four states, and each unpaid order carries a sentence rather than a
status: "12.09.2026 tarihinde hakedişe girer" is something a seller can plan around.

### PHASE_17_SHIPPING_RETURN_REFUND — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-17-fulfilment
PHASE: 17 — Shipping / Return / Refund
TASK: P17-T001 .. P17-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000025_create_shipping_return_tables.php
  apps/api/app/Domains/Fulfilment/Enums/{ReturnStatus,RefundStatus}.php
  apps/api/app/Domains/Fulfilment/Models/{Shipment,ShipmentItem,ReturnRequest,ReturnItem,Refund}.php
  apps/api/app/Domains/Fulfilment/Services/{ShipmentService,ReturnService,RefundService}.php
  apps/api/app/Domains/Fulfilment/Http/Controllers/{ReturnController,SellerFulfilmentController,
    AdminRefundController}.php
  apps/api/app/Domains/Fulfilment/Exceptions/FulfilmentRefused.php
  apps/api/app/Domains/Fulfilment/Tests/{ReturnRefundTest,FulfilmentHttpTest}.php
  apps/api/app/Domains/Finance/Services/SettlementEligibility.php  (the return hold)
  apps/api/app/Domains/Orders/Models/SellerOrder.php  (returns and shipments)
  apps/api/app/Domains/Payments/Gateways/FakePaymentGateway.php  (a refusable refund)
  apps/api/app/Domains/Payments/Services/PaymentProcessor.php  (a failed refund is retryable)
  apps/api/config/refconcept.php, routes/domains/fulfilment.php, routes/api.php, bootstrap/app.php
  packages/ui/src/runtime/types.ts
  apps/storefront/app/pages/account/returns/{index,new}.vue
  apps/storefront/app/pages/account/orders/[number].vue, app/layouts/account.vue
  apps/seller-portal/app/pages/returns.vue, app/layouts/default.vue
  tests/e2e/return-refund.spec.ts
MIGRATIONS: 5 tables — shipments, shipment_items, returns, return_items, refunds
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (709 backend tests / 2164 assertions; 55 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 18 — Super Admin Complete
```

**Three things that look like one and are not.** A *shipment* is a physical parcel with a
carrier and a tracking number, and a seller order can have several — a sofa and its
cushions leave on different days, so "shipped" is a property of a parcel. A *return* is a
customer asking, with its own lifecycle and its own clock. A *refund* is money moving, and
it is deliberately separate from the return because goods and money travel on different
timetables: a return can be approved and the refund fail at the provider, and a refund can
be issued as goodwill with nothing coming back. Folding them into one field makes both
impossible to represent and therefore impossible to fix.

**Everything is per line and per quantity.** A customer who bought four chairs and wants
to return one is the ordinary case; an order-level model turns it into a support
conversation. Every return line carries both a requested and an approved quantity, because
a seller opening the box and accepting two of three is normal.

**`received` and `completed` are separate states**, and so are the two buttons. A parcel
arriving is a physical fact; deciding the return is finished is what releases money. One
button would turn a courier's delivery scan into a refund.

**A failed refund is a state, not a swallowed exception.** A provider outage is the
commonest cause, the operation is safe to repeat, and the customer is owed the money either
way. Nothing is posted to the ledger until the money has actually gone — the books must
never say a refund happened when it did not.

**The reversal is posted per share**: the seller's payable down by their part, commission
down by its part, at the rate that was charged. Posting the whole refund against commission
would make the platform pay for the seller's return; keeping the commission would mean the
platform earns on a sale that did not happen.

**An open return holds the payout** — E2E-09 — and the settlement hold can never be shorter
than the return window, because a configuration where it was would pay a seller while the
customer could still send everything back.

### PHASE_18_SUPER_ADMIN_COMPLETE — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-18-super-admin
PHASE: 18 — Super Admin Complete
TASK: P18-T001 .. P18-T007
STATUS: DONE
FILES_CHANGED:
  apps/api/database/migrations/0001_01_01_000026_create_platform_settings_tables.php
  apps/api/app/Domains/Administration/Services/{AdminPermissionMatrix,PlatformSettings,Features}.php
  apps/api/app/Domains/Administration/Http/Middleware/EnforceAdminPermission.php
  apps/api/app/Domains/Administration/Http/Controllers/{AdminAnalyticsController,AdminAuditController,
    AdminOrderController,AdminSystemController}.php
  apps/api/app/Domains/Administration/Models/{FeatureFlag,SystemSetting}.php
  apps/api/app/Domains/Administration/Tests/{AdminPermissionMatrixTest,CriticalActionAuditTest,
    PlatformSwitchesTest}.php
  apps/api/app/Domains/Identity/Enums/{Permission,SystemRole}.php
  apps/api/app/Domains/Ai/Services/AiJobDispatcher.php  (the platform kill switch)
  apps/api/app/Domains/Payments/Services/GatewayRegistry.php  (a payment method an operator can close)
  apps/api/app/Domains/Finance/Services/SettlementEligibility.php  (hold days from the settings)
  apps/api/app/Domains/Fulfilment/Services/ReturnService.php  (window days from the settings)
  apps/api/app/Domains/Sellers/Http/Controllers/AdminSellerController.php  (the seller's own record)
  apps/api/database/seeders/{PlatformSettingsSeeder,DatabaseSeeder}.php
  apps/api/routes/domains/{administration,sellers}.php, routes/api.php, bootstrap/app.php
  packages/ui/src/runtime/types.ts
  apps/admin-panel/app/pages/{analytics,orders,audit,system}/index.vue, app/layouts/default.vue
  tests/e2e/super-admin.spec.ts
MIGRATIONS: 2 tables — feature_flags, system_settings
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (747 backend tests / 2372 assertions; 61 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 19 — Seller Portal Complete
```

**The gate is a property, not a checklist.** "These endpoints are protected" is a list
somebody has to keep up to date, and the entry that is missing is invisible. What is
enforced instead is **no administrative endpoint can exist without a decision about who may
call it**: a matrix maps route-name prefixes to permissions, middleware on the whole API
group consults it, and a route with no entry is refused at runtime and fails the suite at
build time. `uncovered()` returning anything is a build failure.

**Failing closed is the whole design.** An unknown admin route is a 403 — not a 404 and not
a pass — because "we have not decided who may do this yet" is much closer to "nobody" than
to "everybody". The middleware self-selects on the path rather than being attached route by
route, because a check that has to be remembered is a check that is invisible when it is
missing.

**The longest prefix wins.** Reading a settlement and approving one live under the same
prefix and are different powers; a matrix that could not express that would hand the second
to everybody who needed the first.

**An operator may not touch the platform's own switches.** Everything else on their screen
has a blast radius of one order or one seller; turning a feature on for everybody is a
release decision, and it is the one power here whose blast radius is the whole platform.
The audit screen therefore also shows the caller their own permissions, so a button they
cannot press is explained by a page rather than by a 403.

**Every critical action leaves a record with a reason.** Money leaving, goods released,
access changed, the platform's behaviour altered — thirteen cases perform the action for
real and then assert the trail: what happened, who did it, and, where it costs somebody
something, why. The trail is append-only at the database level, so the record of a decision
cannot be edited by whoever made it.

**A settings screen that writes rows nothing reads is worse than no screen.** It tells
whoever used it that they changed the platform, and they will act on that belief. So the
hold period, the return window and each flag are read by the services that obey them, with
the environment as the floor and a stored row as the override — one order, stated once, and
cleared from cache on write so a change made during an incident takes effect on the next
click.

**A missing flag is on.** A feature that switched itself off because somebody forgot to
seed a row would be an outage caused by the safety mechanism, which is the worst way to
have one. Turning something off is a decision, and a decision has a row. A partial rollout
buckets on a stable hash of key and user, so somebody who has the feature keeps it.

**A secret is never echoed back**, not to the screen and not into the audit log — an audit
trail is read by more people than a secret store is. And an unverified webhook is never
replayed: anybody can post one, and replaying it would let them fabricate a payment.

**Phase 18 found a real collision and fixed it properly.** A seller reading their own
record went through `/admin/sellers/{id}`, where the new rule asks whether the caller holds
a platform permission — which a seller never does. Rather than carve an exception into the
authorisation rule (a rule with an exception in it is a rule nobody can state), the seller
got `/api/v1/seller/profile`, and the administrative path stayed administrative.

### PHASE_19_SELLER_PORTAL_COMPLETE — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-19-seller-portal
PHASE: 19 — Seller Portal Complete
TASK: P19-T001 .. P19-T006
STATUS: DONE
FILES_CHANGED:
  apps/api/app/Domains/Sellers/Services/SellerTeam.php
  apps/api/app/Domains/Sellers/Exceptions/TeamRefused.php
  apps/api/app/Domains/Sellers/Http/Controllers/{SellerTeamController,SellerDashboardController}.php
  apps/api/app/Domains/Sellers/Tests/{SellerTeamTest,SellerDashboardTest}.php
  apps/api/app/Domains/Fulfilment/Http/Controllers/SellerFulfilmentController.php  (pending lines)
  apps/api/app/Domains/Fulfilment/Tests/FulfilmentHttpTest.php
  apps/api/app/Domains/Identity/Enums/SystemRole.php  (staff may read the team)
  apps/api/routes/domains/sellers.php, bootstrap/app.php
  packages/ui/src/runtime/types.ts
  apps/seller-portal/app/pages/{index,team,shipping}.vue, app/layouts/default.vue
  tests/e2e/seller-portal.spec.ts
MIGRATIONS: none — the membership and role tables have been in place since Phase 1
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (775 backend tests / 2467 assertions; 67 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 20 — Storefront Complete + Approved Design Language
```

**A seller is a company, not a person.** Somebody dispatches parcels, somebody else answers
returns, and the person whose name is on the bank account does neither. A platform that
does not model that has not removed the problem — the company solves it by sharing one
login, and then every audit entry says "the seller" and means nobody.

**Two roles, and no third.** An owner can change the team and the payout account; staff work
the day-to-day and cannot. A third rung would need a permission editor, and a permission
editor a seller can use is a way for a seller to lock themselves out of their own account.

**The last owner is refused.** A company with no owner is a company where nobody can add one
back, and the only way out is a support ticket and a console command. The API refuses it and
the screen refuses it first, so the refusal arrives as an explanation rather than as an error.

**One person, one seller.** Somebody on two teams would see two companies' orders through
one session, and every isolation guarantee in this platform is written per organization.

**Membership and role are written together.** A membership with no role is somebody who can
sign in and see nothing; a role with no membership is a permission pointing at a company the
person does not belong to. Neither state means anything, so neither is reachable.

**A removed member is marked, not deleted.** The orders they confirmed and the returns they
decided still name them, and an audit trail pointing at a row that no longer exists has lost
the answer it was kept for.

**The dashboard leads with the queue.** A seller already knows roughly what they sold; what
they do not know is that four orders have been sitting unconfirmed since Friday. Revenue
first would look impressive and bury the only part that needed acting on this morning. Low
stock and nothing-on-the-shelf are counted separately, because one is a reminder and the
other is a listing that has stopped selling.

**The parcel screen knows what is left.** The remaining quantity per line comes from the
server rather than from each client subtracting shipment lines from order lines — arithmetic
that would have to be right in three places, and that a seller would otherwise do in their
head while looking at a screen that already knows the answer.

**A permission added to the enum is not a permission granted.** The map lives in
`SystemRole`, the grants live in the database, and `RolesAndPermissionsSeeder` is what
reconciles them — with `sync()`, so a permission *removed* from the enum is removed from the
role too. Phase 19's E2E run caught the deployment consequence: the code was right and the
environment was stale.

### PHASE_20_STOREFRONT_COMPLETE — DONE (2026-08-26)

```text
UPDATED_AT: 2026-08-26
COMMIT_OR_SNAPSHOT: phase-20-storefront
PHASE: 20 — Storefront Complete + Approved Design Language
TASK: P20-T001 .. P20-T006
STATUS: DONE
FILES_CHANGED:
  packages/ui/src/runtime/useSeo.ts
  packages/ui/src/runtime/useApi.ts  (the Nitro $fetch narrowing)
  apps/storefront/server/routes/{robots.txt,sitemap.xml}.ts
  apps/storefront/app/layouts/default.vue  (skip link, mobile drawer, real footer)
  apps/storefront/app/pages/catalog/[slug].vue  (canonical, Open Graph, Product JSON-LD)
  apps/storefront/app/pages/{index,cart,favorites}.vue
  apps/storefront/app/pages/catalog/index.vue
  apps/storefront/app/pages/account/**  (noindex)
  apps/storefront/app/pages/checkout/{index,return}.vue  (noindex)
  apps/storefront/app/pages/projects/index.vue  (noindex)
  apps/storefront/nuxt.config.ts  (public site origin)
  apps/seller-portal/app/layouts/default.vue, apps/admin-panel/app/layouts/default.vue
  tests/e2e/storefront-quality.spec.ts
MIGRATIONS: none
TESTS_RUN: php artisan test · phpstan level 6 · pint · eslint · vue-tsc
  · check-design-tokens.mjs · playwright (full suite)
TEST_RESULT: PASS (775 backend tests / 2467 assertions; 76 E2E journeys)
BLOCKERS: none
NEXT_ACTION: Phase 21 — Hardening
```

**A phone could not use the site.** The desktop navigation is hidden below `lg` and nothing
replaced it, so a phone visitor saw a logo and a sign-up button and had no way to reach the
catalogue — which is most of the traffic and all of the shopping. The drawer is a real
dialog: focus moves into it, Escape closes it, the page behind does not scroll, and it
closes on navigation so it never reads as a stuck overlay.

**The skip link is the first focusable element on the page.** Tabbing through a whole
header to reach the article you arrived for is not navigation.

**Everything behind a sign-in refuses to be indexed.** An order page is not secret — it is
protected — but a URL a crawler can reach is a URL a search result can carry, and "it needed
a login anyway" is no comfort once an order number is in the title. Those pages carry
`noindex` and deliberately carry no canonical: a canonical asks a crawler to index one URL
rather than another, which is a contradiction on a page that must not be indexed at all.

**`robots.txt` and the sitemap are generated, not written.** A static disallow list drifts
from the router the moment somebody adds a page, and a hand-kept sitemap is wrong the day
after somebody adds a product. The sitemap pages the catalogue sixty at a time because the
API caps a page at sixty — asking for two hundred is a 422, which is how a sitemap silently
loses every product it was written to list.

**Structured data says only what the page says.** A listing that claims availability the
page contradicts is the kind of mismatch that gets a whole site's rich results turned off,
and the customer who clicked through deserves the page to agree with the result.

**The legal pages were reachable only by typing the URL.** A terms page nobody can find is
a terms page nobody agreed to; the footer now links them, along with the catalogue.

**Three navigation links pointed at the homepage.** "Platform", "Nasıl çalışır" and
"Profesyoneller" all resolved to `/` — a menu that lies about where it goes. Replaced with
the destinations that exist.

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
