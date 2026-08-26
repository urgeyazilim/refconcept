# Seller onboarding runbook

For the person who reviews applications. Approving one creates a company on the platform,
grants somebody the ability to list products and take money, and starts a payout
relationship — so the review is a decision, not a formality.

---

## What an application must contain before it can be approved

The applicant cannot submit until the checklist is complete, and the platform refuses to
approve an incomplete one. Both checks exist because a seller approved without a bank
account is a seller who cannot be paid, and that is discovered weeks later.

- **Company details** — legal name, trade name, legal form
- **Tax profile** — decides which identifiers and which documents apply. A sole trader and
  a company do not file the same papers, and asking for the wrong one wastes a week
- **Legal entity** — tax number or national id, per the tax profile
- **Primary contact** — a person, with a working address
- **Registered address**
- **Bank account** — IBAN, checksum-validated on the server. Encrypted at rest; only the last
  four digits are ever shown again, including to the seller
- **Documents** — tax certificate, trade registry gazette, signature circular
- **Agreements** — every outstanding one accepted, each recorded with its version

---

## Reviewing

1. Admin panel → **Başvurular**. Open the application.
2. Press **İncelemeye al**. This is not decoration: it records who is looking, so two
   reviewers do not work the same file and reach different answers.
3. Open each document. They are on the private disk under random keys and reachable only
   through a short-lived signed link after a policy check — a document URL cannot be shared,
   forwarded or bookmarked, and the filename never appears in the audit log.
4. Check the documents against the declared details. The tax number on the certificate and
   the tax number in the form must be the same number.

---

## Approving

Approval demands a **reason**, and the reason is stored. "Belgeler eksiksiz ve doğrulandı"
is enough when it is true; what matters is that six months later somebody can see that a
person looked rather than clicked.

Approval does all of this in one transaction:

- creates the **organization** and the **seller** record with a seller code
- makes the applicant the **owner**, with the organization-scoped owner role
- opens the seller's portal: products, orders, stock, earnings

A **commission rate** may be set at approval. Left alone, the seller inherits the platform
default. The rate is resolved once per order and copied onto the line, so changing it later
never rewrites what a seller earned last quarter.

---

## Rejecting

Rejection also demands a reason, and unlike the approval reason **the applicant reads this
one**. Write it for them:

- Say which document or field is at fault, by name.
- Say what would fix it.
- Do not paste an internal note. "Belge okunaksız" helps; "yine aynı sorun" does not.

A rejected applicant can correct the problem and apply again. That is the intended path, and
a reason they cannot act on turns it into a support conversation.

---

## After approval

The seller signs in and finds their queue. Nothing else is required of the reviewer.

**They cannot list a product without moderation.** A first listing goes to the product
moderation queue; approval there is a separate decision by a different screen.

---

## Suspending a seller

Suspension stops their income. It demands a reason, is audited with it, and the seller
cannot lift it themselves — an obvious thing to state and the exact thing that is missing
from systems where it was never stated.

Suspension stops **new** trading. Orders already placed still ship, returns already open
still resolve, and money already earned is still owed. Anything else would punish the
customer for the seller's problem.

Reactivation is a separate action with its own reason, and both transitions stay on the
seller's record.

---

## The seller's own team

A seller is a company. The owner adds colleagues themselves, from **Ekibim** in their portal;
the platform is not involved and does not need to be.

Two things reviewers get asked about:

- **"I cannot remove myself."** Correct, and deliberate: they are the last owner. A company
  with no owner is a company where nobody can add one back. They make somebody else an owner
  first.
- **"My colleague already works for another seller."** One person belongs to one seller.
  Somebody on two teams would see two companies' orders through one session. They need their
  own account.

---

## Granting a platform role

Not from any screen — there is no endpoint, deliberately. The ability to make somebody a
super admin is not something a compromised session should have.

```bash
docker compose exec api php artisan refconcept:grant-role somebody@example.com operator
```

Roles: `super-admin`, `operator`, `analyst`. An **operator** works every queue and cannot
touch the platform's own switches; an **analyst** reads and cannot press a single verb.

---

## What must never be done

- **Never approve an application whose documents you have not opened.** The checklist proves
  a file was uploaded, not that it says what it should.
- **Never approve without a reason that would still make sense to a stranger.**
- **Never reject with a reason the applicant cannot act on.**
- **Never ask a seller for their IBAN by e-mail.** They enter it themselves; nobody here can
  read it back, which is the point.
