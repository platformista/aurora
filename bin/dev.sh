#!/usr/bin/env bash
# Starts the three apps and all their workers locally, like Upsun does with
# `web` and `workers` in .upsun/config.yaml. Logs go to local/logs/.
# Requires: docker compose up -d, and each app migrated (see README).
# Stop everything with Ctrl+C.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p local/logs
pids=()
run() { local name=$1 dir=$2; shift 2; (cd "apps/$dir" && exec "$@") >"local/logs/$name.log" 2>&1 & pids+=($!); echo "started $name"; }

export PHP_CLI_SERVER_WORKERS=4
run nfw-web           nfw        php artisan serve --port=8001 --no-reload
run oms-web           oms        php artisan serve --port=8002 --no-reload
run promotions-web    promotions php artisan serve --port=8003 --no-reload

run nfw-horizon       nfw        env HORIZON_SUPERVISORS=default php artisan horizon
run nfw-commissions   nfw        env HORIZON_SUPERVISORS=commissions php artisan horizon
run nfw-relay         nfw        php artisan outbox:relay
run nfw-consumer      nfw        php artisan kafka:consume shipments
run oms-horizon       oms        php artisan horizon
run oms-relay         oms        php artisan outbox:relay
run oms-consumer      oms        php artisan kafka:consume orders
run promotions-relay  promotions php artisan outbox:relay
run promotions-consumer promotions php artisan kafka:consume orders

trap 'kill "${pids[@]}" 2>/dev/null; exit 0' INT TERM
echo "NFW: http://127.0.0.1:8001  (pannello demo: /admin)"
wait
