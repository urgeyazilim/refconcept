# REFCONCEPT CRITICAL E2E / INVARIANT SCENARIOS

## E2E-01 — Credits + AI Success
```text
register
→ verify
→ purchase 10 credits
→ payment confirmed
→ available = 10
→ create project/room
→ generate design cost 2
→ reserve 2
→ AI succeeds
→ consume 2
→ available = 8
→ design version visible
```

## E2E-02 — AI Failure
```text
available = 10
→ reserve 2
→ provider timeout/fail
→ retries exhausted
→ release 2
→ available = 10
```

## E2E-03 — Duplicate Payment Callback
Provider sends same success event multiple times:
- one payment effect
- one order confirmation
- one credit load

## E2E-04 — Seller Isolation
Seller A cannot read/write:
- Seller B products
- Seller B stock
- Seller B orders
- Seller B settlements

## E2E-05 — Seller Product Lifecycle
```text
seller apply
→ admin approve
→ product + SKU + price + stock
→ submit moderation
→ admin approve
→ storefront/search visible
→ eligible for AI product matching
```

## E2E-06 — Multi-Seller Order
```text
Seller A sofa
Seller B lamp
→ one cart
→ one customer payment
→ one master order
→ two seller orders
→ commission snapshots
→ balanced ledger
```

## E2E-07 — Partial Refund
```text
two paid items
→ refund one item
→ gateway refund succeeds
→ item financial reversal
→ seller payable reversal
→ commission reversal
→ ledger balanced
```

## E2E-08 — Bank Transfer
```text
checkout
→ pending bank transfer
→ unique reference
→ finance confirms
→ one payment transaction
→ one order confirmation
→ duplicate confirm blocked
```

## E2E-09 — Settlement Hold
```text
delivered
→ hold window
→ return opened
→ payout blocked
→ return resolved
→ settlement recalculated
```

## E2E-10 — Price Change
```text
cart has old price
→ seller changes price
→ checkout revalidates
→ user sees change
→ accepted current price is snapshotted in order
```

## E2E-11 — Stock Race
```text
stock = 1
→ two concurrent checkout reservations
→ exactly one succeeds
→ no negative stock
```

## E2E-12 — Admin Credit Adjustment
```text
admin +5
→ reason required
→ audit event
→ credit ledger entry
→ wallet aggregate matches
```
