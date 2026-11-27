# Assumptions and unconfirmed points

Docs read: https://developer.upsun.com/llms-full.txt (fetched 26 November 2026)
and the pages it contains for PHP, PHP extensions, workers, Laravel/Horizon,
Kafka, MariaDB, Valkey, object storage, crons, multi-app, the GitHub
integration, resources and container profiles. Upsun CLI 5.19.0 was used to
validate `.upsun/config.yaml` (`upsun app:config-validate`: valid) and to check
command flags with `--help`. First deployed on 27 November 2026 (project `4vaxw3yw3bshs`, eu-5). Items
marked **Confirmed on deploy** were settled by the real deploy logs and HTTP
checks; the rest are still open.

## Confirmed by the documentation

| Topic | What the docs say | Where used |
|---|---|---|
| PHP 8.5 | Supported version | `type: "php:8.5"` |
| `rdkafka`, `redis` on 8.5 | Listed as available extensions | `runtime.extensions` |
| App-to-app calls | Relationship with `endpoint: http`, reachable as `http://<relationship>.internal`, plain HTTP, internal only, no circular relationships | NFW → `promotions`, NFW → `oms` |
| Workers | `commands.start` restarted if it exits; SIGTERM then SIGKILL after 15 s; no hooks or crons on workers; mounts not shared with web | All long-running processes |
| Crons | Run on the web container only; a running cron blocks a deploy | Cron only queues jobs |
| MariaDB | `schemas` + `endpoints` with per-schema privileges; relationship variables `<REL>_HOST` etc. | One service, three schemas, three users |
| Valkey | `valkey-persistent` type, `valkey` endpoint; phpredis via the `redis` extension | Queues, cache, sessions |
| Kafka | `kafka:4.3`, `kafka` endpoint, `KAFKA_HOST`/`KAFKA_PORT` | Event bus |
| Object storage | Enabled with `resources:set --object-storage`; minimum 512 GB, billed on allocated size; not on trials; cloned with its size when branching; reserved relationship `object-storage`; path-style S3; one bucket per app, named after the app | OMS invoices |
| GitHub integration | Flags `build-pull-requests`, `pull-requests-clone-parent-data`, `resources-init`; PR environments are active, with the parent's data; token permissions | Scenes 2–3 |
| Container profiles | PHP defaults to `HIGH_CPU` (224 MB at 0.5 CPU); `container_profile` can be set per worker | `commissions` worker uses `BALANCED` |
| Horizon | Run as a worker with `php artisan horizon` | Two Horizon workers in NFW, one in OMS |

## Decisions taken with you

1. One MariaDB service with three schemas, not three services.
2. Object storage for invoices, with a database fallback where there is no bucket.
3. The Promotion Engine's outbox emits `promotion.redeemed`; only the dashboard reads it.
4. PR environments use `--resources-init parent`.
5. NFW reaches the Promotion Engine over the internal HTTP relationship. No Kafka
   request/reply, no public route.

## Not confirmed by the documentation (check on first deploy)

1. **Kafka topic creation — confirmed on deploy, with a change.** The app
   container has no `kafka-topics.sh`, and a metadata request reported
   `Unknown topic or partition`. `aurora:kafka-topics` now sends Kafka's
   `CreateTopics` request (v2) itself (`app/Messaging/KafkaAdmin.php`); the deploy
   log shows the three topics exist. Original note: the docs do not say whether the broker auto-creates
   topics, and php-rdkafka has no admin API. `aurora:kafka-topics` (deploy hook)
   uses `kafka-topics.sh` if the container has it (the docs' export example runs
   it from an app container), otherwise a metadata request that creates topics
   when auto-creation is allowed. It never fails the deploy. If neither works,
   the relays will report `Unknown topic` and the topics must be created by hand
   from an SSH session. Partition and replication defaults are also unknown;
   ordering per order is kept by keying messages on the order ID.
2. **Worker names for `resources:set` — confirmed on deploy:** `upsun resources:get`
   lists `nfw--commissions`, `oms--relay` and so on. Original note: The CLI has a `--worker` filter, but the
   docs do not show the `--size <name>:<value>` name for a worker. The runbook
   assumes `nfw--commissions`; check with `upsun resources:get` and fix DEMO.md
   if it differs.
3. **`ext-pcntl` on Upsun's PHP 8.5 — confirmed on deploy** (Composer accepted
   Horizon's requirement at build time). Original note: Horizon requires it, and it is not in the
   extensions table. The Upsun Horizon guide works, so I assume it is compiled
   in. If it is not, `composer install` fails at build time with a clear message.
4. **Node in the PHP image — confirmed on deploy:** `n auto` installed Node 24.22.3
   and `vite build` succeeded. Original note: I install Node 24 with `n`
   (`dependencies.nodejs.n`, `N_PREFIX`, `n auto` reading `.nvmrc`), as the docs
   describe for non-Node images. It assumes the image has an npm to install `n`.
5. **Valkey relationship scheme.** The relationship reports scheme `valkey`.
   `.environment` unsets `REDIS_URL` and relies on `REDIS_HOST`/`REDIS_PORT`, so
   Laravel never parses a `valkey://` URL. Whether Upsun sets `REDIS_URL` at all
   for this relationship is unconfirmed.
6. **`OBJECT_STORAGE_BUCKETS` format.** The docs show
   `OBJECT_STORAGE_BUCKETS={"myapp": {}}`. `.environment` reads the bucket name
   with `jq -r 'keys[0]'`. Not tested against a real bucket.
7. **`.environment` in workers and crons.** I assume it is sourced for every
   runtime process (web, workers, crons, deploy hook), as the docs imply for
   "the runtime environment".
8. **Worker `variables` replace rather than merge.** The `commissions` and
   `horizon` workers set their own `variables.env`. If this replaces the app's
   `variables` block, the only casualty is `N_PREFIX`, which is needed at build
   time only.
9. **Config cache and workers.** The deploy hook runs `php artisan optimize` in
   the web container. Per-worker settings (`HORIZON_SUPERVISORS`,
   `COMMISSION_COMPLEXITY`, `HORIZON_COMMISSION_PROCESSES`) are read with
   `getenv()` at runtime in `AppServiceProvider`, so they are correct whether or
   not a worker sees that cache. Tested locally both ways.
10. **Commission timings on Upsun.** Measured locally (about 37 s of CPU in total
    with complexity 200). The 0.5 → 2 vCPU projection (about 75 s → under 20 s)
    assumes Upsun's CPU quota behaves like a cgroup limit and that a vCPU is
    comparable to the local core. Re-measure during preparation and adjust
    `COMMISSION_COMPLEXITY` if needed.
11. **Memory of the commissions worker.** `container_profile: BALANCED` on the
    worker is confirmed on deploy (1,088 MB at 0.5 CPU). Measured locally: about 520 MB for 8
    processes plus Horizon's master and supervisor. `BALANCED` at 0.5 CPU gives
    1,088 MB. Not measured on Upsun.
12. **Default sizes on first deploy.** Not documented per app/worker in what I
    read. Relay and consumer processes use about 60 MB each; `HIGH_CPU` at 0.1
    CPU (64 MB) would be too tight, so do not size workers below 0.25 CPU.
13. **PR environment names.** The runbook assumes `pr-<number>`; check with
    `upsun environment:list`.
14. **Object storage on previews.** With `--resources-init parent` and the
    documented cloning behaviour, preview environments get a bucket of the same
    size, billed hourly. The code falls back to the database when there is no
    bucket; removing it from a preview is manual
    (`resources:set --object-storage oms:0 -e <env>`).
15. **`upsun log --worker`, `upsun ssh --worker`, `upsun metrics`** exist in CLI
    5.19 (`--help` checked); their exact output on a multi-app project was not seen.
16. **HTTP basic auth and internal calls.** `upsun httpaccess --auth` protects the
    router only; I assume internal relationships (`*.internal`) bypass it, since
    they do not go through the router.
17. **`source.root` limits the build to the app's directory.** That is why the
    messaging code is copied into each app (`bin/sync-messaging.sh`, checked in CI)
    instead of being shared through a Composer path repository.
18. **Horizon install step.** The Upsun Horizon guide runs `php artisan
    horizon:install` in the build hook. I do not: the provider and config it
    publishes are committed, and Horizon 5.52 serves its dashboard assets from
    the vendor directory (checked locally).
19. **Kafka on Upsun vs Redpanda locally.** Tested locally against Redpanda
    25.2 (Kafka API). Behaviour should match Apache Kafka 4.3 for what the code
    uses (produce with acks=all and idempotence, consumer groups, manual commit).

## Other assumptions

- **Laravel 13** is available (framework 13.44); no fallback was needed.
  Horizon 5.52, Inertia 3 (Laravel adapter 3.8, React adapter 3.11), React 19.
- **PHP version locally** was 8.3; CI runs PHP 8.5 with `setup-php`. The
  `composer.lock` files were resolved on 8.3 and are compatible with 8.5 as far
  as CI shows.
- **Sales cycles** are three weeks long, anchored on Monday 5 January 2026. The
  RFP suggests a three-week rhythm; the anchor is invented.
- **Commission rules** are invented (10% personal, 6/4/3/2/1% over five active
  levels, €100 activity threshold, dynamic compression).
- **Seeded orders** have no order lines and no outbox events: they are
  history. Orders placed during the demo have both.
- **One `APP_KEY`** at project level is shared by the three apps. Fine for a
  demo; use one per app in real life.
- **No authentication** on the demo panel and Horizon; protect the environment
  with HTTP access control.
- **Invoice PDFs** are generated with dompdf, are marked as demonstrative, and
  carry an invented company name and VAT number of zeros.

## Found on the first deploy (fixed)

- **Mounts start empty.** `storage` is empty in every container, so Laravel's
  default `view.compiled` (`realpath(...)`) was `false` and `optimize` failed.
  `config/view.php` now uses the plain path, and the deploy hooks create the
  storage directories.
- **`bootstrap/cache` persists across deploys.** A config cache left by a failed
  deploy made `optimize` run `view:cache` with stale paths. The deploy hooks run
  `php artisan config:clear` in a separate process first.
- **No SSH from the build sandbox.** Port 22 to `ssh.eu-5.platform.sh` is
  blocked from the environment where this repository was built, so commands that
  need `upsun ssh` (seeding, Horizon status) must be run from a workstation.
- **Default sizes after the first deploy:** every app and worker 0.5 CPU;
  MariaDB, Kafka and Valkey 0.5 CPU with 512 MB disk each.
