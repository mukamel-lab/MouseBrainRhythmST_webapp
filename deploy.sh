#!/bin/bash

# Run npm and deploy

cd frontend_src
npm ci \
  --prefer-offline --verbose
#   --omit=optional \
#   --ignore-scripts
npm run lint
npm run build
cp ../dist/index.html ..
rsync -a --delete ../dist/assets/ ../assets/
