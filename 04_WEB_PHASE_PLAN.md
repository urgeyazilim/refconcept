# REFCONCEPT WEB-FIRST AUTONOMOUS PHASE PLAN

## Phase 0 — Repository Bootstrap & Design Foundation
- monorepo structure
- Laravel
- Nuxt storefront/seller/admin
- PostgreSQL
- Redis
- local S3-compatible storage
- Docker Compose
- base CI
- Makefile/dev commands
- health endpoints
- design reference import
- design token foundation
- shared UI package foundation

Gate: clean local boot + baseline tests + design references and base tokens are present.

## Phase 1 — Identity / RBAC / Organizations
- users
- sessions/tokens
- verification
- reset
- profiles/addresses
- roles/permissions
- organizations
- audit baseline

Gate: authentication + policy + tenant tests.

## Phase 2 — Seller Onboarding
- application
- company/legal info
- contacts
- IBAN/bank details
- documents
- agreements/version acceptance
- approval/rejection/suspension
- seller portal onboarding UI

Gate: complete workflow + audit + isolation.

## Phase 3 — Catalog / PIM
- categories
- brands
- styles/colors/materials
- products
- seller listings
- SKU
- variants
- attributes
- dimensions
- media/docs/3D metadata
- moderation workflow

Gate: seller product → admin approve → public product.

## Phase 4 — Import / Price / Inventory
- CSV/XLSX dry-run/import
- mapping
- row errors
- price lists/history
- stock locations
- reservations/movements
- seller API foundation

Gate: import and concurrency tests.

## Phase 5 — Projects / Rooms / Design Versions
- project
- room
- room media
- dimensions
- constraints
- design
- design version tree
- private storage/signed access

Gate: ownership/media/version tests.

## Phase 6 — AI Gateway Foundation
- provider/model config
- task routing
- prompt templates/versions
- async jobs
- fake provider
- OpenAI adapter
- Google adapter
- usage/cost logs
- fallback/retry

Gate: deterministic fake tests + provider contracts.

## Phase 7 — Credit Economy
- packages
- wallet
- immutable credit transactions
- reserve/consume/release
- expiry
- promos
- admin adjustments

Gate: duplicate + concurrency + invariant tests.

## Phase 8 — AI Room & Design
- room analysis
- constraints
- design planning
- image generate/edit
- progress events
- validation
- version save
- failed job credit release

Gate: AI E2E with fake + sandbox contract tests.

## Phase 9 — Product Matching
- object extraction
- attributes
- text embeddings
- visual matching abstraction
- pgvector retrieval
- hard filters
- rerank
- match feedback

Gate: benchmark fixtures + budget/stock/category filters.

## Phase 10 — Search / Favorites / Cart
- hybrid search foundation
- facets
- favorites
- multi-seller cart
- price revalidation
- stock reservation

Gate: price/stock race tests.

## Phase 11 — Checkout / Payment Core
- checkout session
- PaymentIntent
- gateway registry
- transaction
- webhook inbox
- idempotency
- fake gateway
- payment state machine

Gate: duplicate/replay/timeout tests.

## Phase 12 — iyzico
- marketplace/submerchant adapter
- 3DS/payment
- item transaction mapping
- query
- cancel/refund
- approval/disapproval when active integration requires it
- sandbox/reconciliation

Gate: official sandbox/contract scenarios available at implementation time.

## Phase 13 — QNB
- QNB adapter
- 3DS/payment
- query
- cancel/refund
- error mapping
- test/sandbox
- reconciliation

Gate: QNB test/contract flow.

## Phase 14 — Bank Transfer
- platform bank accounts
- unique reference
- receipt
- pending workflow
- manual confirm
- partial/overpayment
- reconciliation model

Gate: duplicate confirmation and amount mismatch tests.

## Phase 15 — Orders / Seller Orders
- master order
- order item snapshots
- seller split
- status machine
- seller notifications
- order documents

Gate: multi-seller E2E.

## Phase 16 — Commission / Ledger / Settlement
- commission resolver hierarchy
- order item commission snapshot
- double-entry ledger
- seller balances
- settlement eligibility
- settlement periods
- payout workflow

Gate: financial invariant suite.

## Phase 17 — Shipping / Return / Refund
- shipment/packages/tracking
- return state machine
- refund state machine
- partial flows
- settlement holds
- financial reversals

Gate: partial return/refund + provider failure tests.

## Phase 18 — Super Admin Complete
- users
- sellers
- products/moderation
- orders
- payments
- bank transfers
- credits
- AI control center
- commission
- ledger
- settlement/payout
- feature flags
- system config
- audit
- failed jobs/webhooks
- analytics dashboard

Gate: admin permission matrix + critical action audit.

## Phase 19 — Seller Portal Complete
- dashboard
- products/imports
- price/stock
- orders
- shipping
- returns
- finance
- settlement
- users/roles
- API/integrations

Gate: full seller E2E and isolation.

## Phase 20 — Storefront Complete + Approved Design Language
- landing
- registration
- project/room/design UX
- credits
- catalog/search
- match alternatives
- product detail
- cart
- checkout
- account/orders
- responsive
- SEO
- accessibility basics
- visual implementation aligned with design refs
- component polish
- luxury-minimal UI parity with approved references

Gate: full customer Playwright journey + visual/UI acceptance against approved design documentation.

## Phase 21 — Hardening
- security review
- dependency scan
- load smoke
- DB/index tuning
- queue tuning
- image/CDN
- observability
- backups
- restore drill
- migration rehearsal
- rollback rehearsal
- payment reconciliation

Gate: P0/P1 = 0 + all readiness tests.

## Phase 22 — Web Release / Stabilization
- staging deploy
- full regression
- sandbox payment suite
- production config checklist
- limited release strategy
- runbooks
- OpenAPI freeze/versioning
- final Test Agent audit

Gate:
`WEB_RELEASE_APPROVED`

No mobile work before this gate.
