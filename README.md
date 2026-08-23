# RefConcept

AI-driven interior design platform and multi-seller marketplace.
A customer describes or photographs a room, the AI produces a design, and every item in
that design maps to a real, purchasable product from an onboarded seller.

**Current milestone: WEB.** Mobile/AR work is blocked until `WEB_RELEASE_APPROVED`
(see [17_MOBILE_AFTER_WEB.md](17_MOBILE_AFTER_WEB.md)).

---

## Repository layout

```text
apps/
  api/              Laravel 12 API — domain modules under app/Domains
  storefront/       Nuxt customer web app
  seller-portal/    Nuxt seller operations app
  admin-panel/      Nuxt super admin app
packages/
  ui/               design tokens, Tailwind v4 theme bridge, shared components
  contracts/        shared API contracts / generated types
  api-client/       typed API client consumed by the Nuxt apps
infra/
  docker/           php, nginx and postgres images/config
  monitoring/       observability configuration
docs/
  ADR/              architecture decision records
  api/ db/ security/ payments/ testing/ operations/
scripts/            developer and CI helper scripts
design_refs/        approved visual references (source of truth for UI)
```

Specification and process documents (`00_`–`22_`, `AGENTS.md`,
`REFCONCEPT_MASTER_SPEC.md`) live at the repository root and are the authoritative
source of truth for scope, rules and phase gates.

---

## Requirements

- Docker Desktop (running)
- Node.js >= 20.11 (Node 22/24 recommended)
- Git

PHP, Composer, PostgreSQL and Redis are **not** needed on the host — they run in
containers. An existing XAMPP installation is neither used nor modified; all host ports
are shifted away from the defaults to avoid collisions.

## Quick start

```powershell
# Windows
copy .env.example .env
.\scripts\rc.ps1 bootstrap     # env files, app key, composer install, migrations, seeds
.\scripts\rc.ps1 status        # verify every service is healthy
npm install
npm run dev:storefront
```

```bash
# Linux / macOS
cp .env.example .env
make bootstrap
make status
npm install && npm run dev:storefront
```

### Local endpoints

| Service | URL |
|---|---|
| API | http://localhost:58000 |
| API health | http://localhost:58000/api/health |
| Storefront | http://localhost:3000 |
| Seller Portal | http://localhost:3001 |
| Super Admin | http://localhost:3002 |
| MinIO console | http://localhost:59001 |
| Mailpit | http://localhost:58025 |
| PostgreSQL | localhost:55432 |
| Redis | localhost:56379 |

---

## Everyday commands

| Task | Windows | Linux/macOS |
|---|---|---|
| start stack | `.\scripts\rc.ps1 up` | `make up` |
| stop stack | `.\scripts\rc.ps1 down` | `make down` |
| **push code changes** | `.\scripts\rc.ps1 sync` | `make sync` |
| continuous sync | `.\scripts\rc.ps1 watch` | `make watch` |
| artisan | `.\scripts\rc.ps1 artisan migrate` | `make migrate` |
| tests | `.\scripts\rc.ps1 test` | `make test` |
| logs | `.\scripts\rc.ps1 logs api` | `make logs` |
| rebuild db | `.\scripts\rc.ps1 fresh` | `make fresh` |

### Why `sync` exists

`apps/api` on the host is the source of truth, but the container runs the code from a
named volume rather than a bind mount. On this Windows setup a single Laravel boot
costs **22.8s** through a bind mount versus **4.3s** from the volume, and the test
suite drops from 104s to 32s — so backend edits are pushed in explicitly.

- After editing PHP: `.\scripts\rc.ps1 sync` (or leave `rc.ps1 watch` running).
- If something was generated *inside* the container: `.\scripts\rc.ps1 pull`.
- On Linux/macOS the penalty does not exist — overlay
  `infra/docker/compose.bindmount.yml` to edit in place instead.

Full rationale and measurements: [ADR-0002](docs/ADR/ADR-0002-local-dev-topology.md).

---

## Engineering rules that are not negotiable

- Money is stored in **minor units as integers**; floats are never used for financial values.
- Payment, credit, order, refund and settlement operations are **idempotent**; a duplicate
  webhook must not produce a second financial effect.
- Financial history is corrected with **reversal entries**, never `UPDATE`/`DELETE`.
- Marketplace accounting is validated by a **balanced double-entry ledger**.
- Seller A can never read Seller B's data; tenant isolation is enforced by policy and tested.
- AI provider and model identifiers are **configuration**, never hard-coded.
- AI work runs **asynchronously on queues**; credits are reserved before a run, consumed on
  success and released on failure.
- A feature is not done without migrations, authorization, validation, loading/error states,
  audit trail, OpenAPI documentation and tests.

Full detail: [05_ARCHITECTURE_AND_CODE_RULES.md](05_ARCHITECTURE_AND_CODE_RULES.md),
[06_SECURITY_PAYMENT_FINANCE_RULES.md](06_SECURITY_PAYMENT_FINANCE_RULES.md),
[07_AI_ENGINE_RULES.md](07_AI_ENGINE_RULES.md),
[08_DATABASE_AND_DOMAIN_RULES.md](08_DATABASE_AND_DOMAIN_RULES.md).

## Design language

The UI is bound to the approved references in [design_refs/](design_refs/) and to
[21_DESIGN_SYSTEM_UI_SPEC.md](21_DESIGN_SYSTEM_UI_SPEC.md) /
[22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md](22_SCREEN_BLUEPRINTS_INFORMATION_ARCHITECTURE.md).

Palette: Charcoal `#111111` · Warm Gray `#F5F3F0` · Sand `#DCCE86` · Taupe `#A89E8E` ·
Gold `#C9A86A`. Tailwind's default palette is removed at the theme level and
`scripts/check-design-tokens.mjs` fails CI on any foreign colour.

## Progress

Live build state is tracked in [13_PROGRESS_STATE.md](13_PROGRESS_STATE.md) and
[14_TASK_LEDGER.md](14_TASK_LEDGER.md); test outcomes in `TEST_REPORT.md`.
