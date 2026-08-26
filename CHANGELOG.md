# Changelog

All notable changes to RefConcept are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added — Phase 0 (Repository Bootstrap & Design Foundation)

- Monorepo layout (`apps/`, `packages/`, `infra/`, `docs/`, `scripts/`) with npm workspaces.
- Docker development stack: PostgreSQL 16 + pgvector 0.8.6, Redis 7, MinIO (S3-compatible),
  Mailpit, PHP 8.3-FPM API image, nginx, queue worker and scheduler.
  Host ports shifted (`58000`, `55432`, `56379`, `59000`, `58025`) so an existing XAMPP
  installation is never disturbed.
- PostgreSQL bootstrap creating both `refconcept` and `refconcept_test` with `vector`,
  `pg_trgm` and `citext` installed in each.
- Laravel 13 API with the `Administration` domain as the reference module layout, and a
  `GET /api/health` readiness endpoint probing database, pgvector, cache, queue, storage
  and migrations (503 when a critical dependency is down).
- Pest test suite running against **real PostgreSQL** (never SQLite), PHPStan/Larastan
  level 6, and Laravel Pint with `declare_strict_types` and strict comparisons.
- `@refconcept/ui` design system package: typed tokens, `--rc-*` custom properties,
  Tailwind v4 `@theme` bridge that deletes Tailwind's default palette, base layer and
  cross-app components.
- Three Nuxt 4 applications (storefront, seller portal, super admin) sharing that design
  system, each verifying API connectivity on boot.
- Design token guard (`scripts/check-design-tokens.mjs`) failing CI on any colour outside
  the approved palette.
- GitHub Actions CI: backend tests + static analysis + style, frontend typecheck/lint/build,
  container image build, dependency audit.
- Developer command wrappers (`scripts/rc.ps1`, `Makefile`) and idempotent bootstrap scripts.
- ADR-0002 (local development topology), ADR-0003 (design system delivery).

### Fixed — Phase 0

- **Test suite ran against the development database.** `env_file` injection made the
  container's real environment override PHPUnit's `<env>` values, so `RefreshDatabase`
  would have truncated local development data while reporting a green run. The injection
  was removed and `Tests\TestCase::setUp()` now refuses any connection whose database name
  does not end in `_test`.
- Composer could not install through the Windows bind mount (300s unzip timeout);
  dependencies moved to a named volume.
- Laravel boot cost 22.8s per command over the Windows bind mount. Application source moved
  to a named volume with explicit host→container sync: boot 22.8s → 4.3s, test suite
  104s → 32s.

### Added — Phase 1 (Identity / RBAC / Organizations)

- Identity schema on UUIDv7 primary keys with `citext` e-mail, UTC timestamps, database
  CHECK constraints on every status column and partial unique indexes guaranteeing one
  default shipping and one default billing address per customer.
- Authentication API: registration with KVKK consent capture, login issuing Sanctum
  tokens, session records per device, logout and logout-everywhere, `GET /auth/me`.
- E-mail verification and password reset built on single-use SHA-256 hashed tokens.
  Redeeming a reset revokes every live token and closes every session.
- RBAC: 12 seeded permissions and 5 system roles, platform- and organization-scoped
  grants with expiry, and an `AccessControl` service that answers membership and
  permission separately.
- Organizations as the tenant boundary, with an `OrganizationPolicy` that decides
  seller-to-seller isolation in one place.
- Append-only `audit_logs`, immutability enforced by a PostgreSQL trigger, written by an
  `AuditLogger` that redacts passwords, tokens, card data and IBANs.
- Customer profile and address book with ownership policies and a verified-e-mail gate.
- Rate limiters for login, registration, password reset and verification resend, keyed by
  e-mail **and** IP so one attacker cannot lock out a victim by failing their login.
- 78 backend tests / 235 assertions, including 15 tenant isolation cases.

### Fixed — Phase 1

- **Every authenticated route returned 500.** A stale `bootstrap/cache/packages.php` left
  on the host was pushed into the container on each sync, hiding Sanctum and removing its
  auth guard. The sync scripts now clear the compiled cache on both push and pull.
- `config/auth.php` pointed at the framework's `App\Models\User` and defined no Sanctum
  guard; rewritten for the Identity domain model.
- Model factories could not resolve for domain-namespaced models
  (`Factory::guessFactoryNamesUsing`).
- `email:rfc,dns` performed a live MX lookup on every registration, breaking the test
  suite and blocking `*.local` development accounts; extracted to configuration.

### Added — Phase 2 (Seller Onboarding)

- Seller applications kept separate from approved sellers, so a rejection stays on
  record with its reason and an approval preserves what was reviewed. One open
  application per applicant, enforced by a partial unique index.
- `Iban` value object: constructs only from a value passing the ISO 13616 mod-97
  check, stored encrypted with a masked display value and a keyed fingerprint for
  duplicate detection.
- Onboarding checklist derived from the data rather than stored as flags, driving both
  the portal's progress bar and the server-side submission guard from one
  implementation.
- Required documents follow the taxpayer type, so a sole proprietor is not asked for a
  trade registry gazette.
- Versioned agreements with immutable, checksummed acceptances — enforced by a
  database trigger as well as in PHP.
- `ApplicationWorkflow` as the single place status changes. Approval creates the
  organization, seller, membership and role grant in one transaction.
- Suspension, reactivation and commission changes demand a reason and are recorded in
  both `seller_status_history` and the audit log.
- Onboarding documents on the private disk under random keys, served by short-lived
  signed URL after a policy check.
- Seller portal: sign-in, dashboard and the full onboarding wizard.
- Super admin: review queue, application review with document decisions, and seller
  administration.
- `refconcept:grant-role` console command for bootstrapping the first operator; there
  is deliberately no HTTP endpoint that grants platform roles.
- Shared API and auth composables moved into `@refconcept/ui` so all three apps talk
  to the backend identically.

### Fixed — Phase 2

- **Every primary call to action on the storefront navigated nowhere.** `RcButton`
  rendered `<component is="NuxtLink">` by string name, which only resolves against
  locally registered components — and the component lives in a shared package. Found
  by an end-to-end click; screenshots looked perfect.
- Database status defaults were not reflected on freshly created models.
- `Artisan::starting()` does not exist in Laravel 13 and broke every artisan command;
  commands are now registered through `withCommands()`.

### Added — Phase 3 (Catalog / PIM and the product lifecycle)

- Catalogue taxonomy on UUIDv7 keys: categories with materialised paths and room
  types, brands, styles, colours, materials, and attributes whose "required" flag
  lives on the category pivot — so the seller's form and the submission gate read the
  same source and cannot disagree about what is mandatory.
- `CatalogTaxonomySeeder` — reference data, not demo data, so it runs in production
  too: 40 categories, 8 attributes, 19 colours, 18 materials, 8 styles and 6 brands,
  idempotent by natural key.
- Products separated from offers: a `Product` is what the thing *is*, a `ProductSku`
  is one seller's commercial terms for it. Two sellers can list the same sofa without
  the matching engine seeing two different sofas.
- **Money as integer minor units from the form field to the database column.** Prices
  cross the wire as integers, tax and discounts are basis points, and the single
  conversion between what a seller types and what is stored lives in one place.
- Product dimensions in millimetres, with width and depth required: they are what
  decide whether the piece fits the wall the design engine wants to put it against.
- `ProductCompleteness` — the readiness of a listing is derived from its data, never
  from a stored flag that a partial save could set.
- `ProductModerationWorkflow` — the only place a moderation status changes. Every
  transition is checked against the state machine, recorded in history, and audited,
  and every decision carries a mandatory reason enforced by both the application and a
  database constraint.
- Product imagery on its own anonymously-readable bucket: random object keys, the file
  extension derived from the decoded image type rather than the uploaded filename, and
  a partial unique index guaranteeing exactly one cover image per product.
- Public catalogue: category-branch, room, style, budget and trigram search filters,
  four sort orders, and a scalar subquery for price sorting so pagination does not lie
  about how many products exist. Every query runs through one `publiclyVisible()`
  scope; nothing builds its own visibility condition.
- Seller portal: product list, creation, and a full editor with a live completeness
  checklist, gallery management with cover selection and reordering, and per-offer
  pricing, stock and dimensions.
- Super admin: the moderation queue, and a review screen that shows the reviewer what
  a customer would see, with approve, reject (naming the fields at fault) and recall.
- Storefront: the catalogue with URL-backed filters, and a product page organised
  around choosing between sellers' offers.
- `DemoCatalogSeeder` — twelve published listings with real photography, uploaded to
  the public bucket exactly as a seller's upload would be.
- `RcStatusPill` and `useMoney` in the shared package, so lifecycle colours and money
  formatting cannot drift between the three apps.

### Fixed — Phase 3

- **A seller could not upload a product image at all.** There was no endpoint and no
  screen, so the completeness gate demanded a photograph that could not be supplied
  and no listing could ever be submitted.
- **An approved listing was approved, complete, and invisible.** Approval left the
  product's status and its offers at `draft`, so it satisfied moderation and still
  failed the visibility scope. The unit tests passed because they set the status by
  hand; only the end-to-end run went through the door a seller actually uses.
- **An approved listing could never be edited again** — no typo fix, no better
  photograph, ever. Approved listings are now editable, and any edit sends the listing
  back to the review queue and clears `published_at`, so what a customer sees is
  always something a reviewer looked at.
- The seller's product list returned 500 whenever a listing had an offer: the "from"
  price asks each offer whether its seller may trade, and the relation was not eager
  loaded. Lazy loading is disabled outside production, which turned an N+1 into an
  error — the right trade, and the reason this surfaced at all.
- `attributes` and `dimensions` were passed to `fill()` although neither is a column,
  so any request carrying them raised a mass-assignment error.
- The attribute *label* was serialised where the value belonged, so the seller's form
  matched none of its own options and silently cleared every attribute on save.
- Categories were ordered by position across all depths, which interleaved branches in
  the category select; they are now ordered by the materialised path.
- Demo seller accounts had an organization and a role grant but no trading account, so
  a demo seller reached the product form and was refused at the last step for a reason
  nothing on screen explained.

### Added — Phase 4 (Import, pricing, inventory and the partner API)

- **Bulk product import in three steps.** The file is parsed once into `import_rows`;
  validation reads those rows and writes nothing to the catalogue; commit applies the
  ones that passed, each in its own transaction. A seller sees how many products will
  be created, how many updated, and exactly which lines are wrong and why — before
  anything happens, because there is no undo for a catalogue.
- A streaming CSV and XLSX reader built for the files sellers actually have: the
  semicolon delimiter Turkish Excel writes (detected by column count, not guessed),
  the byte-order mark it prefixes, the Windows-1254 encoding older exports use, and
  comma decimals. Every line is stored verbatim alongside its parsed form, so "why did
  line 251 come out wrong" is answerable months later without the original file.
- Column mapping guessed from Turkish or English headers, accent-insensitively, and
  always shown to the seller for confirmation. Two columns claiming the same field
  leaves both unmapped rather than picking one — a wrong guess nobody notices writes
  wrong data into a live catalogue.
- An import template generated from the field catalogue rather than committed as a
  static file, so it cannot drift from the columns the importer understands.
- **Price lists with time windows**, so a campaign never overwrites the everyday
  price. Ending a campaign restores yesterday's prices because nothing overwrote them.
- **Append-only price history**, enforced by a database trigger, recording what
  changed, by how much in basis points, who changed it and *where it came from* — a
  40% drop caused by a misplaced decimal in a spreadsheet is otherwise indistinguishable
  from a deliberate campaign.
- **A stock ledger.** `stock_movements` is the record; `stock_items` is a snapshot of
  it written inside the same locked transaction. Every write takes a row lock and
  decides from what it reads under that lock, and CHECK constraints refuse a negative
  or over-reserved balance even for a caller that forgets to.
- Reservations with expiry: idempotent per reference so a retried checkout cannot take
  the stock twice, all-or-nothing across a multi-line basket, and released
  automatically — on the next reservation of that row, and by a five-minute sweep for
  everything else.
- **Scoped machine credentials** for a seller's own systems. Deliberately not Sanctum
  tokens: a partner credential belongs to a system rather than a person, carries its
  own scopes, is rate-limited per credential, and is revocable without logging anybody
  out. The secret is hashed and returned exactly once.
- A partner API addressed by the seller's own SKU codes rather than by RefConcept ids,
  reporting per-line results so one discontinued product does not fail a 4,000-row
  nightly sync.
- Seller portal: bulk import with the mapping and preview flow, a bulk price editor
  with per-SKU history, a stock screen separating on-hand from reserved from sellable,
  and integration credentials with a request log.

### Fixed — Phase 4

- A newly imported SKU had no price history at all: the row was created already
  priced, so the price book correctly saw no change and wrote nothing. The origin of a
  product's very first price — the one most worth being able to explain — was the one
  thing nobody could look up.
- The demo catalogue set stock quantities on SKUs with no ledger rows behind them, so
  a demo product page claimed six in stock while the stock screen was empty. Opening
  stock is now booked as a receipt through the ledger.

### Added — Phase 16 (Commission, ledger and settlement)

- **A double-entry journal, append-only.** Every financial event is a set of lines that sum
  to zero, and neither the entries nor the lines can be updated or deleted — a mistake is
  corrected by a reversing entry so both stay visible. That is the difference between a
  ledger and a table of numbers, and it is enforced by database triggers rather than by
  convention.
- **Balance checked twice.** In the service, with a message naming both figures, and again
  by a **deferred constraint trigger** that runs at commit. The deferral is the point: an
  entry is built line by line and can only be judged as a whole.
- **A marketplace's cash treated as what it is.** A sale debits cash and credits each
  seller's payable plus commission revenue. Posting the customer's payment as income and
  the payouts as expenses would balance perfectly and describe a different business — one
  that looks enormously profitable right until it pays its sellers.
- **The commission hierarchy from the finance rules**, six rungs deep: the order item's own
  snapshot, then a campaign, seller+category, seller, category and the platform default. It
  is resolved once, at order time, and copied onto the line — re-resolving later would let a
  rate change rewrite what a seller earned last quarter. The decision carries the rule that
  produced it, because "why is my commission 14%" is the question sellers ask most.
- **Settlement eligibility with reasons.** Payment captured, goods delivered, hold period
  passed, nothing open against it, seller still trading, not already settled. Each order a
  seller is waiting on carries a sentence — "12.09.2026 tarihinde hakedişe girer" — rather
  than a status code, because a date is something a seller can plan around.
- **A three-step payout.** Building is arithmetic and can be re-run and posts nothing;
  approving commits the money into a clearing account so it cannot be counted twice or
  swept into a second run; paying is a person recording that a transfer left, with the
  bank's own reference. Collapsing them would turn a mistake in the arithmetic into a bank
  transfer, and a nightly job that approved its own payouts would pay a suspended seller at
  three on a Sunday morning.
- **The same order can never be in two settlements**, enforced by a unique index rather than
  by care — a bank transfer is not something anybody can recall.
- **Seller balances as a projection**, rebuilt from the journal rather than incremented. If
  it ever disagrees, the journal is right and this is rebuilt; incrementing would mean every
  write has to be perfect forever.
- **An earnings page in the seller portal** showing four figures rather than one — ready,
  pending, in payout, paid — because the money really is in four states, and a single
  "bakiye" is how a seller reads a number they cannot yet have.
- **A finance screen in the admin panel** that says whether the books balance before it says
  anything else, lists the account balances and the journal, and keeps approving and paying
  as two separate buttons.

### Added — Phase 15 (Orders and seller orders)

- **One order for the customer, one per seller inside it.** A marketplace order is two
  things at once: the customer paid once for a basket and will ask about it by one number,
  while each seller received a separate instruction with their own parcel, their own status
  and their own money. Modelling only the first leaves every seller screen filtering a
  shared table by hand; modelling only the second leaves a customer with three orders they
  never placed.
- **Every line is a snapshot.** The product name, the SKU code, the price, the tax rate and
  the commission are copied at the moment of the order. A product renamed next month must
  not change what an invoice from last month says it was, and a seller who renegotiates
  their rate must not retroactively change what they earned. An order is a record of an
  event, not a view over the current catalogue.
- **A status machine per seller order, and a master status derived from them.** Nobody sets
  the customer's status by hand — it is computed after every change, because a summary that
  can be written independently of what it summarises will eventually disagree with it.
  `partially_shipped` exists because telling a customer their order has shipped while two
  parcels are still on shelves is technically true and practically a lie.
- **A seller cannot cancel what has already left.** After the van it is a return, with a
  different set of rights. Cancelling before that puts the stock back on the shelf, because
  the stock left when the payment was captured and a warehouse that disagrees with the
  ledger only reveals it weeks later as a sale nobody can fulfil.
- **One payment makes one order**, guaranteed by a unique index on the checkout session
  rather than by the caller being careful — the same defence that protects the credit load
  beside it.
- **An append-only status history** with who changed it, when, in what role and why. "When
  did this become shipped, and who said so" is the question every dispute starts with, and
  a table that can be edited cannot answer it.
- **Order numbers people can read out.** `RC-2026-001234` for the customer and
  `RC-2026-001234-2` for the second seller in it, so a seller and a customer on the phone
  are obviously talking about the same order. Allocated from a database sequence, because a
  count is a race and a random string is unreadable.
- **Orders screens** in the storefront — a list and a detail grouped by seller — and a
  working queue in the seller portal that opens on what still needs packing, shows what the
  seller will actually be paid next to what the customer paid, and takes its available
  moves from the server rather than from a second copy of the rules in a Vue file.
- **Sellers are told when they have something to pack**, and customers when one of their
  parcels leaves — by name, because "kargoya verildi" without one raises more questions
  than it answers on a three-seller order.

### Added — Phase 14 (Havale / EFT)

- **A payment method with no provider in it.** The customer transfers money to one of the
  platform's own accounts and a person in finance confirms it against a statement. It goes
  through the same gateway contract, the same state machine and the same fulfilment path as
  a card payment, so nothing downstream has to know how the money arrived.
- **A reference built to be typed.** It is the only thing tying a line on a bank statement
  to an order, so it is unique for all time rather than merely among live transfers, and it
  is drawn from an alphabet with no 0/O and no 1/I/L — a character pair that is identical in
  one bank's font is a payment nobody can match.
- **Short and over payments as named states.** People transfer the wrong figure constantly:
  a typo, an intermediary bank's fee taken in transit, two orders paid in one go. A boolean
  "paid?" forces an operator to decide privately whether 4.997,50₺ is close enough and
  leaves no trace of the decision. A shortfall releases nothing and states the figure still
  owed; an overpayment releases the order and records a surplus somebody owes back.
- **Stock held for the transfer window, not the card window.** Two days rather than fifteen
  minutes, because a customer told their goods are reserved and then losing them overnight
  has been lied to. It is a real cost borne against a payment that may never arrive, which
  is why the window is configured and why an unpaid transfer is expired promptly — and why
  expiring one returns its own stock rather than waiting for a second timer to agree.
- **Confirmation happens once**, enforced three ways: a row lock, a state check that refuses
  the second operator with a sentence rather than a blank error, and a partial unique index
  behind both.
- **Reading a payment and settling one are separate grants.** Answering "did it arrive" is a
  support job; deciding that it did releases goods and cannot be undone. An analyst gets the
  first and not the second.
- **Receipts on the private disk**, under random keys, reachable only through a five-minute
  signed link issued after a permission check — the same tier as seller onboarding
  documents, because a bank's PDF carries an account number and a balance.
- **A finance screen that does the arithmetic in front of the operator.** The received
  figure has to be typed rather than defaulted, and the difference from what was expected is
  shown before the confirm button does anything — because a number already in the box is a
  number that gets accepted without being read.
- **A transfer page in the storefront** with the reference above the account details,
  copyable in one tap, and the instruction to include it repeated where somebody skimming
  will still see it.

### Added — Phase 11 (Checkout and payment core)

- **A checkout session that freezes what is being paid for.** Between pressing "pay" and
  the bank answering there is a redirect, a 3DS page and often several minutes — and in
  those minutes a seller can reprice and an address book can be edited. The session copies
  the totals and the address text in and stops asking, so the amount charged is the amount
  agreed and the parcel goes where it was promised.
- **A payment state machine with declared transitions.** Providers deliver news out of
  order: a capture can arrive before the browser has come back from 3DS, a failure can
  follow a success because an older retry was queued behind it. A transition that is not
  listed is not applied — a late "failed" against a captured payment is dropped, because
  the alternative is a record saying we were not paid while the money sits in the account.
- **A webhook inbox: received first, understood later, never twice.** The endpoint writes
  a row and answers 200; a queued job works out what it meant. Doing the domain work inline
  is how a slow database turns into a provider retry, then a second delivery, then a
  customer credited twice. Duplicates are answered 200 for the same reason — a provider
  told a duplicate failed will resend it forever.
- **Two duplicate defences.** The inbox dedupes on the provider's own event id *and* on a
  fingerprint of the raw body, both unique indexes so a simultaneous double delivery is
  settled by PostgreSQL rather than by a check-then-insert both copies pass. And because a
  provider may send two genuinely *different* events carrying the same news, the state
  machine catches what no fingerprint can.
- **`Idempotency-Key` on the one route where a duplicate costs money.** A browser on a bad
  connection retries. A mobile app retries on timeout. Both get the first answer back, byte
  for byte. The same key with a different body is refused rather than answered with
  somebody else's result, and a failed answer is never stored — freezing a transient error
  into a permanent one for that key would be worse than the error.
- **An append-only payment record**, enforced by a PostgreSQL trigger rather than by an
  Eloquent guard a raw query would walk past. Every call to a provider and its outcome,
  including the ones we were told and deliberately ignored. When a customer says they were
  charged twice, this table is the answer, and it is only an answer if nothing can quietly
  edit it.
- **A provider contract with five methods and nothing else**, and a marketplace settlement
  capability kept separate because most providers do not have one. An adapter translates
  vocabulary; it does not retry, does not write to the database, and does not decide what a
  successful payment means. Adding iyzico cannot introduce a second set of rules about when
  a payment counts as paid.
- **A test provider that behaves like a real one** — immediate capture, 3DS, a decline, a
  timeout, a refund, duplicate webhooks — with no network call, chosen by card token rather
  than by amount. It is what lets the payment tests be part of the ordinary suite instead of
  something somebody remembers to run against a sandbox once a release.
- **Card data never enters the codebase.** No PAN, no CVV, no expiry: the customer types it
  on the provider's own page and we receive a token or a redirect. Provider responses are
  redacted before they are stored, belt and braces.
- **A payment page and a return page** in the storefront, a working "Satın al" on the credit
  packages where a placeholder used to promise one, and a checkout that gives the stock back
  the moment somebody changes their mind.

### Fixed — Phase 11

- **A basket emptied by its own stock hold.** A customer taking the last of the stock into
  checkout and then re-reading their basket was told the thing they were buying was sold
  out, and the lines were removed. Revalidation now counts a cart's own reservations as
  available to it, and "withdrawn" is separated from "none left" so the ledger stays the
  authority on quantity.
- **A sold-out listing that stayed on sale.** `product_skus.stock_quantity` — what the
  catalogue's list query reads — was written only by the seller's own stock endpoint, so
  buying the last unit left the listing advertising stock until a seller happened to open
  the stock page. The inventory ledger now keeps the projection in step on every movement.

### Added — Phase 10 (Search, favourites and the basket)

- **A multi-seller basket.** Lines are grouped by who is selling them, because that is what
  a marketplace basket is: several parcels from several shops, arriving on different days.
  The seller is recorded on the line rather than looked up through the offer, so a basket
  keeps saying which shop something came from even after the listing is withdrawn.
- **Stock is not held while a basket sits there.** Holding it would mean a browser tab left
  open for a week keeps a sofa off the market, and a marketplace's job is to sell the sofa.
  The hold is taken at checkout, for fifteen minutes, by the ledger built in Phase 4 — all
  of a basket or none of it, with rows locked in a fixed order so two baskets queue instead
  of deadlocking. Backing out releases immediately rather than leaving a sofa unbuyable
  while somebody else is told "sold out".
- **A price is snapshotted when a line is added, and never silently changed.** Revalidation
  reports what moved: a rise is shown with both figures and has to be accepted, a fall
  blocks nothing, an item that sold out is removed and said so, and one short of stock is
  reduced rather than dropped. Charging a customer more than they were shown is the failure
  this whole mechanism exists to prevent, and finding out at payment is the worst moment.
- **Tax is counted as part of the price, not on top.** Turkish prices are quoted inclusive
  of KDV: 20.000₺ at 20% contains 3.333,33₺ of tax. The other way round overcharges every
  customer by a fifth.
- **Hybrid search**: a trigram match on the name for the misspellings a search box actually
  receives, full-text over the description, and a vector for meaning — fused by rank rather
  than by score, because a similarity, a `ts_rank` and a cosine distance are numbers on
  unrelated scales and adding them is arithmetic without meaning.
- **The vector ranks but does not decide.** Measured against the live embedding model, pure
  nonsense sits about 0.35 from its nearest product and a real keyword match about 0.30 —
  not a margin to build a search box on. So a query with no lexical footing returns nothing
  rather than answering gibberish with a page of sofas.
- **Facets** for category, style and price band, counted before pagination and excluding
  their own filter — the only way a count tells somebody what is behind a filter they have
  not clicked yet. Empty bands are not offered at all.
- **Favourites**, per product rather than per offer: favouriting a sofa means the sofa, and
  a favourite that broke when one seller went out of stock would be a promise the feature
  never made. A withdrawn product leaves the list but keeps its row, so re-listing brings
  it back.
- **A cart page and a favourites page** in the storefront, and a working basket button on
  the product page where a placeholder used to say the feature was coming.

### Added — Phase 9 (Product matching)

- **A design now comes with a shopping list.** Every placement in the plan — "a sofa up to
  2200mm against the south wall" — becomes a shortlist of products that are in stock, fit,
  cost less than the budget allows, and look like the design.
- **Narrow first, then rank.** Category, stock, budget and width are applied in SQL before
  anything is scored, because a model asked to respect "no wider than 2200mm" will sometimes
  and a `WHERE` clause always does. What is left for the vector is the part that is
  genuinely a matter of resemblance.
- **Semantic search over the catalogue**, using pgvector with an HNSW cosine index. A
  customer asking for "warm minimalist oak" finds a sofa a seller described as "İskandinav
  meşe iskeletli" without either phrase containing the other — which full-text search cannot
  do, and which a synonym list large enough to fake would need maintaining forever.
- **A category that matches nothing returns nothing.** Silently dropping the filter would
  let the search fall back to the nearest products in the whole catalogue, which is how a
  plan asking for a chandelier ends up recommending a wardrobe with nothing looking wrong.
- **The rerank is optional in the strongest sense.** A model reorders the shortlist — only
  the shortlist, because a rerank over four hundred candidates is a bill — and if the call
  fails, the list built from similarity is returned unchanged. Its opinion is blended 60/40
  with the similarity rather than replacing it, so a model with a favourite cannot bury a
  genuinely closer match.
- **Prices are snapshots.** A customer who returns next week sees the list they were shown,
  with today's price beside it when the two differ. Hiding the change would be the wrong kind
  of tidy: the difference is the most useful thing the row can tell them.
- **Feedback, because everything else is the system marking its own homework.** Six verdicts,
  each naming the part of the pipeline it blames — wrong size is a filter bug, wrong style a
  modelling problem — so a week of clicks is readable. Every verdict is kept rather than the
  latest overwriting the last, and the one thing it changes automatically is that a rejected
  product is not suggested again for that spot.
- **Catalogue embedding is hashed and scheduled.** `refconcept:embed-catalogue` runs nightly
  and is safe to repeat: a product whose text has not changed costs nothing. The text
  embedded is assembled from what describes the product, in a fixed order, with the seller's
  name and delivery terms deliberately left out — two sofas from the same shop must not be
  similar *because* of the shop.
- **Matching cannot fail a design.** It runs as the last step of the generation pipeline and
  is unable to fail the version: a render the customer paid for is not lost because the
  catalogue happened to have no sofas in their budget.

### Added — Phase 8 (AI room analysis and design generation)

- **The design engine**: a room photograph becomes a finished render in three model calls —
  read the room, decide the layout, draw it — with the arithmetic in between that stops the
  layout asking for furniture the room cannot take.
- **A room is read once.** The analysis is cached against the photograph rather than the
  design, because a room does not change when somebody tries a second style. The second
  render of the same room reuses the first reading, and the quote drops the step so nobody
  is billed for a call that will not happen.
- **The plan is kept, and it is what the shopping list will be built from.** "A sofa up to
  2200mm against the south wall, in oak and cream" is a product search; the picture is not.
  A plan is immutable once written, by a database trigger, because it is the row that
  answers "why is there a sideboard there".
- **Placements are checked against the room.** A model will cheerfully put a 2600mm sofa
  against a 2200mm wall, and the render will look fine because an image is not to scale —
  the customer discovers the problem when a delivery van arrives. What does not fit is
  recorded with its reason rather than silently dropped, because a plan that quietly loses a
  piece of furniture produces an image and a shopping list that disagree.
- **One charge for a design, not one per step.** Credits are held when the version is
  created and settled when it finishes; the three model calls underneath run at zero
  customer cost. Every failure — a provider refusal, a render with no image, a dead worker —
  returns the whole hold.
- **Progress a customer can watch.** Each step writes an append-only event, the page polls,
  and the bar is driven by which stage the engine announced rather than by elapsed time. A
  bar fed by real durations jumps about as providers vary; one fed by stage boundaries moves
  predictably. Polling that keeps failing stops and says so, rather than leaving a spinner
  that has quietly given up.
- **Two render qualities** — a quick preview and the one you show people — chosen by the
  customer, priced from the AI routes, and stored on the version so a route repointed next
  month cannot rewrite what a version already in the tree was.
- **Provider images are staged on the private disk.** They cannot travel in a job row, and
  they must not sit on an anonymously-readable bucket: what passes through is a render of
  the inside of somebody's home. The pipeline copies the bytes to the design's own storage
  and discards the staged copy.
- **Turkish case-folding, in one place.** `mb_strtolower('İ')` produces an i followed by a
  combining dot rather than a plain i — so a spreadsheet column headed "İndirimli fiyat"
  folded to "i ndirimli fiyat", matched no alias, and the discount prices silently never
  arrived. `TurkishText` folds before lowercasing and is now used by every place that
  compares Turkish text.
- **Running out of credits is a 422 and a paused feature is a 503**, rendered once at the
  application boundary rather than caught in each controller — so a new caller cannot forget
  to, and the customer gets the two numbers they need.

### Added — Phase 7 (Credit economy)

- **An immutable credit ledger, and a wallet that is only ever a snapshot of it.** Every
  movement is an append-only row carrying the balance it produced; `credit_wallets` exists
  so a page load is one row rather than a sum over a year of history. Both are written
  inside one locked transaction, so they cannot drift — and when they ever did, the ledger
  wins. Append-only is enforced by a PostgreSQL trigger rather than by an Eloquent guard a
  raw query would walk straight past: this is the table a customer's complaint gets settled
  against, and a mistake is corrected with a compensating entry the way a mistake in any
  ledger is.
- **Credits expire in lots, soonest deadline first.** A balance cannot expire; a grant can.
  Fifty credits bought in March and ten from a promotion in June are one number in a wallet
  and two different deadlines, so consumption draws from batches in deadline order. Spending
  the long-lived credits first would silently destroy the ones with a date on them, and the
  customer would see a balance drop for no reason they could find.
- **A hold is not a charge.** An AI job reserves its cost before it is queued and either
  consumes or releases afterwards, so a render that failed because a provider timed out
  costs the customer nothing — that is our problem, not theirs. Three attempts against a
  flaky provider is still one charge: the retry is our decision and our cost. A customer who
  cannot afford a render is told so while they are still looking at the button rather than
  handed a job id and a failure four seconds later.
- **Every mutating path is idempotent on a caller-supplied reference.** A client retrying a
  request whose response it never saw is the normal case, not the exceptional one, and
  answering it with a second charge is the failure worth engineering against. A hold settles
  exactly once however many times a duplicate queue delivery asks it to.
- **The database refuses what the application should not have asked for.** A negative
  balance, held credits exceeding the balance, a rate window that ends before it begins, and
  — the one worth naming — a movement whose direction contradicts its type. A "consume" that
  adds credits is not a rounding error, it is free money, and it would balance perfectly in
  every report.
- **A hand correction demands a reason, in the schema.** It is the only movement that
  happens because a person decided it should, and "why do I have forty fewer credits than
  yesterday" needs an answer better than "somebody ran a script". Both the member of staff
  and their reason reach the customer's own statement, not only an internal log. A
  correction that would drive a balance below zero is refused rather than clamped.
- **Promotion codes written on the assumption that somebody is attacking them.** The
  promotion row is locked before its redemptions are counted, so two simultaneous claims
  cannot both find room under the limit. An unknown code, an ended campaign and an exhausted
  budget all return one identical refusal, because distinguishing them turns the endpoint
  into an oracle that enumerates live campaigns. Redemption is rate-limited per account and
  requires a verified e-mail — without which a promotion is a free-credit machine for
  anybody willing to type a different address each time. "Already redeemed" *is* said
  plainly, because the person asking has already proved they know the code.
- **An hourly sweep** that expires dated lots and returns holds nobody came back for.
  Expiry alone would be fine once a day; the holds set the cadence, because credits a
  customer cannot spend while their screen says they can becomes a support ticket within the
  hour. An abandoned hold is recorded as `expired` rather than `released`, because a release
  is a system that finished its job and an expiry is a request that vanished.
- **Credit tables restrict deletion rather than cascading.** A financial record outlives the
  account it belonged to, which is what tax retention requires anyway. Erasing an account
  means anonymising the person and keeping the money — an explicit, audited procedure rather
  than a side effect of a foreign key.
- **A customer's credits page**: available and total balance, what is about to expire shown
  *above* the statement rather than buried in it, a promotion code field, the packages on
  sale, and a statement with holds filtered out — a reserve followed by a consume is one
  event to the person who ran a render, and three lines for it is how a statement becomes
  something nobody checks.
- **A credits screen in the admin panel**: packages and campaigns with their redemption
  counts, budgets and windows, and a switch for each. Adjusting a balance lives behind a
  wallet lookup rather than on the list, because a correction should be something somebody
  arrives at after reading an account's history.

### Added — Phase 6 (AI gateway foundation)

- **One gateway between RefConcept and every model.** Which provider, which model, which
  prompt version, what timeout, how many retries, what a call may cost, how many a
  customer may run at once, and whether the feature runs at all are rows in
  `ai_task_routes`. Nothing in the application writes a model name in a string literal,
  so moving a task onto a cheaper model — or off the site entirely — is configuration
  rather than a deploy, and every change is audited.
- **All the policy in one place.** Adapters translate one call into one answer and
  classify what came back; retries, fallback, cost ceilings, recording and structured
  output validation belong to the gateway. An adapter that also retried would be a second
  home for the retry rule, and the second home is always the one that drifts.
- **Failures are values, not exceptions.** A timeout, a rate limit, a malformed answer and
  a safety refusal mean four different things, and the classification is what decides
  whether to try again, try somebody else, or stop. A safety refusal is not retried — the
  same provider will refuse identically — but it *does* warrant a fallback, because
  providers draw the line in different places.
- **Cost is checked before the call, not after.** An estimate that passes a ceiling and
  then overshoots it has protected nothing. Prices live in `ai_cost_rates` in **micros**
  (millionths of a currency unit, the one documented exception to minor units, because a
  thousand tokens can cost a fraction of a cent) with a validity window, so a job run in
  March keeps reporting March's price however often the rate has moved since.
- **Prompts are versioned and, once published, immutable** — enforced by a PostgreSQL
  trigger rather than by convention, because one UPDATE would silently rewrite the history
  of every job that ever ran against that wording. Improving a prompt means the next
  version, which leaves the old one readable beside the jobs that used it. Version numbers
  are assigned under a row lock, and a version can be previewed against sample input
  without calling anything.
- **Every attempt is recorded** — `ai_requests`, `ai_usage`, `ai_failures` — including the
  ones that failed, because a provider that read the input and then refused still charged
  for reading it. Credits are counted once per job, never per attempt: a customer must not
  pay three times because a provider was flaky.
- **A kill switch per task**, with a mandatory written reason that appears on the console
  rather than only in a log. It refuses at the dispatcher as well as at the gateway, so a
  paused feature does not quietly accumulate a queue of jobs that will all fail.
- **A customer's AI job is as private as the room it describes.** Its input holds the link
  to a photograph of their home and whatever they typed about how they live in it, so
  `AiJob` joins projects and rooms in the exclusion from the super-admin bypass. Platform
  staff get the operational view — task, model, timings, cost, failure kind, the rendered
  prompt — and never the payload. An image URL travels as an attachment and never as
  prompt text, because a URL in a prompt is a URL a model can repeat back into an answer
  somebody else reads.
- **Adapters for Google Generative AI and OpenAI**, each translating that provider's own
  vocabulary of failure into ours. Two traps are handled explicitly: Google reports a
  safety refusal as an ordinary `200` with a `finishReason`, and OpenAI reports both a
  genuine bad request and a content-policy refusal as a `400`. The Google key travels in a
  header rather than the query string, because query strings reach access logs.
- **A deterministic fake provider** so continuous integration exercises the whole AI path
  on every commit without spending a lira. Its answers derive from the call's fingerprint,
  and it can be scripted to produce any failure on demand — which is how the retry,
  fallback, cost-cap and kill-switch paths are provoked exactly rather than hoped about.
- **Idempotent dispatch and per-user concurrency limits.** A customer who taps "render"
  twice, or a client retrying a request whose response it never saw, gets the same job
  back rather than a second charge. The limit is per user per task, so one person queueing
  forty renders neither delays nor locks out anybody else.
- **The AI control room** in the admin panel: every task with its model, prompt version,
  credit cost, success rate, average latency and spend over a chosen window; the failure
  breakdown; provider keys shown as a four-character hint and never in full; and the pause
  and resume buttons. Tasks with no route are highlighted, because an unrouted task is a
  feature that fails the first time a customer touches it and is silent until then.
- **A seeder that ships all twelve tasks routed and prompted**, so the gateway works the
  moment the database exists. With no provider key on file it routes everything to the
  local simulator and says so, rather than shipping twelve features that fail on first use.

### Added — Phase 5 (Projects, rooms and design versions)

- **Projects**: a customer's home, or the part of it they are working on. Owner,
  status history, an optional budget in minor units, and an address reused from their
  own address book rather than duplicated.
- **Rooms** carrying their envelope and an honest `measurement_quality` — estimated,
  measured by hand, scanned, verified — because the difference changes what a design
  *means*: a sofa against a guessed wall is a suggestion, one against a measured wall
  is close to a promise. A database constraint refuses a room that claims to be
  measured while leaving the numbers empty.
- **Room constraints**: windows, doors, radiators, columns, placed against a wall at an
  offset. "There is a window" decides nothing; where it is and how wide it is decides
  whether a 220 cm sofa fits under it.
- **The strictest privacy tier in the system.** Room photographs go on the private disk
  under random object keys. No response ever contains a URL or a storage path — a link
  is a separate request that runs the ownership check and expires in five minutes — and
  the models have no `url()` method at all, because there is nowhere to point one. The
  filename is deliberately kept out of the audit log.
- **Platform staff excluded from the super-admin bypass** for customer projects. That
  bypass is right for operational tables and would have been silently wrong here; the
  exclusion is matched on model class rather than ability name, and both directions are
  asserted.
- **Designs as a tree, not a list.** Every version records the version it came from, so
  "make the sofa darker" branches rather than overwrites and the version somebody liked
  is always still there. Numbers are chosen under a row lock and never reused even after
  a failure, only a finished version may be branched from, and a finished version never
  changes.
- **The original is immutable, structurally**: AI renders live in `design_assets` and
  the customer's own photographs in `room_media`, with different writers, so there is no
  code path that could write one over the other.
- **Project sharing** for the ordinary case — a partner, an interior designer. Invited by
  e-mail because the person you want to show your living room to usually has no account
  yet; the invitation token is hashed, returned once, bound to the invited address,
  expires in two weeks and is burned on use. Revoking is recorded rather than deleted.
- Storefront: projects, rooms with a photograph gallery, measurements in centimetres,
  constraints, and the design version tree drawn as a tree.
- `RoomType` is now one vocabulary shared with the product catalogue, which is what
  makes matching possible: a bedroom design offers bedroom furniture because both sides
  agree what a bedroom is.

### Fixed — Phase 5

- **The super-admin authorization bypass would have let platform staff open any
  customer's project** and look at photographs of their home. Correct for operational
  tables, silently wrong for this one.
- *(Carried from Phase 2)* `DocumentStorage` fell back to a route name the router never
  registers, so a deployment without object storage would have returned 500 on every
  "view document". Every environment RefConcept is tested in can sign a URL, so nothing
  exercised it; a test now asserts all three download route names resolve.
- `DesignVersionRefused` declared a readonly `$code`, which PHP refuses to redeclare
  over `Exception::$code` — a fatal error at class load.
