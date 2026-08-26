# Security checklist

Every item here is enforced by a test, or is explicitly marked as something a person has to
do. That distinction is the point of the document: a checklist of good intentions is a
checklist somebody ticks.

The tests live in `app/Domains/Administration/Tests/SecurityReadinessTest.php`,
`AdminPermissionMatrixTest.php` and `CriticalActionAuditTest.php`, plus the per-domain
isolation suites.

---

## Card data

| Rule | How it holds |
|---|---|
| PAN, CVV and expiry never enter the codebase | **Test** — the source tree is scanned for fields named for them |
| No column exists for card data | **Test** — every migration is scanned |
| Provider responses are redacted before storage | Code — `PaymentProcessor` stores a redacted response |
| Tokenising gateway only | Design — the platform never sees a card; the customer enters it at the provider |

**Never ask a customer for card details**, by any channel. There is nowhere to put them and
no reason for anybody here to see them.

---

## Authorisation

| Rule | How it holds |
|---|---|
| No administrative endpoint exists without a decision about who may call it | **Test** — the matrix's `uncovered()` must be empty, and the suite fails otherwise |
| An unknown admin route is refused | **Test** — a route registered at runtime answers 403 |
| A more specific rule outranks a broader one | **Test** — reading a settlement and approving one are different powers |
| No HTTP route grants a platform role | **Test** — the router is scanned; roles are granted by console only |
| The super-admin bypass never reaches a customer's project | **Test** — a super admin cannot view or update one |
| An operator cannot change the platform's own switches | **Test** — flags and settings are super-admin only |
| One seller cannot address another's data | **Test** — per-domain isolation suites; not-yours is a 404, not a 403 |

---

## Secrets

| Rule | How it holds |
|---|---|
| Nothing shaped like a provider key is committed | **Test** — env examples and the whole source tree are scanned for known key shapes |
| An AI provider key is never written into a stored request | **Test** — the AI domain is scanned |
| Provider credentials come from the secret manager in production | **Person** — see `PRODUCTION_CHECKLIST.md` |
| `APP_KEY` is unique per environment | **Person** — it encrypts seller IBANs |

The local MinIO password is committed on purpose: it is the password of a container
listening on localhost, it must match in two files for a fresh checkout to work, and hiding
it would break the bootstrap while protecting nothing.

---

## Data at rest and in transit

| Rule | How it holds |
|---|---|
| Seller payout IBANs are encrypted and only the last four are exposed | **Test** — the plaintext appears in neither the response nor the row |
| Room photographs are never handed out as a reusable URL | **Test** — E2E; signed link only, after an ownership check |
| Onboarding documents live on the private disk under random keys | Code — short-lived signed URLs after a policy check |
| A document filename never enters the audit log | Code — the audit context carries ids, not names |
| Private responses are never proxy-cacheable | **Test** — `no-store, private` |
| TLS everywhere, HSTS over TLS | **Person** + code — the header is sent automatically when the request is secure |

The **platform's own receiving IBAN** is a deliberate, documented exception: it is printed on
the checkout page, because a customer cannot make a transfer to an account they cannot see.

---

## The record

| Rule | How it holds |
|---|---|
| Every critical action is audited with an actor and, where it costs somebody, a reason | **Test** — thirteen actions performed for real, each asserting its trail |
| The audit trail cannot be edited or deleted | **Test** — a database trigger refuses UPDATE and DELETE |
| Nine append-only tables really are append-only | **Test** — every one is checked for its trigger |
| A secret's value never enters the audit log | **Test** — a changed secret is logged as `(gizli)` |
| Every response carries a request id | **Test** — assigned, honoured from upstream, and sanitised |

---

## Input and abuse

| Rule | How it holds |
|---|---|
| Rate limits on login, registration, password reset and verification resend | Code — named limiters; `auth-journey.spec.ts` proves the login limiter fires |
| An unverified webhook is stored and never acted on | **Test** — replay is refused |
| A replayed webhook is processed once | **Test** — four deliveries load credits once |
| Uploads are type- and size-checked | Code — request validation; also limit at the proxy |
| A caller-supplied request id that could not safely be logged is replaced | **Test** |
| Security headers are sent by the application, not only by the proxy | **Test** — nosniff, DENY, Referrer-Policy, Permissions-Policy |

---

## Money

| Rule | How it holds |
|---|---|
| Money is never a float | Code + review — integer minor units everywhere; rates in basis points |
| Every ledger entry balances | **Test** — in the service and again by a deferred constraint trigger at commit |
| Nothing in the ledger is ever edited | **Test** — corrections are reversing entries |
| A refund posts nothing until the money has actually moved | **Test** |
| The provider's record and the ledger are compared | **Test** + `refconcept:reconcile-payments` daily |

---

## Dependencies

| Rule | How it holds |
|---|---|
| No known-vulnerable PHP package | `composer audit` — in CI |
| No known-vulnerable JS package | `npm audit` — in CI |

---

## What is not covered

Stated plainly, because a checklist that implies completeness it does not have is worse than
a shorter one.

- **No penetration test.** Everything here is a property the team asserted about its own
  system. An adversary who has not read this document will look somewhere else.
- **No automated accessibility or dependency-license scan.**
- **No secret scanning in git history**, only in the working tree. A key committed and later
  removed is still in the history.
- **No WAF, no bot management, no DDoS protection.** Infrastructure decisions.
- **iyzico and QNB are not integrated**, so nothing about a real card flow — 3DS, chargebacks,
  provider-side fraud checks — has been exercised.
