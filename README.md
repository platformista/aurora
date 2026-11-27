# aurora-demo

A small, fictitious social-selling platform built to show 2R Studio what running
a Laravel codebase on **Upsun Cloud (Flex)** looks like: write code, push, and the
platform runs the web servers, workers, queues, crons, databases, broker and
storage underneath.

It is a demo, not a production system. All names, products and data are invented.
No real brand appears anywhere.

- Runbook for the session (Italian): [DEMO.md](DEMO.md)
- Assumptions and points the documentation did not confirm: [ASSUMPTIONS.md](ASSUMPTIONS.md)

## Contents

1. [Architecture](#architecture)
2. [How messaging survives a broker outage](#how-messaging-survives-a-broker-outage)
3. [Commission run](#commission-run)
4. [From servers to one YAML file](#from-servers-to-one-yaml-file)
5. [Local development](#local-development)
6. [Deploying to Upsun](#deploying-to-upsun)
7. [Manual setup steps](#manual-setup-steps)
8. [Splitting into three projects](#splitting-into-three-projects)
9. [Tests and CI](#tests-and-ci)

## Architecture

One Git repository, one Upsun project, three applications deployed side by side
(Upsun "multi-app"). Each app has its own directory, build, database schema and
workers, and can be resized independently.

```
                    https://{default}/
                           │  (Upsun router)
                           ▼
 ┌──────────────────────────────────────┐   HTTP, internal network   ┌───────────────────────┐
 │ nfw  — NFW/Bridge (Inertia + React)  │ ─────────────────────────▶ │ promotions            │
 │ web · horizon · commissions · relay  │   http://promotions.internal│ web · relay · consumer│
 │ consumer · cron                      │ ─────────────┐             └──────────┬────────────┘
 └───────┬──────────────────────┬───────┘              │ http://oms.internal    │
         │                      │                      ▼                        │
         │                      │        ┌───────────────────────────┐          │
         │                      │        │ oms                        │          │
         │                      │        │ web · horizon · relay ·    │          │
         │                      │        │ consumer                   │          │
         │                      │        └──────┬───────────┬────────┘          │
         ▼                      ▼               ▼           ▼                   ▼
   MariaDB 11.8            Kafka 4.3        Valkey 8.1   Object storage     (same services)
   schemas nfw/oms/        topics           queues,      invoice PDFs
   promotions, one user    aurora.orders    cache,       (oms bucket)
   per app                 aurora.shipments sessions,
                           aurora.promotions demo switch
```

| App | Role | Public route | Workers |
|---|---|---|---|
| `nfw` | Seller portal and back office: sellers (`incaricate`) in a hierarchy, catalogue of 20 products, order form, "my network", "my commissions", demo panel | `https://{default}/` | `horizon`, `commissions`, `relay`, `consumer` + cron |
| `oms` | Consumes `order.placed`, creates the shipment, generates the invoice PDF, publishes `order.shipped` | none (internal only) | `horizon`, `relay`, `consumer` |
| `promotions` | `POST /api/quote` prices a basket (three rules); consumes `order.placed` to keep a redemption ledger and publishes `promotion.redeemed` | none (internal only) | `relay`, `consumer` |

### Order flow

1. The seller fills in the order form. NFW calls the Promotion Engine
   **synchronously** at `http://promotions.internal/api/quote`. That host name
   comes from an app-to-app relationship (`endpoint: http`) in
   `.upsun/config.yaml`; the Promotion Engine has no public route at all.
2. NFW writes the order, its lines and an `order.placed` event to
   `outbox_events` **in one transaction**, and returns. Kafka is not involved in
   the request.
3. NFW's `relay` worker publishes the event to `aurora.orders`.
4. OMS's `consumer` creates the shipment and an `order.shipped` outbox row (same
   transaction), then queues the invoice job on Horizon. OMS's `relay` publishes
   `order.shipped`.
5. NFW's `consumer` marks the order as shipped. The seller's "I miei ordini" page
   polls every 3 seconds, so the status flips in front of the audience.

The promotion rules are fictitious: 10% off above €80; a free mini lip balm with
the night cream; 15% off when the basket has three or more body-care items.

The synchronous call is a deliberate trade-off. If the Promotion Engine is down,
NFW refuses the order with a clear message rather than charging the wrong
price. A real system might fall back to list prices and reconcile later.

## How messaging survives a broker outage

Upsun Cloud's Kafka is one service container per environment, not a
three-node cluster. The design goal is therefore: **a broker outage causes
delay, never lost or duplicated business effects.** Four pieces deliver it.
They live in `apps/*/app/Messaging` (identical in the three apps; see
[bin/sync-messaging.sh](bin/sync-messaging.sh)).

**1. Transactional outbox** (`Outbox.php`). The business change and the event
row are committed together. `Outbox::record()` refuses to run outside a
transaction. If the transaction rolls back, there is no event; if it commits,
the event exists and will be published eventually. Taking an order never
depends on the broker.

**2. Relay** (`Relay.php`, `RelayCommand.php`, one `relay` worker per app).
Reads pending rows oldest first, produces them with `acks=all` and
`enable.idempotence=true`, and marks a row as sent **only after librdkafka's
delivery report confirms it**. On failure it records the error, keeps the row,
and backs off as a whole (1, 2, 4… 30 s), so events keep their order when the
broker returns. This is at-least-once delivery: if the process dies between the
broker's ack and the `UPDATE`, the event is sent again.

**3. Idempotent consumers** (`IdempotentConsumer.php`, `ConsumeCommand.php`).
Every event carries an ID generated once, when the outbox row was written. The
consumer inserts that ID into `processed_events` in the same transaction as the
handler's writes. A redelivered event finds its ID and is skipped. The Kafka
offset is committed only after that transaction commits. The OMS and
Promotion Engine handlers are also idempotent on their own (one shipment per
order, unique `(order, rule)` redemption), as a second line of defence.

**4. Workers restart themselves.** If a consumer hits an error it exits without
committing the offset. Upsun restarts the worker, and it resumes from the last
committed offset.

Result: after any outage, every order reaches the OMS **exactly once in effect**.
The demo panel shows "orders sent by NFW" against "shipments created by OMS",
and the duplicates skipped.

### The outage switch

`/admin` → **"Simula broker non disponibile"**. It sets a key in the shared
Valkey. While it is on, every relay connects to `127.0.0.1:1`, where nothing
listens, so librdkafka fails for real (connection refused, `Message timed out`)
instead of the code skipping the publish. Orders keep being accepted; the
outbox backlog on the panel grows. Switch it off: the relays reconnect, the
backlog drains, and the counters meet.

It is labelled as a simulation in the UI: you cannot stop a managed service on
demand, and the Kafka container keeps running throughout.

Tested locally against Redpanda: 40 orders taken during the simulated outage,
40 pending events, drained in about two seconds after recovery, 44 orders and 44
shipments, no duplicates.

## Commission run

`php artisan commissions:run --period=current` (also the nightly cron and the
"Calcola ora" button) queues a run on the dedicated `commissions` Horizon queue:

1. `PrepareCommissionRun` loads the hierarchy (50,000 sellers) and the sellers
   who qualify in the cycle, and splits the cycle's orders into chunks of
   10,000.
2. `ComputeCommissionChunk` jobs run **in parallel** (a Laravel batch). Rules:
   10% personal commission; 6/4/3/2/1% to the first five *active* uplines, with
   dynamic compression (inactive uplines are skipped without using up a level).
   Sales cycles are three weeks long.
3. `FinalizeCommissionRun` merges the chunks, writes one row per beneficiary and
   records the duration shown on the demo panel.

The `commissions` worker runs a fixed pool of 8 Horizon processes and uses the
`BALANCED` container profile, because 8 PHP processes need about 520 MB and PHP's
default `HIGH_CPU` profile gives 224 MB at 0.5 CPU. The jobs are CPU-bound, so
the run time follows the CPU given to that container.

**Calibration, stated plainly:** the real rules are cheap (about 2.5 million
integer operations for 400,000 orders). `COMMISSION_COMPLEXITY` (default 200)
evaluates the rule chain that many times per order. It stands in for a richer
rule set, so that resizing the worker visibly changes the run time. It does not
change the result, and a test checks that. Measured locally on the full seed
(400,181 orders in the cycle): about 37 s of CPU in total. That projects to about
75 s at 0.5 vCPU and under 20 s at 2 vCPU on Upsun; confirm on the first deploy.

## From servers to one YAML file

| On a VM | In this repository |
|---|---|
| Nginx/Apache + PHP-FPM | `web.locations` in `.upsun/config.yaml` |
| Supervisor programs (`horizon`, consumers…) | `workers:` — one container per process, restarted if it exits; SIGTERM, then SIGKILL after 15 s on deploy |
| crontab | `crons:` (runs on the web container) |
| MariaDB server, users, grants | `services.mariadb` with `schemas` and `endpoints` |
| Redis | `services.valkey` (`valkey-persistent`) |
| Kafka/Redpanda cluster | `services.kafka` |
| S3 bucket + credentials | Object storage, enabled with `upsun resources:set --object-storage` |
| `.env` per server | `.environment` per app, mapping the variables Upsun generates from relationships; secrets as project variables |
| Deploy script | `hooks.build` (no services, builds the image) and `hooks.deploy` (migrations) |
| Staging copies | One environment per Git branch / pull request, cloned with data |
| OS, PHP and service patching, backups | The platform |

## Local development

Requirements: PHP 8.3+ with `rdkafka`, `redis`, `pdo_mysql` and `pcntl`;
Composer; Node 24; Docker.

```bash
docker compose up -d                          # MariaDB (3 databases), Valkey, Redpanda
for app in nfw oms promotions; do
  (cd apps/$app && composer install && cp .env.example .env && php artisan key:generate && php artisan migrate)
done
(cd apps/nfw && npm ci && npm run build && php artisan db:seed && php artisan aurora:kafka-topics)
bin/dev.sh                                    # 3 web servers + all workers, logs in local/logs/
```

Open http://127.0.0.1:8001 (demo panel at `/admin`, Horizon at `/horizon`).
For the full volume: `php artisan aurora:seed-volume` in `apps/nfw` (about 30 s
locally).

Tests: `php artisan test` in each app (SQLite in memory by default; set
`DB_CONNECTION=mariadb` and the `DB_*` variables to run them on MariaDB, as CI
does). If you change `apps/nfw/app/Messaging` or `apps/nfw/tests/Messaging`,
run `bin/sync-messaging.sh`.

## Deploying to Upsun

Everything the platform needs is in `.upsun/config.yaml` and the three
`.environment` files. Ask before creating anything that costs money; the steps
are:

```bash
upsun project:create --title aurora-demo --set-remote   # choose an EU region
upsun variable:create --level project --name env:APP_KEY --sensitive true \
  --visible-build false --value "base64:$(openssl rand -base64 32)"
upsun push                 # or: set up the GitHub integration first (below) and push to GitHub
```

The first deploy creates the three apps, their workers, MariaDB, Valkey and
Kafka, runs the migrations and creates the topics. Then:

```bash
upsun resources:set --object-storage oms:524288          # 512 GB, the minimum; see the note below
upsun ssh --app nfw -- php artisan aurora:seed-volume --force   # 50,000 sellers, 500,000 orders
upsun resources:get                                       # check names and sizes
```

**Object storage** is billed on the allocated size (minimum 512 GB). When an
environment is branched, the bucket and its size are cloned too. The OMS works
with or without it. `.environment` switches invoice storage to the database
when there is no `object-storage` relationship (web and worker containers do not
share mounts, so a file mount would not work). To keep a preview environment
without a bucket: `upsun resources:set --object-storage oms:0 -e <env>`.

## Manual setup steps

These need a person with admin rights on GitHub and on the Upsun project.

1. **APP_KEY** — the `variable:create` command above. One project-level key is
   shared by the three apps (acceptable for a demo; use one per app with
   `--level environment` and an app-specific prefix in real life).
2. **GitHub integration** (every push deploys, every pull request gets an environment):
   - Create a fine-grained token on GitHub for the repository with: Commit
     statuses (read/write), Contents (read/write), Metadata (read), Pull requests
     (read/write), Webhooks (read/write). The user must be a repository admin.
   - Run:
     ```bash
     upsun integration:add --type github --repository <owner>/<repo> --token <TOKEN> \
       --build-pull-requests true --pull-requests-clone-parent-data true \
       --fetch-branches true --prune-branches true --resources-init parent
     upsun integration:validate
     ```
     `--resources-init parent` makes PR environments start with production's
     sizes instead of the minimum.
   - From then on, push to GitHub, not to Upsun. GitHub is the source of truth.
3. **GitHub Actions** — nothing to configure: `.github/workflows/ci.yml` runs on
   every push and pull request. Optionally make it a required check in the branch
   protection rules.
4. **Optional variables**
   - `LOAD_TEST_TOKEN` (env var on the environment, sensitive): when set,
     `POST /api/ordini` requires the header `X-Load-Test-Token`. Pass it to k6
     with `-e TOKEN=...`.
   - `COMMISSION_COMPLEXITY`, `HORIZON_COMMISSION_PROCESSES`: set on the
     `commissions` worker in `.upsun/config.yaml`.
5. **Upsun Dispatch (optional, scene 3 of DEMO.md)** — in the Upsun Dispatch
   console: create an organisation, install the Dispatch GitHub App on the
   repository (**Settings → GitHub**; a repository admin is needed, and Issue to
   code needs push access), set Code review to automatic and enable **Plan
   approval** for Issue to code. The day before the session, open the issue
   given in DEMO.md and comment exactly `@upsun-dispatch implement`. Dispatch is
   not required by anything in this repository or on Upsun; the same scene works
   with a hand-written pull request.
6. **Protect the demo** — `/admin` and `/horizon` have no login. Before sharing
   the URL: `upsun httpaccess --auth demo:<password>` (per environment).

## Splitting into three projects

If 2R Studio prefers one Upsun project per application (separate teams,
billing, permissions or release cadence), the code does not change:

- Each `apps/<name>` directory becomes its own repository with its own
  `.upsun/config.yaml`, holding that app's block, `source.root: /`, and its own
  services: a MariaDB with one schema, a Valkey, and either its own Kafka or a
  shared broker.
- The `app/Messaging` copy is already self-contained; in real life publish it as
  a private Composer package.
- The only cross-app couplings are the internal HTTP relationships
  (`promotions.internal`, `oms.internal`) and the shared Kafka. Across projects,
  the Promotion Engine needs a route (public, or restricted by IP or HTTP access
  control), and the apps need a broker they can all reach: one Kafka in one
  project, exposed to the others, or an external Kafka-compatible service. Check
  the cross-project connectivity options with Upsun before choosing.
- The demo switch and dashboard counters use the shared Valkey; with separate
  projects, each app's `/internal/stats` would be called over HTTP (already the
  case for the dashboard) and the switch would be per project.

## Tests and CI

- `tests/Messaging` (identical in the three apps): outbox atomicity and the
  "inside a transaction" guard; relay publishes in order, marks only
  acknowledged events, keeps events on failure and drains later; backoff;
  consumer skips duplicates and retries cleanly after a failed handler; the
  outage switch.
- NFW: order and event written together or not at all; order accepted while
  the broker is down; Promotion Engine down → order refused; `order.shipped`
  applied once; commission calculator rules and compression; a chunked
  run equals a single pass.
- OMS: one shipment, one event and one invoice even when delivered twice;
  internal invoice and stats endpoints.
- Promotion Engine: the three rules and their combination; the quote API;
  redemptions recorded once.

CI (`.github/workflows/ci.yml`) checks that the messaging copies are identical,
validates `.upsun/config.yaml` with the Upsun CLI, builds NFW's front end, and
runs the three test suites on PHP 8.5 against MariaDB 11.8.

Load test: [load/k6/peak-evening.js](load/k6/peak-evening.js) (see DEMO.md, scene 4).
