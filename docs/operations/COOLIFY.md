# Deploying RefConcept with Coolify

The concrete procedure, from an empty Ubuntu server to a site answering on four domains.
`DEPLOYMENT.md` next door describes the *shape* of a deploy and why the order is what it is;
this describes the buttons.

Written to be followed by somebody who did not build the system, and to be re-followed six
months later when the second server is set up and nobody remembers the first one.

---

## What you need before starting

| | |
|---|---|
| Server | Ubuntu 24.04, root access, **4 vCPU / 8 GB minimum**, 16 GB comfortable |
| Domain | One, with DNS you can edit |
| Object storage | An S3-compatible provider — two buckets |
| SMTP | Host, port, username, password, and a `From` address on your own domain |

**The memory figure is not padding.** PostgreSQL, Redis, PHP-FPM, nginx, three Nuxt
processes, two queue workers and Coolify itself share this machine, and the AI worker holds
its memory for the two or three minutes a render takes. At 4 GB it runs and it will feel it
during a render; at 8 GB it does not.

---

## 1. The server

```bash
ssh root@YOUR_SERVER_IP
apt update && apt upgrade -y
```

Then Coolify, which installs Docker if it is not already there:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Open `http://YOUR_SERVER_IP:8000` and create the first administrator account. **Do this
within minutes of the install finishing** — until that account exists, the registration page
is open to anybody who finds the port.

The onboarding then offers to connect a server over SSH. **Skip it.** Coolify registered the
machine it is running on as `localhost` when it installed, and that is what you deploy to.
The SSH flow is for adding a *second*, separate machine later.

### Firewall

Open 22, 80 and 443. Nothing else needs to be reachable from outside — the compose file
publishes no ports, so PostgreSQL and Redis are only visible on the private Docker network.

Port 8000 is the Coolify panel and is the one exception during setup. Close it as soon as
the panel has its own domain (below): a management panel on plain HTTP is a session token
travelling in clear text.

```bash
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp
ufw allow 8000/tcp        # temporary — see below
ufw --force enable
```

### The panel's own domain

In Coolify: **Settings → General → Instance Domain**, set `panel.example.com`. Coolify puts
its own panel behind its proxy and issues a certificate for it. Then:

```bash
ufw delete allow 8000/tcp
```

---

## 2. DNS

Five `A` records, all pointing at the server's IP:

| Name | Serves |
|---|---|
| `@` | storefront — the customer's shop |
| `satici` | seller portal |
| `yonetim` | super-admin panel |
| `api` | the Laravel API |
| `panel` | Coolify itself |

`www` as a `CNAME` to `@` if you want it.

**Leave the mail records alone.** If `MX` and `mail.` point at an existing host, that is where
mail should keep going — the application only sends through SMTP and does not receive.

Wait for propagation before deploying. Coolify requests certificates during the deploy, and
Let's Encrypt refuses a name that does not yet resolve to this machine — which fails the
deploy for a reason that looks like a Docker problem and is not.

```bash
dig +short example.com satici.example.com yonetim.example.com api.example.com
```

All four must print the server's IP.

---

## 3. Object storage

Two buckets. They are different in kind, not just in name, and the split is a privacy
boundary rather than an organisational one.

| Bucket | Holds | Access |
|---|---|---|
| `refconcept` | Room photographs, renders, room tours, seller onboarding documents | **Private.** No public read, ever. Served only through short-lived signed links, after an ownership check |
| `refconcept-public` | Product imagery | Public read. These are catalogue photographs a seller published on purpose |

**Never make the first bucket public, not even briefly, not even to debug something.** It
holds photographs of the inside of customers' homes and scans of sellers' identity documents.
A bucket that was public for an hour is a bucket that may have been indexed.

### Cloudflare R2

Create both buckets, set the location hint to **EU** if your customers are in Europe or
Turkey, then create an API token with **Object Read & Write** scoped to them.

Enable public access on `refconcept-public` only, and note its public URL — that becomes
`AWS_URL`. A custom domain (`cdn.example.com`) is nicer than the generated `r2.dev` address
and is set up in the bucket's settings.

R2 needs `AWS_DEFAULT_REGION=auto` and `AWS_USE_PATH_STYLE_ENDPOINT=true`.

---

## 4. The Coolify resource

**+ New → Docker Compose → Public/Private Repository**, point it at this repository, and set:

| Field | Value |
|---|---|
| Branch | `main` |
| Docker Compose Location | `/docker-compose.production.yml` |
| Build Pack | Docker Compose |

Coolify reads the file and lists the ten services. Four of them declare a `SERVICE_FQDN_*`
variable, which is how Coolify knows to offer a domain field for each:

| Service | Domain |
|---|---|
| `nginx` | `https://api.example.com` |
| `storefront` | `https://example.com` |
| `seller-portal` | `https://satici.example.com` |
| `admin-panel` | `https://yonetim.example.com` |

Nothing else gets a domain. `postgres`, `redis`, `api`, `queue`, `queue-ai` and `scheduler`
are internal, and giving one of them a domain would publish it.

---

## 5. Environment variables

In the resource's **Environment Variables** tab. Everything here is a secret or an address;
none of it belongs in the repository.

Generate the application key first, on your own machine or on the server:

```bash
docker run --rm refconcept-api php artisan key:generate --show
```

**`APP_KEY` is not a password you can change later.** It encrypts provider credentials and
seller payout IBANs at rest; rotating it makes every one of those columns unreadable. Store
it somewhere you will still have in two years.

| Variable | Value | Notes |
|---|---|---|
| `APP_KEY` | `base64:…` | Generated above. Never rotate casually |
| `APP_URL` | `https://api.example.com` | The API's own address |
| `REFCONCEPT_STOREFRONT_URL` | `https://example.com` | Also the CORS allow-list |
| `REFCONCEPT_SELLER_PORTAL_URL` | `https://satici.example.com` | " |
| `REFCONCEPT_ADMIN_PANEL_URL` | `https://yonetim.example.com` | " |
| `POSTGRES_PASSWORD` | long random | |
| `REDIS_PASSWORD` | long random | Optional but set it |
| `AWS_ACCESS_KEY_ID` | from the provider | |
| `AWS_SECRET_ACCESS_KEY` | from the provider | |
| `AWS_ENDPOINT` | `https://<account>.r2.cloudflarestorage.com` | |
| `AWS_URL` | public bucket's read URL | Product imagery only |
| `AWS_BUCKET` | `refconcept` | The private one |
| `AWS_PUBLIC_BUCKET` | `refconcept-public` | |
| `MAIL_HOST` / `MAIL_PORT` | your SMTP | 587 with `tls`, or 465 with `ssl` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | your SMTP | |
| `MAIL_FROM_ADDRESS` | `noreply@example.com` | Must be on a domain you control |
| `OPENAI_API_KEY` | | Renders. Without it the design engine falls back and produces worse rooms |
| `GOOGLE_AI_API_KEY` | | Room analysis, plans, embeddings, room tours |

The three `REFCONCEPT_*_URL` values are the CORS allow-list as well as the links inside
outgoing mail. Get one wrong and that application loads, looks fine, and every request it
makes is refused by the browser — which reads as a broken site with no error message
anywhere on the server.

### Mail deliverability

If you are sending through a shared hosting SMTP, publish these on the sending domain or
verification mail will go to spam and customers will simply never finish signing up:

- **SPF** — `v=spf1 include:<your host's spf> ~all`
- **DKIM** — from the hosting panel's mail settings
- **DMARC** — `v=DMARC1; p=none; rua=mailto:you@example.com` to start

Send yourself a registration mail after the first deploy and check where it lands. This is
the single most common thing that is broken on launch day and the least visible.

---

## 6. Deploy

Press **Deploy**. The first one takes several minutes: two PHP stages, three Nuxt builds.

What happens, in order:

1. `postgres` starts and the init script installs `vector`, `pg_trgm` and `citext`
2. `redis` starts
3. `api` waits for both, then **runs migrations and re-seeds reference data** in its
   entrypoint, under a lock, before PHP-FPM accepts a request
4. `queue`, `queue-ai` and `scheduler` wait for `api` to report healthy
5. `nginx` and the three Nuxt applications come up and get their certificates

Reference data is re-seeded on **every** deploy, deliberately. The role to permission map
lives in code and the grants live in rows, and the seeder reconciles them with `sync()` — so
a permission removed from the enum is removed from the role too. A release once shipped a
screen staff could not open because the code was right and the environment was stale.

### Smoke test

```bash
curl -s https://api.example.com/api/health | jq
```

Expect `200`. It probes the database, pgvector, cache, queue, storage and migrations
individually, and returns `503` naming whichever one is unhappy. A `503` here is the fastest
diagnosis available — read it before looking at logs.

Then, by hand:

- Open the storefront. Register. **Does the verification mail arrive?**
- Open `satici.` and `yonetim.` — both should load and refuse to let you in
- Sign in, create a project, upload a room photograph
- Watch it in `queue-ai`'s logs: `docker logs -f refconcept-queue-ai-1`

### The first administrator

No HTTP endpoint grants platform roles, by design — a privilege-escalation bug in a
controller cannot exist if no controller can do it. The console can:

```bash
docker exec -it refconcept-api-1 php artisan refconcept:grant-role you@example.com super_admin
```

---

## 7. Afterwards

### Deploying a change

Push to `main`. Coolify rebuilds and restarts. Migrations run in the API's entrypoint before
it serves anything, so a schema change and the code that needs it arrive together.

For anything that is not purely additive — a renamed column, a narrowed type, a dropped
table — read the expand/contract section of `DEPLOYMENT.md` first. A single release that
renames a column takes the site down for the length of the deploy, because for that window
half the running processes disagree about the schema.

### Staging

When the project is busy enough that trying things in production stops being acceptable,
create a **second Coolify resource from the same repository** on a `staging` branch, with its
own domains (`test.example.com`, …) and its own database. It costs nothing but disk on the
same server, and it is the difference between finding out here and finding out in front of a
customer.

Keep the two environments' variables separate. A staging deployment pointed at the
production database is not staging.

### Rolling back

Coolify keeps previous deployments; redeploy an earlier one. **This does not undo
migrations.** If the release included a destructive schema change, the old code will run
against the new schema — which is exactly why migrations are backward-compatible by default.

Never roll a ledger migration backwards without thinking hard. The credit journal is
append-only and its balance is enforced by a deferred constraint trigger.

### Backups

The database holds a financial ledger. Losing it is not an inconvenience.

Coolify can back up its own managed database resources on a schedule; this compose file runs
its own PostgreSQL, so set up a nightly dump:

```bash
docker exec refconcept-postgres-1 pg_dump -U refconcept refconcept | gzip > refconcept-$(date +%F).sql.gz
```

Put it on a cron and copy the result **off this machine** — a backup on the disk it is
protecting is not a backup. Restore one somewhere else once, before you need to, so you know
the file is good and how long it takes.

### Watching it

- `docker logs -f refconcept-queue-ai-1` — renders and room tours, the slow and expensive work
- `docker logs -f refconcept-queue-1` — payments and notifications, the work somebody is waiting on
- `/api/health` — from an uptime monitor, every minute
- Coolify's own notification settings — get told when a deploy fails, not by a customer

---

## Known gaps at first launch

State them out loud rather than discovering them under pressure:

- **Card payment does not work.** iyzico and QNB are unfinished. Bank transfer is the only
  live method, and it is manual: somebody confirms each payment in the admin panel.
- **The renderer adds small decorative objects** that are not in the shopping list — a vase, a
  stack of books. The design page says so; it is not yet fixed.
- **Room tours are eight seconds** and the camera walks into the room and turns. It is not a
  360° orbit, and cannot be one from a single photograph without the model inventing the wall
  behind the camera.
