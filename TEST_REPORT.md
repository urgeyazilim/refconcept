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

---

## Phase 5 — Projects, rooms and design versions

**Date:** 2026-08-24
**Scope:** a customer's home — projects, rooms, private photographs, the things a
design has to work around, sharing, and the design version tree.

### Gate criteria

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | A project is visible only to its owner and invited members | Pest + Playwright | **PASS** — asserted from the list, from a direct id, and from a nested room |
| 2 | **Platform staff cannot open a customer project** | Pest | **PASS** — the super-admin `Gate::before` bypass is excluded for these models, and the exclusion is asserted rather than assumed |
| 3 | The bypass still applies everywhere else | Pest | **PASS** — an operator can still work the moderation queue |
| 4 | A verified e-mail is required before a project can exist | Pest | **PASS** |
| 5 | A viewer may look and nothing more | Pest + Playwright | **PASS** |
| 6 | An editor may change but not delete, and not invite | Pest | **PASS** |
| 7 | Revoking access closes the door immediately | Pest + Playwright | **PASS** |
| 8 | A revoked membership stays on the record | Pest | **PASS** — who had access and when is worth being able to answer |
| 9 | The invitation token is returned exactly once | Pest | **PASS** — hashed at rest, absent from every later response |
| 10 | A forwarded invitation does not work | Pest | **PASS** — the signed-in address must match the invited one |
| 11 | An expired or wrong token is refused with one message | Pest | **PASS** — distinguishing them would tell a stranger which projects exist |
| 12 | The token is burned on use | Pest | **PASS** |
| 13 | Inviting twice is a resend, not a second seat | Pest + partial unique index | **PASS** |
| 14 | **Room photographs are on the private disk under random keys** | Pest | **PASS** — never on the public bucket, whatever the configuration |
| 15 | **No response ever carries a URL or a storage path** | Pest + Playwright | **PASS** — a link is a separate request that checks ownership and expires in five minutes |
| 16 | The bytes are behind the same policy as the metadata | Pest + Playwright | **PASS** — owner 200, stranger 403, anonymous 401, `no-store` |
| 17 | Download route names actually resolve | Pest | **PASS** — the assertion that catches a fallback nothing else exercises |
| 18 | Non-photographs and unusably small photographs refused | Pest | **PASS** — below 640 px on the longest edge is a design of a blur |
| 19 | The first photograph becomes the one the engine works from | Pest + Playwright | **PASS** |
| 20 | Deleting the primary promotes another | Pest | **PASS** |
| 21 | Deleting a photograph removes the bytes | Pest | **PASS** — keeping a picture of somebody's home after they asked for it gone is indefensible |
| 22 | A floor plan cannot become the design source | Pest | **PASS** |
| 23 | The filename never reaches the audit log | Pest | **PASS** — "bebek-odasi-yatak.jpg" tells staff something they have no business knowing |
| 24 | **Version numbers never repeat, even after a failure** | Pest + unique index | **PASS** — picked under a row lock, so a double click cannot collide |
| 25 | **A refinement is a child, not a replacement** | Pest | **PASS** — and two refinements may branch from the same version |
| 26 | Only a finished version may be branched from | Pest | **PASS** — pending and failed both refused |
| 27 | A parent from another design is refused | Pest | **PASS** |
| 28 | **A finished version never changes** | Pest | **PASS** — at the service, and `ready`/`failed` invariants at the storage layer |
| 29 | A version cannot be its own parent | CHECK constraint | **PASS** |
| 30 | A customer can go back to an earlier version | Pest + Playwright | **PASS** |
| 31 | A failed refinement does not make a working design look broken | Pest | **PASS** |
| 32 | The whole tree loads in one query | Pest | **PASS** — ≤ 3 queries for a four-version tree |
| 33 | Every prompt that shaped an image is recoverable | Pest | **PASS** |
| 34 | A design cannot start on a room with no photograph | Pest + Playwright | **PASS** |
| 35 | A viewer cannot spend the owner's credits | Pest | **PASS** |
| 36 | Measured rooms must carry measurements | Pest + CHECK constraint | **PASS** — at the form and at the storage layer |
| 37 | Backend suite | `php artisan test` | **PASS** — 353 tests, 1045 assertions |
| 38 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 39 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 40 | **End-to-end** | Playwright, live stack | **PASS** — 18 journeys |

### End-to-end journeys added

```text
project journey   customer creates a project -> adds a room ->
                  "waiting for a photograph" said plainly ->
                  uploads one -> it becomes the design source ->
                  enters measurements in centimetres, sees 23.52 m² ->
                  places a window on a wall ->
                  starts a design, v1 appears in the tree

privacy           the listing carries no URL and no storage path ->
                  a link is a separate request, owner only, expires in 5 minutes ->
                  the bytes: owner 200, stranger 403, anonymous 401, no-store ->
                  the browser still renders the photograph

sharing           owner invites by e-mail -> the partner is refused until they accept ->
                  accepting grants read access ->
                  a viewer cannot edit ->
                  revoking closes the door immediately
```

### Defects found and fixed during this phase

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P5-D001 | **P2** | The super-admin `Gate::before` bypass would have let platform staff open any customer's project and look at photographs of their home. It was correct for operational tables and silently wrong for this one. | The bypass now skips `Project`, `Room`, `RoomMedia`, `Design` and `DesignVersion`, matched on class rather than on ability name so a model added later is not silently excluded from the exclusion. Asserted in both directions. |
| P5-D002 | **P3** | *(carried from Phase 2)* `DocumentStorage::temporaryUrl()` fell back to `route('api.v1.seller.documents.download')` — a name the router never registers, because the `api.` prefix is not applied. Every environment RefConcept is tested in can sign a URL, so it would have surfaced only in production on a deployment without object storage, as a 500 on every "view document". | Corrected, and a test now asserts that all three download route names resolve. |
| P5-D003 | P3 | `DesignVersionRefused` declared a readonly `$code`, which PHP refuses to redeclare over `Exception::$code` — a fatal error at class load rather than a warning. | Renamed to `$reason`. |

### Design decisions worth recording

- **The original is immutable, structurally.** AI renders live in `design_assets`, room
  photographs in `room_media`, with different writers. There is no code path that
  could write a render over a customer's own photograph, which is a stronger guarantee
  than everybody remembering not to.
- **`room_dimensions` was not built as a separate table.** The specification lists it
  in §9.6 and then puts width/length/height on `rooms` in §10.6; the two contradict
  each other. Every room has exactly one envelope, so a join for three integers buys
  nothing. What genuinely varies in number — windows, doors, columns — is
  `room_constraints`.
- **`room_scans` is deferred to Phase 17** with the rest of the RoomPlan/ARCore work.
  `measurement_quality` already has a `scanned` value waiting for it.
- **Room types are one vocabulary shared with the product catalogue.** A bedroom design
  offers bedroom furniture because both sides agree what a bedroom is; two lists that
  drift produce a matching engine that finds nothing and no error to explain it.
- **Generation is not implemented here, and the UI says so.** A version is honestly
  reported as queued rather than dressed in a fake progress bar. The tree, its
  numbering and its branching rules are real and fully tested; the AI gateway (Phase 6)
  and the design engine (Phase 8) fill them in.

### Notes

- Invitation e-mails are Phase 12. Until then the owner copies the invitation link
  themselves, and the screen says that plainly rather than implying a mail was sent.
- Room photographs accept HEIC, because that is what an iPhone produces. Dimensions
  cannot be read from one without a PHP extension that may be absent, so the upload is
  accepted with unknown dimensions rather than refused.

### Verdict

**PHASE 5 GATE: PASS** — proceed to Phase 6.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (17 phases remaining).

---

## Phase 6 — AI gateway foundation

**Date:** 2026-08-25
**Scope:** the one place RefConcept talks to a model — providers, credentials, models,
prices, prompt versions, routing, retries, fallback, cost ceilings, the kill switch, and
the record of every attempt.

### Gate criteria

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **Nothing in the application names a model** | code review + Pest | **PASS** — provider, model, prompt version, timeout, retries, credits and cost ceiling are all rows in `ai_task_routes` |
| 2 | A task with no route fails without contacting a provider | Pest | **PASS** — and writes a failure row, so the omission is visible on the dashboard that exists to show it |
| 3 | **The kill switch stops a task before anything is spent** | Pest + Playwright | **PASS** — refused at the gateway *and* at the dispatcher, so no queue of doomed jobs accumulates |
| 4 | Pausing demands a written reason | Pest + Playwright | **PASS** — and the reason is shown on the console, not only in a log |
| 5 | A transient failure is retried on the same model | Pest | **PASS** — three attempts, every one recorded |
| 6 | A failure that would repeat identically is not retried | Pest | **PASS** — one call for an invalid request, not three |
| 7 | **A persistent failure falls back to a second provider** | Pest | **PASS** — the fallback attempt is marked as one |
| 8 | A safety refusal goes straight to the fallback | Pest | **PASS** — not retryable, but worth a different provider; providers draw the line in different places |
| 9 | A failure the fallback would share does not reach it | Pest | **PASS** |
| 10 | **The cost ceiling is checked before the call** | Pest | **PASS** — zero provider calls when the estimate exceeds it |
| 11 | Cost comes from the rate table, never from the provider | Pest | **PASS** — a provider cannot misreport what we believe we spent |
| 12 | A rate may only start after the one it replaces | Pest + CHECK constraint | **PASS** — refused by the endpoint with a sentence, before the constraint has to |
| 13 | A price change closes the old row rather than editing it | Pest | **PASS** — a March job keeps reporting March's price |
| 14 | Failed attempts are still charged for | Pest | **PASS** — a provider that read the input and then refused still billed for reading it |
| 15 | Credits are charged once per job, not per attempt | Pest | **PASS** — a customer must not pay three times for a flaky provider |
| 16 | **Structured output is validated before it is called a success** | Pest | **PASS** — prose where an object was asked for is a retryable failure, not a mystery two steps downstream |
| 17 | A missing key is named in the failure | Pest | **PASS** |
| 18 | A wrong type is caught | Pest | **PASS** — including a decimal string where minor units were expected |
| 19 | A fenced code block is unwrapped, not rejected | Pest | **PASS** — the model answered correctly and added decoration |
| 20 | The routed prompt version is what gets sent | Pest | **PASS** — rendered with the job's own input |
| 21 | **An image URL never enters the prompt text** | Pest | **PASS** — it travels as an attachment; a URL in a prompt is a URL a model can repeat back |
| 22 | The API key is absent from the call fingerprint | Pest | **PASS** |
| 23 | **A published prompt version cannot be edited** | Pest + PostgreSQL trigger | **PASS** — refused through the API *and* against the table directly |
| 24 | Version numbers are assigned under a lock | Pest | **PASS** — two people saving at once cannot collide |
| 25 | Publishing retires the previous version and repoints the route | Pest | **PASS** — half of either would be a change nothing uses |
| 26 | A prompt can be previewed without calling anything | Pest | **PASS** — and names the variables the input did not supply |
| 27 | **An API key is stored encrypted and never returned** | Pest | **PASS** — not in the response, not readable in the table, not in the audit log |
| 28 | Only one credential is active at a time | Pest | **PASS** — two would be an ambiguity discovered while reading a bill |
| 29 | A driver with no adapter is refused on the form | Pest | **PASS** |
| 30 | An image task cannot be pointed at a text model | Pest | **PASS** — caught where the person who made the change is looking |
| 31 | One route per task, however many times it is saved | Pest | **PASS** |
| 32 | **A customer's job payload is theirs alone** | Pest | **PASS** — owner 200, stranger 403, **super admin 403** |
| 33 | The super-admin bypass excludes `AiJob` | Pest | **PASS** — a job is a second door into the room projects already lock |
| 34 | Platform staff keep the operational view | Pest | **PASS** — task, model, timings, cost, failure kind; no input, no output, no photograph |
| 35 | The customer view carries no provider or model detail | Pest | **PASS** — of no use to a customer and of considerable use to a competitor |
| 36 | A repeated idempotency key returns the same job | Pest | **PASS** — a double tap must not be charged twice |
| 37 | Concurrency is limited per user, not globally | Pest | **PASS** — one person's queue does not lock anybody else out |
| 38 | An adapter that throws does not strand a job | Pest | **PASS** — recorded as a provider error rather than leaving a spinner forever |
| 39 | A crashed worker does not leave a job at `running` | code review (`RunAiJob::failed`) | **PASS** |
| 40 | The queue does not retry on top of the gateway | code review (`$tries = 1`) | **PASS** — nine calls to a provider that is rate-limiting us, all charged, is the failure this avoids |
| 41 | Provider failures are classified, not thrown | Pest | **PASS** — 429 retryable, 400 not, a 400 that is a content-policy refusal treated as a refusal |
| 42 | **Google's 200-with-`finishReason: SAFETY` is caught** | Pest | **PASS** — the trap that would otherwise report an empty answer as a success |
| 43 | A blocked prompt with no candidates is caught | Pest | **PASS** |
| 44 | The Google key goes in a header, not the query string | Pest | **PASS** — query strings reach access logs; headers do not |
| 45 | An inline image is re-hosted, not linked | Pest | **PASS** — the provider's URL expires within the hour |
| 46 | Continuous integration spends nothing | Pest | **PASS** — `FakeAiProvider` is deterministic and scriptable; no test reaches a network |
| 47 | Every task ships routed and prompted out of the box | seeder + Pest | **PASS** — twelve routes, twelve published prompts; falls back to the simulator when no key is on file |
| 48 | Backend suite | `php artisan test` | **PASS** — 413 tests, 1250 assertions |
| 49 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 50 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 51 | **End-to-end** | Playwright, live stack | **PASS** — 20 journeys |

### End-to-end journeys added

```text
ai console   operator signs in -> AI control room lists all twelve tasks ->
             pauses "Destek asistanı" with a written reason ->
             the reason appears on the screen ->
             the API reports the route as paused ->
             resumes it

ai console   a customer's token gets 403 from every /admin/ai endpoint
```

### What this phase deliberately did not do

- **No endpoint starts a job.** Jobs are created by the feature that needs one — a design
  version, a room analysis — because only that feature knows what to do with the answer.
  A generic "run this prompt" endpoint would let anybody with an account spend the
  provider budget on prompts of their own choosing.
- **No credits are debited.** That is Phase 7. A half-written version of it here would
  mean two places debiting a balance by the time the real one exists. `credit_cost` is
  recorded on the job; nothing spends it yet.
- **`AiTask` is an enum, not a table** — a documented deviation from the specification's
  table list. A task type is code: each value has a prompt written for it, a schema the
  application parses and a call site that reads the answer. A row in a table would add
  none of those. What genuinely belongs in the database is the *routing*, and that is
  exactly what `ai_task_routes` holds.

### Honest limitations

- **The adapters are tested against a faked HTTP layer.** These tests assert that *given*
  a response of a certain shape the adapter classifies it correctly — not that OpenAI or
  Google still produce that shape. The second is not knowable from a test suite, and a
  recorded fixture pretending otherwise would only assert that it still matches itself.
  The mitigation is that a misclassification degrades rather than breaks: an unrecognised
  failure falls into `ProviderError`, which is retryable and warrants a fallback.
- **Cost estimation is deliberately pessimistic and therefore approximate.** Input tokens
  are estimated at four characters each, which is the usual approximation for Latin text
  and is wrong for Turkish in the safe direction. A ceiling that occasionally refuses a
  call it could have afforded is a better failure than one that lets a runaway through.
- **`GeneratedImageStore` writes to the public bucket.** A render is something a customer
  shares with a partner and a contractor; the room photograph that produced it is not, and
  stays private. Keys are random, so a render is not discoverable without its link — but
  it is not access-controlled either, and that is a deliberate trade rather than an
  oversight.

### Notes

- The Google key is read from `GOOGLE_AI_API_KEY` in `apps/api/.env` (gitignored), placed
  on file by the seeder, and encrypted at rest by the model's cast. With no key present
  the seeder routes every task to the local simulator and says so, so a fresh clone boots
  with a working — if artificial — AI path rather than twelve broken features.
- The key used during development should be rotated before anything ships.

### Verdict

**PHASE 6 GATE: PASS** — proceed to Phase 7.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (16 phases remaining).

---

## Phase 7 — Credit economy

**Date:** 2026-08-25
**Scope:** what a customer buys and what an AI render spends — packages, wallets, an
immutable ledger, expiry, holds, promotions and hand corrections.

### Gate criteria (04_WEB_PHASE_PLAN.md: duplicate + concurrency + invariant tests)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **The ledger is the authority and nothing else writes it** | code review + Pest | **PASS** — `CreditLedger` is the sole writer of all four tables |
| 2 | Every entry carries the balance it produced | Pest | **PASS** — a disputed statement shows what was true then, not what today's code computes |
| 3 | **The ledger cannot be edited** | Pest + PostgreSQL trigger | **PASS** — UPDATE and DELETE both refused, asserted directly against the table |
| 4 | **A balance can never go negative** | Pest + CHECK constraint | **PASS** — asserted through the service and against the table |
| 5 | Held credits can never exceed the balance | Pest + CHECK constraint | **PASS** |
| 6 | The direction of each movement matches its type | Pest + CHECK constraint | **PASS** — a "consume" that adds credits is refused by the database |
| 7 | An adjustment without a reason is impossible | Pest + CHECK constraint | **PASS** — enforced in the schema, not only in the request rules |
| 8 | **The aggregate always agrees with the lots** | Pest | **PASS** — asserted after nine consecutive movements of five different kinds |
| 9 | **A repeated reference does not grant twice** | Pest | **PASS** — the same transaction is returned |
| 10 | A repeated reference does not spend twice | Pest | **PASS** |
| 11 | A repeated reference does not hold twice | Pest | **PASS** — the existing reservation is handed back |
| 12 | **A hold settles exactly once** | Pest | **PASS** — consume, consume, release leaves one charge |
| 13 | **The wallet row is locked before any decision** | Pest (query inspection) | **PASS** — `SELECT … FOR UPDATE`, then decide from what the lock returned |
| 14 | Lots are locked when drawn from | Pest (query inspection) | **PASS** |
| 15 | **A stale caller-held wallet cannot overspend** | Pest | **PASS** — the ledger re-reads inside the lock and refuses |
| 16 | Holds are independent of one another | Pest | **PASS** — one consumes, one releases, both for their own amount |
| 17 | The sum of holds never exceeds the balance | Pest | **PASS** — the ninth of ten holds is refused, on availability rather than balance |
| 18 | Every movement is one transaction | Pest | **PASS** — a rollback leaves no lot, no entry and no balance change |
| 19 | **Credits expire soonest-deadline-first** | Pest | **PASS** — a promotion expiring in a week is spent before a purchase lasting a year |
| 20 | Dated credits are spent before undated ones | Pest | **PASS** — undated credits are the reserve, not the first thing reached for |
| 21 | Expiry removes exactly the unheld remainder | Pest | **PASS** |
| 22 | **Held credits do not expire underneath a running job** | Pest | **PASS** — otherwise the settle finds no hold and the work is free |
| 23 | Abandoned holds are swept and returned | Pest | **PASS** — recorded as `expired`, distinct from a clean `released` |
| 24 | A refusal says how many credits are missing | Pest | **PASS** — "8 gerekiyor, 3 var" rather than "yetersiz bakiye" |
| 25 | **A promotion's budget is counted under a lock** | Pest | **PASS** — a limit of two hands out two, not three |
| 26 | A per-user limit is honoured, including above one | Pest | **PASS** — three claims produce three distinct grants, not one repeated |
| 27 | **Unknown, ended and exhausted codes give one identical refusal** | Pest | **PASS** — otherwise the endpoint enumerates live campaigns |
| 28 | "Already redeemed" is said plainly | Pest | **PASS** — safe, because the asker already knows the code |
| 29 | A welcome bonus tests for credits, not for registration date | Pest | **PASS** — somebody who signed up a year ago and is only now trying the product still qualifies |
| 30 | Redemption is rate-limited per account | Pest | **PASS** — five attempts, then 429 |
| 31 | **Redemption requires a verified e-mail** | Pest | **PASS** — without it a promotion is a free-credit machine |
| 32 | A campaign can be switched off | Pest | **PASS** |
| 33 | **An AI job holds its cost before it is queued** | Pest | **PASS** — refused at the door, not queued and failed four seconds later |
| 34 | A successful job consumes the hold | Pest | **PASS** |
| 35 | **A failed job costs the customer nothing** | Pest | **PASS** — provider failure, cancel and a dead worker all release |
| 36 | Three provider attempts are one charge | Pest | **PASS** — the retry is our decision and our cost |
| 37 | A zero-cost task holds nothing | Pest | **PASS** — a query rewrite is paid from the platform's budget |
| 38 | A job with no owner holds nothing | Pest | **PASS** |
| 39 | A refused job leaves no litter in the customer's history | Pest | **PASS** — the row is removed rather than left as something that never ran |
| 40 | The render appears on the statement in the customer's language | Pest + Playwright | **PASS** — "Görsel üretimi (taslak)", not a job id |
| 41 | **Holds are hidden from the statement** | Pest | **PASS** — a reserve plus a consume is one event to the person who ran it |
| 42 | Expiring credits are surfaced without being asked for | Pest + Playwright | **PASS** — above the statement, not inside it |
| 43 | One customer never sees another's wallet | Pest | **PASS** — and the routes carry no id to get wrong |
| 44 | The admin routes refuse a customer | Pest + Playwright | **PASS** |
| 45 | A hand correction is audited with actor and reason | Pest + Playwright | **PASS** — and the reason reaches the customer's own statement |
| 46 | A correction cannot drive a balance below zero | Pest + Playwright | **PASS** — refused rather than clamped |
| 47 | The reconciliation figure is on the support screen | Pest | **PASS** — where the person who most needs it is already looking |
| 48 | Backend suite | `php artisan test` | **PASS** — 473 tests, 1455 assertions |
| 49 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 50 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 51 | **End-to-end** | Playwright, live stack | **PASS** — 24 journeys |

### End-to-end journeys added

```text
credit economy   customer signs in -> credits page shows 0 ->
                 redeems a campaign code -> balance, expiry warning and
                 statement all move -> the same code is refused a second time ->
                 the API confirms one grant, not two

credit economy   staff correct a balance (refused without a reason, accepted
                 with one) -> the customer sees the correction and its reason
                 on their own statement

credit economy   a customer's token gets 403 from every /admin/credits endpoint

credit economy   an operator closes a package and reopens it
```

### What this phase deliberately did not do

- **No purchases.** Buying a package needs a payment provider, which is Phase 11. The
  packages are real rows on a real endpoint and the storefront lists them honestly — with
  "Satın alma yakında açılıyor" rather than a button that does nothing. `lifetime_purchased`
  exists and stays at zero until then.
- **No double-entry journal.** `06_SECURITY_PAYMENT_FINANCE_RULES.md` specifies one for
  money, and Phase 13 builds it. Credits are not money in that sense — nobody is owed a
  payout in credits — so this ledger records credit movements, and the journal will record
  the lira that bought them.
- **No refund of a purchase.** The transaction type exists and the ledger can grant against
  it; what triggers it is a payment reversal, which does not exist yet.

### Honest limitations

- **The concurrency tests assert the lock is taken, not that it blocks.** They prove the
  ledger takes `SELECT … FOR UPDATE` and decides from what the lock returned, and that a
  caller holding a stale wallet cannot overspend. That PostgreSQL then serialises a second
  transaction is PostgreSQL's behaviour; testing it would be testing PostgreSQL. The
  invariants that survive a caller who forgets the lock entirely were pushed into the
  schema instead, and those are asserted directly. This is the same position taken for the
  stock ledger in Phase 4, for the same reason.
- **A hold's two-hour expiry is a chosen number, not a derived one.** It has to exceed the
  slowest route's timeout multiplied by its retries with room to spare, and today's slowest
  route could reach roughly five minutes. Two hours is generous rather than calculated; if
  a future route is configured beyond that, the sweeper could release a hold under a
  running job and the work would be free. Worth a guard when route timeouts become
  operator-editable in anger.
- **`redemption_count` on a promotion is a denormalised counter.** It is incremented under
  the same lock that reads it, so it cannot drift under concurrency — but it is a second
  place the truth lives, and `credit_promotion_redemptions` is the first. They are compared
  in the tests; there is no scheduled reconciliation between them.

### Notes

- The sweep runs hourly, not nightly. Expiry alone would be fine once a day; abandoned
  holds set the cadence, because credits a customer cannot spend while their screen says
  they can is a support ticket within the hour.
- The seeded welcome bonus grants 25 credits — enough for a room analysis and a draft
  render, which is the smallest amount that lets somebody see what the product does. A
  bonus that runs out before the first result is worse than none.

### Verdict

**PHASE 7 GATE: PASS** — proceed to Phase 8.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (15 phases remaining).

---

## Phase 8 — AI room analysis and design generation

**Date:** 2026-08-25
**Scope:** the pipeline that turns a customer's photograph into a design — analysis,
planning, validation, rendering, progress, and what happens to the credits when any of it
fails.

### Gate criteria (04_WEB_PHASE_PLAN.md: AI E2E with fake + sandbox contract tests)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **A photograph becomes a finished render** | Pest + Playwright | **PASS** — analysis, plan and image, with the design pointing at the new version |
| 2 | Each step leaves something worth keeping | Pest | **PASS** — `room_analyses`, `design_plans`, `design_assets` |
| 3 | **One charge for the whole version** | Pest + Playwright | **PASS** — 1 + 1 + 2 credits held once and consumed once; the three AI jobs cost the customer nothing of their own |
| 4 | A room is read once and reused | Pest | **PASS** — the second design of the same room skips the analysis and is quoted 3 rather than 4 |
| 5 | The skip is visible rather than silent | Pest | **PASS** — recorded as a `skipped` event |
| 6 | A premium render is priced above a draft | Pest | **PASS** — both read from the routes, so an operator reprices without a deploy |
| 7 | **Every stage announces itself** | Pest + Playwright | **PASS** — queued, analysis, plan, render, save, done |
| 8 | Progress is polled and the page updates | Playwright | **PASS** |
| 9 | Polling that keeps failing stops and says so | code review | **PASS** — five consecutive failures, then a message rather than a silent spinner |
| 10 | **A placement the room cannot take is refused** | Pest | **PASS** — a 6000mm sideboard in a 5000mm room |
| 11 | The refusal is recorded, not dropped | Pest | **PASS** — with its reason, and the count reaches the customer |
| 12 | A room with no measurements refuses nothing | code review | **PASS** — arithmetic on a guessed number would reject real furniture |
| 13 | Blocking constraints reduce the usable wall | code review | **PASS** — a window that must stay visible takes its width plus clearance |
| 14 | **A failed step returns every credit** | Pest + Playwright | **PASS** — provider refusal, no-image render, cancel and a dead worker all release the hold |
| 15 | A render that produced no image fails honestly | Pest | **PASS** — distinct from a provider failure: the call worked and the money was spent |
| 16 | **The provider's own words never reach the customer** | Pest | **PASS** — "gpt-image-1 in org-abc123" becomes "İstek sınırı" |
| 17 | A paused task is explained, not reported as a crash | Pest | **PASS** — the gateway's refusal is passed through |
| 18 | A customer who cannot pay is told before anything runs | Pest + Playwright | **PASS** — 422 with the two numbers, and the version says why |
| 19 | A duplicate queue delivery does not run twice | Pest | **PASS** — no second set of provider calls, no second charge |
| 20 | A refinement branches from a finished version | Pest | **PASS** — and is quoted without the analysis |
| 21 | A failed refinement leaves the earlier version alone | Pest | **PASS** — a design with a good image does not look broken |
| 22 | **The plan is immutable once written** | PostgreSQL trigger | **PASS** |
| 23 | A room has exactly one current analysis | partial unique index | **PASS** |
| 24 | Confidence is stored in basis points | Pest | **PASS** — and clamped, because a model answering 1.4 has not become more certain than certain |
| 25 | **Provider images are staged privately** | Pest | **PASS** — `ai-staging/` on the private disk, never the public bucket |
| 26 | The staged copy is discarded after it is claimed | code review | **PASS** — scratch space nobody empties becomes an archive of every render |
| 27 | The image is copied without an HTTP round trip | code review | **PASS** — a reference is preferred over a URL |
| 28 | An event message longer than the column is truncated | code review | **PASS** — an event is a status line, not a log, and a failed insert loses the very event explaining the failure |
| 29 | **Turkish folding does not mangle İ** | Pest | **PASS** — a real defect: "İndirimli fiyat" matched no column alias, and discount prices silently never arrived |
| 30 | Backend suite | `php artisan test` | **PASS** — 493 tests, 1528 assertions |
| 31 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 32 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 33 | **End-to-end** | Playwright, live stack | **PASS** — 26 journeys |

### End-to-end journeys added

```text
design generation   an operator repoints the three pipeline tasks at the simulator ->
                    a customer is funded -> a room with a photograph ->
                    the design is started in the browser -> progress runs to "Hazır" ->
                    the API confirms a plan, an image and one charge ->
                    the statement shows "Tasarım üretimi"

design generation   a customer with no credits is refused before anything runs
```

### Defects found and fixed during this phase

- **The gateway overwrote a job's credit cost from its route** when it started, so the
  pipeline's zero-cost steps would have been charged a second time. The dispatcher owns the
  cost; the gateway now records only which route ran.
- **Nested AI jobs ran twice under a synchronous queue driver.** `dispatch()` queues, and
  the pipeline then awaited the answer — which under the sync driver meant the job ran on
  dispatch and again on await. Split into `accept`, `dispatch` and `runInline`: a caller
  already on a worker never queues a nested job.
- **`GeneratedImageStore` wrote renders to the public bucket.** Fixed to the private disk;
  see criterion 25.
- **Turkish `İ` folding**; see criterion 29.
- **A route saved with its own fallback as primary hit a CHECK constraint and 500'd.** The
  console now clears the stale fallback, which is the operator's unambiguous intention.
- **An event message built from an exception exceeded its column** and lost the event that
  was explaining the failure.

### Honest limitations

- **The simulator is not a model.** Every assertion in this phase is against a fake provider
  that answers deterministically. That is the right way to test a pipeline — a suite that
  called a real model would fail for reasons that have nothing to do with the code — but it
  means nothing here tells us whether the *prompts* produce good designs. That question
  needs people looking at renders, and it is not one a test suite can answer.
- **Placement validation is arithmetic, not judgement.** It refuses what does not fit and
  says nothing about whether a sideboard belongs on that wall. A plan can be dimensionally
  valid and still ugly.
- **A room with no measurements is not checked at all.** A customer who never measured gets
  whatever the model proposes, because refusing furniture on the strength of a guessed wall
  length would be worse than not refusing it. The measurement quality is recorded and shown;
  acting on it is a product decision nobody has made yet.
- **The render prompt names the elements to preserve, and nothing verifies that it did.**
  The analysis says "keep the window" and the prompt says so too, but whether the produced
  image kept it is unchecked — that needs a second vision call, and Phase 9's object
  extraction is the natural place for it.
- **Two hours is still a chosen number** for the credit hold, as in Phase 7. A pipeline of
  three calls with retries stays far inside it today.

### Notes

- The E2E journey repoints the three pipeline tasks at the local simulator through the same
  console endpoints an operator would use, and puts the routing back afterwards. Running a
  real provider here would make the suite slow, expensive and dependent on somebody else's
  uptime.
- The queue worker holds compiled code in memory between jobs, so `scripts/sync.sh` alone
  does not update it — `docker compose restart queue` is needed after changing anything a
  queued job runs. This cost a debugging session and is worth writing down.

### Verdict

**PHASE 8 GATE: PASS** — proceed to Phase 9.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (14 phases remaining).

---

## Phase 9 — Product matching

**Date:** 2026-08-25
**Scope:** turning a design plan into products a customer can buy — embeddings, hard
filters, pgvector retrieval, reranking, the shopping list, and what a customer says about
it.

### Gate criteria (04_WEB_PHASE_PLAN.md: benchmark fixtures + budget/stock/category filters)

The benchmark is a fixed catalogue of seven products with deliberate differences — sofas
of different widths and prices, a sofa nobody can buy, a coffee table, a wardrobe in
another room — so every assertion below names the answer it expects and why.

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **Every listable product gets a vector** | Pest | **PASS** — and only listable ones; a draft listing cannot appear in a result |
| 2 | Unchanged text is not re-embedded | Pest | **PASS** — hashed input, so a nightly pass over an unchanged catalogue costs one query |
| 3 | Changed text is re-embedded | Pest | **PASS** — and replaces rather than accumulating |
| 4 | The seller's name is not in the embedded text | Pest | **PASS** — two sofas from one shop must not be similar *because* of the shop |
| 5 | **Only the right category comes back** | Pest | **PASS** — the wardrobe and the coffee table are excluded from a sofa search |
| 6 | **A category with no match returns nothing** | Pest | **PASS** — the important negative: falling back to the whole catalogue would recommend a wardrobe for a chandelier |
| 7 | Category matching survives capitals and accents | Pest | **PASS** — the planner writes prose, the catalogue holds slugs |
| 8 | **Nothing out of stock is ever suggested** | Pest | **PASS** — asserted with the out-of-stock product as the *textually nearest* candidate |
| 9 | **A product too wide for the wall is refused** | Pest | **PASS** — 2100mm passes a 2200mm limit, 3200mm does not |
| 10 | An unmeasured product is allowed through | Pest | **PASS** — excluding everything unmeasured would empty the results for the customer who measured |
| 11 | **A budget ceiling is respected** | Pest | **PASS** — in the retrieval and again per placement in the list |
| 12 | A bedroom category stays out of a living room | Pest | **PASS** — and the same query finds it in the bedroom |
| 13 | Products used for one placement are excluded from the next | Pest | **PASS** — two placements wanting a sofa get two different sofas |
| 14 | Ordering follows meaning, not price | Pest | **PASS** — the nearest description ranks first |
| 15 | Similarity is a bounded percentage | Pest | **PASS** — clamped, because a similarity below nothing is unreadable |
| 16 | One row per product, not per offer | code review + Pest | **PASS** — `DISTINCT ON`, cheapest purchasable offer wins |
| 17 | **The list is grouped by placement** | Pest + Playwright | **PASS** — "for the sofa, these" rather than a flat list |
| 18 | Ranks start at one and follow the score | Pest | **PASS** |
| 19 | **The price shown is a snapshot** | Pest | **PASS** — and a later change is reported rather than silently applied |
| 20 | A placement the catalogue cannot serve is left empty | Pest | **PASS** — an empty group beats a wrong suggestion |
| 21 | Rebuilding replaces rather than merges | Pest | **PASS** — two generations would produce an order nobody can explain |
| 22 | **The rerank is optional** | code review | **PASS** — a failed model call returns the similarity ordering unchanged |
| 23 | The rerank is blended, not substituted | code review | **PASS** — 60/40, so a model with a favourite cannot bury a closer match |
| 24 | Only the shortlist is reranked | code review | **PASS** — ten candidates, not four hundred |
| 25 | **The list is the owner's alone** | Pest | **PASS** — a stranger gets 403 |
| 26 | Choosing demotes the alternatives | Pest | **PASS** — two accepted for one spot does not reflect what happened |
| 27 | The total counts only what was chosen | Pest | **PASS** — summing suggestions would be five times the real figure next to "toplam" |
| 28 | **Feedback is recorded and blames a stage** | Pest | **PASS** — wrong size is a filter bug, wrong style a modelling problem |
| 29 | Every verdict is kept | Pest | **PASS** — "too expensive" then "wrong style" is two statements |
| 30 | A negative verdict stops the suggestion recurring | Pest | **PASS** — the one thing feedback changes automatically |
| 31 | A positive verdict changes nothing | Pest | **PASS** |
| 32 | Matching cannot fail a design | code review | **PASS** — a render the customer paid for is not lost because the catalogue had no sofas |
| 33 | Backend suite | `php artisan test` | **PASS** — 521 tests, 1592 assertions |
| 34 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 35 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 36 | **End-to-end** | Playwright, live stack | **PASS** — 27 journeys |

### End-to-end journeys added

```text
design generation   the catalogue is embedded through the operator's own command ->
                    a design is generated -> the shopping list comes back grouped by
                    placement, with a named product, a real SKU and a price above zero
```

### Defects found and fixed during this phase

- **`SELECT DISTINCT` with an ordered expression** failed outright in PostgreSQL. Rewritten
  as `DISTINCT ON (p.id)` inside a subquery, which also expresses the real intent — one row
  per product, cheapest purchasable offer.
- **The vector column is `NOT NULL`,** so writing the row through Eloquent and setting the
  vector afterwards could never work. Replaced with a single upsert, which is also one
  write rather than three per product.
- **Registration throttling broke the suite as it grew.** The browser-driven registration
  now waits out the limit and resubmits, the way `accounts.ts` already honoured
  `Retry-After` for the API — the throttle stays real rather than being disabled for tests.

### Honest limitations

- **The fake embeddings are word-overlap, not meaning.** They make the benchmark
  deterministic and the filters testable, and they prove nothing about whether a real
  embedding model finds "İskandinav meşe" for "warm minimalist oak". That question needs a
  real model and a person judging the results; no fixture can answer it.
- **There is no relevance metric.** The suite asserts that specific products are or are not
  returned; it does not measure precision or recall over a labelled set, because no labelled
  set exists. `design_match_feedback` is the beginning of one — it is the reason the table
  is there — and until enough of it accumulates, "is matching good" is a judgement rather
  than a number.
- **Image embeddings are schema and enum only.** "A sofa like the one in this render" is a
  different question from "a sofa matching this description", and only the second is
  answered today. The column and the enum case exist so adding it does not need a migration
  and a re-embedding run.
- **Object extraction is a table without a producer.** `design_extracted_objects` is
  migrated and modelled; nothing writes to it yet. The `ObjectExtraction` task exists in the
  gateway, and wiring it belongs with the interactive render view rather than here — a
  bounding box is only useful once somebody can click on it.
- **The per-placement budget is an even split plus half.** Crude and defensible: the sofa
  costs more than the lamp, and inventing a split without asking the customer would be
  inventing a preference. It is the first thing to revisit when there is feedback to revisit
  it with.
- **The rerank weighting (60/40) is a guess.** It is a starting point chosen to stop a model
  with a favourite from flattening the ranking, not a tuned figure.

### Notes

- The catalogue is embedded by `refconcept:embed-catalogue`, scheduled nightly and safe to
  run repeatedly. It reports embedded / unchanged / failed separately, because "1200
  processed" hides whether anything happened.
- Embeddings are 768-dimensional, which is a schema-level commitment: changing it means
  re-embedding everything. The model name is stored beside every vector so a mixed catalogue
  is detectable rather than quietly wrong.
- The HNSW index needs no training pass, so similarity search works on an empty catalogue
  and stays correct as products are added one at a time — which is how a marketplace
  actually grows.

### Verdict

**PHASE 9 GATE: PASS** — proceed to Phase 10.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (13 phases remaining).

---

## Phase 10 — Search, favourites and the basket

**Date:** 2026-08-25
**Scope:** finding things, keeping them, and a basket that tells the truth when the world
moves underneath it.

### Gate criteria (04_WEB_PHASE_PLAN.md: price/stock race tests)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **One open basket per customer** | Pest + partial unique index | **PASS** — two would mean items split across two places with one of them invisible |
| 2 | The same offer added twice raises the quantity | Pest + unique index | **PASS** — not a second line showing two of something added once |
| 3 | **The price is snapshotted when the line is added** | Pest | **PASS** |
| 4 | **A price rise is reported and not applied** | Pest + Playwright | **PASS** — both figures shown, the old one still in force, the subtotal unchanged |
| 5 | A price fall does not block checkout | Pest | **PASS** — nobody is harmed by paying less |
| 6 | A new price applies only when accepted | Pest + Playwright | **PASS** — an explicit act, so it is agreed to rather than done to them |
| 7 | **A line nobody can buy is removed and reported** | Pest | **PASS** — not left greyed out to fail at payment |
| 8 | A line short of stock is reduced, not removed | Pest | **PASS** — somebody who wanted four and can have two would rather have two |
| 9 | Adding more than exists is refused, with the number | Pest | **PASS** — "yalnızca 2 adet kaldı", not "yetersiz stok" |
| 10 | **An idle basket holds no stock** | Pest + Playwright | **PASS** — the decision this phase turns on |
| 11 | **Checkout holds all of a basket or none of it** | Pest + Playwright | **PASS** — a partial hold is a problem handed to the customer, not an order |
| 12 | The hold has a deadline | Pest | **PASS** — fifteen minutes, so an abandoned attempt returns the stock |
| 13 | Backing out releases immediately | Pest + Playwright | **PASS** — not left to expire while somebody else is told "sold out" |
| 14 | A basket in checkout cannot be edited | Pest + Playwright | **PASS** — otherwise the hold and the basket disagree |
| 15 | **Two baskets never hold more than exists** | Pest | **PASS** — the invariant, asserted however the two interleave |
| 16 | A stale basket cannot over-reserve | Pest | **PASS** — revalidation runs before any hold is taken |
| 17 | Rows are locked before anything is decided | Pest (query inspection) | **PASS** — two tabs adding the same product cannot both insert |
| 18 | Checkout on an empty basket is refused | Pest | **PASS** |
| 19 | The seller is recorded on every line | Pest | **PASS** — a basket keeps saying which shop an item came from |
| 20 | **Tax is inside the price, not on top** | Pest | **PASS** — 20.000₺ at 20% contains 3.333,33₺; the other way overcharges by a fifth |
| 21 | An unlisted product cannot enter a basket | Pest | **PASS** |
| 22 | One customer cannot touch another's line | Pest | **PASS** — 404 rather than 403: whether it exists is not a stranger's business |
| 23 | **Search finds by name** | Pest + Playwright | **PASS** |
| 24 | Search survives a misspelling | Pest | **PASS** — trigram similarity, because a search box receives "Bergma" constantly |
| 25 | Search finds words from a description | Pest | **PASS** — the maintained tsvector |
| 26 | **Nonsense returns nothing** | Pest + Playwright | **PASS** — the important negative; see the limitation below for what it cost |
| 27 | A product every method agrees on outranks one only a single method found | Pest | **PASS** — the reason fusion is by rank |
| 28 | **Facets count the whole result, not the page** | Pest | **PASS** — one product on the page, three in the count |
| 29 | Facets narrow with the other filters | Pest | **PASS** |
| 30 | Empty price bands are not offered | Pest | **PASS** — a filter returning nothing teaches somebody the filters do not work |
| 31 | Favouriting twice is favouriting once | Pest | **PASS** |
| 32 | A withdrawn product leaves the favourites list but keeps its row | Pest | **PASS** — re-listing brings it back |
| 33 | Favourites are private | Pest | **PASS** |
| 34 | A page's favourite state is one request | Pest | **PASS** — not a join on a listing anonymous visitors also read |
| 35 | Backend suite | `php artisan test` | **PASS** — 561 tests, 1697 assertions |
| 36 | Static analysis / style | PHPStan L6, Pint | **PASS** |
| 37 | Frontend gates | ESLint, vue-tsc, token guard | **PASS** |
| 38 | **End-to-end** | Playwright, live stack | **PASS** — 32 journeys |

### End-to-end journeys added

```text
shopping   a customer adds to the basket -> the seller reprices through the pricing
           endpoint -> the cart page shows both figures and refuses to move on ->
           accepting applies the new price

shopping   one basket holds three of three at checkout -> a second basket is told the
           item sold out and emptied -> the first backs out -> the second can buy

shopping   a basket in checkout refuses edits

shopping   a customer favourites from the product page and finds it on their list

shopping   search finds a listing, offers facets, and answers nonsense with nothing
```

### Defects found and fixed during this phase

- **An N+1 on the busiest endpoint a shop has.** `revalidate()` loaded the product but not
  its SKUs, and `isPubliclyVisible()` reads them — so every cart view issued a query per
  line. Strict mode caught it in tests; production would have carried it silently.
- **The seeded embedding model did not exist.** `text-embedding-004` is retired for this
  key; the catalogue silently failed to embed with a message that read like an outage.
  Corrected to `gemini-embedding-001`, which needs `outputDimensionality` passed explicitly
  because its native vector is far wider than the column.
- **Embeddings from that model are not normalised at a reduced width.** Cosine distance on
  unnormalised vectors ranks a long description above a good one, and every similarity
  percentage computed from them would have been meaningless. Normalised in the adapter, so
  every vector in the column is comparable however it was produced.
- **A nearest-neighbour search with no ceiling is a sort, not a search.** Caught by a test
  asserting that nonsense returns nothing — without it, every query returned the whole
  catalogue in some order.

### Honest limitations

- **Pure semantic search is not enabled, and the reason is measured rather than assumed.**
  Against the live embedding model, the nearest neighbour of "zzzzqqqqxyz" sits 0.3526 away
  and the nearest of "Bergama" 0.2969 — a six-hundredth margin that no threshold can
  separate reliably. So the lexical methods decide *whether* anything matched and the vector
  decides the order. The cost is real: "sıcak ve sade bir salon", which matches no word in
  any product, now returns nothing. Enabling it needs one of a larger catalogue, a threshold
  calibrated against labelled queries, or a rerank pass over the top neighbours — and until
  one of those exists, answering gibberish with a page of sofas would be the worse failure.
- **Guest baskets are not supported.** A cart requires an account. That is a real conversion
  gap and a deliberate deferral: a guest cart needs a merge-on-login story and an
  abandoned-cart sweep, both of which belong with the full storefront in Phase 20 rather
  than half-built here.
- **Nothing sweeps abandoned checkout holds.** The reservations carry a fifteen-minute
  expiry and the ledger already knows how to release stale holds, but no schedule calls it
  for carts — so a customer who closes the tab mid-payment keeps the stock held until
  something else touches that SKU. Phase 11 owns the checkout lifecycle and is the right
  place for it; today the exposure is fifteen minutes per abandoned attempt.
- **The per-line quantity ceiling is 99.** Arbitrary, and wrong for a trade customer buying
  chairs. It is a guard against a typo rather than a considered commercial limit.
- **Facet counts run one query per price band.** Four extra counts on a catalogue listing is
  cheap at this size and will not be at a hundred thousand products; a single grouped query
  with `width_bucket` is the fix when it matters.
- **Search relevance is untested as relevance.** The suite proves the machinery — that each
  method contributes, that fusion prefers agreement, that filters narrow — and not that the
  ordering is *good*. That needs labelled queries, and the same limitation stands as in
  Phase 9.

### Notes

- The catalogue was embedded against the live Gemini model during this phase — eighteen
  products, no failures — so the embedding path is verified against a real provider and not
  only against the simulator.
- `CandidateQuery` in Phase 9 deliberately has no distance ceiling, and that is not an
  inconsistency: its hard filters have already narrowed the set to one category, and "the
  three nearest sofas" is the answer being asked for. Here the question is "does anything
  match at all", which is a different question.

### Verdict

**PHASE 10 GATE: PASS** — proceed to Phase 11.

`WEB_RELEASE_APPROVED`: **NOT GRANTED** (12 phases remaining).

---

## Phase 11 — Checkout and payment core

**Date:** 2026-08-25
**Scope:** taking money, and the four ways a payment system quietly loses it.

### Gate criteria (04_WEB_PHASE_PLAN.md: duplicate / replay / timeout tests)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **A session freezes the price** | Pest | **PASS** — the seller reprices mid-checkout and the total does not move |
| 2 | **A session freezes the address** | Pest | **PASS** — editing the address book later does not change where a parcel was promised |
| 3 | Opening checkout twice returns the same session | Pest + partial unique index | **PASS** — not a second stock hold for one basket |
| 4 | Somebody else's address is refused | Pest | **PASS** — 404 rather than 403: whether it exists is not a stranger's business |
| 5 | An unverified account cannot pay | Pest (HTTP) | **PASS** — otherwise the marketplace is a card-testing service |
| 6 | **A capture consumes the stock hold** | Pest + Playwright | **PASS** — consumed, not released; a sold sofa must not return to the shelf |
| 7 | A paid basket is closed | Pest | **PASS** — not left sitting there ready to be paid again |
| 8 | **A capture credits a wallet exactly once** | Pest + Playwright | **PASS** — paid and bonus credits as separate lots |
| 9 | **The same webhook four times loads credits once** | Pest + Playwright | **PASS** — E2E-03; deduped on event id and body fingerprint |
| 10 | **Two different events with the same news act once** | Pest | **PASS** — no fingerprint can catch this; the state machine does |
| 11 | **A late failure after a capture is ignored** | Pest | **PASS** — and logged, because "we were told this and ignored it" is the sentence somebody needs later |
| 12 | An unsigned event is stored and refused | Pest + Playwright | **PASS** — 401 at the door, a row on the other side of it |
| 13 | An event claiming more than the payment is refused | Pest | **PASS** — the provider is the authority on its own payment, not on the amount |
| 14 | **A timeout leaves the checkout retryable** | Pest + Playwright | **PASS** — the session survives a decline; a customer does not start over at today's prices |
| 15 | A retry after a timeout is a second attempt, both kept | Pest | **PASS** — the history a chargeback is argued from |
| 16 | **The provider is asked when we do not know** | Pest | **PASS** — our database is never the authority on whether a bank took money |
| 17 | A second payment while one is at the bank is refused | Pest + partial unique index | **PASS** — the double-click defence |
| 18 | A session already paid cannot be paid again | Pest | **PASS** |
| 19 | **An `Idempotency-Key` replays the first answer** | Pest (HTTP) + Playwright | **PASS** — byte for byte, with `Idempotent-Replay: true` |
| 20 | The same key with a different body is refused | Pest (HTTP) | **PASS** — a retry, that is not; answering it with a stored result would be worse |
| 21 | A failed answer is not stored under a key | Pest (HTTP) | **PASS** — else a transient failure freezes into a permanent one for that key |
| 22 | **A refund cannot exceed what was captured** | Pest + CHECK constraint | **PASS** — refused with a sentence before the constraint is reached |
| 23 | A partial refund leaves the rest refundable | Pest | **PASS** |
| 24 | A retried refund is the same refund | Pest + partial unique index | **PASS** — the operation most likely to be retried, and the one where a duplicate costs money |
| 25 | **The financial record cannot be edited** | Pest (raw `UPDATE`) | **PASS** — a PostgreSQL trigger, not an Eloquent guard a raw query walks past |
| 26 | An abandoned checkout is closed and the stock returned | Pest | **PASS** — every minute, because a live session locks the customer out of starting another |
| 27 | A checkout mid-3DS is left alone by the sweeper | Pest | **PASS** — "your payment expired" while the bank thinks otherwise is worse than a late session |
| 28 | An unknown gateway name is a 404 | Pest (HTTP) | **PASS** — the webhook endpoint does not confirm which integrations exist |
| 29 | One customer cannot read another's payment | Pest (HTTP) | **PASS** |
| 30 | Backing out returns the stock immediately | Pest (HTTP) + Playwright | **PASS** |
| 31 | **The 3DS round trip works end to end** | Playwright | **PASS** — out to a stand-in bank page, back, and the page asks rather than assumes |
| 32 | Card data never reaches the API | Code review + adapter contract | **PASS** — a token or a redirect, never a PAN; responses redacted before storage |

### Defects found and fixed in this phase

| Id | Severity | Defect | Fix |
|---|---|---|---|
| P11-D001 | **P1** | A basket that took the last of the stock into checkout and was then re-read emptied itself and reported "out of stock" — against its **own** hold. Every customer buying the last unit of anything would have lost their basket while paying for it. | `CartService::revalidate()` counts the cart's own reservations as available to it; `ProductSku::isOffered()` and `Product::isListable()` separate "withdrawn" from "none left", so the ledger stays the authority on quantity |
| P11-D002 | **P1** | `product_skus.stock_quantity` — what the catalogue's list query reads — was written only by the seller's own stock endpoint. Buying the last unit left the listing advertising stock until a seller happened to open the stock page, and the next customer found out at checkout. | `InventoryLedger` projects the sellable figure onto the SKU on every movement, in the ledger, where the change is known |
| P11-D003 | P2 | The stand-in 3DS page rendered perfectly and did nothing when clicked. Blade swallows the newline after a directive, so three `const` declarations collapsed onto one line and the whole script was a syntax error. | Explicit semicolons, and a comment saying why they are load-bearing |
| P11-D004 | P2 | `GET /checkout` always read the basket session, so buying credits landed on a page saying there was nothing to pay for. | The endpoint takes `purpose`; a customer may legitimately have one session of each |

### Known limitations

- **Shipping is zero.** Rates, options and delivery promises are Phase 17. The total is
  stated as the goods total rather than invented, because a figure that changes after the
  customer agreed to it is the failure the session exists to prevent.
- **No orders yet.** A paid basket consumes its hold and is marked `ordered`; building the
  master order and its per-seller orders is Phase 15, and hooks into
  `CheckoutFulfiller::settleCart()` — deliberately one call rather than logic spread
  through the payment code.
- **One gateway.** The registry, the contract and the webhook inbox are provider-agnostic
  and the only adapter is the test one. iyzico is Phase 12; the marketplace settlement
  contract is declared and unimplemented until then.
- **Refunds are service-level only.** The processor can refund and the record is correct,
  but there is no operator screen for it — that arrives with the admin work in Phase 18.

---

## Phase 14 — Bank transfer

**Date:** 2026-08-26
**Scope:** a payment method with no provider in it — the customer transfers money to our
own account and a person confirms it against a statement.

### Gate criteria (04_WEB_PHASE_PLAN.md: duplicate confirmation and amount mismatch tests)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **A reference is allocated and quoted** | Pest + Playwright | **PASS** — and it is the payment's external id, so the unique index guarantees no two payments share one |
| 2 | The reference avoids lookalike characters | Pest | **PASS** — no 0/O, no 1/I/L; it is copied by eye into a banking app |
| 3 | The reference is unique for all time | Pest + unique index | **PASS** — statements are reconciled months after a transfer stops being live |
| 4 | Reloading quotes the same reference | Pest | **PASS** — two references would leave the money matching neither |
| 5 | **Stock is held for the transfer window** | Pest + Playwright | **PASS** — two days, not fifteen minutes |
| 6 | The customer is told the window before choosing | Playwright | **PASS** — a method that quietly takes two days is a support ticket |
| 7 | No receiving account is a 503, not a crash | Pest | **PASS** — nobody did anything wrong |
| 8 | **Too little released nothing** | Pest + Playwright | **PASS** — the gate; a hundred kuruş short is still short |
| 9 | The shortfall is stated as a figure | Pest + Playwright | **PASS** — "eksik ödeme" alone leaves a customer guessing what to send |
| 10 | A shortfall can be made up against the same reference | Pest | **PASS** — `short_paid` is not terminal |
| 11 | **Too much releases the order and records the surplus** | Pest | **PASS** — captured at what was owed; the excess is a refund, not a larger sale |
| 12 | An arrival of nothing is refused | Pest | **PASS** |
| 13 | **A transfer is confirmed exactly once** | Pest + Playwright | **PASS** — row lock, state check, and a partial unique index behind both |
| 14 | A second confirmation does not consume stock twice | Pest + Playwright | **PASS** — one unit left the shelf, not two |
| 15 | A second confirmation does not credit a wallet twice | Pest | **PASS** — the same fulfilment path a card uses, called once |
| 16 | A refusal demands a reason | Pest (HTTP) | **PASS** — an unexplained financial refusal is indistinguishable from a mistake |
| 17 | A refusal is recorded with who and when | Pest | **PASS** |
| 18 | A refused transfer leaves the session payable | Pest | **PASS** — the customer pays another way rather than starting over |
| 19 | **An unpaid transfer expires and returns the stock** | Pest | **PASS** — by its own clock, not the checkout sweeper's |
| 20 | A transfer inside its window is left alone | Pest | **PASS** |
| 21 | Reading and settling are separate permissions | Pest (HTTP) | **PASS** — an analyst can answer "did it arrive" and cannot decide that it did |
| 22 | A customer cannot reach finance | Pest (HTTP) | **PASS** |
| 23 | **Somebody else's reference is a 404** | Pest + Playwright | **PASS** — the reference is short and typable, which is what makes it guessable |
| 24 | A receipt goes to the private disk under a random key | Pest | **PASS** — the path never appears in a response |
| 25 | A receipt is reached only by a short-lived signed link | Code + Pest | **PASS** — five minutes; the file shows somebody's bank balance |
| 26 | A non-receipt file is refused | Pest (HTTP) | **PASS** |
| 27 | Uploading a receipt does not confirm anything | Pest (HTTP) | **PASS** — a receipt is a picture, and pictures are easy to make |
| 28 | A receiving IBAN is checksum-validated | Pest (HTTP) | **PASS** — a mistyped one sends every customer's money elsewhere |
| 29 | The accounts are readable before signing in | Pest (HTTP) | **PASS** — a customer choosing a method should see the options first |
| 30 | The IBAN is grouped for copying | Pest (HTTP) | **PASS** — an unbroken run of 26 characters is how a digit gets dropped |

### Defects found and fixed in this phase

| Id | Severity | Defect | Fix |
|---|---|---|---|
| P14-D001 | P2 | An expiring transfer marked itself expired but left the stock held: the checkout session had been stretched to the transfer window, so the checkout sweeper would not touch it for another two days. | `BankTransferService::expireOverdue()` releases the holds and closes the session itself, rather than relying on a second clock agreeing |
| P14-D002 | P3 | Saving a receiving account with a bad IBAN produced a 500 from the value object rather than a 422 with a field error. | Validated with the same mod-97 closure rule the seller onboarding form uses |

### Known limitations

- **Reconciliation is manual.** Finance reads a statement and types the figure. Importing a
  statement file, or reading a bank API, is a later piece of work — the schema is ready for
  it (`value_date` exists precisely because statements are organised by it) but nothing
  parses a file yet.
- **An overpayment is recorded, not refunded.** The surplus is visible on the transfer and
  in the audit trail; actually sending it back is a manual transfer somebody makes, and the
  operator screen for refunds arrives with the admin work in Phase 18.
- **One currency in practice.** The schema carries a currency on both the account and the
  transfer, and the account lookup filters on it, but nothing else in the platform sells in
  anything but TRY yet.

---

## Phase 15 — Orders and seller orders

**Date:** 2026-08-26
**Scope:** what was bought, from whom, and how far along it is.

### Gate criteria (04_WEB_PHASE_PLAN.md: multi-seller E2E · E2E-06)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **One payment becomes one master order** | Pest + Playwright | **PASS** |
| 2 | **One seller order per seller in the basket** | Pest + Playwright | **PASS** — two sellers, two parcels, two statuses |
| 3 | Each seller's total is their own goods | Pest + Playwright | **PASS** — not a share of the basket |
| 4 | The customer gets one number, each seller their own | Pest + Playwright | **PASS** — derived from the master, so support can tell they are the same order |
| 5 | **A duplicate confirmation makes one order** | Pest + unique index | **PASS** — the fulfiller re-run produces nothing new |
| 6 | Each seller is told about their own part only | Pest | **PASS** — two notifications, neither mentioning the other seller |
| 7 | **The product name is frozen** | Pest | **PASS** — renaming the product afterwards does not change the order |
| 8 | **The price is frozen** | Pest | **PASS** |
| 9 | **The commission is snapshotted at order time** | Pest | **PASS** — renegotiating the rate afterwards does not change what was earned |
| 10 | The address is copied, not linked | Pest | **PASS** — editing the address book does not move last month's parcel |
| 11 | **The master status is derived from its parts** | Pest + Playwright | **PASS** |
| 12 | One parcel shipped reads as partially shipped | Pest + Playwright | **PASS** — the failure mode this exists to prevent |
| 13 | All parcels shipped reads as shipped | Pest | **PASS** |
| 14 | All parcels delivered reads as delivered | Pest | **PASS** |
| 15 | A cancelled part does not strand the order | Pest | **PASS** — excluded from "have they all shipped" |
| 16 | Every part cancelled cancels the order | Pest | **PASS** |
| 17 | **A shipped parcel cannot be cancelled** | Pest + Playwright | **PASS** — after the van it is a return, with different rights |
| 18 | A cancellation demands a reason | Pest + Playwright (HTTP) | **PASS** |
| 19 | The same status twice is a no-op | Pest | **PASS** — a double-clicked button is not an event |
| 20 | **Cancelling puts the stock back** | Pest + Playwright | **PASS** — the ledger and the warehouse stay in agreement |
| 21 | Every change is recorded with who and why | Pest | **PASS** |
| 22 | The history is append-only | Pest (raw `UPDATE`) | **PASS** — enforced by a trigger |
| 23 | The history starts at the order's creation | Pest | **PASS** — a record that omits the first event has a hole in it |
| 24 | **A seller sees only their own orders** | Pest + Playwright | **PASS** |
| 25 | A seller cannot open a competitor's order | Pest + Playwright | **PASS** — 404, not 403 |
| 26 | A seller gets the delivery address | Pest | **PASS** — a courier label needs it |
| 27 | A seller learns nothing about the rest of the basket | Pest | **PASS** |
| 28 | A customer cannot open somebody else's order | Pest | **PASS** |
| 29 | A customer cannot reach the seller endpoints | Pest | **PASS** |
| 30 | An illegal transition names both states | Pest (HTTP) | **PASS** — a seller on a stale screen learns what it is now |
| 31 | A credit purchase does not become an order | Pest | **PASS** — no seller, no parcel |

### Defects found and fixed in this phase

| Id | Severity | Defect | Fix |
|---|---|---|---|
| P15-D001 | P2 | Recomputing the master status crashed with a `TypeError`: `pluck('status')` applies the Eloquent enum cast, so the mapping was handed an enum where it expected a string. Every status change on a seller order would have failed. | `toBase()->pluck('status')` so the values come back as the strings the column holds |
| P15-D002 | P3 | An order's history began at its first *change*, so "when was this placed" had to be inferred from a timestamp on another table. | The factory writes the opening entry |

### Known limitations

- **No shipping details yet.** A seller marks a parcel shipped; there is no carrier, no
  tracking number and no delivery estimate. That is Phase 17, along with returns — which is
  also why `returned` exists as a status with nothing that can reach it yet.
- **No commission hierarchy.** The snapshot is taken correctly and at the right moment, but
  it is resolved from the seller's own rate falling back to the platform default. The full
  priority order from `06_SECURITY_PAYMENT_FINANCE_RULES.md` — order item, campaign,
  seller+category, seller, category, default — and the double-entry ledger behind it are
  Phase 16's.
- **No cancellation refund.** Cancelling returns the stock and records why; giving the
  customer their money back is a refund against the payment, which finance does by hand
  until the admin screens arrive in Phase 18.
- **No order documents.** An invoice compliant with Turkish e-Arşiv rules is a tax
  integration rather than a PDF, and belongs with that work. The order detail page is
  printable and carries everything a customer needs to see.

---

## Phase 16 — Commission, ledger and settlement

**Date:** 2026-08-26
**Scope:** where the money is, who it belongs to, and how it gets to them.

### Gate criteria (04_WEB_PHASE_PLAN.md: financial invariant suite)

| # | Criterion | Method | Result |
|---|---|---|---|
| 1 | **A sale posts a balanced journal** | Pest | **PASS** — debit equals credit and equals what the customer paid |
| 2 | **The whole ledger balances** | Pest | **PASS** — asserted after every scenario in the suite |
| 3 | **Cash is mostly a liability, not revenue** | Pest | **PASS** — seller payables plus commission equal the cash held |
| 4 | A duplicate confirmation posts one journal | Pest | **PASS** — idempotency key derived from the event |
| 5 | **An unbalanced entry is refused in the service** | Pest | **PASS** — with both figures named |
| 6 | **An unbalanced entry is refused by the database** | Pest (raw insert) | **PASS** — deferred constraint trigger, forced with `SET CONSTRAINTS ALL IMMEDIATE` |
| 7 | **The ledger cannot be edited** | Pest (raw `UPDATE`/`DELETE`) | **PASS** — triggers on both tables |
| 8 | **A mistake is undone by a reversing entry** | Pest | **PASS** — nets to zero, both entries still readable |
| 9 | Reversing twice reverses once | Pest | **PASS** — otherwise the original is re-posted |
| 10 | **The commission hierarchy prefers the most specific rule** | Pest | **PASS** — platform → category → seller → seller+category → campaign |
| 11 | A finished campaign is ignored | Pest | **PASS** |
| 12 | The decision names the rule that produced it | Pest | **PASS** — "Eylül kampanyası", not 500 |
| 13 | **The snapshot survives a later rate change** | Pest | **PASS** — rung one of the hierarchy |
| 14 | A seller's negotiated column still counts | Pest (suite) | **PASS** — treated as the `seller` rung so the existing screen keeps working |
| 15 | **An undelivered order cannot be settled** | Pest | **PASS** |
| 16 | **A delivery inside the hold cannot be settled** | Pest + Playwright | **PASS** — the return window |
| 17 | A delivery past the hold can | Pest | **PASS** |
| 18 | A suspended seller cannot be paid | Pest | **PASS** |
| 19 | A draft posts nothing to the ledger | Pest | **PASS** — which is what makes re-running the builder safe |
| 20 | **Approving moves money into a clearing account** | Pest | **PASS** |
| 21 | **Paying moves it out of the bank** | Pest | **PASS** — and the books still balance |
| 22 | **A settlement cannot be approved twice** | Pest + Pest (HTTP) | **PASS** — 409 naming the current status |
| 23 | A settlement cannot be paid before approval | Pest + Pest (HTTP) | **PASS** |
| 24 | A payout reference is required | Pest (HTTP) | **PASS** — a payout nobody can look up is a seller asking where their money is |
| 25 | **The same order is never in two settlements** | Pest + unique index | **PASS** — a bank transfer cannot be recalled |
| 26 | Cancelling returns orders to the pool | Pest | **PASS** — the approval is unwound by a reversing entry |
| 27 | **A cancelled seller order unwinds only that seller's share** | Pest | **PASS** — the other sellers' parcels are still on their way |
| 28 | The seller balance projection matches the journal | Pest | **PASS** — rebuilt from the journal, never incremented |
| 29 | A seller sees four figures and a sentence per order | Pest (HTTP) + Playwright | **PASS** |
| 30 | One seller cannot see another's earnings | Pest (HTTP) | **PASS** |
| 31 | **Reading the books and moving money are separate grants** | Pest (HTTP) | **PASS** — an analyst reads, an operator settles |
| 32 | A seller cannot reach platform finance | Pest (HTTP) + Playwright | **PASS** |
| 33 | A contradictory commission rule is refused | Pest (HTTP) + CHECK | **PASS** — a `seller` rule with a category cannot be written |

### Defects found and fixed in this phase

| Id | Severity | Defect | Fix |
|---|---|---|---|
| P16-D001 | **P1** | The commission hierarchy picked the wrong rule. Several sort keys passed to `Collection::sortBy()` as closures are not the multi-key sort they look like, so a category rate beat a negotiated seller rate — every affected order would have been snapshotted with the wrong commission and no way to tell afterwards. | One composed sort key, built as a string so specificity, priority and recency order in a single ascending pass |
| P16-D002 | P2 | A self-referencing foreign key inside the same `CREATE TABLE` — PostgreSQL refuses it because the primary key does not exist yet. | Added after the table with an explicit `ALTER TABLE` |
| P16-D003 | P3 | A test asserted against "the newest order" where two were placed in the same test and `placed_at` can tie — it would have passed for the wrong reason. | Selected by exclusion instead |

### Known limitations

- **The settlement period is derived, not calendared.** A run covers the deliveries it
  actually contains rather than a fixed fortnight. Fixed cycles per seller are a
  commercial feature, not a correctness one, and the schema carries the dates for it.
- **No adjustments yet.** `settlements.adjustment_minor` exists and is always zero:
  manual corrections, penalties and goodwill credits are operator tooling that belongs
  with Phase 18's admin work.
- **A cancellation records what the customer is owed but does not refund it.** The journal
  moves the money to `LIABILITY:CUSTOMER_REFUND`; actually sending it back is a refund
  against the payment, and the refund state machine is Phase 17.
- **The approve-then-pay two-step is proved in the backend suite, not in the browser.** A
  settlement needs a delivery past the hold, and the only ways to produce one in an E2E run
  are to wait a fortnight or to open a test-only endpoint that ages deliveries. An endpoint
  that moves money closer to leaving is not worth the coverage.
