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