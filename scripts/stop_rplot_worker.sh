#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PID_FILE="$APP_ROOT/cache/rplots/worker.pid"
if [[ -s "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  kill "$(cat "$PID_FILE")"
  echo "Stopped R plot worker: PID $(cat "$PID_FILE")"
else
  echo "R plot worker is not running"
fi
rm -f "$PID_FILE"
