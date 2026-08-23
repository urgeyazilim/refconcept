# RefConcept Architecture

> Companion to `REFCONCEPT_MASTER_SPEC.md` §5–§8. This file describes what is
> actually built; the spec describes what must be built.

## 1. Shape

RefConcept is a **web-first modular monolith** (ADR-0001): one Laravel application
partitioned into domain modules, three Nuxt clients, and a small set of stateful
services. Modules communicate through application services and domain events, never
by reaching into each other's tables.

```text
┌──────────────┐  ┌───────────────┐  ┌──────────────┐
│  Storefront  │  │ Seller Portal │  │ Super Admin  │   Nuxt 4 / Vue 3 / TS
└──────┬───────┘  └───────┬───────┘  └──────┬───────┘
       └──────────────────┼─────────────────┘
                          │ HTTPS / JSON (OpenAPI described)
                   ┌──────▼───────┐
                   │  Laravel API │  app/Domains/*
                   └──┬────┬───┬──┘
        ┌─────────────┘    │   └──────────────┐
┌───────▼──────┐  ┌────────▼───────┐  ┌───────▼────────┐
│ PostgreSQL   │  │ Redis          │  │ S3-compatible  │
│ + pgvector   │  │ cache/queue    │  │ object storage │
└──────────────┘  └────────────────┘  └────────────────┘
                          │
                 ┌────────▼─────────┐
                 │ queue workers    │  AI jobs, payments, settlement, mail
                 └──────────────────┘
```

## 2. Backend module layout

Each domain under `apps/api/app/Domains/<Domain>` owns its own vertical slice:

```text
Actions/        single-purpose write operations (the unit a controller calls)
DTOs/           readonly data carriers crossing boundaries
Enums/          closed value sets, backed by string values
Events/         facts other domains may react to
Exceptions/     domain-specific failures
Jobs/           queued work
Listeners/      reactions to other domains' events
Models/         Eloquent models for this domain's tables only
Policies/       authorization; tenant isolation lives here
Queries/        read models and reporting queries
Repositories/   persistence abstractions where an interface earns its keep
Services/       orchestration that does not fit a single Action
ValueObjects/   Money, Iban, Sku, ... — always validated on construction
Http/           Controllers, Requests, Resources, Middleware
Tests/          the domain's own feature/unit tests
```

A domain directory is created when the phase that needs it starts, not upfront —
empty scaffolding hides which parts of the system are real.

`Administration/` is the reference implementation of this layout (see the health
check slice: `Enums`, `DTOs`, `Services`, `Http/Controllers`).

## 3. Non-negotiable invariants

| Area | Invariant | Enforced by |
|---|---|---|
| Money | integer minor units, never float | `Money` value object + DB integer columns |
| Payments | duplicate webhook ⇒ one financial effect | webhook inbox + idempotency keys |
| Ledger | every transaction balances to zero | double-entry lines + invariant test suite |
| Corrections | no `UPDATE`/`DELETE` on financial history | reversal entries only |
| Tenancy | seller A cannot read seller B | policies + isolation tests per endpoint |
| AI | provider/model IDs are configuration | admin-managed routing tables |
| AI credits | reserve → consume on success / release on failure | credit state machine + job lifecycle |
| PII/PCI | no PAN/CVV stored | gateway tokenisation only |

## 4. Frontend

Three Nuxt applications share one design system package, `@refconcept/ui`:

- `tokens.ts` — typed tokens for logic
- `tokens.css` — `--rc-*` custom properties (runtime source of truth)
- `theme.css` — Tailwind v4 `@theme` bridge; deletes Tailwind's default palette
- `base.css` — resets and shared primitives (`.rc-card`, `.rc-container`, `.rc-icon`)
- `components/` — cross-app components, auto-imported by every Nuxt app

Colour drift is a build failure, not a review comment: `scripts/check-design-tokens.mjs`
scans app sources for foreign hexes and deleted Tailwind palettes. See ADR-0003.

## 5. Local topology

Backend and stateful services run in Docker; Nuxt dev servers run on the host for
usable HMR on Windows. Host ports are shifted (`58000`, `55432`, `56379`, `59000`,
`58025`) so an existing XAMPP install is untouched. See ADR-0002.

## 6. Testing layers

| Layer | Tool | Scope |
|---|---|---|
| Unit | Pest | value objects, state machines, calculators |
| Feature | Pest + real PostgreSQL | endpoints, policies, migrations |
| Static | PHPStan/Larastan level 6 | types across app/config/database/routes |
| Style | Pint | strict types, strict comparisons |
| Component | Vitest | Vue components and composables |
| E2E | Playwright | the critical journeys in 15_CRITICAL_E2E_SCENARIOS.md |
| Design | token guard | approved palette only |

Financial and payment behaviour is additionally covered by invariant suites that
must pass before any phase closes.
