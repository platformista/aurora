#!/usr/bin/env bash
# app/Messaging and tests/Messaging are maintained in apps/nfw and copied to the
# other apps, so each app stays self-contained (each is built from its own
# directory on Upsun). In a real project this would be a private Composer package.
# Usage: bin/sync-messaging.sh          copy from nfw to oms and promotions
#        bin/sync-messaging.sh --check  fail if the copies differ (used in CI)
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
for app in oms promotions; do
  for dir in app/Messaging tests/Messaging; do
    if [ "${1:-}" = "--check" ]; then
      diff -r "apps/nfw/$dir" "apps/$app/$dir" >/dev/null || { echo "apps/$app/$dir differs from apps/nfw/$dir"; status=1; }
    else
      rm -rf "apps/$app/$dir" && cp -r "apps/nfw/$dir" "apps/$app/$dir"
    fi
  done
done
exit $status
