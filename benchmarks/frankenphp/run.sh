#!/usr/bin/env bash
# Load test under FrankenPHP worker mode: Keel (compiled caches) vs Slim 4, same JSON route.
# Needs: frankenphp, oha (brew install oha). Usage: benchmarks/frankenphp/run.sh [duration] [concurrency]
set -euo pipefail
cd "$(dirname "$0")"
DURATION="${1:-10s}"; CONCURRENCY="${2:-64}"
for app in keel slim; do
  port=$([ "$app" = keel ] && echo 8081 || echo 8082)
  (cd "$app" && frankenphp run --config Caddyfile >"/tmp/keel-bench-$app.log" 2>&1 &)
  sleep 2
  curl -fs "http://127.0.0.1:$port/json/42" >/dev/null || { echo "$app did not start"; cat "/tmp/keel-bench-$app.log"; exit 1; }
  oha -z 2s -c "$CONCURRENCY" --no-tui "http://127.0.0.1:$port/json/42" >/dev/null   # warm-up
  echo "== $app (FrankenPHP worker, ${CONCURRENCY} connections, ${DURATION})"
  oha -z "$DURATION" -c "$CONCURRENCY" --no-tui "http://127.0.0.1:$port/json/42" | grep -E 'Requests/sec|Average:|50.00%|99.00%|\[200\]'
  pkill -f "frankenphp run --config Caddyfile" || true
  sleep 1
done
