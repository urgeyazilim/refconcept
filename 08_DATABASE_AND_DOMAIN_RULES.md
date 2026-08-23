# REFCONCEPT DATABASE & DOMAIN BUILD RULES

The detailed table inventory is in `REFCONCEPT_MASTER_SPEC.md`.

## Core Aggregate Order

Build approximately in this dependency order:

```text
Identity
→ Organizations/Sellers
→ Catalog/PIM
→ Pricing/Inventory
→ Projects/Rooms
→ Designs/AI
→ Credits
→ Search/Matching
→ Cart/Checkout
→ Payments
→ Orders
→ Commission/Ledger
→ Settlement
→ Shipping/Returns/Refunds
→ Admin/Analytics/Integrations
```

## Required Persistence Rules

### Users
- unique verified identities
- status/history
- sessions/devices
- consent/versioning
- privacy requests

### Sellers
- organization scoped
- application/onboarding state
- legal/bank/docs
- agreement acceptance
- approval/suspension history
- API keys hashed
- provider mappings encrypted where needed

### Products
- canonical product + seller listing/SKU separation
- variants
- attributes
- dimensions
- media
- price history
- stock/reservations
- moderation history

### Projects/Rooms/Designs
- user ownership
- private media
- immutable original
- design version tree
- prompts/jobs/cost linkage

### Credits
Authoritative source is immutable transaction ledger.
Wallet aggregate is a performant snapshot only.

### Orders
- master order
- seller orders
- order items
- product/price/commission snapshots
- order state history

### Payments
- intent
- attempts
- transactions
- webhook inbox
- reconciliation
- bank transfer

### Ledger
- entry
- lines
- balanced journal
- seller/account dimensions
- no destructive edits after posting

## Concurrency

Mandatory transactions/locks/atomic updates:
- credit reserve/consume/release
- stock reserve/release
- order number generation
- payment idempotency
- refund total
- settlement creation
- payout creation

## Search
MVP:
- PostgreSQL FTS
- `pg_trgm`
- `pgvector`

Add OpenSearch only after measured need.

## Index Review
Test Agent/Database Agent must review:
- unique keys
- foreign keys
- tenant scoping indexes
- product search indexes
- order/seller date/status
- payment external IDs
- webhook event IDs
- credit idempotency
- vector indexes
