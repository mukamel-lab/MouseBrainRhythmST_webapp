#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
CACHE_DIR="$APP_ROOT/cache/rplots"
PID_FILE="$CACHE_DIR/worker.pid"
HEARTBEAT="$CACHE_DIR/worker.heartbeat"
LOG_FILE="$CACHE_DIR/worker.log"
if [[ -s "$PID_FILE" ]]; then
  PID="$(cat "$PID_FILE")"
  if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
    echo "running: PID $PID"
  else
    echo "pid file exists but process is not running: $PID"
  fi
else
  echo "not running: no PID file"
fi
if [[ -f "$HEARTBEAT" ]]; then
  echo "heartbeat: $(cat "$HEARTBEAT")"
else
  echo "heartbeat: missing"
fi
if [[ -f "$LOG_FILE" ]]; then
  echo "last log lines:"
  tail -20 "$LOG_FILE"
fi
