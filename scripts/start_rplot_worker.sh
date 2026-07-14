#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CACHE_DIR="$APP_ROOT/cache/rplots"
TMP_DIR="$CACHE_DIR/tmp"
JOBS_DIR="$CACHE_DIR/jobs"
PID_FILE="$CACHE_DIR/worker.pid"
LOG_FILE="$CACHE_DIR/worker.log"
mkdir -p "$TMP_DIR" "$JOBS_DIR"
chmod 1777 "$CACHE_DIR" "$TMP_DIR" "$JOBS_DIR" 2>/dev/null || true
if [[ -s "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
  echo "R plot worker already running: PID $(cat "$PID_FILE")"
  exit 0
fi
export R_LIBS_USER="$APP_ROOT/R-library"
export TMPDIR="$TMP_DIR"
export HOME="$APP_ROOT"
nohup Rscript "$APP_ROOT/scripts/rplot_worker.R" --app-root="$APP_ROOT" >> "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"
echo "Started R plot worker: PID $(cat "$PID_FILE")"
