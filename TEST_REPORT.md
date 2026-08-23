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
