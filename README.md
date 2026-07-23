# Diurnal Brain Transcriptome Atlas

**Interactive spatial-transcriptomics atlas for brain diurnal rhythms in healthy and Alzheimer’s disease model mice.**

This web application accompanies **Gelber, Romero et al., bioRxiv 2026** and provides an interactive interface for exploring 24-hour rhythmic transcription across cortical and subcortical mouse brain regions.

**Live app:** https://brainome.ucsd.edu/BrainRhythmSTT/  
**Preprint:** https://www.biorxiv.org/content/10.64898/2026.01.26.701799v1  
**External raw data browser:** https://viewers.karospace.se/viewers/gse282203-combined-binary-sidecar.html

## Overview

Diurnal rhythms in brain transcription align neural, immune, and metabolic processes with the light-dark cycle and are profoundly disrupted in Alzheimer’s disease. However, the regional organization of diurnal transcription in the healthy and diseased brain remains poorly defined.

Using large-scale spatial transcriptomics, this study maps 24-hour rhythmic transcription across cortical and subcortical regions of the mouse brain. The app allows users to explore gene-level diurnal expression, spatial expression patterns, rhythmicity statistics, dorsal–ventral hippocampal differences, rostral–caudal cortical rhythms, and matched Allen Brain Atlas in situ hybridization images.

## What you can explore

### Diurnal expression

Search a gene and visualize its 24-hour expression profile across brain regions, genotype, age, sex, and Zeitgeber Time. Plots are double-plotted to make rhythmic patterns easier to inspect. The browser renderer includes individual observations, mean ± 1 SD summaries, fitted sinusoidal curves, ggplot-like axes and legends, and ordered nested facet strips analogous to `ggh4x::facet_nested()`.

### Spatial mean expression

View spatial expression patterns across annotated brain regions using log2-normalized counts.

### Rhythmicity results

Search supplementary rhythmicity and differential-rhythmicity results by gene. The table summarizes significant findings from the paper’s supplementary analyses, including NTG rhythmicity, APP23 rhythmicity, regional differential rhythmicity, cortical subregion tests, and genotype-associated differential rhythmicity.

### Dorsal/ventral hippocampus

Explore WT dorsal-vs-ventral hippocampal expression results. The panel reports differential expression results and includes matched sagittal Allen Brain Atlas in situ hybridization for the searched gene.

### Rostral-caudal rhythmicity

Explore rostral, intermediate, and caudal cortical rhythmicity profiles across cortical layers. The browser-rendered SVG follows the supplied R/ggplot2 renderer: a 6.3 × 4.2 aspect ratio, `theme_bw(base_size = 12)`, double-plotted light/dark intervals, region-colored fitted curves, mean ± 1 SD summaries, small jittered observations, a gene-labelled y axis, the cortical layer as the subtitle, and an untitled bottom legend.

### Raw data browser

The external KaroSpace viewer provides direct exploration of the raw spatial transcriptomics data, including tissue sections, spatial annotations, embeddings, and gene-expression overlays.

## Citation

Please cite:

**Gelber, Romero et al.** Diurnal brain transcriptome atlas of regional rhythmicity and Alzheimer’s disease-associated disruption. **bioRxiv** 2026.

Preprint: https://www.biorxiv.org/content/10.64898/2026.01.26.701799v1

## Contacts

- **Alon Gelber** — agelber@ucsd.edu
- **Eran Mukamel** — emukamel@ucsd.edu
- **Paula Desplats** — pdesplat@ucsd.edu

## Labs

- [Desplats Lab at UC San Diego](https://desplatslab.org/)
- [Mukamel Lab at UC San Diego](https://brainome.ucsd.edu/)

## Data and methods summary

The app displays precomputed results from spatial transcriptomic analysis of mouse brain sections sampled across the diurnal cycle. Gene expression values are shown as log2-normalized counts. Rhythmicity and differential-rhythmicity results are derived from statistical models described in the accompanying manuscript and supplementary tables.

Runtime visualization uses precomputed read-only data files served through the public web application. Statistical modeling, normalization, rhythmicity testing, and differential-expression analyses are performed offline in R; the web interface is intended for interactive exploration and visualization of those results.

For the main diurnal view, PHP exposes observations, fitted-model coefficients, dimension labels, and palettes through the JSON `plot-data` route. React renders the final SVG in the browser. The order of the selected split variables controls the facet formula: with multiple variables, the first creates rows and the remaining variables create nested column strips from outer to inner. The default is equivalent to `age ~ sex + region`, with genotype used for color.

For the rostral-caudal view, `api/index.php?route=rostral-caudal` returns ordered cortical-position metadata, double-plotted observations, mean/SD summaries, and fitted curves. `frontend_src/src/plot/RostralCaudalPlot.jsx` renders the final SVG and serializes that same SVG for download. The former PHP rostral-caudal SVG route is not retained.

## Repository contents

This repository contains the public web application source code, frontend assets, PHP API, data-export utilities, and documentation needed to reproduce the browser interface. Large unpublished data files are not stored in this public repository.

## Acknowledgements

This resource was developed by the Desplats and Mukamel labs at UC San Diego for interactive exploration of the spatial and temporal organization of brain transcriptional rhythms.
