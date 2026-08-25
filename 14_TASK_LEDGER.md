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