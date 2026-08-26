# Payment runbook

For whoever is on call when money is involved. Written to be read at 03:00 by somebody who
did not build this.

**The rule that outranks every procedure below: never guess which system is right.** A
mismatch means two records disagree about money. Reading both and doing nothing for an hour
is always cheaper than acting on the wrong one — a wrong refund is a second problem on top
of the first, and it is the one the customer remembers.

---

## First: is anything actually wrong?

```bash
docker compose exec api php artisan refconcept:reconcile-payments --days=1
```

Exit code 0 means the provider's record and the ledger agree. Non-zero means at least one
**critical** finding; warnings alone do not fail the run.

```bash
curl -s https://<host>/api/health | jq '.checks'
```

Every dependency is named. A green overall status with a red `queue` is the shape of a
problem that has not surfaced yet.

---

## A customer says they paid and their order is missing

1. **Find the payment.** Admin panel → Siparişler, search by e-mail or order number. If no
   order exists, the payment may have been taken without one being created.

2. **Check the webhook queue.** Admin panel → Sistem → *Ödeme bildirimleri*. A row with
   status `received` or `failed` is a notification that arrived and was not processed.

3. **If the signature is verified**, press *Yeniden işle*. This is safe to press twice: the
   processor claims the row with a conditional update and the payment state machine refuses
   a transition that has already happened.

4. **If the signature is not verified**, there is no replay button and there must not be.
   Anybody can post a notification; replaying an unverified one would let them fabricate a
   payment. Treat it as a report, not as evidence.

5. **If nothing is in the queue at all**, the provider never delivered. Query the provider's
   own dashboard for the reference, then follow *money taken and never posted* below.

---

## Reconciliation reports `captured_not_posted`

The customer has paid and the books do not know. Everything downstream — commission, the
seller's payout, tax — will exclude it until this is fixed.

1. Note the payment intent id from the report. It is the reference the finding names.
2. Confirm at the provider that the capture really succeeded. **Do not skip this**: the
   report says our two records disagree, not which one is wrong.
3. If it succeeded, the missing work is the order and its journal entry. Do not write a
   journal entry by hand — the entry is posted by `OrderAccounting` from the order, and an
   entry with no order behind it is a second inconsistency wearing the shape of a fix.
   Escalate to engineering with the intent id.
4. If it did **not** succeed at the provider, our transaction row is wrong and the customer
   was not charged. Nothing is owed; record the finding and move on.

---

## Reconciliation reports `duplicate_transaction`

The same provider transaction was recorded twice — a webhook delivered and processed twice.
The idempotency key exists to make this impossible, so a finding here means that protection
is not working and engineering must know today.

Check whether the *money* was doubled or only the *record*: the provider's dashboard is the
authority on how many captures happened. A duplicated record with a single capture is a data
problem; a genuine double capture is a refund and an apology.

---

## Reconciliation reports `stuck_pending`

A warning, not a failure. Bank transfers legitimately wait — a customer has three days to
pay. A **card** payment pending for a day is different: query the provider for the outcome
and let the payment state machine record it.

---

## A refund failed

Refunds have their own lifecycle and a failed one is a state, not an exception. Admin panel
→ Finans shows failed refunds; the finance queue on the dashboard counts them.

- **Retrying is safe.** Failed attempts are excluded from the dedupe and each attempt carries
  its own key, precisely so a provider outage does not leave a customer permanently
  unrefunded.
- **Nothing is posted to the ledger until the money has actually gone.** If the books show no
  reversal, none was made — that is by design, not an omission.
- If the provider refuses repeatedly, the reason is on the refund record. A payment too old
  to refund at the gateway is refunded by bank transfer, manually, and recorded with a reason.

---

## Bank transfers

- A transfer is confirmed by a person against a bank statement. There is no automatic
  matching, deliberately: a reference typed by a customer is not proof of a deposit.
- **Confirming the same transfer twice is refused.** If the button does nothing and the page
  says it is already confirmed, it is already confirmed — check the order before assuming a
  bug.
- A **short payment** releases nothing and states what is still owed. Do not confirm the full
  amount to "unblock" an order; that hands over goods for money that never arrived.
- An **overpayment** is confirmed and the difference is refunded through the normal refund
  path.

---

## Switching a payment method off

Admin panel → Sistem → `checkout.bank-transfer`. Takes effect on the next request.

This stops **new** payments only. Refunds and late notifications for payments already taken
still reach their adapter, which is what you want during an incident: the point is to stop
adding to the problem, not to strand the money already in it.

The card gateway has no flag on purpose. A checkout with no way to pay is a worse outage
than a slow one, and turning it off is a deploy-level decision.

---

## What must never be done

- **Never edit the ledger.** It refuses UPDATE and DELETE at the database level. A mistake is
  corrected by a reversing entry, so the mistake and the correction both stay visible.
- **Never edit an audit row**, for the same reason and by the same mechanism.
- **Never replay an unverified webhook.**
- **Never confirm a transfer you have not seen on a statement.**
- **Never ask a customer for card details.** Not by phone, not by e-mail. The platform has
  nowhere to put them and nobody here has any reason to see them.

---

## Escalation

Engineering needs three things and rarely more: the **request id** from the response header
(`X-Request-Id`), the **order or payment intent id**, and the **exact time**. Every log line
written while handling a request carries its id, so those three turn a report into a search.
