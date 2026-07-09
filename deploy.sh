#!/bin/bash

# Run npm and deploy

cd frontend_src
npm ci --prefer-offline
npm run lint
npm run build
cp ../dist/index.html ..
rsync -a --delete ../dist/assets/ ../assets/
