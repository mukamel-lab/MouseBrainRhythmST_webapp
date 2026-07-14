#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
CACHE_DIR="$APP_ROOT/cache/rplots"
mkdir -p "$CACHE_DIR/jobs" "$CACHE_DIR/tmp"
chmod 755 "$APP_ROOT/cache" 2>/dev/null || true
chmod 1777 "$CACHE_DIR" "$CACHE_DIR/jobs" "$CACHE_DIR/tmp" 2>/dev/null || true
PID_FILE="$CACHE_DIR/worker.pid"
HEARTBEAT="$CACHE_DIR/worker.heartbeat"
LOG_FILE="$CACHE_DIR/worker.log"

if [[ -s "$PID_FILE" ]]; then
  OLD_PID="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "$OLD_PID" ]] && kill -0 "$OLD_PID" 2>/dev/null; then
    echo "R plot worker already running: PID $OLD_PID"
    exit 0
  fi
fi

export R_LIBS_USER="${R_LIBS_USER:-$APP_ROOT/R-library}"
export TMPDIR="$CACHE_DIR/tmp"
export HOME="$APP_ROOT"
nohup Rscript "$APP_ROOT/scripts/rplot_worker.R" --app-root="$APP_ROOT" >> "$LOG_FILE" 2>&1 &
PID=$!
echo "$PID" > "$PID_FILE"
sleep 1
if kill -0 "$PID" 2>/dev/null; then
  echo "Started R plot worker: PID $PID"
  echo "Log: $LOG_FILE"
else
  echo "R plot worker failed to start. Last log lines:" >&2
  tail -40 "$LOG_FILE" >&2 || true
  exit 1
fi
