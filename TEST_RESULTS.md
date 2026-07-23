# Test results

Package: full PHP/SQLite application with the main diurnal rhythmicity and rostral-caudal plots rendered as React SVG from JSON payloads.

## Passed in this build environment

- `npm test` — passed all 13 facet-layout, plot-math, and rostral-caudal geometry tests.
- `npm run lint` — passed.
- `npm run build` — passed with Vite 7.3.6.
- React server-render smoke check — passed for the rostral-caudal SVG; the output contained the gene title/y label, left-aligned cortical-layer subtitle, all three region labels, four light/dark intervals, curves, SD bars, summary points, raw points, and the untitled bottom legend.
- PHP syntax checks — passed for every PHP file under the app tree using PHP CLI 8.4.16.
- Generated production assets were copied into the public `index.html` and `assets/` paths.
- Static cleanup checks confirmed that the old main diurnal `plot.svg`/`plot.pdf` implementations and the old rostral-caudal `plot.svg`/`rc_plot_svg()` implementation are absent.

## Rostral-caudal parity covered

- 6.3 × 4.2 R-device aspect ratio.
- `theme_bw(base_size = 12)`-style panel, major/minor grid, axes, and ArialMT-oriented typography.
- X expansion, breaks `0, 12, 24, 36`, labels `0, 12, 0, 12`, and four light/dark phase blocks.
- Layer order: fitted curves, mean ± SD error bars, mean points, then jittered individual points.
- R geometry values: line width `0.55`, error-bar line width `0.25`, error-bar cap width `0.18`, mean-point size `1.6`, raw-point size `0.55`, alpha `0.18`, and jitter width `0.18`.
- Gene on both the centered bold-italic title and y axis, cortical-layer subtitle, ordered region colors, and an untitled bottom legend.
- Browser-side SVG download uses the same displayed React SVG.

## PHP/SQLite endpoint smoke test

`tests/run_smoke.sh` now verifies the `/rostral-caudal` JSON payload, region order, axis/legend labels, original and double-plotted point counts, summary count, curve resolution, and the existing application endpoints.

It could not execute in this container because the available PHP CLI has PDO but does not include the `pdo_sqlite` driver. Run it on the deployment host with:

```bash
bash tests/run_smoke.sh
```

The script creates temporary fixture databases and does not use the private production databases.
