# REFCONCEPT ENVIRONMENT, DEVOPS & RELEASE

## Environments

### Local
Docker Compose:
- app/API
- PostgreSQL
- Redis
- object storage emulator
- mail catcher
- optional observability services

### Test
Ephemeral CI DB/storage.

### Staging
Production-like:
- sandbox iyzico
- test QNB
- fake/manual bank transfer
- limited AI budget or fake provider for most regression

### Production
- real payment credentials
- secret manager
- managed DB/Redis
- S3-compatible object storage
- CDN/WAF
- backups/PITR
- monitoring/alerts

## Required Environment Variable Groups

```text
APP_*
DB_*
REDIS_*
S3_*
MAIL_*
AI_OPENAI_*
AI_GOOGLE_*
IYZICO_*
QNB_*
BANK_TRANSFER_*
OBSERVABILITY_*
```

Never place real secrets in `.env.example`.

## Desired Developer Commands

The autonomous agent should create/converge on:

```bash
make bootstrap
make up
make down
make lint
make test
make test-backend
make test-frontend
make test-e2e
make test-payments
make test-security
make migrate
make seed
make openapi
```

## CI Pipeline

Pull request:
```text
install
→ lint
→ static analysis
→ unit
→ feature/API
→ frontend typecheck/unit
→ build
→ dependency/security scan
```

Main:
```text
all checks
→ deploy staging
→ migrate
→ seed reference data
→ E2E
→ smoke
→ release gate
```

### Reference data is part of a deploy, not part of setup

`RolesAndPermissionsSeeder` and `PlatformSettingsSeeder` run on **every** deploy, not only
on a fresh stack. Both are idempotent, and the roles seeder uses `sync()` so a permission
removed from `SystemRole` is removed from the role rather than lingering as a grant nobody
intended.

The reason is that the role → permission map lives in code and the grants live in rows. A
permission added to the enum and not seeded is a feature that fails for exactly the people
it was written for, with nothing in the logs to say why — Phase 19 shipped a screen staff
could not open on a stale environment while every test passed. The code was right and the
database was old, which is the hardest version of this bug to recognise.

## Observability
- trace/request ID
- user/seller/order/payment/AI job correlations
- structured logs
- error monitoring
- metrics
- queue lag
- provider latency/error rate
- AI spend
- payment success/refund rate
- reconciliation mismatches

## Backups
- automated DB backup/PITR
- object storage versioning
- encrypted backups
- documented restore
- tested restore drill

## Migration Strategy
Expand/contract for breaking changes.
Prefer backward-compatible deployment.

## Web Release
Only after `WEB_RELEASE_APPROVED`.
