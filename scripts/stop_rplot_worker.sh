#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
CACHE_DIR="$APP_ROOT/cache/rplots"
PID_FILE="$CACHE_DIR/worker.pid"
if [[ ! -s "$PID_FILE" ]]; then
  echo "No worker PID file found."
  exit 0
fi
PID="$(cat "$PID_FILE")"
if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
  kill "$PID"
  echo "Stopped R plot worker: PID $PID"
else
  echo "Worker PID $PID is not running."
fi
rm -f "$PID_FILE" "$CACHE_DIR/worker.heartbeat"
