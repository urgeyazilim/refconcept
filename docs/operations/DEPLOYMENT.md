# Deployment

How RefConcept is built, shipped and rolled back. The environment-specific values live in
`PRODUCTION_CHECKLIST.md`; this describes the shape.

---

## What ships

Four deployable things, from one repository:

| Artefact | What it is | Depends on |
|---|---|---|
| `api` | Laravel 13 on PHP 8.3-FPM, behind nginx | PostgreSQL 16 + pgvector, Redis, S3-compatible storage, SMTP |
| `queue` | `queue:work --queue=payments,default` | the same |
| `queue-ai` | `queue:work --queue=ai` | the same, plus the AI provider |
| `scheduler` | `schedule:work` | the same |
| `storefront`, `seller-portal`, `admin-panel` | Nuxt 4 in Node, server-rendered | the API only |

The three Nuxt apps are separate origins and talk to the API over HTTP like any other
client. They hold no database credentials and no provider keys.

**Two queue workers, not one.** An AI render can hold a worker for ten minutes; a payment
confirmation queued behind one is a customer watching a spinner for reasons that have
nothing to do with their payment. Running only `queue` would restore that behaviour
silently.

---

## Order of operations

```text
build images
  ↓
deploy api (migrations not yet run)
  ↓
php artisan migrate --force
  ↓
seed reference data
  ↓
restart queue, queue-ai, scheduler
  ↓
deploy the three Nuxt apps
  ↓
smoke
```

Three things about this order are not arbitrary.

**Migrations run after the API is deployed and before workers restart.** A worker holding
the previous release's compiled code against the new schema is the failure that looks like
data corruption and is not.

**Reference data is seeded on every deploy.** The role → permission map lives in code and
the grants live in rows, and `RolesAndPermissionsSeeder` reconciles them — with `sync()`, so
a permission removed from the enum is removed from the role too. Phase 19 shipped a screen
staff could not open because the code was right and the environment was stale.

**The Nuxt apps go last.** They are the only part a customer sees; deploying them before the
API they call is a window where the site is newer than its own backend.

---

## Migrations

Backward-compatible by default. A migration that only adds is safe to run before the code
that uses it, which is what makes the order above possible.

For anything else — a renamed column, a narrowed type, a dropped table — the expand/contract
sequence applies and takes two releases:

1. **Expand.** Add the new shape. Write to both. Deploy.
2. **Backfill.** Migrate the data. Verify.
3. **Contract.** Stop writing the old shape, then drop it. Deploy.

A single release that renames a column takes the site down for the length of the deploy,
because for that window half the running processes disagree about the schema.

**Never roll a ledger migration backwards** without engineering. The journal is append-only
and its balance is enforced by a deferred constraint trigger; changing the schema underneath
it is not a deployment operation.

---

## Rollback

The application rolls back by redeploying the previous image. Assume the database does not
roll back with it.

- Prefer **rolling forward**. A `down()` that drops a column drops the data in it, and
  "we can roll back" is usually a statement about the code only.
- **After any rollback that touched the schema, re-seed reference data.** A re-applied
  migration comes back empty — Phase 21's rehearsal proved it by breaking the E2E run.
- Restoring from backup is the last step, not the first, and only after
  `scripts/backup-drill.sh` has been run against that specific backup.

---

## Configuration

Everything is environment variables. Nothing real is committed.

- `apps/api/.env.example` names every variable the API reads. It ships placeholders and the
  local MinIO pair, which is the password of a container listening on localhost.
- `NUXT_PUBLIC_API_BASE` and `NUXT_PUBLIC_SITE_URL` are per-app and per-environment. The
  second builds every canonical URL and the sitemap, so a wrong value points crawlers at a
  host that is not the site.
- Config is cached in production (`config:cache`). A variable read at runtime rather than
  through `config()` will be missing — that is why every read goes through `config()`.

---

## Health and readiness

`GET /api/health` probes database, pgvector, cache, queue, storage and migrations, and names
each one. It answers 503 when a critical dependency is down, which makes it a readiness
probe rather than a liveness one — a container that cannot reach PostgreSQL should stop
receiving traffic, not be killed and restarted into the same problem.

`GET /up` is the plain liveness probe: the process is running.

---

## After every deploy

```bash
php artisan refconcept:openapi --check        # the contract still matches the routes
php artisan refconcept:verify-ai-models       # the model codes still exist at the provider
curl -s https://<host>/api/health | jq '.status'
```

Then a human smoke: sign in, open the catalogue, add something to a basket. Automated checks
answer "is it up"; those three answer "is it usable", and they are not the same question.

---

## Scheduled work

The scheduler must be running or nothing expires:

| Command | Why it matters if it stops |
|---|---|
| `refconcept:expire-checkout-sessions` | Stock stays held for baskets nobody is paying for |
| `refconcept:expire-bank-transfers` | The same, for transfers nobody completed |
| `refconcept:sweep-expired-credits` | Customers keep credits they should have lost |
| `refconcept:build-settlements` | Sellers are never paid |
| `refconcept:reconcile-payments` | Nobody finds out the books and the provider disagree |

The last one exits non-zero on a critical finding, which is what an alert should watch.
