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
