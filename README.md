# Diurnal Brain Transcriptome Atlas

**Interactive spatial-transcriptomics atlas for brain diurnal rhythms in healthy and Alzheimer’s disease model mice.**

This web application accompanies **Gelber, Romero et al., bioRxiv 2026** and provides an interactive interface for exploring 24-hour rhythmic transcription across cortical and subcortical mouse brain regions.

**Live app:** https://brainome.ucsd.edu/BrainRhythmSTT/  
**Preprint:** https://www.biorxiv.org/content/10.64898/2026.01.26.701799v1  
**External raw data browser:** https://viewers.karospace.se/viewers/gse282203-combined-binary-sidecar.html

## Overview

Diurnal rhythms in brain transcription align neural, immune, and metabolic processes with the light-dark cycle and are profoundly disrupted in Alzheimer’s disease. However, the regional organization of diurnal transcription in the healthy and diseased brain remains poorly defined.

Using large-scale spatial transcriptomics, this study maps 24-hour rhythmic transcription across cortical and subcortical regions of the mouse brain. The app allows users to explore gene-level diurnal expression, spatial expression patterns, rhythmicity statistics, APP23–NTG differential expression, dorsal–ventral hippocampal differences, rostral–caudal cortical rhythms, and matched Allen Brain Atlas in situ hybridization images.

## What you can explore

### Diurnal expression

Search a gene and explore its 24-hour expression profile across annotated brain regions. Compare patterns by genotype, age, and sex to examine how diurnal expression varies across anatomical and biological contexts.

### Spatial mean expression

View spatial expression patterns across annotated brain regions using log2-normalized counts.

### Rhythmicity results

Search supplementary rhythmicity and differential-rhythmicity results by gene. The table summarizes significant findings from the paper’s supplementary analyses, including NTG rhythmicity, APP23 rhythmicity, regional differential rhythmicity, cortical subregion tests, and genotype-associated differential rhythmicity.

### APP23 vs. NTG differential expression

Compare gene expression between APP23 and non-transgenic (NTG) mice within individual brain regions. Explore overall genotype effects, age- and sex-specific comparisons, and genotype interactions alongside sample-level expression and differential-expression statistics.

### Dorsal/ventral hippocampus

Explore WT dorsal-vs-ventral hippocampal expression results. The panel reports differential expression results and includes matched sagittal Allen Brain Atlas in situ hybridization for the searched gene.

### Rostral-caudal rhythmicity

Explore how rhythmic gene expression varies among rostral, intermediate, and caudal cortical regions and across cortical layers. Compare regional time courses to examine spatial differences in the timing and magnitude of diurnal expression.

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

The diurnal and rostral–caudal views present sample-level expression together with fitted 24-hour profiles, enabling comparison across anatomical regions and biological groups. Display controls change how observations are organized visually without altering the underlying statistical results.

The APP23-versus-NTG view presents time-agnostic differential-expression results derived from cluster-level negative-binomial models that account for age and sex. Multiple-testing-adjusted results are reported for balanced overall comparisons as well as selected age-, sex-, and genotype-specific contrasts.

## Repository contents

This repository contains the public web application source code, frontend assets, PHP API, data-export utilities, and documentation needed to reproduce the browser interface. Large unpublished data files are not stored in this public repository.

## Acknowledgements

This resource was developed by the Desplats and Mukamel labs at UC San Diego for interactive exploration of the spatial and temporal organization of brain transcriptional rhythms.
