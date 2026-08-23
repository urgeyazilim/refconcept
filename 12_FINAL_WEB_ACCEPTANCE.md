# REFCONCEPT FINAL WEB ACCEPTANCE

No “project finished” claim until all mandatory items are checked and the independent Test Agent approves.

## Build / Environment
- [ ] clean clone boots
- [ ] `.env.example` complete
- [ ] Docker/local bootstrap works
- [ ] migrations reproducible
- [ ] seeds work
- [ ] queue workers run
- [ ] storefront builds
- [ ] seller portal builds
- [ ] admin builds
- [ ] health endpoints pass

## User
- [ ] register/login/verify
- [ ] profile/address
- [ ] credit purchase
- [ ] project/room upload
- [ ] AI design
- [ ] design versions
- [ ] failed AI releases credits
- [ ] real product matching
- [ ] variants/alternatives
- [ ] cart
- [ ] checkout
- [ ] payment
- [ ] order
- [ ] shipping
- [ ] return/refund

## Seller
- [ ] onboarding
- [ ] docs/agreement/bank
- [ ] admin approval
- [ ] product/SKU/variant
- [ ] media
- [ ] import
- [ ] price/stock
- [ ] moderation
- [ ] order processing
- [ ] shipment
- [ ] returns
- [ ] finance
- [ ] settlements/payouts
- [ ] tenant isolation

## Super Admin
- [ ] user management
- [ ] seller management
- [ ] product moderation
- [ ] orders
- [ ] payment transactions
- [ ] bank transfers
- [ ] refunds
- [ ] credit wallets/packages
- [ ] AI control center
- [ ] commission rules
- [ ] ledger
- [ ] settlement/payout
- [ ] queues/webhooks
- [ ] feature flags
- [ ] audit/system settings

## Payments
- [ ] payment core abstraction
- [ ] iyzico sandbox/contract
- [ ] QNB test/sandbox/contract
- [ ] bank transfer
- [ ] duplicate webhook protection
- [ ] replay protection where applicable
- [ ] cancel/refund
- [ ] partial refund
- [ ] reconciliation

## Finance
- [ ] commission hierarchy
- [ ] order snapshot
- [ ] multi-seller split
- [ ] balanced double-entry ledger
- [ ] seller payable/balance
- [ ] settlement hold
- [ ] payout workflow
- [ ] refund reversal

## AI / Credits
- [ ] provider-independent routing
- [ ] fake provider
- [ ] prompt versions
- [ ] async queue
- [ ] room analysis
- [ ] design generate/edit
- [ ] matching
- [ ] cost tracking
- [ ] reserve
- [ ] consume
- [ ] release
- [ ] concurrency protection
- [ ] fallback

## Security
- [ ] RBAC
- [ ] IDOR tests
- [ ] tenant isolation tests
- [ ] rate limits
- [ ] upload protection
- [ ] secret scan
- [ ] payment data rules
- [ ] privileged audit
- [ ] P0 = 0
- [ ] P1 = 0

## Testing
- [ ] backend unit
- [ ] backend API/feature
- [ ] DB/concurrency
- [ ] integration
- [ ] frontend unit
- [ ] Playwright E2E
- [ ] payment
- [ ] AI
- [ ] credit
- [ ] financial invariants
- [ ] security
- [ ] load smoke
- [ ] backup/restore

## Documentation / Operations
- [ ] README
- [ ] ARCHITECTURE
- [ ] OpenAPI
- [ ] ADRs
- [ ] TEST_REPORT
- [ ] SECURITY_CHECKLIST
- [ ] PAYMENT_RUNBOOK
- [ ] SELLER_ONBOARDING_RUNBOOK
- [ ] DEPLOYMENT
- [ ] PRODUCTION_CHECKLIST
- [ ] CHANGELOG
- [ ] monitoring/alerts
- [ ] rollback plan

## Final Required Line

Only the independent Test Agent may write:

```text
WEB_RELEASE_APPROVED
```
