# RefConcept — Test Report

> Maintained by the Independent Test Agent role (`03_INDEPENDENT_TEST_AGENT.md`).
> Tests are never weakened to make a phase pass. A failing gate keeps the phase open.

---

## Phase 0 — Repository Bootstrap & Design Foundation

- **Run date:** 2026-08-23
- **Environment:** Docker (PHP 8.3.28-fpm-alpine, PostgreSQL 16 + pgvector 0.8.6, Redis 7, MinIO, Mailpit)
- **Commit/snapshot:** Phase 0 bootstrap

### Gate definition (04_WEB_PHASE_PLAN.md)

> Clean local boot + baseline tests + design references and base tokens are present.

### Results

| # | Check | Method | Result |
|---|---|---|---|
| 1 | Docker stack boots | `docker compose up -d` | **PASS** — postgres, redis, minio, mailpit, api, nginx, queue, scheduler all running |
| 2 | PostgreSQL reachable | health probe | **PASS** — driver `pgsql` |
| 3 | pgvector installed | `pg_extension` query | **PASS** — v0.8.6, in both `refconcept` and `refconcept_test` |
| 4 | Redis cache | write/read/forget round-trip | **PASS** |
| 5 | Queue backend | Redis `PING` via queue connection | **PASS** |
| 6 | S3-compatible storage | put/get/delete against MinIO | **PASS** |
| 7 | Migrations | `php artisan migrate --force` | **PASS** — 3 baseline migrations applied |
| 8 | Health endpoint | `GET /api/health` | **PASS** — HTTP 200, all six checks `ok` |
| 9 | Backend test suite | `php artisan test` | **PASS** — 8 passed, 38 assertions, 32.21s |
| 10 | Design token guard | `node scripts/check-design-tokens.mjs` | **PASS** — no foreign colours |
| 11 | Design references present | `design_refs/` | **PASS** — 7 approved reference files |
| 12 | Base tokens present | `@refconcept/ui` | **PASS** — `tokens.ts`, `tokens.css`, `theme.css`, `base.css` |

### Test suite detail

```text
PASS  Tests\Unit\Administration\HealthStatusTest
  ✓ it collapses many statuses to the worst one
  ✓ it treats an empty status list as healthy
  ✓ it serialises a check result without null noise
  ✓ it rounds durations to two decimals

PASS  App\Domains\Administration\Tests\HealthEndpointTest
  ✓ it reports the platform as healthy when every dependency responds
  ✓ it runs against postgresql with the pgvector extension installed
  ✓ it exposes the health endpoint without authentication
  ✓ it never leaks internal details in the public payload

Tests:    8 passed (38 assertions)
```

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P0-D001 | **P1** | Container environment (`env_file`) overrode PHPUnit's `<env>` values, so the suite ran against the **development** database. `RefreshDatabase` would have silently wiped local data while reporting green. | Removed `env_file` injection from the php services; Laravel reads `.env` from disk. Added a hard guard in `Tests\TestCase::setUp()` that refuses any connection whose database name does not end in `_test`. |
| P0-D002 | P2 | `composer create-project` timed out writing `vendor/` through the Windows bind mount (300s unzip limit). | PHP dependencies moved to a named volume (`api-vendor`). |
| P0-D003 | P2 | Laravel boot took **22.8s** per command over the Windows bind mount (measured against 2.5s on the container filesystem) — untenable across 22 phases. | Application source moved to a named volume (`api-app`) with host→container sync (`scripts/sync.ps1`, `docker compose watch`). Boot **22.8s → 4.3s**; suite **104s → 32s**. |
| P0-D004 | P3 | `composer.json` contained invalid JSON escaping after generation. | Rewritten and validated. |

### Known constraints (not defects)

- First request after a container restart takes 9–16s while opcache warms; subsequent
  requests are ~0.5s.
- Host→container sync is a manual step (`scripts/rc.ps1 sync`) unless
  `docker compose watch` is running. Files generated *inside* the container need
  `scripts/rc.ps1 pull` to reach the host.

### External go-live dependencies (unchanged)

iyzico production keys · QNB production merchant · production bank reconciliation
source · production cloud/DNS/storage · legal/KVKK review · accounting/tax review.

### Verdict

**PHASE 0 GATE: PASS** — proceed to Phase 1 (Identity / RBAC / Organizations).

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (22 phases remaining).

---

## Phase 1 — Identity / RBAC / Organizations

- **Run date:** 2026-08-24
- **Environment:** Docker (PHP 8.3, Laravel 13.26.1, PostgreSQL 16 + pgvector 0.8.6, Redis 7, MinIO, Mailpit)
- **Commit/snapshot:** Phase 1 identity

### Gate definition (04_WEB_PHASE_PLAN.md)

> Authentication + policy + tenant tests.

### Results

| # | Check | Method | Result |
|---|---|---|---|
| 1 | Identity schema migrates | `migrate:fresh` | **PASS** — 7 migrations, UUIDv7 keys, citext e-mail, CHECK constraints |
| 2 | Reference data seeds | `db:seed` | **PASS** — 12 permissions, 5 system roles, idempotent |
| 3 | Registration | Pest feature suite | **PASS** — account + profile + consents, no token before verification, no roles granted |
| 4 | KVKK consent enforcement | Pest | **PASS** — privacy notice and terms mandatory, marketing optional and separable |
| 5 | Password policy | Pest | **PASS** — weak passwords rejected; breach check configurable |
| 6 | Authentication | Pest | **PASS** — token issued, session recorded, last login stamped |
| 7 | Account enumeration resistance | Pest | **PASS** — identical answers for unknown account, wrong password, unknown/expired/reused tokens |
| 8 | Blocked accounts | Pest | **PASS** — suspended/banned refused; live token rejected immediately after suspension |
| 9 | E-mail verification | Pest | **PASS** — single use, expiry honoured, previous token invalidated, address-change guard |
| 10 | Password reset | Pest | **PASS** — hashed tokens, single use, all sessions revoked on redemption |
| 11 | Profile | Pest | **PASS** — e-mail/status not editable through the profile endpoint |
| 12 | Addresses | Pest | **PASS** — ownership enforced, one default per kind, soft delete, verification required |
| 13 | **Tenant isolation** | Pest | **PASS** — 15 cases; seller A cannot read or write seller B in any direction |
| 14 | Role expiry / membership states | Pest | **PASS** — expired grants, removed and invited members all deny |
| 15 | Audit immutability | migration + model | **PASS** — DB trigger rejects UPDATE/DELETE; model throws first |
| 16 | Static analysis | PHPStan level 6 | **PASS** — no errors |
| 17 | Code style | Pint | **PASS** — 101 files |
| 18 | Backend suite | `php artisan test` | **PASS** — 78 tests, 235 assertions |
| 19 | Frontend build | `npm run build` | **PASS** — 3 Nuxt apps |
| 20 | Frontend typecheck | `vue-tsc` | **PASS** |
| 21 | Frontend lint | ESLint | **PASS** |
| 22 | Live end-to-end | HTTP against the running stack | **PASS** — register → queued mail delivered to Mailpit → login → `/auth/me` → create/list address |

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P1-D001 | **P1** | A stale `bootstrap/cache/packages.php` on the host was synced into the container on every push, hiding newly installed packages. It silently removed Sanctum's auth guard, so **every authenticated route returned 500**. | `scripts/sync.*` now clears the compiled cache in the container on push and on pull; the artifacts were deleted from the host. |
| P1-D002 | P2 | `auth.php` still pointed at the framework's `App\Models\User` and defined no Sanctum guard. | Rewritten for the Identity domain model with a `sanctum` guard as the default. |
| P1-D003 | P2 | Model factories could not resolve for domain-namespaced models. | `Factory::guessFactoryNamesUsing` maps by class name. |
| P1-D004 | P2 | `email:rfc,dns` performed a live MX lookup, breaking tests and blocking `*.local` accounts. | Extracted to `EmailRules`, configuration driven, disabled in tests and local. |
| P1-D005 | P3 | Factory-built models were partially hydrated, so `Model::shouldBeStrict()` threw on unread attributes. | Factory refreshes after creation. |

### Notes

- Storefront sign-up/sign-in **screens** are Phase 20 work per `04_WEB_PHASE_PLAN.md`; Phase 1
  delivers the API surface those screens will call.
- Rate limiting is configured and wired (`auth-login`, `auth-register`, `auth-password-reset`,
  `auth-verification-resend`) but is not asserted by the suite yet — the limiter shares cache
  state across tests. A dedicated isolated-store test is scheduled for Phase 21 hardening.

### Verdict

**PHASE 1 GATE: PASS** — proceed to Phase 2 (Seller Onboarding).

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (21 phases remaining).

---

## Phase 1b — Storefront identity screens + first E2E suite

- **Run date:** 2026-08-24
- **Scope change:** every phase from here ships its own UI instead of deferring all
  screens to Phase 20. This entry covers Phase 1's screens, backfilled.

### Results

| # | Check | Method | Result |
|---|---|---|---|
| 1 | Register → verify → sign in → profile → address → sign out | Playwright, live stack | **PASS** |
| 2 | Unverified account is gated out of the address book | Playwright | **PASS** |
| 3 | Registration blocked without both mandatory consents | Playwright | **PASS** |
| 4 | Login error identical for known and unknown accounts | Playwright | **PASS** |
| 5 | Password reset link works, old password dies | Playwright | **PASS** |
| 6 | Backend suite | `php artisan test` | **PASS** — 80 tests, 242 assertions |
| 7 | PHPStan level 6 / Pint / ESLint / vue-tsc / token guard | CI gates | **PASS** |

The E2E suite is genuinely end to end: the verification and reset links are read out
of the message the queue worker actually delivered to the SMTP server, so a broken
worker, mailer, database or API fails the test.

### Defects found and fixed

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P1b-D001 | **P2** | The first address was not becoming the customer's default. The server used `?? $isFirst`, which only applies when the field is absent, but a browser form posts unchecked boxes as `false` — so an account with exactly one address had no default and would reach checkout with an empty address selector. The Pest test missed it because its payload omitted the flags. | Forced for the first address; two feature tests added (explicit `false` payload, and later addresses staying non-default). |

### Known constraints

- **Hydration input race.** Nuxt accepts typing before Vue hydrates and then patches
  the DOM from empty reactive state, discarding the first field filled. On a fast
  local connection the window is tens of milliseconds; on a slow one a real user can
  lose a keystroke. The E2E helpers verify and re-fill rather than hide it. Reducing
  the window (smaller hydration payload, deferred non-critical components) is a
  Phase 21 hardening item.
- Legal pages are placeholders pending legal review — already tracked as an external
  go-live dependency.

---

## Phase 2 — Seller Onboarding

- **Run date:** 2026-08-24
- **Environment:** Docker (PHP 8.3, Laravel 13.26.1, PostgreSQL 16 + pgvector, Redis 7, MinIO, Mailpit)

### Gate definition (04_WEB_PHASE_PLAN.md)

> Complete workflow + audit + isolation.

### Results

| # | Check | Method | Result |
|---|---|---|---|
| 1 | Seller schema migrates | `migrate:fresh` | **PASS** — 10 tables, CHECK constraints, partial unique indexes, immutability trigger |
| 2 | Application creation | Pest | **PASS** — one open application per applicant enforced |
| 3 | Verified e-mail required to apply | Pest | **PASS** |
| 4 | Section saves | Pest | **PASS** — legal entity, tax profile, contacts, address, bank account |
| 5 | Turkish identifier validation | Pest | **PASS** — VKN 10 digits, TCKN 11, MERSİS 16 |
| 6 | **IBAN mod-97 validation** | Pest unit + feature | **PASS** — 12 cases; mistyped digit, transposition and truncation all rejected |
| 7 | IBAN encryption | Pest | **PASS** — plaintext absent from the column and from every response; only last four returned |
| 8 | Checklist derivation | Pest | **PASS** — completion computed from data, never a stored flag |
| 9 | Document requirements by taxpayer type | Pest | **PASS** — a sole proprietor is not asked for a trade registry gazette |
| 10 | Submission guard | Pest | **PASS** — incomplete application refused, missing steps named |
| 11 | Application locked after submission | Pest | **PASS** |
| 12 | Agreement acceptance | Pest | **PASS** — text checksum recorded; repeat accept is a no-op, not a 500 |
| 13 | Acceptance immutability | Pest + DB trigger | **PASS** |
| 14 | **Approval creates the tenant** | Pest | **PASS** — organization, seller, membership and role grant in one transaction |
| 15 | Decision requires a reason | Pest + CHECK constraint | **PASS** |
| 16 | Applicant cannot decide their own application | Pest | **PASS** |
| 17 | Double decision refused | Pest | **PASS** — one seller, not two |
| 18 | Suspension / reactivation | Pest | **PASS** — reason mandatory, status history recorded, self-service refused |
| 19 | Commission change audited | Pest | **PASS** — before/after values in the audit log |
| 20 | **Tenant isolation** | Pest | **PASS** — 9 cases; documents, IBANs, admin routes and seller records all refused across tenants |
| 21 | Storage path never exposed | Pest | **PASS** |
| 22 | Backend suite | `php artisan test` | **PASS** — 135 tests, 389 assertions |
| 23 | Static analysis / style | PHPStan L6, Pint | **PASS** — 148 files |
| 24 | Frontend gates | ESLint, vue-tsc, build, token guard | **PASS** |
| 25 | **End-to-end** | Playwright, live stack | **PASS** — 9 journeys across storefront and seller portal |

### End-to-end journeys

```text
customer identity        register → verify by e-mail → sign in → profile → address → sign out
                         unverified account gated out of the address book
                         consent enforcement
                         account-enumeration resistance
                         password reset invalidates the old password

seller onboarding        sign in → create application → tax profile → legal entity →
                         contact → address → IBAN (rejected then accepted) →
                         three documents → three agreements → submit → locked
                         submit disabled while steps are outstanding
                         individual seller asked for TCKN, not VKN
seller review            operator approves → seller code issued → applicant sees a live account
```

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P2-D001 | **P2** | `RcButton` rendered `<component is="NuxtLink">` by string name. That only resolves against locally registered components, and the component lives in `@refconcept/ui` — so **every primary call to action on the storefront rendered correctly and navigated nowhere**. Screenshots could not reveal it; only a click could. | `resolveComponent('NuxtLink')`. |
| P2-D002 | P3 | Database status defaults were not reflected on freshly created models, so a new application reported a null status until reloaded. | Model-side `$attributes` defaults for application, seller and document. |
| P2-D003 | P3 | `Artisan::starting()` does not exist in Laravel 13; the console route file broke every artisan command. | Commands registered through `withCommands()` in `bootstrap/app.php`. |

### Notes

- `refconcept:grant-role` bootstraps the first operator from the console. There is
  deliberately no HTTP endpoint that grants platform roles.
- Agreement bodies are drafts pending legal review — an external go-live dependency.

### Verdict

**PHASE 2 GATE: PASS** — proceed to Phase 3 (Catalog / PIM).

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (20 phases remaining).

---

## Phase 3 — Catalog and product lifecycle

**Date:** 2026-08-24
**Scope:** category taxonomy, product/SKU model, imagery, moderation, the public
catalogue, and the three screens that drive them.

### Gate criteria

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | Taxonomy seeds and is idempotent | Pest + `db:seed` | **PASS** — 40 categories, 8 attributes, 19 colours, 18 materials, 8 styles, 6 brands |
| 2 | Category attributes drive the seller form | Pest | **PASS** — the required flag comes off the pivot, so the form and the submission gate cannot disagree |
| 3 | **Prices are exact minor units end to end** | Pest | **PASS** — 48.900,00 ₺ posts as `4890000` and comes back as `4890000` |
| 4 | Tax from basis points | Pest | **PASS** — 20% of 4.890.000 is 978.000, no drift |
| 5 | Discount reported in basis points | Pest | **PASS** |
| 6 | Sale price above list price refused | Pest | **PASS** |
| 7 | Completeness derived, never stored | Pest | **PASS** — description, image, SKU, price, width + depth, required attributes |
| 8 | Submission guard names what is missing | Pest | **PASS** |
| 9 | Listing locked while a reviewer holds it | Pest | **PASS** |
| 10 | **Unapproved listing invisible to customers** | Pest | **PASS** — checked before *and* after approval, through both catalogue endpoints |
| 11 | Approval publishes and activates offers | Pest | **PASS** — status, `published_at` and draft SKUs move together |
| 12 | Seller-paused offer survives approval | Pest | **PASS** — approval is a moderation decision, not a licence to undo the seller's |
| 13 | **Editing a published listing re-queues it** | Pest | **PASS** — product, SKU and gallery edits all pull it out of the catalogue |
| 14 | Every decision carries a reason | Pest + CHECK constraint | **PASS** |
| 15 | Rejection names the fields at fault | Pest | **PASS** |
| 16 | Recall takes a live listing off sale immediately | Pest | **PASS** |
| 17 | Suspended seller's listings disappear | Pest | **PASS** — visibility delegates to the SKU scope, which asks the seller |
| 18 | **Tenant isolation** | Pest + Playwright | **PASS** — list, edit, SKU, media and moderation all refused across sellers |
| 19 | Imagery on the public bucket only | Pest | **PASS** — separate bucket, random key, extension from the decoded type |
| 20 | Non-image uploads refused | Pest | **PASS** — PDF and SVG rejected; a file that will not decode is rejected even if its headers claim otherwise |
| 21 | One cover image per product | Pest + partial unique index | **PASS** — reorder parks rows out of the index's way; deleting the cover promotes the next |
| 22 | Catalogue filters | Pest | **PASS** — category branch, room, style, budget (on the *effective* price of a purchasable offer), search |
| 23 | Unknown category returns nothing, not everything | Pest | **PASS** |
| 24 | Backend suite | `php artisan test` | **PASS** — 213 tests, 657 assertions |
| 25 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 26 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 27 | **End-to-end** | Playwright, live stack | **PASS** — 12 journeys across all three apps |

### End-to-end journeys added

```text
product lifecycle   seller creates a draft -> category-driven attributes -> photograph ->
                    price and dimensions -> checklist clears -> submit ->
                    catalogue checked and EMPTY ->
                    reviewer picks it up -> approves with a reason ->
                    catalogue shows it, priced and measured ->
                    seller pauses it -> catalogue empty again

                    reviewer rejects with named fields -> listing stays out of the
                    catalogue and reopens for editing

                    one seller cannot open another seller's listing (403, and the
                    portal says so in Turkish rather than in Laravel's English)
```

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P3-D001 | **P2** | A seller had no way to upload a product image at all — no endpoint, no screen — so the completeness gate demanded a photograph that could not be supplied and no listing could ever be submitted. | `ProductImageStorage` + `ProductMediaController`, on a separate anonymously-readable bucket, with 12 tests. |
| P3-D002 | **P2** | Approval left `products.status` at `draft` and SKUs at `draft`, so an approved listing satisfied moderation and still failed `publiclyVisible()`. Approved, complete, and invisible. Unit tests passed because they forced the status by hand. Found by the end-to-end run. | Approval now publishes: status to active, draft offers to active, offers the seller paused left alone. |
| P3-D003 | **P2** | An approved listing could never be edited again — no typo fix, no better photograph, ever. | Approved is editable; any edit sends the listing back to the review queue and clears `published_at`, so what a customer sees is always something a reviewer looked at. |
| P3-D004 | **P2** | `SellerProductController::index` did not eager-load `skus.seller`, but the "from" price asks each offer whether its seller may trade. With lazy loading disabled, the seller's product list returned 500. Not caught by tests because the fixtures used there had no SKUs. | Eager load added in both places that serialise a product; regression test added. |
| P3-D005 | P3 | `attributes` and `dimensions` were passed to `fill()` although neither is a column, so any request carrying them raised a mass-assignment error. | `Arr::except` at both call sites. |
| P3-D006 | P3 | `ProductResource` serialised the attribute *label* as its value, so the seller's form matched none of its own options and silently cleared every attribute on the next save. | `value` (the stored code) and `display` (the label) are now separate fields. |
| P3-D007 | P3 | Categories were ordered by `position` across all depths, so a flat select interleaved branches — "Kanepe" appeared three entries above the "Oturma Grubu" it belongs to. | Ordered by the materialised path; option indentation uses non-breaking spaces, which a browser does not collapse. |
| P3-D008 | P3 | The seller portal on :3001 and the admin panel on :3002 share one cookie jar in development, because a browser scopes cookies by host and ignores the port. Signing into one silently signed into the other. | Test-side: cookies cleared before each sign-in. Not a product defect — the three apps sit on separate domains in production. |
| P3-D009 | P3 | Demo seller accounts had an organization and a role grant but no `sellers` row, so a demo seller could reach the product form and be refused at the last step for a reason nothing on screen explained. | `DemoAccountsSeeder` creates the trading account too. |
| P3-D010 | P3 | `__vue_app__` is set when `app.mount()` is called, which is *before* an async `<script setup>` resolves. Clicks landing in that window hit server-rendered markup with no listeners and were swallowed silently. | `gotoInteractive()` waits for the page's own fetch to settle. The same window is a real (small) UX cost of SSR, tracked as hardening. |

### Demo data

`php artisan db:seed` now leaves a working catalogue behind: twelve published
listings across the two demo sellers, photographed, priced, measured and approved.
The imagery is uploaded to the public bucket as `ProductMedia` exactly as a seller's
upload would be, so the demo exercises the same storage path as production rather
than pointing at a static asset only the seeder knows about.

### Notes

- Product imagery is the one anonymously-readable store in the system. It is a
  separate bucket rather than a public prefix inside the private one: bucket-level
  anonymous read is a single auditable setting, whereas a public prefix is one
  careless policy edit away from serving tax certificates.
- SVG is refused despite being an image. It is a document format that can carry
  script, and it would be served from that bucket.
- Basket and checkout are Phase 6. The product page says so rather than showing a
  button that does nothing.

### Verdict

**PHASE 3 GATE: PASS** — proceed to Phase 4.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (19 phases remaining).

---

## Phase 4 — Import, pricing, inventory and the partner API

**Date:** 2026-08-24
**Scope:** spreadsheet import with a dry run, price lists and price history, a stock
ledger with reservations, and scoped machine credentials for a seller's own systems.

### Gate criteria

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | Turkish spreadsheets read correctly | Pest | **PASS** — semicolon delimiter detected by column count, byte-order mark stripped, Windows-1254 converted, comma decimals parsed |
| 2 | Column mapping guessed, never assumed | Pest | **PASS** — Turkish and English headers matched accent-insensitively; two columns claiming one field leaves both unmapped |
| 3 | **Dry run writes nothing** | Pest + Playwright | **PASS** — the catalogue is asserted empty *after* validation and before the commit |
| 4 | Row errors are per field and per line | Pest | **PASS** — the line number is the one the seller sees in Excel |
| 5 | Duplicate SKU inside one file caught | Pest | **PASS** — names the earlier line |
| 6 | Required columns enforced before validation | Pest | **PASS** |
| 7 | Commit applies good rows, skips bad ones | Pest | **PASS** — one malformed line does not take the others |
| 8 | Re-importing updates rather than duplicates | Pest | **PASS** — SKU code is identity |
| 9 | Imported prices land in history as `import` | Pest | **PASS** — including the first price of a newly created SKU |
| 10 | Imported stock goes through the ledger as a count | Pest | **PASS** |
| 11 | Upload stays on the private disk, path never exposed | Pest | **PASS** |
| 12 | **Price history is append-only** | Pest + trigger | **PASS** — UPDATE and DELETE both refused by the database |
| 13 | Unchanged price writes no history row | Pest | **PASS** |
| 14 | Campaign never overwrites the everyday price | Pest | **PASS** — ending a campaign restores yesterday's prices because nothing overwrote them |
| 15 | Campaign windows respected in both directions | Pest | **PASS** — not-yet-started and already-ended both fall through to the SKU price |
| 16 | One default price list per seller | Pest + partial unique index | **PASS** |
| 17 | Sale above list refused | Pest + CHECK constraint | **PASS** — at the service and at the storage layer |
| 18 | **Stock movements are append-only** | Pest + trigger | **PASS** |
| 19 | Balance invariants enforced by the database | Pest + CHECK constraint | **PASS** — negative on-hand and reserved-above-on-hand both refused even bypassing the service |
| 20 | **Reserving decides under a row lock** | Pest | **PASS** — the ledger issues `SELECT … FOR UPDATE` and re-reads inside it; a stale caller-held model cannot over-reserve |
| 21 | Reserving is idempotent per reference | Pest + partial unique index | **PASS** — a retried checkout does not take the stock twice |
| 22 | Releasing twice does not credit twice | Pest | **PASS** |
| 23 | Expired holds free their stock | Pest + scheduler | **PASS** — cleared on the next reservation of that row, and swept every five minutes |
| 24 | Multi-line reservation is all or nothing | Pest | **PASS** — rows locked in a fixed order, so two baskets cannot deadlock |
| 25 | Dispatch moves both numbers in one transaction | Pest | **PASS** |
| 26 | **Partner secret is shown once and never recoverable** | Pest | **PASS** — hashed at rest, absent from every later response |
| 27 | Unknown key and wrong secret answer identically | Pest | **PASS** — including the timing, which is why an unknown key still pays for a hash |
| 28 | Scopes are enforced per route | Pest + Playwright | **PASS** — a `stock:write` credential is refused at `/partner/prices` |
| 29 | Revoked and expired credentials refused | Pest | **PASS** — revocation requires a reason, enforced by a CHECK constraint |
| 30 | Rate limited per credential | Pest | **PASS** — 429 with `Retry-After` |
| 31 | Request log keeps the path, never the query string | Pest | **PASS** |
| 32 | **Tenant isolation** | Pest + Playwright | **PASS** — imports, prices, stock and credentials all refused across sellers; another seller's SKU code reads as unknown rather than as an error |
| 33 | Backend suite | `php artisan test` | **PASS** — 290 tests, 878 assertions |
| 34 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 35 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 36 | **End-to-end** | Playwright, live stack | **PASS** — 15 journeys |

### End-to-end journeys added

```text
bulk import      seller uploads a Turkish-Excel CSV (semicolons, BOM, comma decimals) ->
                 the mapping is guessed from Turkish headers -> dry run ->
                 catalogue checked and EMPTY ->
                 the bad row is named with its line number ->
                 commit applies the two good rows ->
                 48.900,00 survives the round trip on the price screen ->
                 stock arrived through the ledger

repricing        bulk edit -> one save -> history shows old -> new, with its source

partner API      seller issues a scoped credential in the portal ->
                 the secret is shown exactly once ->
                 a machine pushes stock with it ->
                 the same credential is refused at the prices endpoint ->
                 the seller sees the request in the usage log ->
                 revoking kills it immediately
```

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P4-D001 | **P2** | A newly imported SKU had no price history at all: the row was created already priced, so the price book correctly saw no change and wrote nothing. The origin of a product's very first price — the one most worth being able to explain — was the one thing nobody could look up. | `PriceBook::recordInitialPrice()`, called on create, with a null previous value. |
| P4-D002 | P3 | The demo catalogue set `stock_quantity` on the SKU without any ledger rows behind it, so a demo product page claimed six in stock while the stock screen was empty — exactly the inconsistency the ledger exists to prevent. | The seeder now books opening stock as a receipt through `InventoryLedger`. |
| P4-D003 | P3 | An earlier `migrate:fresh` left the development database with only the taxonomy seeded, which broke registration in the end-to-end run in a way that surfaced as "grant-role failed". | Development data restored with a full `db:seed`; noted here because the symptom pointed somewhere else entirely. |

### Notes on the concurrency claim

The ledger's guarantee is asserted at the level RefConcept is responsible for: that
every write takes `SELECT … FOR UPDATE` on the stock row and decides from what it
reads *inside* that lock, never from the model the caller passed in. Two of the tests
say exactly that — one inspects the SQL, one hands the service a deliberately stale
model and expects the reservation to be refused.

That the lock then blocks a second transaction is PostgreSQL's behaviour rather than
ours, and a test for it would be testing the database. The invariants that survive
even a caller who forgets the lock are pushed down to the schema instead: a CHECK
constraint refuses `reserved > on_hand`, and a partial unique index refuses a second
live hold for one reference.

### Notes

- Import files go on the **private** disk. A seller's supplier price list is the one
  document that tells a competitor exactly what they pay, so it is treated like an
  identity document rather than like a product photograph.
- The import template is generated from the field catalogue rather than committed as
  a static file, so it cannot fall out of step with the columns the mapper
  understands. Semicolon-separated with a byte-order mark, because a template that
  opens as one column in Turkish Excel teaches the seller the feature is broken.
- Partner credentials are deliberately not Sanctum tokens: a partner credential
  belongs to a system rather than to a person, carries its own scopes, and must be
  revocable without logging anybody out of the seller portal.
- Stock reservations are consumed by the order flow, which is Phase 6. The reserve,
  release and dispatch paths are built and tested here because inventory without them
  is a number rather than a ledger.

### Verdict

**PHASE 4 GATE: PASS** — proceed to Phase 5.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (18 phases remaining).
