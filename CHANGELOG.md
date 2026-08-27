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

### Fixed — after Phase 22 (currency and the AI image path)

- **Every money figure is now lira, including AI spend.** The AI console stored and printed
  the provider's own dollars while every other figure in the system was TRY — a spend total
  sitting next to an order total in a different unit with nothing saying so. Google publishes
  its price list in dollars, so the cost is now converted once, when the usage row is
  written, and what is stored is lira. **Relabelling was not an option**: writing ₺ over a
  dollar figure makes the number wrong by the whole exchange rate and wrong *silently* —
  nothing else in the system would ever disagree with it. The rate is configurable and an
  operator can update it from Sistem → Ayarlar (`finance.usd_try_rate`); an unusable rate
  leaves the figure unconverted rather than zeroing it, because a spend report reading zero
  looks like a quiet month and nobody investigates a quiet month.
- **Product prices accept only the supported currency.** The SKU form allowed EUR, USD and
  GBP from a hardcoded list while `money.supported_currencies` said TRY. It now reads the
  config, so the two cannot disagree.
- **A seeded model that Google does not serve.** `gemini-3-pro` was configured for room
  analysis, design planning, tagging and support answers. Every one of them failed with the
  provider's `invalid_request`, which the platform rendered to a customer as *"Oda fotoğrafı
  okunamadı. Daha aydınlık bir fotoğrafla tekrar deneyin."* — a good message for the failure
  it was written for and a lie about this one. The customer retook the photograph in better
  light and failed again. Repointed at `gemini-2.5-pro`, verified against ListModels *and* a
  real call.
- **`refconcept:verify-ai-models`**, so that cannot happen quietly again. It asks each
  provider whether the configured model codes exist and suggests near misses. Deliberately
  not in the test suite: it needs the network and a live key, and a suite that fails when a
  third party is having a bad morning is a suite people learn to ignore.
- **Room photographs were being handed to the provider as a URL.** Wrong twice over. It does
  not work — Gemini's `file_uri` accepts a URI from Google's own Files API, not an arbitrary
  link, so every design generation failed with *"Cannot fetch content from the provided
  URL"*. And it should not work: room photographs live on the private disk precisely so no
  URL to one ever leaves this system, and handing a third party a fetchable link to somebody's
  home would have quietly broken that while looking like an optimisation. The bytes are now
  read inside our own network and sent inline, bounded at 8MB per image, with the URL kept
  out of the logs.

### Added — Phase 22 (Web release / stabilization)

- **The API contract, generated from the router** and frozen at `apps/api/openapi.json` —
  205 paths, 251 operations, with the required permission stated on every administrative
  one. `refconcept:openapi --check` fails in CI when the committed document stops matching
  the routes, because a specification nobody verifies is wrong the first time somebody adds
  an endpoint and an integrator finds out before the team does. Request and response schemas
  are deliberately not invented: the shapes live in `packages/ui/src/runtime/types.ts`,
  where three clients compile against them.
- **Component tests for the shared design system** — eighteen, on the rules every app
  inherits: a status chip takes its colour from the code and never from the label, an error
  is *associated* with its field rather than merely drawn near it, a loading button cannot be
  pressed a second time, and a button given a destination renders a real link. That last one
  is a regression test: it once rendered an unknown element, looked perfect in every
  screenshot and did nothing when clicked.
- **Four operational documents**, written for the person who did not build this: a payment
  runbook whose first rule is never to guess which system is right, a seller onboarding
  runbook that explains what to write in a rejection reason, a production checklist that
  lists what is **blocked** rather than omitting it, and a deployment document explaining why
  reference data is seeded on every deploy and why workers restart after the migration.
- **A security checklist** that marks every rule as enforced by a test or by a person, and
  says plainly what is not covered.
- **A limited release strategy**: sellers first by invitation, bank transfer before cards,
  credits with a low ceiling, feature flags as the throttle, reconciliation daily from day
  one rather than from the first problem.

### Fixed — Phase 22

- **The OpenAPI freeze would have been decorative.** The document was written to a path
  outside the container's application tree, so `--check` had nothing to compare against.
- **The published API version still said `0.1.0-phase0`.**

### Release status

`WEB_RELEASE_APPROVED` is **not** written. Everything measurable passes and P0/P1 are zero,
but Phases 12 (iyzico) and 13 (QNB) are deferred pending documentation and credentials —
so the platform cannot take a card payment. A marketplace on bank transfer alone is a viable
limited launch and is not a completed web release. See the Independent Test Agent verdict at
the bottom of `12_FINAL_WEB_ACCEPTANCE.md`.

### Added — Phase 21 (Hardening)

- **The security rules are properties now, not prose.** A rule that lives only in a document
  is a rule somebody breaks in a hurry six months from now with nothing to stop them. The
  suite asserts that a card number has nowhere to go, that no HTTP route grants a platform
  role, that the super-admin bypass never reaches a customer's project, that no plaintext
  IBAN leaves the server or sits in a row, that every append-only table really is, and that
  nothing shaped like a provider key is committed anywhere.
- **Security headers set by the application**, not only by nginx — including
  `Permissions-Policy` and HSTS over TLS. A header added by infrastructure disappears the
  day somebody puts a different proxy in front, and nothing fails when it does.
- **A request id on every response**, honouring one the caller already assigned and
  replacing one that could not safely be logged. The audit log's `request_id` column had
  been null since Phase 1.
- **Payment reconciliation** (`refconcept:reconcile-payments`): the provider's transaction
  log against the journal, because each is internally consistent and neither can be checked
  against itself. Non-zero exit on anything critical, so a scheduler can alert; warnings do
  not fail the run, because an alert that fires on normal business gets turned off. Nothing
  is corrected automatically — a mismatch means two systems disagree about money.
- **A backup and restore drill** (`scripts/backup-drill.sh`) that dumps, restores into a
  throwaway database, compares row counts on the tables whose loss would hurt, and cleans up
  after itself. A backup nobody has restored is a hope.
- **A load smoke test** (`scripts/load-smoke.mjs`) that fails on any 5xx or dropped request
  under concurrency and only reports slow percentiles — the question is whether anything
  collapses, not how fast a laptop is.
- **Product media cached immutably.** The keys contain a UUID, so a URL always names the
  same bytes; that is what makes a year's caching correct rather than reckless.

### Fixed — Phase 21

- **Payment webhooks were queued behind ten-minute AI renders**, on a single worker. A
  customer's payment confirmation could sit behind somebody else's sofa. Split into two
  queues and two worker processes, with a test that stops the next job landing on the wrong
  one.
- **Every catalogue search made a live call to the embedding provider** — a network round
  trip on the most-used endpoint on the site, a cost per search, and a search box whose
  latency was somebody else's uptime. Query vectors are cached for an hour under a hashed
  key; search p50 under concurrency went from 2120ms to 602ms.
- **Two duplicate indexes**, one of them five phases old. Invisible from outside — no query
  is slower, the table simply pays for two index writes on every insert and holds two copies
  on disk.
- **Product images were served with no cache headers**, so every catalogue grid
  re-downloaded every thumbnail on every visit.

### Added — Phase 20 (Storefront complete + approved design language)

- **A phone can use the site.** The desktop navigation is hidden below `lg` and nothing
  replaced it, so a phone visitor saw a logo and a sign-up button and no way to reach the
  catalogue. The drawer that replaces it is a real dialog: focus moves into it, Escape
  closes it, the page behind does not scroll, and it closes on navigation so it never reads
  as a stuck overlay. The basket link now stays in the header at every width, because that
  is truer on a phone than on a desktop.
- **A skip link on all three apps**, first in the DOM and visible only when focused.
  Tabbing through a whole header — or a whole admin sidebar — to reach the row you came for
  is not navigation.
- **One SEO composable** rather than a `useHead` block per page: canonical, Open Graph,
  Twitter card, and a description trimmed at a word boundary, because a snippet cut
  mid-word reads as machine output.
- **Everything behind a sign-in refuses to be indexed.** An order page is not secret, it is
  protected — but a URL a crawler can reach is a URL a search result can carry. Those pages
  carry `noindex` and no canonical at all: a canonical asks a crawler to index one URL
  rather than another, which is a contradiction on a page that must not be indexed.
- **`robots.txt` and `sitemap.xml` are generated.** A static disallow list drifts from the
  router the moment somebody adds a page, and a hand-kept sitemap is wrong the day after
  somebody adds a product. The sitemap pages the catalogue at the API's own limit, and
  falls back to the static pages rather than answering 500 where a crawler expected XML.
- **Product structured data** — price, currency and availability — taken from what the page
  itself displays, so a rich result can never contradict the page behind it.
- **A footer that links the legal pages.** They existed and could only be opened by typing
  the URL; a terms page nobody can find is a terms page nobody agreed to.

### Fixed — Phase 20

- **Three navigation links pointed at the homepage.** "Platform", "Nasıl çalışır" and
  "Profesyoneller" all resolved to `/`. A menu that lies about where it goes is worse than
  a shorter menu.
- **A `<Teleport>` in the layout crashed the client app on unmount**, taking the page's
  event handlers with it — the symptom was a product page whose "Sepete ekle" button did
  nothing at all. The drawer is fixed-positioned and the layout root imposes no transform,
  so the teleport bought nothing.
- **The seller portal wiped its own confirmation.** Saving a parcel set a success message
  and then refreshed the screen, which cleared it — the seller saw the parcel appear and no
  word about whether it had worked.
- **A head getter reached forward to a `const` declared later in the same file**, which
  throws on the client and takes the whole page down with it. The product page lost its
  canonical and its event handlers together.

### Added — Phase 19 (Seller portal complete)

- **A seller can have colleagues.** Somebody dispatches parcels, somebody else answers
  returns, and the person whose name is on the bank account does neither. Two roles, and no
  third: an owner changes the team and the payout account, staff work the day-to-day. A
  third rung would need a permission editor, and a permission editor a seller can use is a
  way for a seller to lock themselves out of their own account.
- **The last owner cannot demote or remove themselves.** A company with no owner is a
  company where nobody can add one back, and the only way out is a support ticket and a
  console command. Refused by the API and by the screen, so the refusal arrives as an
  explanation rather than as an error.
- **One person belongs to one seller.** Somebody on two teams would see two companies'
  orders through one session, and every isolation guarantee in this platform is written per
  organization.
- **The person being added must already have an account.** Creating one from a team screen
  would let a seller set a password for an address they do not control, and "somebody added
  me to their company" is not a reason to hand over an account.
- **A removed member is marked, not deleted**, because the orders they confirmed and the
  returns they decided still name them — and they can be added back, since somebody
  returning from leave is not a new company.
- **A real seller dashboard**, leading with the queue rather than with revenue: orders not
  yet confirmed, parcels not yet sent, returns nobody has answered, stock running out,
  listings still in moderation. Each one is a way in rather than a number to write down.
  Low stock and nothing-on-the-shelf are counted separately, because one is a reminder and
  the other is a listing that has stopped selling.
- **A parcel screen.** A shipment is a physical thing with a carrier and a tracking number,
  and one order can have several. The remaining quantity per line comes from the server, so
  no client has to subtract shipment lines from order lines and no seller has to do it in
  their head. Delivery is marked per parcel; the order becomes "kargoya verildi" on its own
  once everything has actually gone.
- **Staff can read the team and change nothing.** Somebody working a returns queue sees
  "kim onayladı" next to a decision, and a name they cannot look up is worse than no name.
  The management controls are absent rather than disabled, and the page says why — a
  greyed-out button nobody can explain reads as a bug.

### Fixed — Phase 19

- **The seller's dashboard queried a column that does not exist.** Listings are owned by an
  organization rather than by a seller row, so every catalogue count answered 500.
- **A team listing was a lazy-load away from failing.** `displayName()` reads the profile,
  lazy loading is disabled on purpose, and a list of twenty members would otherwise have
  been twenty extra queries.
- **A permission added to the enum is not a permission granted.** The E2E run caught the
  deployment consequence: the role map is code and the grants are rows, and
  `RolesAndPermissionsSeeder` is what reconciles them.

### Added — Phase 18 (Super admin complete)

- **A permission matrix that no administrative endpoint can escape.** The guarantee is not
  "these endpoints are protected" — that is a list somebody has to keep up to date, and the
  entry that is missing is invisible. It is that *no admin route can exist without a
  decision about who may call it*: route-name prefixes map to permissions, middleware on the
  whole API group consults the map, and a route with no entry is refused at runtime and
  fails the suite at build time.
- **Failing closed.** An unknown admin route is a 403 — not a 404 and not a pass — because
  "we have not decided who may do this yet" is much closer to "nobody" than to "everybody".
  The middleware recognises its own territory by path rather than being attached route by
  route, because a check that has to be remembered is a check that is invisible when it is
  missing.
- **The longest prefix wins**, so reading a settlement and approving one can be different
  powers even though they live under the same prefix.
- **Nine new platform permissions and three roles that mean something.** An analyst reads
  and cannot press a single verb; an operator works every queue but cannot touch the
  platform's own switches; a super admin can. Turning a feature on for everybody is a
  release decision rather than an operational one, and it is the only power on these
  screens whose blast radius is the whole platform.
- **A critical-action audit gate.** Thirteen cases perform an action for real — a bank
  transfer confirmed, a settlement approved and paid, a manual refund, a credit adjustment,
  a seller suspended, a return decided, an order moved, a feature flag flipped, a setting
  changed, a webhook replayed — and then assert the trail: what happened, who did it, and
  where it costs somebody something, why. The trail is append-only in the database, so the
  record of a decision cannot be edited by whoever made it.
- **Feature flags and platform settings that actually do something.** The settlement hold
  period, the return window and three flags are read by the services that obey them, with
  the environment as the floor and a stored row as the override. A settings screen that
  writes rows nothing reads is worse than no screen: it tells whoever used it that they
  changed the platform, and they will act on that belief.
- **A missing flag is on.** A feature that switched itself off because somebody forgot to
  seed a row would be an outage caused by the safety mechanism. Turning something off is a
  decision, and a decision has a row. A partial rollout buckets on a stable hash of key and
  user id, so somebody who has the feature keeps it rather than losing it mid-journey.
- **Switching a payment method off does not strand the money already taken through it.**
  Only *starting* a payment is gated; refunds and late notifications still reach the adapter
  that understands them.
- **A secret is never echoed back** — not to the settings screen, not to whoever set it, and
  not into the audit log, which is read by more people than a secret store is. An unverified
  webhook is never replayed either: anybody can post one, and replaying it would let them
  fabricate a payment.
- **The admin panel's own screens.** A dashboard that leads with the queue of things still
  waiting for a person rather than with totals, an order search by the two things a caller
  on the phone actually has, an audit viewer with the caller's own permissions beside it —
  so a button they cannot press is explained by a page rather than by a 403 — and a system
  screen for flags, settings, failed jobs and unprocessed webhooks.

### Fixed — Phase 18

- **A feature check could crash the feature it guards.** The flag model was cached, and an
  Eloquent object read back out of a shared cache by another process comes back as whatever
  that process can reconstruct — here an incomplete class and a fatal error, which took out
  seller onboarding, AI jobs and the bank-transfer method together. Two scalars are cached
  now, and a test pins the service and the model to the same rollout arithmetic.
- **The audit list read columns that do not exist.** The viewer mapped `subject_type` and
  `subject_id`; the table stores `auditable_type` and `auditable_id`, so the one screen an
  investigation starts from answered 500.
- **A seller could no longer read their own record.** It was served from
  `/admin/sellers/{id}`, where the new rule asks whether the caller holds a platform
  permission — which a seller never does. Rather than carve an exception into the
  authorisation rule, the seller got `/api/v1/seller/profile` and the administrative path
  stayed administrative. An authorisation rule with an exception in it is a rule nobody can
  state.
- **PHPStan caught two unsound assumptions** in the new code: iterating the route collection
  through an interface that only promises countability, and an untyped array return on the
  settings model.

### Added — Phase 17 (Shipping, returns and refunds)

- **Shipments as parcels, not as a status.** A seller order can ship in several — a sofa
  and its cushions leave on different days — so the quantities live on the shipment lines
  and the order only becomes "shipped" once everything ordered has actually gone. A seller
  who dispatches one of four chairs and sees "kargoya verildi" has been given a status that
  will confuse their customer for a week.
- **Returns per line and per quantity.** A customer who bought four chairs and wants to
  return one is the ordinary case; an order-level model turns it into a support
  conversation. Every line carries both a requested and an approved quantity, because a
  seller opening the box and accepting two of three is normal.
- **`received` and `completed` as separate states**, with separate buttons. A parcel
  arriving is a physical fact; deciding the return is finished is what releases money. One
  button would turn a courier's delivery scan into a refund.
- **Refunds with their own lifecycle**, deliberately not folded into the return. Goods and
  money travel on different timetables: a provider can refuse a refund on a payment that is
  too old, a bank can take days, and a goodwill refund has no return behind it at all. A
  failed refund is a state that can be retried, because the customer is owed the money
  either way — and nothing is posted to the ledger until the money has actually gone.
- **The reversal is split by share.** The seller's payable comes down by their part and
  commission by its part, at the rate that was charged. Posting the whole refund against
  commission would make the platform pay for the seller's return; keeping the commission
  would mean the platform earns on a sale that did not happen.
- **Goods are restocked when they arrive, not when the return is approved** — and only the
  quantity that was accepted. Restocking on approval would put a sofa back on sale while it
  is still in a courier's van.
- **An open return holds the seller's payout** and says so on their earnings page, before
  the release date rather than after it: a date on its own is misleading while a return is
  running, and a seller who planned around it would be owed an explanation twice.
- **The settlement hold can never be shorter than the return window.** A configuration
  where it was would pay a seller while the customer could still send everything back, so
  the larger of the two is taken and the misconfiguration is harmless instead of expensive.
- **A returns section in the storefront** — a list that says where the goods are and,
  separately, where the money is — and a returns queue in the seller portal where accepting
  part of a request is a number per line rather than a yes or no.

### Fixed — Phase 17

- **A refused refund could never be retried.** Refund attempts were deduped on an operation
  key that included failed ones, so a retry short-circuited and reported success — and the
  unique index would have refused the row anyway. A provider outage would have left a
  customer permanently unrefunded with the record saying otherwise.
- **"No exception" was treated as success.** The payment processor records a provider
  refusal rather than throwing, because a decline is an answer rather than an error; the
  refund service now reads that record back instead of assuming.

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
