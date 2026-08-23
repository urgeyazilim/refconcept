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
