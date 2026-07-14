#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CACHE_DIR="$APP_ROOT/cache/rplots"
PID_FILE="$CACHE_DIR/worker.pid"
HEARTBEAT="$CACHE_DIR/worker.heartbeat"
LOG_FILE="$CACHE_DIR/worker.log"
if [[ -s "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  echo "running: PID $(cat "$PID_FILE")"
else
  echo "not running"
fi
if [[ -f "$HEARTBEAT" ]]; then
  echo -n "heartbeat: "
  cat "$HEARTBEAT"
else
  echo "heartbeat: missing"
fi
if [[ -f "$LOG_FILE" ]]; then
  echo "last log lines:"
  tail -20 "$LOG_FILE"
fi
