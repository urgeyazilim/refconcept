# REFCONCEPT ARCHITECTURE & CODING RULES

## Locked Architecture Direction

### Backend
- PHP 8.4+ compatible stack
- Laravel 13.x or the compatible current stable version selected at bootstrap
- REST API `/api/v1`
- Modular Monolith
- Laravel Policies/Gates
- Queue/Horizon
- Realtime through Laravel-supported websocket/realtime layer
- OpenAPI

### Web
- Vue 3
- Nuxt current stable
- TypeScript strict
- Storefront
- Seller Portal
- Super Admin

### Data
- PostgreSQL
- pgvector
- PostGIS where location queries require it
- Redis
- S3-compatible object storage

### AI/CV
- provider-independent AI gateway
- optional Python/FastAPI CV service when a real CV workload justifies it

### Delivery
- Docker
- CI/CD
- staging/production
- central observability

## Modular Monolith Domains

```text
Identity
Organizations
Customers
Sellers
Catalog
Products
Pricing
Inventory
Media
Projects
Rooms
Designs
AI
Credits
Search
Recommendations
Cart
Checkout
Orders
Payments
Ledger
Commissions
Settlements
Shipping
Returns
Refunds
Promotions
Reviews
Notifications
Messaging
Support
Integrations
Analytics
Audit
Administration
```

## Dependency Direction

UI/controller:
→ application/action
→ domain
→ repository/provider interface
→ infrastructure adapter

Never:
- controller directly mutates financial state
- Vue computes authoritative commission
- payment provider updates order row directly without domain workflow

## State Machines

Critical statuses only through services/workflows:
- seller
- product moderation
- AI job
- payment
- order
- return
- refund
- settlement
- payout

## Money

```text
amount_minor BIGINT
currency CHAR(3)
rate_bps INT
```

Never float.

## IDs / Time

- UUIDv7 preferred
- UTC persistence
- local display timezone
- human order numbers separate from PK

## Jobs

Every job defines:
- idempotency
- timeout
- retry
- backoff
- structured logging
- failure event
- dead/failed handling

## Tests & Static Analysis

Backend:
- Pest or PHPUnit
- PHPStan/Larastan
- Pint

Frontend:
- ESLint
- TypeScript strict
- Vitest
- Playwright

No phase closes with red static analysis/test suites.
