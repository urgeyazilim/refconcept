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
| P2-T001 | 2 | ARCHITECT_AGENT | P1-T006 | TODO | schema review | Seller application, legal entity, contacts, addresses, bank accounts, tax profile |
| P2-T002 | 2 | BACKEND_AGENT | P2-T001 | TODO | workflow tests | Application intake, document upload to private storage, state machine |
| P2-T003 | 2 | BACKEND_AGENT | P2-T002 | TODO | agreement tests | Versioned agreements and acceptance records |
| P2-T004 | 2 | BACKEND_AGENT | P2-T003 | TODO | admin action tests | Approval / rejection / suspension with reason + audit |
| P2-T005 | 2 | SELLER_PORTAL_AGENT | P2-T002 | TODO | portal E2E | Seller onboarding UI in the seller portal |
| P2-T006 | 2 | INDEPENDENT_TEST_AGENT | P2-T001..005 | TODO | Phase 2 gate | Complete workflow + audit + isolation |

## Scope notes

- Customer-facing **sign-up / sign-in screens** belong to Phase 20 (Storefront Complete)
  per `04_WEB_PHASE_PLAN.md`. Phase 1 delivered the API those screens consume.
- Rate limiting for the auth endpoints is configured and active; an isolated-cache test
  for it is scheduled with Phase 21 hardening.

## Allowed Status
```text
TODO
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
