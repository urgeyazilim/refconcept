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
