# Diurnal Brain Transcriptome Atlas

Interactive web atlas for **Gelber, Romero et al. (bioRxiv 2026)**, supporting exploration of spatial transcriptomic rhythms across the mouse brain and their disruption in the APP23 Alzheimer's disease model.

Live application: <https://brainome.ucsd.edu/MouseBrainRhythmST/>

Preprint: <https://www.biorxiv.org/content/10.64898/2026.01.26.701799v1.full>

Raw section-level viewer: <https://viewers.karospace.se/viewers/gse282203-combined-binary-sidecar.html>

## Overview

Diurnal transcription coordinates neural, immune, and metabolic programs with the light-dark cycle. This application provides a browser-based interface to spatial transcriptomic analyses from the associated study, including regional expression, rhythmicity calls, rostral-caudal cortical dynamics, dorsal/ventral hippocampal expression, and matched Allen Brain Atlas in situ hybridization images.

The production app is designed for Brainome-style Apache/PHP hosting. Statistical modeling and data preparation are performed offline in R. Runtime queries are served by PHP from read-only SQLite databases, and the browser renders the compiled React interface.

## Application sections

- **About** — paper summary, citation, contacts, and links to the raw data browser.
- **Diurnal Expression** — 24-hour expression profiles and sinusoidal model fits across brain regions.
- **Spatial mean** — region-level spatial expression maps.
- **Rhythmicity results** — searchable supplementary rhythmicity and differential rhythmicity tables.
- **Rostral-Caudal Rhythmicity** — rostral, intermediate, and caudal cortical rhythmic expression for cortical-layer clusters.
- **Dorsal/ventral hippocampus** — WT dorsal-vs-ventral hippocampal expression and DESeq2 results with matched Allen ISH.

## Repository layout

```text
api/                 PHP API and plotting helpers
assets/              compiled JavaScript/CSS bundles
frontend_src/        React source code
metadata/            static spatial-map assets
cache/               optional Allen metadata/image cache
data-private/        local SQLite databases; not committed
export/              R export scripts
check.php            temporary server diagnostic page
```

## Required runtime data

Place the SQLite databases in `data-private/`:

```text
data-private/
├── diurnal.sqlite
├── dorsal_ventral.sqlite
├── supplemental.sqlite
└── rostral_caudal.sqlite
```

The rostral-caudal tables are stored in a separate `rostral_caudal.sqlite` database. It is created by `export/export_rostral_caudal_sqlite.R`.

## Brainome deployment notes

The app requires Apache/PHP with `pdo_sqlite` enabled. R, Node.js, npm, and a background service are not required on Brainome.

After uploading the app and databases, open:

```text
https://brainome.ucsd.edu/MouseBrainRhythmST/check.php
```

Confirm that the PHP version, PDO SQLite, database readability, and compiled frontend are OK. Then disable the diagnostic page:

```bash
mv check.php check.php.disabled
```

## Rostral-caudal export

Run on the analysis machine where `coefs_for_plotting.rds` exists:

```bash
Rscript export/export_rostral_caudal_sqlite.R \
  --output-dir=/home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2/new_data_output2 \
  --overwrite
```

The script writes:

```text
/home/agelber/desp1/precast/precast_final_with_ros_caud/analysis2/new_data_output2/rostral_caudal.sqlite
```

Copy that database to the deployed `data-private/rostral_caudal.sqlite`.

## Development

Build the frontend locally:

```bash
cd frontend_src
npm ci
npm run lint
npm run build
```

The Vite build writes to `dist/`; copy `dist/index.html` and `dist/assets/` to the application root for deployment.

## Citation

Gelber, Romero et al. **Diurnal Brain Transcriptome Atlas**. bioRxiv 2026.

## Contacts

- Alon Gelber: <agelber@ucsd.edu>
- Eran Mukamel: <emukamel@ucsd.edu>
- Paula Desplats: <pdesplat@ucsd.edu>

## License and data access

This repository contains the public web application code. Large exported data files are not committed to GitHub; deploy them separately as read-only SQLite databases.
