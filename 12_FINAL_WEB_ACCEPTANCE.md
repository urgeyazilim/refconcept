# REFCONCEPT FINAL WEB ACCEPTANCE

No “project finished” claim until all mandatory items are checked and the independent Test Agent approves.

> **Audited 2026-08-26, after Phase 22.** 117 items verified against a test, a command
> or a document; 3 left unticked with the reason attached. Nothing is ticked on
> the strength of an intention. The three open items are what stands between this and
> `WEB_RELEASE_APPROVED` — see the bottom of this file.

## Build / Environment
- [x] clean clone boots
- [x] `.env.example` complete
- [x] Docker/local bootstrap works
- [x] migrations reproducible
- [x] seeds work
- [x] queue workers run
- [x] storefront builds
- [x] seller portal builds
- [x] admin builds
- [x] health endpoints pass

## User
- [x] register/login/verify
- [x] profile/address
- [x] credit purchase
- [x] project/room upload
- [x] AI design
- [x] design versions
- [x] failed AI releases credits
- [x] real product matching
- [x] variants/alternatives
- [x] cart
- [x] checkout
- [x] payment
- [x] order
- [x] shipping
- [x] return/refund

## Seller
- [x] onboarding
- [x] docs/agreement/bank
- [x] admin approval
- [x] product/SKU/variant
- [x] media
- [x] import
- [x] price/stock
- [x] moderation
- [x] order processing
- [x] shipment
- [x] returns
- [x] finance
- [x] settlements/payouts
- [x] tenant isolation

## Super Admin
- [x] user management
- [x] seller management
- [x] product moderation
- [x] orders
- [x] payment transactions
- [x] bank transfers
- [x] refunds
- [x] credit wallets/packages
- [x] AI control center
- [x] commission rules
- [x] ledger
- [x] settlement/payout
- [x] queues/webhooks
- [x] feature flags
- [x] audit/system settings

## Payments
- [x] payment core abstraction
- [ ] iyzico sandbox/contract — **DEFERRED (Phase 12) — needs official documentation and sandbox credentials**
- [ ] QNB test/sandbox/contract — **DEFERRED (Phase 13) — needs merchant account and test environment**
- [x] bank transfer
- [x] duplicate webhook protection
- [x] replay protection where applicable
- [x] cancel/refund
- [x] partial refund
- [x] reconciliation

## Finance
- [x] commission hierarchy
- [x] order snapshot
- [x] multi-seller split
- [x] balanced double-entry ledger
- [x] seller payable/balance
- [x] settlement hold
- [x] payout workflow
- [x] refund reversal

## AI / Credits
- [x] provider-independent routing
- [x] fake provider
- [x] prompt versions
- [x] async queue
- [x] room analysis
- [x] design generate/edit
- [x] matching
- [x] cost tracking
- [x] reserve
- [x] consume
- [x] release
- [x] concurrency protection
- [x] fallback

## Security
- [x] RBAC
- [x] IDOR tests
- [x] tenant isolation tests
- [x] rate limits
- [x] upload protection
- [x] secret scan
- [x] payment data rules
- [x] privileged audit
- [x] P0 = 0
- [x] P1 = 0

## Testing
- [x] backend unit
- [x] backend API/feature
- [x] DB/concurrency
- [x] integration
- [x] frontend unit
- [x] Playwright E2E
- [x] payment
- [x] AI
- [x] credit
- [x] financial invariants
- [x] security
- [x] load smoke
- [x] backup/restore

## Documentation / Operations
- [x] README
- [x] ARCHITECTURE
- [x] OpenAPI
- [x] ADRs
- [x] TEST_REPORT
- [x] SECURITY_CHECKLIST
- [x] PAYMENT_RUNBOOK
- [x] SELLER_ONBOARDING_RUNBOOK
- [x] DEPLOYMENT
- [x] PRODUCTION_CHECKLIST
- [x] CHANGELOG
- [ ] monitoring/alerts — **NOT DONE — the health probe, the reconciliation exit code and the failed-job count are all alertable; nothing is wired to a destination, which is an infrastructure decision**
- [x] rollback plan

## Final Required Line

Only the independent Test Agent may write:

```text
WEB_RELEASE_APPROVED
```

---

## Independent Test Agent verdict — 2026-08-26

**The line is not written.** Three mandatory items are open, and one of them is the
platform's ability to take a card payment.

### What was verified

| Suite | Result |
|---|---|
| Backend (Pest, real PostgreSQL) | **805 passed**, 2538 assertions |
| Frontend components (Vitest) | **18 passed** |
| E2E (Playwright, three apps) | **76 journeys** |
| Static analysis (PHPStan level 6) | No errors |
| Style (Pint), lint (ESLint), types (vue-tsc) | Clean |
| Design tokens | No colour outside the approved palette |
| `composer audit` / `npm audit` | No advisories, 0 vulnerabilities |
| Load smoke | No 5xx, no dropped request under concurrency |
| Backup + restore drill | Restored, row counts matched |
| Migration + rollback rehearsal | Both pass |
| OpenAPI freeze | `refconcept:openapi --check` clean — 205 paths, 251 operations |

P0 = 0. P1 = 0.

### Why the line is withheld

**1. `iyzico sandbox/contract` — deferred (Phase 12).** Needs official documentation and
sandbox credentials that do not exist yet.

**2. `QNB test/sandbox/contract` — deferred (Phase 13).** Needs a merchant account and a test
environment.

Together these mean the platform **cannot take a card payment**. Bank transfer works
end to end and is genuinely production-ready; a marketplace that only accepts transfers is a
viable limited launch and is not a completed web release. Writing the approval line while
`06_SECURITY_PAYMENT_FINANCE_RULES.md` lists two unimplemented gateways would be a report
that says something untrue about the system.

**3. `monitoring/alerts`.** Everything needed is emitted — a health probe that names each
dependency, a request id on every response, a reconciliation command whose exit code is
alertable, a failed-job count. Nothing is wired to a destination, and choosing that
destination belongs to whoever operates the platform.

### What this milestone *is*

Everything in `04_WEB_PHASE_PLAN.md` except Phases 12 and 13 is built, tested and
documented: identity and RBAC, seller onboarding, the catalogue, imports, projects and
rooms, the AI gateway and credit economy, design generation, product matching, search and
cart, checkout with bank transfer, orders, the double-entry ledger, commission and
settlement, shipping and returns, the super admin, the seller portal, the storefront, and a
hardening pass that found six defects including two P1s.

The correct next step is a **limited release on bank transfer** — the strategy is written in
`docs/operations/PRODUCTION_CHECKLIST.md` — with card payments opening when Phase 12 or 13
is unblocked. At that point this file is re-audited and the line can be written honestly.
