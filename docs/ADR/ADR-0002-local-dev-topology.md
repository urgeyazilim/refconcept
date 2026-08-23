# ADR-0002 — Local development topology: backend in Docker, Nuxt on the host

- Status: Accepted
- Date: 2026-08-23
- Phase: 0
- Supersedes: —

## Context

`19_TECH_STACK_LOCK.md` locks Laravel/PHP, PostgreSQL, Redis, S3-compatible storage,
Nuxt/Vue 3/TypeScript and Docker. The development machine is Windows 11 with:

- XAMPP present but carrying PHP 7.4 (Laravel 13 requires PHP >= 8.3)
- no PostgreSQL, no Redis
- Docker Desktop available (WSL2 backend, no user Linux distribution installed)
- Node.js 24 available
- the repository living on the Windows filesystem at `C:\xampp\htdocs\refconcept`

Three constraints pull against each other:

1. The backend needs PHP 8.3 / PostgreSQL / Redis, which the host cannot provide
   without replacing the user's XAMPP installation.
2. Docker Desktop bind mounts from a Windows path cross the WSL2 filesystem boundary.
   Measured on this machine: **one Laravel boot costs 22.8s over the bind mount and
   2.5s on the container filesystem** — a 9x penalty on every artisan command, test
   run and HTTP request, across 22 phases.
3. Nuxt dev servers with HMR over the same bind mount are equally slow and produce
   unreliable file watching.

## Decision

- **Backend and all stateful services run in Docker**: `api` (php-fpm 8.3), `nginx`,
  `postgres` (pgvector/pg16), `redis`, `minio`, `mailpit`, plus `queue` and `scheduler`
  workers. XAMPP is never used and never modified.

- **Application source lives in a named volume (`api-app`), not a bind mount.**
  The host directory `apps/api` remains the source of truth and is edited normally;
  it is pushed into the volume by `scripts/rc.ps1 sync` (one-shot `docker cp`) or
  continuously by `docker compose watch`, which is configured on the `api` service.
  Files generated inside the container come back with `scripts/rc.ps1 pull`.

- **PHP dependencies live in a second named volume (`api-vendor`).** Writing
  `vendor/` through the bind mount did not merely run slowly — `composer create-project`
  exceeded its 300s unzip timeout and failed outright.

- **Nuxt apps run on the host** with Node 24 during development, talking to the API
  over `http://localhost:58000`.

- **Nuxt apps still get Dockerfiles**, used by CI and by staging/production images, so
  the containerised path is exercised on every pipeline run rather than only at deploy.

- Host port numbers are deliberately shifted (`58000`, `55432`, `56379`, `59000`,
  `58025`) so the stack can never collide with an already running Apache/MySQL from
  XAMPP.

### Measured effect

| Operation | Bind mount | Named volume |
|---|---:|---:|
| `php artisan --version` | 22.8s | 4.3s |
| Full backend test suite (8 tests) | 104s | 32s |
| Warm `GET /api/health` | — | 0.47s |

## Alternatives considered

- **Bind-mount the source anyway.** Simplest and always in sync, but pays the 9x I/O
  penalty on every single command for the entire project.
- **Move the repository into WSL2.** The fastest option, but no user Linux distribution
  is installed, and it would relocate a project that deliberately lives under
  `C:\xampp\htdocs`.
- **Everything in Docker, including Nuxt dev servers.** Uniform, but HMR latency
  measured in seconds per change makes Phase 20 UI work impractical.
- **Everything on the host (install PHP 8.3 + PostgreSQL natively).** Fastest inner
  loop, but mutates the user's machine, diverges from CI, and makes pgvector manual.

## Consequences

- **Sync is a real step.** Editing a PHP file on the host does not change container
  behaviour until `rc.ps1 sync` runs or `docker compose watch` is active. This is the
  price of the 9x speedup and must stay visible in the developer commands and README.
- Artisan generators run inside the container write into the volume, not the host;
  `rc.ps1 pull` reconciles. In practice the code is authored on the host instead.
- Two runtimes to start locally (`docker compose up` + `npm run dev:*`), wrapped by
  `scripts/rc.ps1` / `Makefile` so it stays one command.
- CI has none of this problem (Linux runners, native filesystem) and mounts nothing —
  it checks the code out and builds images directly.

## Test plan

- `docker compose up -d` followed by `scripts/rc.ps1 status` reports all services healthy.
- `GET http://localhost:58000/api/health` returns `200` with database, pgvector, cache,
  queue, storage and migration checks passing.
- After `rc.ps1 sync`, a change made on the host is observable through the API.
- Each Nuxt app boots on the host and reaches the API health endpoint through its
  runtime config base URL.
- CI builds every Dockerfile on each push.
