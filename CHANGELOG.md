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

### Added — Phase 1 (Identity / RBAC / Organizations)

- Identity schema on UUIDv7 primary keys with `citext` e-mail, UTC timestamps, database
  CHECK constraints on every status column and partial unique indexes guaranteeing one
  default shipping and one default billing address per customer.
- Authentication API: registration with KVKK consent capture, login issuing Sanctum
  tokens, session records per device, logout and logout-everywhere, `GET /auth/me`.
- E-mail verification and password reset built on single-use SHA-256 hashed tokens.
  Redeeming a reset revokes every live token and closes every session.
- RBAC: 12 seeded permissions and 5 system roles, platform- and organization-scoped
  grants with expiry, and an `AccessControl` service that answers membership and
  permission separately.
- Organizations as the tenant boundary, with an `OrganizationPolicy` that decides
  seller-to-seller isolation in one place.
- Append-only `audit_logs`, immutability enforced by a PostgreSQL trigger, written by an
  `AuditLogger` that redacts passwords, tokens, card data and IBANs.
- Customer profile and address book with ownership policies and a verified-e-mail gate.
- Rate limiters for login, registration, password reset and verification resend, keyed by
  e-mail **and** IP so one attacker cannot lock out a victim by failing their login.
- 78 backend tests / 235 assertions, including 15 tenant isolation cases.

### Fixed — Phase 1

- **Every authenticated route returned 500.** A stale `bootstrap/cache/packages.php` left
  on the host was pushed into the container on each sync, hiding Sanctum and removing its
  auth guard. The sync scripts now clear the compiled cache on both push and pull.
- `config/auth.php` pointed at the framework's `App\Models\User` and defined no Sanctum
  guard; rewritten for the Identity domain model.
- Model factories could not resolve for domain-namespaced models
  (`Factory::guessFactoryNamesUsing`).
- `email:rfc,dns` performed a live MX lookup on every registration, breaking the test
  suite and blocking `*.local` development accounts; extracted to configuration.

### Added — Phase 2 (Seller Onboarding)

- Seller applications kept separate from approved sellers, so a rejection stays on
  record with its reason and an approval preserves what was reviewed. One open
  application per applicant, enforced by a partial unique index.
- `Iban` value object: constructs only from a value passing the ISO 13616 mod-97
  check, stored encrypted with a masked display value and a keyed fingerprint for
  duplicate detection.
- Onboarding checklist derived from the data rather than stored as flags, driving both
  the portal's progress bar and the server-side submission guard from one
  implementation.
- Required documents follow the taxpayer type, so a sole proprietor is not asked for a
  trade registry gazette.
- Versioned agreements with immutable, checksummed acceptances — enforced by a
  database trigger as well as in PHP.
- `ApplicationWorkflow` as the single place status changes. Approval creates the
  organization, seller, membership and role grant in one transaction.
- Suspension, reactivation and commission changes demand a reason and are recorded in
  both `seller_status_history` and the audit log.
- Onboarding documents on the private disk under random keys, served by short-lived
  signed URL after a policy check.
- Seller portal: sign-in, dashboard and the full onboarding wizard.
- Super admin: review queue, application review with document decisions, and seller
  administration.
- `refconcept:grant-role` console command for bootstrapping the first operator; there
  is deliberately no HTTP endpoint that grants platform roles.
- Shared API and auth composables moved into `@refconcept/ui` so all three apps talk
  to the backend identically.

### Fixed — Phase 2

- **Every primary call to action on the storefront navigated nowhere.** `RcButton`
  rendered `<component is="NuxtLink">` by string name, which only resolves against
  locally registered components — and the component lives in a shared package. Found
  by an end-to-end click; screenshots looked perfect.
- Database status defaults were not reflected on freshly created models.
- `Artisan::starting()` does not exist in Laravel 13 and broke every artisan command;
  commands are now registered through `withCommands()`.
