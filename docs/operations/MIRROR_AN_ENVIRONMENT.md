# Making one environment a copy of another

For the case where a deployed server has to hold exactly what a development machine holds —
the same products, the same 3D models, the same accounts, the same rooms — because the thing
being tested is the whole system and not a fresh one.

`refconcept:seed-demo` is the other answer and usually the better one: it stands up a
testable catalogue in five seconds with no data transfer and no secrets leaving anybody's
machine. Use this when that is not enough.

---

## What travels

| | |
|---|---|
| Database | every row: accounts, projects, rooms, designs, orders, the ledger, the AI job history |
| `refconcept-private` | room photographs, renders, layout snapshots, seller documents |
| `refconcept-public` | product photographs and 3D models |

About a gigabyte, most of it 3D models. Nothing is filtered — that is what "copy" means here,
and a partial copy produces a system that looks whole and is not.

## The one decision a script cannot make: `APP_KEY`

Four columns are encrypted with it:

- `ai_provider_credentials.secret_encrypted` — the OpenAI, Google and fal.ai keys
- `seller_bank_accounts.iban_encrypted`
- `sellers.iyzico_submerchant_key`, `sellers.qnb_merchant_reference`

Restored under a different `APP_KEY` they arrive as bytes nothing can read. There is no error
and no warning: the AI answers "no credential" the first time somebody runs a design, and an
IBAN fails to decrypt on the day somebody is paid.

**Two ways out, and they are not equal.**

**Give the target the source's `APP_KEY`.** Everything decrypts, including the AI keys, so
nothing has to be typed anywhere and no key is ever pasted into a terminal or a chat window.
Right for a test server that is meant to *be* the copy. Wrong for a server that will hold
real customers, because two environments sharing a key means a dump from one is readable
production data from the other — which is why
[the production checklist](PRODUCTION_CHECKLIST.md) says to generate a fresh one.

The value is in `apps/api/.env` on the source, as `APP_KEY=base64:…`. Copy it into the
target's environment. It does not need to pass through anybody's screen on the way.

**Or give the target its own key and re-seed the credentials.** Set `OPENAI_API_KEY`,
`GOOGLE_AI_API_KEY` and `FAL_API_KEY` in the target's environment and run
`php artisan db:seed --class=AiGatewaySeeder` after the restore: it reads them from the
environment and encrypts them with the key the target has. The seller IBANs stay unreadable,
which for demo sellers costs nothing and for real ones costs a great deal.

The import script says which of the two happened, by trying to decrypt one credential and
reporting the result. It is the only check that catches this before a customer does.

---

## The procedure

**On the source:**

```bash
bash scripts/export-environment.sh mirror
```

Writes `mirror/` — `database.dump`, `storage/`, and a manifest naming the commit it came
from.

**Move it.** It is about a gigabyte and it contains customers' room photographs, password
hashes and encrypted bank details. Over `scp`, or `rsync -avz --progress`, to somewhere only
the target's operator can read:

```bash
rsync -avz --progress mirror/ user@server:/opt/refconcept-mirror/
```

Not through a file-sharing service, not through a chat, and not left on either machine
afterwards.

**On the target:**

```bash
bash scripts/import-environment.sh /opt/refconcept-mirror
```

It asks once, in words, before dropping the database. Then it restores, replaces both
buckets, clears the caches and reports whether the encrypted columns opened.

**Afterwards:**

```bash
rm -rf mirror                      # on the source
rm -rf /opt/refconcept-mirror      # on the target
```

---

## What is still not the source afterwards

- **Queue workers** hold compiled code and an open connection. Restart them, or the first job
  after a restore runs against a database that has been replaced underneath it.
- **Scheduled work** resumes where the restored rows left off: checkout sessions that expired
  on the source expire again here, and settlement building may rebuild what was already
  built. On a test server that is the point; anywhere else, read what the scheduler does
  before letting it run.
- **Domains** differ, so any absolute URL stored in a row still names the source. Signed
  storage links are generated per request and are fine; anything written into a row is not.
- **The mail transport** is whatever the target's environment says. If that is Mailpit, every
  address in the restored database is safe by accident rather than by design — check it
  before restoring a database full of real addresses onto anything that can actually send.
