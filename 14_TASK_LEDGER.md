# REFCONCEPT TASK LEDGER

| Task ID | Phase | Agent | Dependency | Status | Test Gate | Notes |
|---|---:|---|---|---|---|---|
| P0-T001 | 0 | ARCHITECT_AGENT | - | DONE | structure review | Monorepo layout, brand lock, ARCHITECTURE.md, ADR-0002/0003 |
| P0-T002 | 0 | DEVOPS_SECURITY_AGENT | P0-T001 | DONE | stack boots healthy | Docker: postgres+pgvector, redis, minio, mailpit, php 8.3, nginx, queue, scheduler |
| P0-T003 | 0 | BACKEND_AGENT | P0-T002 | DONE | health + tests PASS | Laravel 13, Administration domain, `/api/health`, Pest, PHPStan L6, Pint |
| P0-T004 | 0 | STOREFRONT_AGENT | P0-T002 | DONE | app builds | Nuxt 4 storefront on shared design system, hero + pillars + API status |
| P0-T005 | 0 | SELLER_PORTAL_AGENT | P0-T002 | DONE | app builds | Nuxt 4 seller portal shell, operational theme |
| P0-T006 | 0 | ADMIN_AGENT | P0-T002 | DONE | app builds | Nuxt 4 super admin shell, Phase 18 section anchors |
| P0-T007 | 0 | INDEPENDENT_TEST_AGENT | P0-T003..006 | PASS | Phase 0 gate | `TEST_REPORT.md` — PASS, 4 defects found and fixed |
| P0-T008 | 0 | DESIGN_SYSTEM_AGENT | P0-T001 | DONE | token guard PASS | `@refconcept/ui`: tokens.ts / tokens.css / theme.css / base.css + CI colour guard |
| P1-T001 | 1 | ARCHITECT_AGENT | P0-T007 | DONE | schema review | Identity schema: UUIDv7 keys, citext e-mail, UTC, DB CHECK constraints, partial unique indexes |
| P1-T002 | 1 | BACKEND_AGENT | P1-T001 | DONE | auth feature tests | Registration, login, tokens, sessions, e-mail verification, password reset |
| P1-T003 | 1 | BACKEND_AGENT | P1-T002 | DONE | policy tests | Permissions/roles/grants, organizations, membership, `AccessControl` |
| P1-T004 | 1 | BACKEND_AGENT | P1-T003 | DONE | audit tests | Append-only `audit_logs` with DB trigger, redacting `AuditLogger` |
| P1-T005 | 1 | BACKEND_AGENT | P1-T002 | DONE | isolation tests | Profile and address book, ownership policies, verified-e-mail gate |
| P1-T006 | 1 | INDEPENDENT_TEST_AGENT | P1-T001..005 | PASS | Phase 1 gate | 78 tests / 235 assertions; 15 tenant isolation cases; 5 defects found and fixed |
| P2-T001 | 2 | ARCHITECT_AGENT | P1-T006 | DONE | schema review | 10 tables; CHECK constraints, partial unique indexes, acceptance immutability trigger |
| P2-T002 | 2 | BACKEND_AGENT | P2-T001 | DONE | workflow tests | Application intake, derived checklist, private document storage, state machine |
| P2-T003 | 2 | BACKEND_AGENT | P2-T002 | DONE | agreement tests | Versioned agreements, checksummed immutable acceptances |
| P2-T004 | 2 | BACKEND_AGENT | P2-T003 | DONE | admin action tests | Approval creates the tenant; rejection, suspension and commission changes audited |
| P2-T005 | 2 | SELLER_PORTAL_AGENT | P2-T002 | DONE | portal E2E | Onboarding wizard with live checklist progress |
| P2-T006 | 2 | ADMIN_AGENT | P2-T004 | DONE | admin E2E | Review queue, application review, seller administration |
| P2-T007 | 2 | INDEPENDENT_TEST_AGENT | P2-T001..006 | PASS | Phase 2 gate | 135 tests / 389 assertions, 9 E2E journeys, 3 defects found and fixed |
| P3-T001 | 3 | ARCHITECT_AGENT | P2-T007 | DONE | schema review | Categories, brands, attributes, styles, colours, materials, room taxonomy; 40 categories with materialised paths |
| P3-T002 | 3 | BACKEND_AGENT | P3-T001 | DONE | catalog tests | Products, seller listings, SKUs, variants, dimensions; money as integer minor units throughout |
| P3-T003 | 3 | BACKEND_AGENT | P3-T002 | DONE | media tests | Product media on its own public bucket: random keys, extension from the decoded type, one cover per product |
| P3-T004 | 3 | BACKEND_AGENT | P3-T003 | DONE | moderation tests | Seller product to admin approve to public product; an edit to a live listing re-queues it |
| P3-T005 | 3 | SELLER_PORTAL_AGENT | P3-T002 | DONE | portal E2E | Product list, creation, editor, gallery and SKU management in the seller portal |
| P3-T006 | 3 | ADMIN_AGENT | P3-T004 | DONE | admin E2E | Moderation queue and review screen with approve, reject and recall |
| P3-T007 | 3 | INDEPENDENT_TEST_AGENT | P3-T001..006 | DONE | Phase 3 gate | Phase 3 gate: 213 backend tests, 12 E2E journeys across three apps (see TEST_REPORT.md) |
| P4-T001 | 4 | ARCHITECT_AGENT | P3-T007 | DONE | schema review | Pricing, inventory, imports and API credentials; append-only triggers, balance CHECK constraints, partial unique indexes |
| P4-T002 | 4 | BACKEND_AGENT | P4-T001 | DONE | import tests | CSV/XLSX streaming reader, column mapping, dry run, per-row errors, commit |
| P4-T003 | 4 | BACKEND_AGENT | P4-T001 | DONE | pricing tests | Price lists with time windows, append-only price history, campaign resolution |
| P4-T004 | 4 | BACKEND_AGENT | P4-T001 | DONE | concurrency tests | Stock ledger with row locks, idempotent reservations, expiry sweep, all-or-nothing baskets |
| P4-T005 | 4 | BACKEND_AGENT | P4-T004 | DONE | partner API tests | Scoped key/secret credentials, per-credential rate limits, request log |
| P4-T006 | 4 | SELLER_PORTAL_AGENT | P4-T002..005 | DONE | portal E2E | Prices, stock, bulk import and integrations screens |
| P4-T007 | 4 | INDEPENDENT_TEST_AGENT | P4-T001..006 | DONE | Phase 4 gate | 290 backend tests, 15 E2E journeys (see TEST_REPORT.md) |
| P5-T001 | 5 | ARCHITECT_AGENT | P4-T007 | DONE | schema review | Projects, members, rooms, private media, constraints, designs and the version tree |
| P5-T002 | 5 | BACKEND_AGENT | P5-T001 | DONE | ownership tests | Owner and invited members only; the super-admin bypass excluded for customer projects |
| P5-T003 | 5 | BACKEND_AGENT | P5-T001 | DONE | media tests | Private disk, random keys, no URL in any response, short-lived signed links |
| P5-T004 | 5 | BACKEND_AGENT | P5-T001 | DONE | version tests | Tree with locked numbering, branch-from-finished-only, immutable once ready |
| P5-T005 | 5 | BACKEND_AGENT | P5-T002 | DONE | sharing tests | Hashed one-time invitations bound to the invited address, revocable and recorded |
| P5-T006 | 5 | STOREFRONT_AGENT | P5-T002..004 | DONE | storefront E2E | Projects, rooms, photograph gallery, measurements, constraints and the version tree |
| P5-T007 | 5 | INDEPENDENT_TEST_AGENT | P5-T001..006 | DONE | Phase 5 gate | 353 backend tests, 18 E2E journeys (see TEST_REPORT.md) |
| P6-T001 | 6 | ARCHITECT_AGENT | P5-T007 | DONE | schema review | Providers, credentials, models, cost rates, prompt versions, task routes, jobs, requests, usage, failures |
| P6-T002 | 6 | BACKEND_AGENT | P6-T001 | DONE | gateway tests | One gateway owns routing, retries, fallback, cost ceilings and recording; adapters only translate |
| P6-T003 | 6 | BACKEND_AGENT | P6-T002 | DONE | adapter tests | OpenAI and Google adapters, plus a deterministic fake so CI never spends money |
| P6-T004 | 6 | BACKEND_AGENT | P6-T002 | DONE | prompt tests | Versioned prompts, immutable once published (database trigger), rendered previews |
| P6-T005 | 6 | BACKEND_AGENT | P6-T002 | DONE | privacy tests | Job payloads are the customer’s alone; AiJob excluded from the super-admin bypass |
| P6-T006 | 6 | BACKEND_AGENT | P6-T002 | DONE | console tests | Routing, key rotation, cost rates and the kill switch, all audited |
| P6-T007 | 6 | ADMIN_PANEL_AGENT | P6-T006 | DONE | admin E2E | AI control room: routes, spend, failures and the kill switch |
| P6-T008 | 6 | INDEPENDENT_TEST_AGENT | P6-T001..007 | DONE | Phase 6 gate | 413 backend tests, 20 E2E journeys (see TEST_REPORT.md) |
| P7-T001 | 7 | ARCHITECT_AGENT | P6-T008 | DONE | schema review | Packages, wallets, expiry lots, an append-only ledger, holds, promotions and redemptions |
| P7-T002 | 7 | BACKEND_AGENT | P7-T001 | DONE | invariant tests | One ledger writes everything; balance never negative, holds never exceed it, aggregate always matches the lots |
| P7-T003 | 7 | BACKEND_AGENT | P7-T002 | DONE | concurrency tests | Row locks with a re-read inside them; a stale caller-held wallet cannot overspend |
| P7-T004 | 7 | BACKEND_AGENT | P7-T002 | DONE | duplicate tests | Every mutating path idempotent on a reference; a hold settles exactly once |
| P7-T005 | 7 | BACKEND_AGENT | P7-T002 | DONE | promotion tests | Locked budget, per-user limits, one identical refusal for every unusable code |
| P7-T006 | 7 | BACKEND_AGENT | P7-T002 | DONE | AI integration tests | Hold on queue, consume on success, release on failure — a failed render costs nothing |
| P7-T007 | 7 | INDEPENDENT_TEST_AGENT | P7-T001..006 | DONE | Phase 7 gate | 473 backend tests, 24 E2E journeys (see TEST_REPORT.md) |
| P8-T001 | 8 | ARCHITECT_AGENT | P7-T007 | DONE | schema review | Room analyses cached per photograph, immutable plans, append-only progress events |
| P8-T002 | 8 | BACKEND_AGENT | P8-T001 | DONE | analysis tests | One reading per photograph, reused across designs, confidence in basis points |
| P8-T003 | 8 | BACKEND_AGENT | P8-T001 | DONE | planning tests | Structured plan validated against the room; what does not fit is recorded, not dropped |
| P8-T004 | 8 | BACKEND_AGENT | P8-T002..003 | DONE | pipeline tests | Analyse, plan, render, save — with progress a customer can watch |
| P8-T005 | 8 | BACKEND_AGENT | P8-T004 | DONE | credit tests | One hold for the whole version; every failure path returns it in full |
| P8-T006 | 8 | STOREFRONT_AGENT | P8-T004 | DONE | storefront E2E | Live progress, render quality, the plan beside the image |
| P8-T007 | 8 | INDEPENDENT_TEST_AGENT | P8-T001..006 | DONE | Phase 8 gate | 493 backend tests, 26 E2E journeys (see TEST_REPORT.md) |
| P9-T001 | 9 | ARCHITECT_AGENT | P8-T007 | DONE | schema review | pgvector embeddings with an HNSW index, snapshot matches, append-only feedback |
| P9-T002 | 9 | BACKEND_AGENT | P9-T001 | DONE | embedding tests | Hashed input so an unchanged catalogue costs nothing; seller and delivery terms excluded |
| P9-T003 | 9 | BACKEND_AGENT | P9-T002 | DONE | filter benchmark | Category, stock, budget, width and room type — every one asserted against a fixed catalogue |
| P9-T004 | 9 | BACKEND_AGENT | P9-T003 | DONE | retrieval tests | One row per product, ordering by meaning, an unmatched category returning nothing |
| P9-T005 | 9 | BACKEND_AGENT | P9-T004 | DONE | shopping list tests | Grouped by placement, prices snapshotted, a rerank that cannot break the list |
| P9-T006 | 9 | STOREFRONT_AGENT | P9-T005 | DONE | storefront E2E | The list beside the render, choosing, and telling us what was wrong |
| P9-T007 | 9 | INDEPENDENT_TEST_AGENT | P9-T001..006 | DONE | Phase 9 gate | 521 backend tests, 27 E2E journeys (see TEST_REPORT.md) |
| P10-T001 | 10 | ARCHITECT_AGENT | P9-T007 | DONE | schema review | One open cart per customer, one line per SKU, price snapshots, favourites per product |
| P10-T002 | 10 | BACKEND_AGENT | P10-T001 | DONE | price race tests | A rise is reported and never applied; a fall blocks nothing; acceptance is explicit |
| P10-T003 | 10 | BACKEND_AGENT | P10-T001 | DONE | stock race tests | Nothing held while idle; all-or-nothing at checkout; two baskets never exceed the shelf |
| P10-T004 | 10 | BACKEND_AGENT | P10-T002..003 | DONE | revalidation tests | Sold out removed, short stock reduced, both reported before payment |
| P10-T005 | 10 | BACKEND_AGENT | P10-T001 | DONE | search tests | Three methods fused by rank, facets over the whole result, nonsense returning nothing |
| P10-T006 | 10 | STOREFRONT_AGENT | P10-T002..005 | DONE | storefront E2E | Cart, favourites, and a repricing seen from the other side of the marketplace |
| P10-T007 | 10 | INDEPENDENT_TEST_AGENT | P10-T001..006 | DONE | Phase 10 gate | 561 backend tests, 32 E2E journeys (see TEST_REPORT.md) |
| P11-T001 | 11 | ARCHITECT_AGENT | P10-T007 | DONE | schema review | Session freezes price and address; one live intent per session; append-only transactions; webhook inbox |
| P11-T002 | 11 | BACKEND_AGENT | P11-T001 | DONE | state machine tests | Declared transitions only; late news dropped and logged; capture fulfils exactly once |
| P11-T003 | 11 | BACKEND_AGENT | P11-T001 | DONE | duplicate tests | E2E-03: four deliveries, one credit load; two distinct events with the same news act once |
| P11-T004 | 11 | BACKEND_AGENT | P11-T002 | DONE | timeout tests | A timeout is retryable and the session survives; the provider is asked rather than guessed at |
| P11-T005 | 11 | DEVOPS_SECURITY_AGENT | P11-T001 | DONE | replay tests | Signature over exact bytes; unsigned events stored and refused; Idempotency-Key replay |
| P11-T006 | 11 | BACKEND_AGENT | P11-T001 | DONE | gateway contract | Five-method adapter contract, registry, marketplace settlement kept separate, test provider |
| P11-T007 | 11 | STOREFRONT_AGENT | P11-T002..006 | DONE | checkout E2E | Payment page, 3DS round trip, return page that asks rather than assumes, credit purchase |
| P11-T008 | 11 | INDEPENDENT_TEST_AGENT | P11-T001..007 | DONE | Phase 11 gate | 594 backend tests, 38 E2E journeys, 4 defects found and fixed (see TEST_REPORT.md) |
| P12-T001 | 12 | BACKEND_AGENT | P11-T008 | DEFERRED | iyzico sandbox | Needs live official docs + sandbox credentials; adapter seam ready (see 13_PROGRESS_STATE.md) |
| P13-T001 | 13 | BACKEND_AGENT | P11-T008 | DEFERRED | QNB test flow | Needs live official docs + merchant credentials; adapter seam ready |
| P14-T001 | 14 | ARCHITECT_AGENT | P11-T008 | DONE | schema review | Reference unique for all time; one live and one settled transfer per intent; receipts on the private disk |
| P14-T002 | 14 | BACKEND_AGENT | P14-T001 | DONE | mismatch tests | Short and over payments as named states; a shortfall releases nothing and states the figure |
| P14-T003 | 14 | BACKEND_AGENT | P14-T001 | DONE | duplicate tests | Row lock, state check and a partial unique index; a second confirmation is a 409 |
| P14-T004 | 14 | DEVOPS_SECURITY_AGENT | P14-T001 | DONE | access tests | payments.view and payments.settle split; receipts only via short-lived signed links |
| P14-T005 | 14 | STOREFRONT_AGENT | P14-T002..004 | DONE | transfer E2E | Method choice, reference page, receipt upload; finance queue in the admin panel |
| P14-T006 | 14 | INDEPENDENT_TEST_AGENT | P14-T001..005 | DONE | Phase 14 gate | 619 backend tests, 42 E2E journeys, 2 defects found and fixed (see TEST_REPORT.md) |
| P15-T001 | 15 | ARCHITECT_AGENT | P14-T006 | DONE | schema review | Master order per payment, seller order per seller, everything on a line snapshotted, append-only history |
| P15-T002 | 15 | BACKEND_AGENT | P15-T001 | DONE | split tests | E2E-06: one payment, one order, one seller order per seller, each with their own total |
| P15-T003 | 15 | BACKEND_AGENT | P15-T001 | DONE | status machine tests | Declared transitions per seller order; master status derived; partially_shipped is real |
| P15-T004 | 15 | BACKEND_AGENT | P15-T003 | DONE | stock tests | Cancelling before shipping returns the stock; shipped cannot be cancelled |
| P15-T005 | 15 | STOREFRONT_AGENT | P15-T002..004 | DONE | orders E2E | Customer list and detail grouped by seller; seller queue with payable next to gross |
| P15-T006 | 15 | INDEPENDENT_TEST_AGENT | P15-T001..005 | DONE | Phase 15 gate | 645 backend tests, 46 E2E journeys, 2 defects found and fixed (see TEST_REPORT.md) |
| P16-T001 | 16 | ARCHITECT_AGENT | P15-T006 | DONE | schema review | Append-only journal, deferred balance trigger, per-seller payable accounts, one settlement per order |
| P16-T002 | 16 | BACKEND_AGENT | P16-T001 | DONE | ledger invariants | Every entry balances; nothing edited; reversal not deletion; one journal per event |
| P16-T003 | 16 | BACKEND_AGENT | P16-T001 | DONE | commission tests | Six-rung hierarchy resolved once at order time and snapshotted onto the line |
| P16-T004 | 16 | BACKEND_AGENT | P16-T002 | DONE | eligibility tests | Captured, delivered, held, unsuspended, unsettled — with a sentence per order |
| P16-T005 | 16 | BACKEND_AGENT | P16-T004 | DONE | payout tests | Build, approve, pay as three acts; no double approval; no order in two runs |
| P16-T006 | 16 | STOREFRONT_AGENT | P16-T003..005 | DONE | finance E2E | Seller earnings with four states; admin finance screen leading on "denk mi" |
| P16-T007 | 16 | INDEPENDENT_TEST_AGENT | P16-T001..006 | DONE | Phase 16 gate | 678 backend tests, 50 E2E journeys, 3 defects found and fixed (see TEST_REPORT.md) |
| P17-T001 | 17 | ARCHITECT_AGENT | P16-T007 | DONE | schema review | Shipments as parcels, returns and refunds as separate lifecycles, everything per line |
| P17-T002 | 17 | BACKEND_AGENT | P17-T001 | DONE | partial shipping | A parcel carries part of an order; the order ships when everything has gone |
| P17-T003 | 17 | BACKEND_AGENT | P17-T001 | DONE | partial return tests | Requested and approved quantities; restock on arrival; window and double-return refused |
| P17-T004 | 17 | BACKEND_AGENT | P17-T003 | DONE | provider failure tests | A refused refund is retryable and posts nothing until the money moves |
| P17-T005 | 17 | BACKEND_AGENT | P17-T004 | DONE | settlement hold | E2E-09: an open return blocks the payout and releases it when resolved |
| P17-T006 | 17 | INDEPENDENT_TEST_AGENT | P17-T001..005 | DONE | Phase 17 gate | 709 backend tests, 55 E2E journeys, 3 defects found and fixed (see TEST_REPORT.md) |
| P18-T001 | 18 | ARCHITECT_AGENT | P17-T006 | DONE | permission matrix design | Route-name prefixes to permissions, longest match wins, unclaimed means refused |
| P18-T002 | 18 | BACKEND_AGENT | P18-T001 | DONE | matrix + middleware | Registered on the whole API group and self-selecting, so it cannot be forgotten |
| P18-T003 | 18 | BACKEND_AGENT | P18-T002 | DONE | admin surfaces | Analytics, orders, audit trail, feature flags, settings, failed jobs and webhooks |
| P18-T004 | 18 | BACKEND_AGENT | P18-T003 | DONE | the switches do something | Hold days, return window and three flags read by the services that obey them |
| P18-T005 | 18 | FRONTEND_AGENT | P18-T003 | DONE | admin UI slice | Dashboard leading with the queue, order search, audit viewer with the permission matrix, system screen |
| P18-T006 | 18 | BACKEND_AGENT | P18-T004 | DONE | critical action audit | Thirteen actions performed for real, each asserting who and why |
| P18-T007 | 18 | INDEPENDENT_TEST_AGENT | P18-T001..006 | DONE | Phase 18 gate | 747 backend tests, 61 E2E journeys, 4 defects found and fixed (see TEST_REPORT.md) |
| P19-T001 | 19 | ARCHITECT_AGENT | P18-T007 | DONE | portal gap analysis | Missing: a team, a real dashboard, a parcel screen, and remaining quantities per line |
| P19-T002 | 19 | BACKEND_AGENT | P19-T001 | DONE | seller team API | Two roles, membership and role written together, the last owner refused |
| P19-T003 | 19 | BACKEND_AGENT | P19-T001 | DONE | seller dashboard API | The queue first, the money from the ledger projection, everything scoped to the caller |
| P19-T004 | 19 | BACKEND_AGENT | P19-T001 | DONE | pending shipment lines | What is still on the shelf, sent rather than left to each client to work out |
| P19-T005 | 19 | FRONTEND_AGENT | P19-T002..004 | DONE | portal UI slice | Dashboard, team screen, parcel screen; buttons absent rather than disabled, with the reason said |
| P19-T006 | 19 | INDEPENDENT_TEST_AGENT | P19-T001..005 | DONE | Phase 19 gate | 775 backend tests, 67 E2E journeys, 3 defects found and fixed (see TEST_REPORT.md) |
| P20-T001 | 20 | ARCHITECT_AGENT | P19-T006 | DONE | storefront audit | Screens were complete; responsive, SEO and accessibility were not |
| P20-T002 | 20 | FRONTEND_AGENT | P20-T001 | DONE | mobile navigation | A drawer that is a real dialog, plus a skip link in all three apps |
| P20-T003 | 20 | FRONTEND_AGENT | P20-T001 | DONE | SEO surface | One useSeo composable: canonical, Open Graph, noindex behind a sign-in |
| P20-T004 | 20 | FRONTEND_AGENT | P20-T003 | DONE | robots + sitemap | Generated from the router and the catalogue, paged to the API limit |
| P20-T005 | 20 | FRONTEND_AGENT | P20-T003 | DONE | product structured data | Price and availability, taken from what the page itself shows |
| P20-T006 | 20 | INDEPENDENT_TEST_AGENT | P20-T001..005 | DONE | Phase 20 gate | 775 backend tests, 76 E2E journeys, 4 defects found and fixed (see TEST_REPORT.md) |
| P21-T001 | 21 | BACKEND_AGENT | P20-T006 | DONE | security review | Every rule in 06_SECURITY_… turned into a property the suite enforces |
| P21-T002 | 21 | BACKEND_AGENT | P21-T001 | DONE | dependency scan | composer audit and npm audit both clean |
| P21-T003 | 21 | BACKEND_AGENT | P21-T001 | DONE | security headers | Moved into the application; a proxy change must not lose them |
| P21-T004 | 21 | BACKEND_AGENT | P20-T006 | DONE | load smoke | No 5xx and no dropped requests under concurrency; search was 3.5× slow and is not |
| P21-T005 | 21 | BACKEND_AGENT | P21-T004 | DONE | DB/index tuning | Two duplicate indexes removed; hot paths asserted to have an index |
| P21-T006 | 21 | BACKEND_AGENT | P21-T004 | DONE | queue tuning | Payments and AI split across two workers; the split is now a test |
| P21-T007 | 21 | BACKEND_AGENT | P20-T006 | DONE | observability + CDN | A request id on every response; product media cached immutably |
| P21-T008 | 21 | BACKEND_AGENT | P16-T007 | DONE | payment reconciliation | Provider log against the journal, with an exit code a scheduler can alert on |
| P21-T009 | 21 | INDEPENDENT_TEST_AGENT | P21-T001..008 | DONE | Phase 21 gate | 805 backend tests, 76 E2E journeys, P0/P1 = 0 after 6 defects fixed (see TEST_REPORT.md) |

## Scope notes

- Customer-facing **sign-up / sign-in screens** belong to Phase 20 (Storefront Complete)
  per `04_WEB_PHASE_PLAN.md`. Phase 1 delivered the API those screens consume.
- Rate limiting for the auth endpoints is configured and active; an isolated-cache test
  for it is scheduled with Phase 21 hardening.

## Allowed Status
```text
DONE
IN_PROGRESS
BLOCKED_EXTERNAL
READY_FOR_TEST
FAILED
FIXING
PASS
DONE
```

## Rule
`DONE` requires the task's required test gate to pass.
Categories, brands, attributes, styles, colours, materials, room taxonomy — 40 categories seeded, materialised paths |