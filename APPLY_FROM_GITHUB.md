# Apply this replacement tree to a Git clone

This archive contains the complete public app tree with both the React hierarchical rhythmicity renderer and the ggplot-matched rostral-caudal renderer integrated. It is intended to replace the tracked app files rather than be copied in as a partial patch.

The archive does **not** include private SQLite databases, local deployment configuration, or generated Allen image caches.

## 1. Extract the archive

```bash
tar -xzf MouseBrainRhythmST_webapp-react-rhythmicity-rostral-caudal-update.tar.gz
```

## 2. Replace the tracked files in the clone

From the root of your Git clone:

```bash
rsync -a --delete \
  --exclude='.git/' \
  --exclude='data-private/' \
  --exclude='private_dir/' \
  --exclude='cache/allen/' \
  --exclude='cache/allen-images/' \
  --exclude='api/config.local.php' \
  /path/to/MouseBrainRhythmST_webapp-react-rhythmicity-rostral-caudal/ \
  ./
```

The `--delete` flag removes tracked files that are no longer part of the app, including the superseded main diurnal and rostral-caudal PHP SVG-rendering implementations. The exclusions preserve private data and deployment-specific state.

## 3. Build and validate

```bash
./deploy.sh
```

The deployment script runs:

```text
npm ci
npm test
npm run lint
npm run build
```

It then copies the Vite build output into the public root `index.html` and `assets/` directory.

## 4. Review before committing

```bash
git status --short
git diff --stat
git diff
```

Then commit the replacement normally.

## Runtime requirements

- PHP with PDO SQLite enabled.
- The existing private SQLite files in `data-private/`, or a deployment-specific database directory configured through `api/config.local.php`.
- Node.js/npm only when rebuilding the frontend.

## Browser plot architecture

### Main rhythmicity plot

- `api/index.php?route=plot-data` returns observations, sinusoidal coefficients, dimensions, labels, and colors as JSON.
- `frontend_src/src/plot/RhythmicityPlot.jsx` renders the browser SVG.
- `frontend_src/src/plot/facetLayout.js` implements the ordered hierarchical facet layout.
- The former main diurnal `/plot.svg` and `/plot.pdf` routes are not retained.

### Rostral-caudal plot

- `api/index.php?route=rostral-caudal` returns regions, double-plotted observations, mean/SD summaries, and fitted curves as JSON.
- `frontend_src/src/plot/RostralCaudalPlot.jsx` renders the ggplot-matched SVG.
- `frontend_src/src/plot/rostralCaudalTheme.js` contains the physical R-device dimensions and geometry sizes.
- The former `/rostral-caudal/plot.svg` route and `rc_plot_svg()` implementation are not retained.

Both displayed SVGs are serialized in the browser for their **Download SVG** actions. The separate dorsal/ventral hippocampus view still uses its existing SVG endpoint.
