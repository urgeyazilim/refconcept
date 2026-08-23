# REFCONCEPT SECURITY, PAYMENT & FINANCE RULES

## Secret Handling
- no production secrets in git
- `.env.example` only names/placeholders
- secret manager in production
- provider credentials encrypted/isolated when persisted

## Card Data
Never store raw:
- PAN
- CVV/CVC

Use provider-hosted/tokenized/3DS flows appropriate to the active provider contract.

## Payment Adapter Contract

```php
interface PaymentGatewayInterface
{
    public function createPayment(PaymentRequest $request): PaymentResult;
    public function retrievePayment(string $externalId): PaymentResult;
    public function cancelPayment(CancelRequest $request): CancelResult;
    public function refund(RefundRequest $request): RefundResult;
    public function parseWebhook(array $headers, string $body): WebhookEvent;
}
```

Marketplace settlement capability is separate:

```php
interface MarketplaceSettlementGatewayInterface
{
    public function onboardSeller(SellerPaymentProfile $seller): SellerGatewayProfile;
    public function approveItem(string $externalItemTransactionId): GatewayResult;
    public function disapproveItem(string $externalItemTransactionId): GatewayResult;
}
```

## iyzico
Implement against the **current official documentation at coding time**.
Architecture must support:
- seller/submerchant onboarding when required by marketplace flow
- 3DS/payment
- item mapping
- query
- cancel/refund
- approval/disapproval if required
- sandbox
- reconciliation
- idempotent callbacks/webhooks

## QNB
Implement against the **current official QNB payment gateway documentation at coding time**.
Support:
- 3DS
- payment
- transaction query
- cancel/refund
- response mapping
- test/sandbox
- reconciliation

If the contracted QNB product does not provide marketplace split payout,
RefConcept uses its own internal seller payable/ledger/settlement/payout workflow.

## Bank Transfer
- configurable RefConcept bank accounts
- unique transfer reference
- pending state
- optional receipt upload
- manual finance confirmation in V1
- bank statement/API reconciliation later
- partial/overpayment explicit states
- duplicate confirmation blocked

## Webhook Processing
1. receive
2. verify authentication/signature if provider supports/requires it
3. persist inbox event
4. dedupe provider event ID/body fingerprint
5. queue
6. acknowledge quickly
7. idempotent domain command
8. persist outcome

## Financial Ledger

Use immutable double-entry journal.

Example accounts:
```text
ASSET:CASH_PROVIDER
ASSET:BANK
LIABILITY:SELLER_PAYABLE:{seller}
LIABILITY:CUSTOMER_REFUND
REVENUE:COMMISSION
REVENUE:CREDIT
EXPENSE:PAYMENT_GATEWAY
EXPENSE:AI
CLEARING:PAYMENT
CLEARING:PAYOUT
```

Every posted journal:
`SUM(debit) == SUM(credit)`.

## Commission Resolution

Priority:
1. order-item snapshot
2. campaign override
3. seller+category
4. seller
5. category
6. platform default

Commission is snapshotted at order time.

## Seller Settlement

Customer payment and seller payout are separate.

Settlement eligibility depends on:
- payment captured
- delivery
- hold period
- open return/refund/dispute
- seller status

## Refund/Reversal

Never rewrite historical finance records.
Use reversal/new journal entries.

## High-Risk Admin Actions

Require:
- authorization
- reason
- audit
- optional re-auth/dual approval

Examples:
- large refund
- payout
- commission override
- seller reactivation
- manual ledger adjustment
