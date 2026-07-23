#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
cd "$ROOT/frontend_src"

npm ci --prefer-offline
npm test
npm run lint
npm run build

cp "$ROOT/dist/index.html" "$ROOT/index.html"
rsync -a --delete "$ROOT/dist/assets/" "$ROOT/assets/"
