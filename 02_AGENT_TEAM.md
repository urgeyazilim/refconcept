# REFCONCEPT AGENT TEAM

## ORCHESTRATOR_AGENT
Owns phase/task orchestration and release gates.

## ARCHITECT_AGENT
Owns:
- modular monolith boundaries
- ADRs
- dependency rules
- API versioning
- event boundaries
- state machines
- integration contracts

## DATABASE_AGENT
Owns:
- PostgreSQL schema/migrations
- UUIDv7
- constraints
- indexes
- pgvector/PostGIS
- factories/seeds
- query plans
- concurrency-safe persistence

## BACKEND_AGENT
Owns:
- Laravel domains
- actions/services
- DTOs/value objects
- policies
- events/listeners
- queues
- API
- OpenAPI
- backend tests

## SELLER_CATALOG_AGENT
Owns:
- seller onboarding
- organizations/tenant isolation
- PIM
- categories/brands
- product/SKU/variant
- media/3D metadata
- imports
- price/stock
- moderation
- seller integrations

## AI_AGENT
Owns:
- AI provider abstraction
- OpenAI adapter
- Google adapter
- fake provider
- task routing
- prompt versioning
- room understanding
- design generation/edit
- embeddings
- product matching
- cost tracking
- fallback/retry
- credit linkage

## COMMERCE_AGENT
Owns:
- favorites
- cart
- checkout
- stock reservations
- master order
- seller orders
- shipping
- return/refund orchestration

## PAYMENT_FINANCE_AGENT
Owns:
- payment core
- iyzico
- QNB
- bank transfer
- webhooks
- idempotency
- reconciliation
- commission
- double-entry ledger
- seller balances
- settlement
- payout

## STOREFRONT_AGENT
Owns public/customer Nuxt app:
- auth
- projects/rooms/designs
- AI UX
- credits
- catalog/search
- product match
- cart/checkout
- orders
- responsive/SEO/accessibility

## SELLER_PORTAL_AGENT
Owns:
- onboarding UI
- seller dashboard
- products/imports
- stock/price
- orders/shipping
- returns
- finance/settlements
- integration keys

## ADMIN_AGENT
Owns:
- users
- sellers
- moderation
- catalog
- orders
- payments
- credits
- AI Control Center
- finance
- settlements
- audit
- feature flags
- system settings
- failed jobs/webhooks

## DEVOPS_SECURITY_AGENT
Owns:
- Docker
- CI/CD
- secrets policy
- health
- observability
- backups
- deployment
- security scanning
- release runbooks

## DOCUMENTATION_AGENT
Owns:
- README
- architecture
- ADR
- OpenAPI publishing
- runbooks
- deployment
- changelog
- production checklist

## INDEPENDENT_TEST_AGENT
Owns release truth.
See `03_INDEPENDENT_TEST_AGENT.md`.

## Handoff Contract

Every task handoff must record:

```text
TASK_ID
OWNER_AGENT
STATUS
FILES_CHANGED
SCHEMA_CHANGED
API_CHANGED
EVENTS_ADDED
SECURITY_IMPACT
FINANCIAL_IMPACT
TESTS_ADDED
TESTS_RUN
RESULT
KNOWN_ISSUES
NEXT_DEPENDENCIES
```
