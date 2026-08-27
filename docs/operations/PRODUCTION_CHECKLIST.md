# Production checklist

Everything that must be true before RefConcept serves a real customer, and everything that
must be done on each deploy afterwards.

Items marked **BLOCKED** cannot be satisfied from this repository. They need credentials,
infrastructure or a decision that belongs to whoever runs the platform. They are listed
rather than quietly omitted, because a checklist that only contains what was easy is not a
checklist.

---

## Before the first deploy

### Secrets

- [ ] `APP_KEY` generated for the environment — **not** copied from another one. It encrypts
      seller IBANs, and sharing it between environments means a staging dump is readable
      production data.
- [ ] Every credential comes from the secret manager. Nothing real in any `.env` in git.
- [ ] `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` are production storage credentials, not
      the committed local MinIO pair.
- [ ] **Rotate the Google AI key.** The one used in development has appeared in a terminal
      transcript, and a key that has been on a screen is a key to replace.
- [ ] `APP_DEBUG=false`. A debug page publishes the stack, the query and sometimes the
      parameters.
- [ ] `APP_ENV=production` — the demo seeders check this and refuse to run.

### Database

- [ ] PostgreSQL 16 with `vector`, `pg_trgm` and `citext` installed **in the target
      database**. Extensions are per database; a restore into one without them fails halfway
      through with errors that scroll past.
- [ ] Connection limits sized for the number of PHP-FPM workers, not for the number of
      containers.
- [ ] `php artisan migrate --force` — reproducible from empty, rehearsed in Phase 21.
- [ ] `php artisan db:seed --class=RolesAndPermissionsSeeder`
- [ ] `php artisan db:seed --class=PlatformSettingsSeeder`
- [ ] `php artisan db:seed --class=CommissionSeeder`
- [ ] `php artisan db:seed --class=AiGatewaySeeder`
- [ ] `php artisan db:seed --class=CreditEconomySeeder`
- [ ] `php artisan db:seed --class=SellerAgreementsSeeder`
- [ ] `php artisan db:seed --class=CatalogTaxonomySeeder`
- [ ] `php artisan db:seed --class=BankAccountSeeder` — **with the real receiving account**,
      not the demo one.

> Reference data is seeded on **every** deploy, not only the first. The role → permission map
> lives in code and the grants live in rows; a permission added to the enum and not seeded is
> a feature that fails for exactly the people it was written for. Phase 19 shipped a screen
> staff could not open for this reason, and Phase 21's rollback rehearsal reproduced it.

### Queues

- [ ] Two workers running: `--queue=payments,default` and `--queue=ai`. One worker for both
      means a payment confirmation can sit behind a ten-minute render.
- [ ] `--max-time` set so workers recycle. A worker that never restarts holds compiled code
      from the deploy before last.
- [ ] The scheduler is running. Without it nothing expires: checkout sessions, bank transfer
      references, credit expiry, settlement building.

### Storage

- [ ] Private bucket is **not** publicly readable. Room photographs, onboarding documents and
      receipts live there and are reachable only through short-lived signed links.
- [ ] Public bucket is readable and fronted by a CDN. **BLOCKED** — the cache headers are set
      (`public, max-age=31536000, immutable`); nothing is distributing them yet.
- [ ] Uploads are size-limited at the proxy as well as in the application.
- [ ] `AWS_PUBLIC_ENDPOINT` matches the host a **browser** reaches the bucket by. A signed
      URL carries the host in its signature, so a link signed for the name the API container
      uses is rejected at the name the browser uses — and the symptom is not an error page,
      it is a photograph that renders as a broken image. Leave it equal to `AWS_ENDPOINT`
      when the two are the same, which is the usual production case.

### Payments

- [ ] **BLOCKED — iyzico** (Phase 12): production merchant account, keys, sandbox
      verification, 3DS flow, reconciliation file format.
- [ ] **BLOCKED — QNB** (Phase 13): production merchant, keys, test environment, response
      mapping.
- [ ] Webhook endpoints reachable from the provider's network, and their signature secrets
      configured. An unverified notification is stored and never acted on.
- [ ] The receiving bank account in `payment_bank_accounts` is the real one. It is published
      on the checkout page — a documented, deliberate exception to the IBAN rule.

### Mail

- [ ] A real transport. Mailpit is a development catcher and swallows everything.
- [ ] SPF, DKIM and DMARC published. Verification e-mails that land in spam are accounts that
      never activate.
- [ ] The from-address is a mailbox somebody reads.

### Web

- [ ] `NUXT_PUBLIC_SITE_URL` set per app. It builds every canonical URL and the sitemap; a
      wrong value points crawlers at a host that is not the site.
- [ ] `NUXT_PUBLIC_API_BASE` points at the production API.
- [ ] TLS everywhere. HSTS is sent automatically over HTTPS.
- [ ] CORS lists the real origins, not `*`.

### Observability

- [ ] Logs shipped somewhere searchable and queryable by `request_id`.
- [ ] Alerts on: `/api/health` failing, failed job count rising, webhook queue depth,
      `refconcept:reconcile-payments` exiting non-zero.
- [ ] **BLOCKED** — the alert destination is an operational decision.

### Backups

- [ ] `pg_dump` on a schedule, shipped off-host.
- [ ] `bash scripts/backup-drill.sh` run against a real backup before launch. A backup nobody
      has restored is a hope.
- [ ] Object storage versioning or replication enabled. The database can be restored; a
      deleted room photograph cannot.

---

## On every deploy

```text
1. migrate --force
2. seed reference data (roles, settings, commission, AI routes, agreements, taxonomy)
3. restart both queue workers        # they hold compiled code in memory
4. php artisan refconcept:openapi --check
5. php artisan refconcept:verify-ai-models   # the model codes still exist at the provider
6. smoke: /api/health, sign in, open the catalogue, add to basket
```

Step 3 is not optional and is the one most often forgotten: a worker that is not restarted
runs the previous release's code against the new release's database.

---

## Limited release

The platform is a marketplace, which means it is empty until sellers are on it and useless
to sellers until customers are on it. So the order is fixed:

1. **Sellers first, by invitation.** Onboard a handful by hand, watch the first products
   through moderation, and confirm the first payout end to end before anybody is shopping. A
   settlement that goes wrong with three sellers is a conversation; with three hundred it is
   an incident.
2. **Bank transfer before cards.** It is the payment method that works today and the one
   whose failure mode is a person checking a statement rather than a gateway integration.
   Card payments open when iyzico or QNB is live and verified in sandbox.
3. **Credits with a low ceiling.** AI spend is real money per job. Start with the smallest
   package, watch cost per job in the AI console for a week, and raise it deliberately.
4. **Feature flags are the throttle.** `ai.design-generation`, `checkout.bank-transfer` and
   `seller.self-onboarding` can each be closed in one click without a deploy — and a partial
   rollout keeps a given user on the same side of the line rather than moving them.
5. **Reconciliation daily from day one.** Not from the first problem: the whole point is that
   the problem it catches is invisible until somebody is owed money.

---

## Rollback

The application rolls back by deploying the previous image. The database usually does not.

- **Migrations are not automatically reversible in production.** Every migration has a
  `down()` and rehearsing a rollback locally is required (Phase 21 did), but a `down()` that
  drops a column drops the data in it. Prefer rolling forward.
- **After any rollback that touched the schema, re-seed reference data.** A re-applied
  migration comes back empty; Phase 21's rehearsal proved it by breaking the E2E run.
- **Never roll back across a ledger migration** without engineering. The journal is
  append-only and its balance is enforced by a deferred constraint trigger; a schema change
  underneath it is not a deployment operation.
- Restore from backup only as the last step, and only after `scripts/backup-drill.sh` has
  been run against that specific backup.
