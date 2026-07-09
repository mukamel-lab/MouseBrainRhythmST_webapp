import { useCallback, useEffect, useMemo, useState } from 'react';
import { apiUrl, asArray, fetchAllenIsh, fetchGenes, fetchHippocampusDv, fetchHippocampusDvGenes, fetchJson, fetchRhythmicity, fetchRostralCaudal, fetchRostralCaudalGenes, fetchRhythmicityBasic, resolveGene } from './api';

const DEFAULT_GENE = 'Dbp';
const DEFAULT_HIPPOCAMPUS_DV_GENE = 'Lct';
const DEFAULT_HIPPOCAMPUS_DV_CLUSTER = 'dg_sg';
const DEFAULT_ROSTRAL_CAUDAL_GENE = 'Dbp';
const DEFAULT_ROSTRAL_CAUDAL_CLUSTER = 'L23';
const PREPRINT_URL = 'https://www.biorxiv.org/content/10.64898/2026.01.26.701799v1.full';
const RAW_DATA_BROWSER_URL = 'https://viewers.karospace.se/viewers/gse282203-combined-binary-sidecar.html';
const DEFAULT_COLOR_BY = 'region';
const DEFAULT_RHYTHMICITY_THRESHOLD = 0.1;
const PANEL_KEYS = ['map_ntg_7', 'map_ntg_14', 'map_app_7', 'map_app_14'];

const DEFAULT_CLUSTER_LABELS = {
  l23: 'Cortex Layer 2/3',
  l4: 'Cortex Layer 4',
  l5a: 'Cortex Layer 5a',
  l5b: 'Cortex Layer 5b',
  l6a: 'Cortex Layer 6a',
  l6b: 'Cortex Layer 6b',
  ctx23: 'Cortex Layer 2/3',
  ctx4: 'Cortex Layer 4',
  ctx5a: 'Cortex Layer 5a',
  ctx5b: 'Cortex Layer 5b',
  ctx6a: 'Cortex Layer 6a',
  ctx6b: 'Cortex Layer 6b',
  ca1: 'CA1',
  ca3sosr: 'CA3-so/sr',
  ca3so: 'CA3-so/sr',
  ca3sp: 'CA3-sp',
  dgmo: 'Dentate Gyrus molecular layer',
  dgsg: 'Dentate Gyrus granule layer',
  rhp: 'Retrohippocampal region',
  piriform: 'Piriform cortex',
  cp: 'Caudoputamen',
  caudoputamen: 'Caudoputamen',
  str: 'Ventral striatum',
  strv: 'Ventral striatum',
  amy: 'Amygdala',
  amygdala: 'Amygdala',
  gp: 'Globus pallidus',
  globuspallidus: 'Globus pallidus',
  rn: 'Reticular nucleus',
  reticularnucleus: 'Reticular nucleus',
  lv: 'Lateral ventricle',
  lateralventricle: 'Lateral ventricle',
  lateralventicle: 'Lateral ventricle',
  ft: 'Fiber tracts',
  fibertracts: 'Fiber tracts',
  meninges: 'Meninges',
  meninge: 'Meninges',
};
const DEFAULT_VARIABLE_LABELS = { region: 'Region', age: 'Age', sex: 'Sex', genotype: 'Genotype' };


function normalizeChoices(metadata, key) {
  return asArray(metadata?.choices?.[key]);
}

function normalizeDefaults(metadata, key) {
  return asArray(metadata?.defaults?.[key]);
}

function cleanFilename(value) {
  return String(value || 'plot').replace(/[^A-Za-z0-9._-]+/g, '_');
}

function exactOption(options, value) {
  const cleaned = String(value || '').trim().toLowerCase();
  if (!cleaned) return '';
  return options.find((option) => option.toLowerCase() === cleaned) || '';
}

function formatCount(value) {
  const number = Number(value || 0);
  return Number.isFinite(number) ? number.toLocaleString() : String(value || 0);
}

function displayValue(value, fallback = '—') {
  const text = String(value ?? '').trim();
  return text || fallback;
}


function sourceTableLabel(value) {
  const text = String(value ?? '').trim();
  const match = text.match(/^S(\d+)$/i);
  return match ? `Sup Table ${match[1]}` : text;
}

function sortValue(row, key, labelCluster) {
  if (key === 'source') return `${sourceTableLabel(row.table_id)} ${row.table_name || ''}`;
  if (key === 'result') return row.result_type || '';
  if (key === 'context') return row.context_display || labelClusterPhrase(row.context, labelCluster) || '';
  if (key === 'significance') return Number(row.significance ?? Number.POSITIVE_INFINITY);
  if (key === 'pvalue') return Number(row.p_value ?? row.pvalue ?? Number.POSITIVE_INFINITY);
  if (key === 'amp_phase') return ampPhaseText(row);
  if (key === 'detail') return row.detail_display || row.detail || '';
  return '';
}

function clusterLabelKey(value) {
  return String(value ?? '').trim().replace(/[^A-Za-z0-9]+/g, '').toLowerCase();
}

function makeClusterLabeler(metadata) {
  const labels = { ...DEFAULT_CLUSTER_LABELS };
  const metadataLabels = metadata?.labels?.region || {};
  for (const [id, label] of Object.entries(metadataLabels)) {
    labels[clusterLabelKey(id)] = String(label);
  }
  return (value) => {
    const text = String(value ?? '').trim();
    if (!text) return text;
    return labels[clusterLabelKey(text)] || text;
  };
}

function labelClusterPhrase(value, labelCluster) {
  const text = String(value ?? '').trim();
  if (!text) return '';

  const vsParts = text.split(/\s+vs\s+/i);
  if (vsParts.length > 1) return vsParts.map(labelCluster).join(' vs ');

  const underscoreParts = text.split('_');
  if (underscoreParts.length === 2 && underscoreParts.every(Boolean)) {
    const labelled = underscoreParts.map(labelCluster);
    if (labelled.some((label, index) => label !== underscoreParts[index])) return labelled.join(' vs ');
  }

  const spaceParts = text.split(/\s+/);
  if (spaceParts.length > 1) {
    const last = spaceParts[spaceParts.length - 1];
    const labelledLast = labelCluster(last);
    if (labelledLast !== last) return [...spaceParts.slice(0, -1), labelledLast].join(' ');
  }

  return labelCluster(text);
}

function variableLabel(metadata, value) {
  const text = String(value ?? '');
  return String(metadata?.labels?.variables?.[text] || DEFAULT_VARIABLE_LABELS[text] || text);
}

function ampPhaseText(row) {
  const primary = [];
  const amplitude = row.amplitude_display || row.amplitude;
  const phaseHr = row.phase_hr_display || row.phase_hr;
  const amplitude2 = row.amplitude_2_display || row.amplitude_2;
  const phaseHr2 = row.phase_hr_2_display || row.phase_hr_2;
  if (amplitude) primary.push(`amp ${amplitude}`);
  if (phaseHr) primary.push(`phase ${phaseHr} h`);
  const secondary = [];
  if (amplitude2) secondary.push(`amp ${amplitude2}`);
  if (phaseHr2) secondary.push(`phase ${phaseHr2} h`);
  if (!primary.length && !secondary.length) return '—';
  const chunks = [];
  if (primary.length) chunks.push(primary.join(', '));
  if (secondary.length) chunks.push(`second: ${secondary.join(', ')}`);
  return chunks.join('; ');
}

function CheckboxGroup({ label, options, value, onChange, optionLabel = (option) => option }) {
  const selected = new Set(value);
  return (
    <fieldset className="fieldset">
      <legend>{label}</legend>
      <div className="checkbox-list">
        {options.map((option) => (
          <label key={option}>
            <input
              type="checkbox"
              checked={selected.has(option)}
              onChange={(event) => {
                if (event.target.checked) onChange([...new Set([...value, option])]);
                else onChange(value.filter((item) => item !== option));
              }}
            />
            <span>{optionLabel(option)}</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function MultiSelect({ label, options, value, onChange, size = 5, optionLabel = (option) => option }) {
  return (
    <section className="control-group">
      <div className="control-row">
        <label className="control-label">{label}</label>
        <div className="mini-buttons">
          <button type="button" onClick={() => onChange(options)}>All</button>
          <button type="button" onClick={() => onChange([])}>Clear</button>
        </div>
      </div>
      <select
        multiple
        size={Math.min(Math.max(size, 3), Math.max(options.length, 3))}
        value={value}
        onChange={(event) => onChange(Array.from(event.target.selectedOptions).map((option) => option.value))}
      >
        {options.map((option) => (
          <option key={option} value={option}>{optionLabel(option)}</option>
        ))}
      </select>
    </section>
  );
}

function Tabs({ active, onActive }) {
  return (
    <nav className="tabs" aria-label="Result tabs">
      <button type="button" className={`tab ${active === 'about' ? 'active' : ''}`} onClick={() => onActive('about')}>About</button>
      <button type="button" className={`tab ${active === 'diurnal' ? 'active' : ''}`} onClick={() => onActive('diurnal')}>Diurnal Expression</button>
      <button type="button" className={`tab ${active === 'spatial' ? 'active' : ''}`} onClick={() => onActive('spatial')}>Spatial mean</button>
      <button type="button" className={`tab ${active === 'rhythmicity' ? 'active' : ''}`} onClick={() => onActive('rhythmicity')}>Rhythmicity statistics</button>
      <button type="button" className={`tab ${active === 'rostral_caudal' ? 'active' : ''}`} onClick={() => onActive('rostral_caudal')}>Rostral vs. caudal cortex</button>
      <button type="button" className={`tab ${active === 'hippocampus' ? 'active' : ''}`} onClick={() => onActive('hippocampus')}>Dorsal vs. ventral hippocampus</button>
    </nav>
  );
}

function LoadingApp({ message }) {
  return (
    <div className="app-shell single-panel">
      <header className="app-header">
        <div>
          <p className="brand-kicker"><a href="https://desplatslab.org/" target="_blank" rel="noreferrer">Desplats Lab</a> × <a href="https://brainome.ucsd.edu/" target="_blank" rel="noreferrer">Mukamel Lab</a> · UC San Diego</p>
          <h1>Spatio-Temporal Atlas of the Diurnal Mouse Brain Transcriptome</h1>
          <p className="subtitle">Spatial transcriptomics of 24-hour brain transcription in healthy and APP23 mouse brain</p>
        </div>
        <div className="status status-loading">Loading</div>
      </header>
      <main className="content loading-card">{message}</main>
    </div>
  );
}

function AboutPanel({ onNavigate }) {
  return (
    <section className="tab-panel active about-panel" aria-label="About this application">
      <div className="about-hero">
        <div className="about-hero-copy">
          <p className="brand-kicker">Gelber, Romero et al. · bioRxiv 2026</p>
          <h2>Spatio-Temporal Atlas of the Diurnal Mouse Brain Transcriptome</h2>
          <p className="about-lead">
            Comprehensive analysis of brain rhythm data using spatio-temporal transcriptomics (STT).
          </p>
          <div className="about-actions">
            <button type="button" className="primary-button" onClick={() => onNavigate('diurnal')}>Diurnal expression</button>
            <button type="button" onClick={() => onNavigate('rhythmicity')}>Rhythmicity statistics</button>
            <button type="button" onClick={() => onNavigate('rostral_caudal')}>Rostral vs. caudal cortex</button>
            <button type="button" onClick={() => onNavigate('hippocampus')}>Dorsal vs. ventral hippocampus</button>
          </div>
        </div>
      </div>

      <div className="about-info-grid">
        <article className="about-card">
          <h3>Citation</h3>
          <p><a href={PREPRINT_URL} target="_blank" rel="noreferrer">Gelber, Romero et al., bioRxiv 2026</a></p>
        </article>
        <article className="about-card">
          <h3>Contact</h3>
          <p>Alon Gelber (<a href="mailto:agelber@ucsd.edu">agelber@ucsd.edu</a>)</p>
          <p>Eran Mukamel (<a href="mailto:emukamel@ucsd.edu">emukamel@ucsd.edu</a>)</p>
          <p>Paula Desplats (<a href="mailto:pdesplat@ucsd.edu">pdesplat@ucsd.edu</a>)</p>
        </article>
        <article className="about-card">
          <h3>Labs</h3>
          <p><a href="https://desplatslab.org/" target="_blank" rel="noreferrer">Desplats Lab</a> at UCSD</p>
          <p><a href="https://brainome.ucsd.edu/" target="_blank" rel="noreferrer">Mukamel Lab</a> at UCSD</p>
        </article>
      </div>

      <article className="about-abstract">
        <h3>Abstract</h3>
        <p>
          Diurnal rhythms in brain transcription align neural, immune, and metabolic processes with the light-dark cycle and are profoundly disrupted in Alzheimer's disease (AD). However, the regional organization of diurnal transcription in the healthy and diseased brain remains poorly defined. Using large-scale spatial transcriptomics, we mapped 24-hour rhythmic transcription across cortical and subcortical regions of the mouse brain. We identified marked regional differences in rhythmicity, including distinct oscillatory signatures across cortical areas and along the rostro-caudal axis. In the APP23 mouse model of AD, pathology-vulnerable brain regions exhibited early, region-specific disruption of diurnal transcription prior to substantial amyloid plaque deposition. These findings reveal a spatially organized architecture of brain diurnal rhythms and identify early rhythmic dysregulation as a feature of Alzheimer's disease pathogenesis.
        </p>
      </article>

      <article className="about-card raw-data-card">
        <h3>Data download and external resources</h3>
        <p>
          Download the raw spatial transcriptomics data and metadata for the study dataset, including the 24-hour NTG and APP23 mouse brain samples, from <a href="https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=GSE282203" target="_blank" rel="noreferrer">GEO accession GSE282203</a>.
        </p>
        <p>
          Visualize ST datasets in the  <a href={RAW_DATA_BROWSER_URL} target="_blank" rel="noreferrer">KaroSpace browser</a> (external resource), including raw section views, annotations, and gene-expression exploration for the study dataset.
        </p>
      </article>
    </section>
  );
}

function RhythmicitySourceBadges({ counts = {} }) {
  const entries = Object.entries(counts || {});
  if (!entries.length) return null;
  return (
    <div className="source-badges" aria-label="Rhythmicity source counts">
      {entries.map(([source, count]) => (
        <span className="source-badge" key={source}>{sourceTableLabel(source)}: {formatCount(count)}</span>
      ))}
    </div>
  );
}

function RhythmicityResultsTable({ rows = [], labelCluster = (value) => value }) {
  const [sortConfig, setSortConfig] = useState({ key: '', direction: 'none' });
  const columns = [
    ['source', 'Source'],
    ['result', 'Result'],
    ['context', 'Context'],
    ['significance', 'FDR/padj'],
    ['pvalue', 'p value'],
    ['amp_phase', 'Amplitude / phase'],
    ['detail', 'Details'],
  ];
  const sortedRows = useMemo(() => {
    if (!sortConfig.key || sortConfig.direction === 'none') return rows;
    const direction = sortConfig.direction === 'asc' ? 1 : -1;
    return [...rows].sort((a, b) => {
      const av = sortValue(a, sortConfig.key, labelCluster);
      const bv = sortValue(b, sortConfig.key, labelCluster);
      const aMissing = av === null || av === undefined || av === '' || Number.isNaN(av);
      const bMissing = bv === null || bv === undefined || bv === '' || Number.isNaN(bv);
      if (aMissing && bMissing) return 0;
      if (aMissing) return 1;
      if (bMissing) return -1;
      if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * direction;
      return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' }) * direction;
    });
  }, [rows, sortConfig, labelCluster]);
  function toggleSort(key) {
    setSortConfig((current) => {
      if (current.key !== key) return { key, direction: 'asc' };
      if (current.direction === 'asc') return { key, direction: 'desc' };
      return { key: '', direction: 'none' };
    });
  }
  function sortMarker(key) {
    if (sortConfig.key !== key) return '';
    return sortConfig.direction === 'asc' ? ' ▲' : ' ▼';
  }
  if (!rows.length) return null;
  return (
    <div className="results-table-wrap">
      <table className="results-table sortable-table">
        <thead>
          <tr>
            {columns.map(([key, label]) => (
              <th key={key}>
                <button type="button" className="table-sort-button" onClick={() => toggleSort(key)}>
                  {label}{sortMarker(key)}
                </button>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sortedRows.map((row, index) => (
            <tr key={`${row.table_id}-${row.sheet}-${row.context}-${index}`}>
              <td><strong>{sourceTableLabel(row.table_id)}</strong><br /><span>{row.table_name}</span></td>
              <td>{row.result_type}</td>
              <td>{displayValue(row.context_display || labelClusterPhrase(row.context, labelCluster))}</td>
              <td><strong>{displayValue(row.significance_display)}</strong><br /><span>{row.significance_metric}</span></td>
              <td>{displayValue(row.pvalue_display)}</td>
              <td>{ampPhaseText(row)}</td>
              <td className="detail-cell">{displayValue(row.detail_display || row.detail)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}


function BasicRhythmicityCall({ label, result, threshold }) {
  if (!result) {
    return (
      <div className="basic-rhythm-call missing">
        <h4>{label}</h4>
        <p>No significant rhythmicity call at FDR &lt; {threshold}.</p>
      </div>
    );
  }
  return (
    <div className="basic-rhythm-call">
      <h4>{label}</h4>
      <dl className="rhythm-metric-list">
        <div>
          <dt>{displayValue(result.significance_metric, 'FDR')}</dt>
          <dd>{displayValue(result.significance_display)}</dd>
        </div>
        <div>
          <dt>p value</dt>
          <dd>{displayValue(result.pvalue_display)}</dd>
        </div>
        <div>
          <dt>amplitude</dt>
          <dd>{displayValue(result.amplitude)}</dd>
        </div>
        <div>
          <dt>peak ZT</dt>
          <dd>{result.phase_hr ? `${result.phase_hr} h` : '—'}</dd>
        </div>
      </dl>
      <p className="basic-rhythm-source">{sourceTableLabel(result.table_id)}: {result.table_name}</p>
    </div>
  );
}

function CompactRhythmicityUnderPlot({ gene, payload, error, labelCluster = (value) => value }) {
  const rows = Array.isArray(payload?.rows) ? payload.rows : [];
  const threshold = payload?.threshold ?? DEFAULT_RHYTHMICITY_THRESHOLD;
  return (
    <section className="under-plot-rhythm" aria-label={`Basic rhythmicity results for ${gene}`}>
      <div className="under-plot-rhythm-header">
        <div>
          <h2>Rhythmicity in plotted cluster</h2>
          <p>NTG and APP23 single-cluster rhythmicity</p>
        </div>
        {payload?.displayed_count !== undefined ? <span className="source-badge">{formatCount(payload.displayed_count)} shown</span> : null}
      </div>
      {error ? <div className="error-banner">Basic rhythmicity lookup failed. {error}</div> : null}
      {!payload && !error ? <div className="loading compact-loading">Loading basic rhythmicity results…</div> : null}
      {payload && !payload.available ? <div className="empty-results">No rhythmicity index was found on the backend.</div> : null}
      {payload && payload.available && !payload.found ? (
        <div className="empty-results">No exact supplementary-table rhythmicity match was found for {gene}.</div>
      ) : null}
      {payload && payload.found ? (
        rows.length ? (
          <div className="basic-rhythm-grid">
            {rows.map((row) => (
              <article className="basic-rhythm-cluster" key={row.cluster || row.requested_cluster}>
                <h3>{displayValue(row.cluster_display || labelClusterPhrase(row.cluster, labelCluster), row.requested_cluster_display || labelClusterPhrase(row.requested_cluster || 'Cluster', labelCluster))}</h3>
                <div className="basic-rhythm-calls">
                  <BasicRhythmicityCall label="NTG" result={row.ntg} threshold={threshold} />
                  <BasicRhythmicityCall label="APP23" result={row.app23} threshold={threshold} />
                </div>
              </article>
            ))}
            {payload.limited ? <p className="help-text">Showing the first selected clusters only. Narrow the region filter for a single-cluster summary.</p> : null}
          </div>
        ) : <div className="empty-results">No NTG or APP23 single-cluster rhythmicity row passed FDR &lt; {threshold} for {gene} in the selected cluster(s).</div>
      ) : null}
    </section>
  );
}

function RhythmicityPanel({
  currentGene,
  metadata,
  rhythmGeneInput,
  setRhythmGeneInput,
  rhythmQuery,
  setRhythmQuery,
  rhythmSource,
  setRhythmSource,
  rhythmThreshold,
  setRhythmThreshold,
  rhythmPayload,
  rhythmError,
  labelCluster,
}) {
  const sources = Array.isArray(metadata?.rhythmicity?.sources) ? metadata.rhythmicity.sources : [];
  const rows = Array.isArray(rhythmPayload?.rows) ? rhythmPayload.rows : [];
  const thresholdDisplay = Number(rhythmThreshold).toPrecision(2);
  const downloadUrl = apiUrl('/rhythmicity.tsv', {
    gene: rhythmPayload?.gene || rhythmQuery || rhythmGeneInput || currentGene,
    threshold: rhythmThreshold,
    source: rhythmSource,
    limit: 5000,
  });

  function submitSearch(event) {
    event.preventDefault();
    const nextQuery = String(rhythmGeneInput || '').trim() || currentGene;
    setRhythmGeneInput(nextQuery);
    setRhythmQuery(nextQuery);
  }

  return (
    <section className="tab-panel active" aria-label="Rhythmicity results">
      {rhythmError ? <div className="error-banner">Rhythmicity search failed. {rhythmError}</div> : null}
      <div className="rhythm-search-card">
        <form className="rhythm-search-form" onSubmit={submitSearch}>
          <label className="control-label" htmlFor="rhythmGeneInput">Search supplementary rhythmicity tables by gene</label>
          <div className="rhythm-search-row">
            <input
              id="rhythmGeneInput"
              className="text-input"
              value={rhythmGeneInput}
              onChange={(event) => setRhythmGeneInput(event.target.value)}
              placeholder="Gene symbol, e.g. Dbp"
              spellCheck="false"
            />
            <button type="submit" className="primary-button">Search</button>
            <button
              type="button"
              onClick={() => {
                setRhythmGeneInput(currentGene);
                setRhythmQuery(currentGene);
              }}
            >
              Use current plot gene
            </button>
          </div>
          <div className="rhythm-filter-row">
            <label>
              <span>Source</span>
              <select value={rhythmSource} onChange={(event) => setRhythmSource(event.target.value)}>
                <option value="all">All rhythmicity / DRG tables</option>
                {sources.map((source) => (
                  <option key={source.table_id} value={source.table_id}>
                    {source.table_id}: {source.label} ({formatCount(source.row_count)} rows)
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>Significance cutoff</span>
              <input
                className="text-input threshold-input"
                type="number"
                min="0.000001"
                max="0.1"
                step="0.01"
                value={rhythmThreshold}
                onChange={(event) => setRhythmThreshold(Number(event.target.value) || DEFAULT_RHYTHMICITY_THRESHOLD)}
              />
            </label>
          </div>
        </form>
        <p className="methods-note">
          Results are indexed from Sup Table 1, Sup Table 2, Sup Table 3, Sup Table 6, and Sup Table 10 and filtered to rows with the table-specific FDR-like value or padj &lt; {thresholdDisplay}. DEG-only and enrichment-only tables are intentionally excluded from this gene-level rhythmicity search.
        </p>
      </div>

      {!rhythmPayload && !rhythmError ? <div className="loading">Loading rhythmicity results…</div> : null}
      {rhythmPayload && !rhythmPayload.available ? <div className="error-banner">No rhythmicity index was found on the backend.</div> : null}
      {rhythmPayload && rhythmPayload.available && !rhythmPayload.found ? (
        <div className="empty-results">
          <h2>No exact rhythmicity-table match for “{rhythmPayload.input}”</h2>
          {Array.isArray(rhythmPayload.suggestions) && rhythmPayload.suggestions.length ? (
            <p>Suggestions: {rhythmPayload.suggestions.slice(0, 10).join(', ')}</p>
          ) : <p>No similar gene symbols were found in the indexed rhythmicity tables.</p>}
        </div>
      ) : null}
      {rhythmPayload && rhythmPayload.found ? (
        <>
          <div className="result-summary">
            <div>
              <h2>{rhythmPayload.gene}</h2>
              <p>{formatCount(rhythmPayload.count)} significant rhythmicity result{Number(rhythmPayload.count) === 1 ? '' : 's'} found. Showing {formatCount(rhythmPayload.displayed_count)}.</p>
            </div>
            {rows.length ? <a className="download-button" href={downloadUrl} download={`rhythmicity_${cleanFilename(rhythmPayload.gene)}.tsv`}>Download TSV</a> : null}
          </div>
          <RhythmicitySourceBadges counts={rhythmPayload.source_counts} />
          {rhythmPayload.limited ? <p className="help-text">The table is limited for browser performance. Download the TSV for more rows.</p> : null}
          {rows.length ? <RhythmicityResultsTable rows={rows} labelCluster={labelCluster} /> : <div className="empty-results">No rows passed the current source and significance filters.</div>}
        </>
      ) : null}
    </section>
  );
}

function DvResultsTable({ payload, labelCluster = (value) => value }) {
  const rows = Array.isArray(payload?.results) ? payload.results : [];
  if (!rows.length) {
    return <div className="empty-results compact-empty">No D/V DESeq2 FDR row was found for the current gene/filter.</div>;
  }
  return (
    <div className="dv-results-wrap">
      <table className="results-table dv-results-table">
        <thead>
          <tr>
            <th>Cluster</th>
            <th>FDR/padj</th>
            <th>log2FC D/V</th>
            <th>p value</th>
            <th>Direction</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`${row.cluster}-${index}`}>
              <td>{displayValue(row.cluster_display || labelCluster(row.cluster))}</td>
              <td><strong>{displayValue(row.fdr_display || row.padj_display)}</strong></td>
              <td>{displayValue(row.log2FoldChange_display)}</td>
              <td>{displayValue(row.pvalue_display)}</td>
              <td>{displayValue(row.direction)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DvFilterSelect({ label, value, onChange, options, labeler = (item) => item }) {
  return (
    <label>
      <span>{label}</span>
      <select value={value} onChange={(event) => onChange(event.target.value)}>
        {options.map((option) => (
          <option key={typeof option === 'string' ? option : option.id} value={typeof option === 'string' ? option : option.id}>
            {typeof option === 'string' ? labeler(option) : option.label}
          </option>
        ))}
      </select>
    </label>
  );
}

function HippocampusAllenPanel({
  currentGene,
  metadata,
  hipGeneInput,
  setHipGeneInput,
  hipGene,
  setHipGene,
  hipGeneOptions,
  hipCluster,
  setHipCluster,
  hipSplitBy,
  setHipSplitBy,
  hipPayload,
  hipError,
  hipPlotUrl,
  labelCluster,
  allenView,
  setAllenView,
  allenDownsample,
  setAllenDownsample,
  allenPayload,
  allenError,
}) {
  const hipMeta = metadata?.hippocampus_dv || {};
  const hipAvailable = Boolean(hipMeta.available);
  const clusterOptions = [{ id: 'all', label: 'All hippocampus clusters' }, ...(Array.isArray(hipMeta.clusters) ? hipMeta.clusters : [])];
  const choiceMeta = hipMeta.choices || {};
  const splitOptions = asArray(choiceMeta.split_by).length ? asArray(choiceMeta.split_by) : ['none', 'age', 'sex', 'age_sex'];

  function submitGene(event) {
    event.preventDefault();
    const nextGene = String(hipGeneInput || '').trim() || currentGene;
    setHipGeneInput(nextGene);
    setHipGene(nextGene);
  }

  const splitLabel = (value) => ({
    age_sex: 'Age + sex',
    age: 'Age',
    sex: 'Sex',
    none: 'Combined',
  }[value] || value);

  return (
    <section className="tab-panel active" aria-label="Dorsal and ventral hippocampus expression and Allen ISH">
      <div className="hippocampus-grid">
        <article className="hip-card">
          <h2>Dorsal / ventral hippocampus expression</h2>
          <form className="hip-gene-form" onSubmit={submitGene}>
            <label className="control-label" htmlFor="hipGeneInput">Gene</label>
            <div className="hip-search-row">
              <input
                id="hipGeneInput"
                className="text-input"
                list="hipGeneOptions"
                value={hipGeneInput}
                onChange={(event) => setHipGeneInput(event.target.value)}
                placeholder="Gene symbol, e.g. Lct"
                autoComplete="off"
                spellCheck="false"
              />
              <datalist id="hipGeneOptions">
                {hipGeneOptions.map((option) => <option key={option} value={option} />)}
              </datalist>
              <button type="submit" className="primary-button">Apply</button>
              <button
                type="button"
                onClick={() => {
                  setHipGeneInput(currentGene);
                  setHipGene(currentGene);
                }}
              >
                Use current plot gene
              </button>
            </div>
            <div className="dv-filter-grid">
              <DvFilterSelect label="Cluster" value={hipCluster} onChange={setHipCluster} options={clusterOptions} />
              <DvFilterSelect label="Split display by" value={hipSplitBy} onChange={setHipSplitBy} options={splitOptions} labeler={splitLabel} />
            </div>
            <p className="help-text">WT only. All available ages and sexes are included; this control only changes how the plot is split.</p>
          </form>

          {!hipAvailable ? (
            <div className="empty-results">
              <h2>D/V hippocampus data not installed</h2>
              <p>Copy the generated <code>data/hippocampus_dv</code> directory into the app data directory to enable this plot.</p>
            </div>
          ) : null}
          {hipError ? <div className="error-banner">D/V hippocampus lookup failed. {hipError}</div> : null}
          {hipAvailable && !hipPayload && !hipError ? <div className="loading">Loading D/V hippocampus expression…</div> : null}
          {hipPayload && hipPayload.available && !hipPayload.found ? (
            <div className="empty-results">
              <h2>No D/V hippocampus data for “{hipPayload.input || hipGene}”</h2>
              {Array.isArray(hipPayload.suggestions) && hipPayload.suggestions.length ? (
                <p>Suggestions: {hipPayload.suggestions.slice(0, 10).join(', ')}</p>
              ) : <p>No similar gene symbols were found in the D/V hippocampus index.</p>}
            </div>
          ) : null}
          {hipPayload && hipPayload.found ? (
            <>
              {Number(hipPayload.point_count || 0) > 0 ? (
                <div className="dv-plot-frame">
                  <img className="hip-plot-img" src={hipPlotUrl} alt={`Dorsal and ventral hippocampus expression plot for ${hipPayload.gene}`} />
                </div>
              ) : <div className="empty-results">No sample-level D/V expression points passed the current filters.</div>}
              <div className="dv-summary-row">
                <span className="source-badge">WT only</span>
                <span className="source-badge">Split: {hipPayload.split_by_label || splitLabel(hipSplitBy)}</span>
                <span className="source-badge">{formatCount(hipPayload.point_count)} points</span>
                <span className="source-badge">{formatCount(hipPayload.result_count)} FDR rows</span>
                {hipPayload.min_fdr_display ? <span className="source-badge">min FDR {hipPayload.min_fdr_display}</span> : null}
              </div>
              <DvResultsTable payload={hipPayload} labelCluster={labelCluster} />
              <p className="methods-note">
                Differential expression results, dorsal-vs-ventral in WT samples.
              </p>
            </>
          ) : null}
        </article>

        <article className="hip-card allen-card">
          <div className="allen-card-header">
            <div>
              <h2>Allen Brain Atlas ISH</h2>
              <p>Matched sagittal in situ hybridization</p>
            </div>
          </div>
          <div className="allen-controls">
            <label>
              <span>View</span>
              <select value={allenView} onChange={(event) => setAllenView(event.target.value)}>
                <option value="ish">Raw ISH</option>
                <option value="expression">Expression mask</option>
              </select>
            </label>
            <label>
              <span>Downsample</span>
              <select value={allenDownsample} onChange={(event) => setAllenDownsample(Number(event.target.value) || 4)}>
                {[2, 3, 4, 5, 6].map((value) => <option key={value} value={value}>{value}</option>)}
              </select>
            </label>
          </div>
          {allenError ? <div className="error-banner">Allen ISH lookup failed. {allenError}</div> : null}
          {!allenPayload && !allenError ? <div className="loading">Loading Allen ISH image…</div> : null}
          {allenPayload && !allenPayload.found ? (
            <div className="empty-results">
              <h2>No sagittal Allen ISH experiment found for “{allenPayload.input || hipGene}”</h2>
              <p>Try another gene symbol or check the Allen Brain Atlas directly.</p>
            </div>
          ) : null}
          {allenPayload && allenPayload.found ? (
            <>
              <div className="allen-image-frame">
                <AllenIshImage payload={allenPayload} />
              </div>
              <dl className="allen-meta">
                <div><dt>Gene</dt><dd>{allenPayload.gene}</dd></div>
                <div><dt>Dataset</dt><dd>{allenPayload.section_data_set_id}</dd></div>
                <div><dt>Image</dt><dd>{allenPayload.section_image_id}</dd></div>
                <div><dt>Section</dt><dd>{allenPayload.section_number || allenPayload.section_index}</dd></div>
                {allenPayload.atlas_section_ordinal ? <div><dt>Atlas plate</dt><dd>{allenPayload.atlas_section_ordinal}</dd></div> : null}
                {allenPayload.match_method ? <div><dt>Match</dt><dd>{allenPayload.match_method}</dd></div> : null}
              </dl>
              {allenPayload.viewer_url ? <a className="download-button" href={allenPayload.viewer_url} target="_blank" rel="noreferrer">Open in Allen viewer</a> : null}
            </>
          ) : null}
        </article>
      </div>
    </section>
  );
}




function stableUnitInterval(value) {
  const text = String(value ?? '');
  let hash = 2166136261;
  for (let index = 0; index < text.length; index += 1) {
    hash ^= text.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return ((hash >>> 0) % 10000) / 9999;
}

function svgPoints(points) {
  return points.map((point) => `${Number(point[0]).toFixed(2)},${Number(point[1]).toFixed(2)}`).join(' ');
}

function RostralCaudalReactPlot({ payload }) {
  const plot = payload?.plot;
  if (!plot) return <div className="empty-results compact-empty">No plot data were returned for this gene and cortical layer.</div>;

  const width = 760;
  const height = 590;
  const margin = { top: 58, right: 24, bottom: 118, left: 82 };
  const plotW = width - margin.left - margin.right;
  const plotH = height - margin.top - margin.bottom;
  const xMin = Number(plot.x_min ?? 0);
  const xMax = Number(plot.x_max ?? 42);
  const yMin = Number(plot.y_min ?? 0);
  const yMax = Number(plot.y_max ?? 1);
  const yRange = yMax - yMin || 1;
  const regions = Array.isArray(plot.regions) ? plot.regions : [];
  const regionById = Object.fromEntries(regions.map((region) => [region.id, region]));
  const yTicks = Array.isArray(plot.y_ticks) && plot.y_ticks.length ? plot.y_ticks.map(Number).filter(Number.isFinite) : [yMin, yMax];
  const yHasDecimals = yTicks.some((tick) => Math.abs(tick - Math.round(tick)) > 1e-6);
  const yTickLabel = (tick) => (yHasDecimals ? Number(tick).toFixed(1) : String(Number(tick).toFixed(0)));
  const xTicks = Array.isArray(plot.x_ticks) && plot.x_ticks.length ? plot.x_ticks : [
    { x: 0, label: '0' },
    { x: 12, label: '12' },
    { x: 24, label: '0' },
    { x: 36, label: '12' },
  ];
  const xScale = (value) => margin.left + ((Number(value) - xMin) / (xMax - xMin || 1)) * plotW;
  const yScale = (value) => margin.top + plotH - ((Number(value) - yMin) / yRange) * plotH;
  const colorFor = (region) => regionById[region]?.color || '#2563eb';
  const curveRows = Array.isArray(plot.curves) ? plot.curves : [];
  const rawPoints = Array.isArray(plot.points) ? plot.points : [];
  const summaries = Array.isArray(plot.summaries) ? plot.summaries : [];

  return (
    <div className="rc-plot-frame">
      <svg className="rc-react-plot" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`Rostral-caudal rhythmicity plot for ${payload.gene}`}>
        <rect width={width} height={height} fill="white" />
        <text x={width / 2} y="30" textAnchor="middle" className="rc-plot-title">{payload.gene}</text>
        <defs>
          <clipPath id="rcReactPlotClip">
            <rect x={margin.left} y={margin.top} width={plotW} height={plotH} />
          </clipPath>
        </defs>

        {[
          [0, 12, '#F6F18F'],
          [12, 24, '#606161'],
          [24, 36, '#F6F18F'],
          [36, 42, '#606161'],
        ].map(([start, end, color]) => (
          <rect
            key={`${start}-${end}`}
            x={xScale(start)}
            y={margin.top}
            width={xScale(end) - xScale(start)}
            height={plotH}
            fill={color}
            opacity="0.10"
          />
        ))}

        {[0, 12, 24, 36].map((tick) => (
          <line key={`xgrid-${tick}`} x1={xScale(tick)} y1={margin.top} x2={xScale(tick)} y2={margin.top + plotH} className="rc-grid-line" />
        ))}
        {yTicks.map((tick) => {
          const y = yScale(tick);
          return (
            <g key={`ytick-${tick}`}>
              <line x1={margin.left} y1={y} x2={margin.left + plotW} y2={y} className="rc-grid-line" />
              <text x={margin.left - 11} y={y + 4.5} textAnchor="end" className="rc-axis-text">{yTickLabel(tick)}</text>
            </g>
          );
        })}

        <g clipPath="url(#rcReactPlotClip)">
          {curveRows.map((curve) => {
            const points = (curve.points || []).map((point) => [xScale(point.x), yScale(point.y)]);
            return <polyline key={`curve-${curve.region}`} points={svgPoints(points)} fill="none" stroke={colorFor(curve.region)} strokeWidth="2.2" strokeLinejoin="round" strokeLinecap="round" />;
          })}

          {rawPoints.map((point, index) => {
            const jitter = (stableUnitInterval(point.sample_key || `${point.region}-${point.x}-${index}`) - 0.5) * 0.90;
            return (
              <circle
                key={`point-${index}`}
                cx={xScale(Number(point.x) + jitter)}
                cy={yScale(point.y)}
                r="2.1"
                fill={colorFor(point.region)}
                opacity="0.25"
              />
            );
          })}

          {summaries.map((summary, index) => {
            const x = xScale(summary.x);
            const meanY = yScale(summary.mean);
            const lowY = yScale(Number(summary.mean) - Number(summary.sd || 0));
            const highY = yScale(Number(summary.mean) + Number(summary.sd || 0));
            const color = colorFor(summary.region);
            return (
              <g key={`summary-${summary.region}-${summary.x}-${index}`}>
                <line x1={x} y1={lowY} x2={x} y2={highY} stroke={color} strokeWidth="1.35" />
                <line x1={x - 4.5} y1={lowY} x2={x + 4.5} y2={lowY} stroke={color} strokeWidth="1.35" />
                <line x1={x - 4.5} y1={highY} x2={x + 4.5} y2={highY} stroke={color} strokeWidth="1.35" />
                <circle cx={x} cy={meanY} r="4.7" fill={color} stroke="white" strokeWidth="0.8" />
              </g>
            );
          })}
        </g>

        <rect x={margin.left} y={margin.top} width={plotW} height={plotH} fill="none" className="rc-axis-box" />
        {xTicks.map((tick) => {
          const x = xScale(tick.x);
          return (
            <g key={`xtick-${tick.x}`}>
              <line x1={x} y1={margin.top + plotH} x2={x} y2={margin.top + plotH + 5} className="rc-axis-tick" />
              <text x={x} y={margin.top + plotH + 23} textAnchor="middle" className="rc-axis-text">{tick.label}</text>
            </g>
          );
        })}
        <text x={width / 2} y={margin.top + plotH + 55} textAnchor="middle" className="rc-axis-label">Zeitgeber Time (double plotted)</text>
        <text x="24" y={margin.top + plotH / 2} transform={`rotate(-90 24 ${margin.top + plotH / 2})`} textAnchor="middle" className="rc-axis-label">log2 Normalized mRNA Expression</text>

        <g transform={`translate(${margin.left + 150}, ${height - 50})`}>
          <text x="-96" y="5" className="rc-legend-title">Cortical position</text>
          {regions.map((region, index) => (
            <g key={region.id} transform={`translate(${index * 158}, 0)`}>
              <line x1="0" y1="0" x2="22" y2="0" stroke={region.color || '#2563eb'} strokeWidth="3.2" />
              <text x="30" y="5" className="rc-legend-text">{region.label}</text>
            </g>
          ))}
        </g>
      </svg>
    </div>
  );
}

function AllenIshImage({ payload }) {
  if (!payload?.image_url) return null;
  return (
    <img
      className="allen-ish-img"
      src={payload.image_url}
      alt={`Allen Brain Atlas ISH for ${payload.gene}`}
      onError={(event) => {
        if (payload.allen_image_url && event.currentTarget.src !== payload.allen_image_url) {
          event.currentTarget.src = payload.allen_image_url;
        }
      }}
    />
  );
}

function RostralCaudalPanel({
  metadata,
  currentGene,
  rcGeneInput,
  setRcGeneInput,
  rcGene,
  setRcGene,
  rcGeneOptions,
  rcCluster,
  setRcCluster,
  rcPayload,
  rcError,
  rcDownloadUrl,
}) {
  const rcMeta = metadata?.rostral_caudal || {};
  const available = Boolean(rcMeta.available);
  const clusterOptions = Array.isArray(rcMeta.clusters) ? rcMeta.clusters : [];

  function submitGene(event) {
    event.preventDefault();
    const nextGene = String(rcGeneInput || '').trim() || currentGene || DEFAULT_ROSTRAL_CAUDAL_GENE;
    setRcGeneInput(nextGene);
    setRcGene(nextGene);
  }

  return (
    <section className="tab-panel active" aria-label="Rostral vs. caudal cortex">
      <div className="rc-panel-header">
        <div>
          <h2>Rostral vs. caudal cortex</h2>
          <p className="methods-note">Cortical rhythmicity plotted across rostral, intermediate, and caudal cortical positions. Time is double-plotted to show the repeating 24-hour cycle.</p>
        </div>
        {available ? <span className="source-badge">{formatCount(rcMeta.gene_count)} genes</span> : null}
      </div>

      <form className="rhythm-search-form rc-controls" onSubmit={submitGene}>
        <label className="control-label" htmlFor="rcGeneInput">Gene</label>
        <div className="rhythm-search-row">
          <input
            id="rcGeneInput"
            className="text-input"
            list="rcGeneOptions"
            value={rcGeneInput}
            onChange={(event) => setRcGeneInput(event.target.value)}
            placeholder="Gene symbol, e.g. Dbp"
            autoComplete="off"
            spellCheck="false"
          />
          <datalist id="rcGeneOptions">
            {rcGeneOptions.map((option) => <option key={option} value={option} />)}
          </datalist>
          <button type="submit" className="primary-button">Apply gene</button>
          <button type="button" onClick={() => { setRcGeneInput(currentGene); setRcGene(currentGene); }}>Use current plot gene</button>
        </div>
        <label className="control-label" htmlFor="rcCluster">Cortical layer</label>
        <select id="rcCluster" value={rcCluster} onChange={(event) => setRcCluster(event.target.value)}>
          {clusterOptions.map((cluster) => (
            <option key={cluster.id} value={cluster.id}>{cluster.label || cluster.id}</option>
          ))}
        </select>
      </form>

      {!available ? (
        <div className="empty-results">
          <h2>Rostral-caudal rhythmicity data not installed</h2>
          <p>Run the rostral-caudal SQLite export and install <code>data-private/rostral_caudal.sqlite</code> to enable this tab.</p>
        </div>
      ) : null}
      {rcError ? <div className="error-banner">Rostral-caudal lookup failed. {rcError}</div> : null}
      {available && !rcPayload && !rcError ? <div className="loading">Loading rostral-caudal rhythmicity…</div> : null}
      {rcPayload && rcPayload.available && !rcPayload.found ? (
        <div className="empty-results">
          <h2>No rostral-caudal data for “{rcPayload.input || rcGene}”</h2>
          {Array.isArray(rcPayload.suggestions) && rcPayload.suggestions.length ? <p>Suggestions: {rcPayload.suggestions.slice(0, 10).join(', ')}</p> : <p>Try another gene symbol or cortical layer.</p>}
        </div>
      ) : null}
      {rcPayload && rcPayload.found ? (
        <>
          <RostralCaudalReactPlot payload={rcPayload} />
          <div className="result-summary compact-summary">
            <div>
              <h2>{rcPayload.gene}</h2>
              <p>{rcPayload.cluster_label || rcPayload.cluster} · {formatCount(rcPayload.point_count)} expression points · {formatCount(rcPayload.model_count)} model fits</p>
            </div>
            <a className="download-button" href={rcDownloadUrl} download={`rostral_caudal_${cleanFilename(rcPayload.gene)}.svg`}>Download SVG</a>
          </div>
        </>
      ) : null}
    </section>
  );
}

export default function DiurnalExplorer() {
  const [metadata, setMetadata] = useState(null);
  const [fatalError, setFatalError] = useState('');
  const [status, setStatus] = useState('Connecting');
  const [activeTab, setActiveTab] = useState('about');

  const [gene, setGene] = useState(DEFAULT_GENE);
  const [geneInput, setGeneInput] = useState(DEFAULT_GENE);
  const [geneOptions, setGeneOptions] = useState([]);
  const [geneMessage, setGeneMessage] = useState('');
  const [geneBusy, setGeneBusy] = useState(false);

  const [includeRegion, setIncludeRegion] = useState([]);
  const [includeAge, setIncludeAge] = useState([]);
  const [includeSex, setIncludeSex] = useState([]);
  const [includeGenotype, setIncludeGenotype] = useState([]);
  const [colorBy, setColorBy] = useState(DEFAULT_COLOR_BY);
  const [splitBy, setSplitBy] = useState([]);
  const [gamma, setGamma] = useState(1.7);
  const [spatialPayload, setSpatialPayload] = useState(null);
  const [spatialError, setSpatialError] = useState('');
  const [plotError, setPlotError] = useState('');
  const [refreshToken, setRefreshToken] = useState(0);

  const [rhythmGeneInput, setRhythmGeneInput] = useState(DEFAULT_GENE);
  const [rhythmQuery, setRhythmQuery] = useState(DEFAULT_GENE);
  const [rhythmSource, setRhythmSource] = useState('all');
  const [rhythmThreshold, setRhythmThreshold] = useState(DEFAULT_RHYTHMICITY_THRESHOLD);
  const [rhythmPayload, setRhythmPayload] = useState(null);
  const [rhythmError, setRhythmError] = useState('');
  const [plotBasicRhythmPayload, setPlotBasicRhythmPayload] = useState(null);
  const [plotBasicRhythmError, setPlotBasicRhythmError] = useState('');

  const [hipGeneInput, setHipGeneInput] = useState(DEFAULT_HIPPOCAMPUS_DV_GENE);
  const [hipGene, setHipGene] = useState(DEFAULT_HIPPOCAMPUS_DV_GENE);
  const [hipGeneOptions, setHipGeneOptions] = useState([]);
  const [hipCluster, setHipCluster] = useState(DEFAULT_HIPPOCAMPUS_DV_CLUSTER);
  const [hipSplitBy, setHipSplitBy] = useState('none');
  const [hipPayload, setHipPayload] = useState(null);
  const [hipError, setHipError] = useState('');
  const [allenView, setAllenView] = useState('ish');
  const [allenDownsample, setAllenDownsample] = useState(4);
  const [allenPayload, setAllenPayload] = useState(null);
  const [allenError, setAllenError] = useState('');

  const [rcGeneInput, setRcGeneInput] = useState(DEFAULT_ROSTRAL_CAUDAL_GENE);
  const [rcGene, setRcGene] = useState(DEFAULT_ROSTRAL_CAUDAL_GENE);
  const [rcGeneOptions, setRcGeneOptions] = useState([]);
  const [rcCluster, setRcCluster] = useState(DEFAULT_ROSTRAL_CAUDAL_CLUSTER);
  const [rcPayload, setRcPayload] = useState(null);
  const [rcError, setRcError] = useState('');

  const applyDefaults = useCallback((nextMetadata) => {
    if (!nextMetadata) return;
    const defaults = nextMetadata.defaults || {};
    const choices = nextMetadata.choices || {};
    const nextGene = String(defaults.gene || DEFAULT_GENE);
    setGene(nextGene);
    setGeneInput(nextGene);
    setGeneMessage('');
    setIncludeRegion(normalizeDefaults(nextMetadata, 'include_region').length ? normalizeDefaults(nextMetadata, 'include_region') : asArray(choices.region));
    setIncludeAge(normalizeDefaults(nextMetadata, 'include_age').length ? normalizeDefaults(nextMetadata, 'include_age') : asArray(choices.age));
    setIncludeSex(normalizeDefaults(nextMetadata, 'include_sex').length ? normalizeDefaults(nextMetadata, 'include_sex') : asArray(choices.sex));
    setIncludeGenotype(normalizeDefaults(nextMetadata, 'include_genotype').length ? normalizeDefaults(nextMetadata, 'include_genotype') : asArray(choices.genotype));
    setColorBy(String(defaults.color_by || DEFAULT_COLOR_BY));
    setSplitBy(normalizeDefaults(nextMetadata, 'split_by'));
    setGamma(Number(defaults.gamma || 1.7));
    setRhythmGeneInput(nextGene);
    setRhythmQuery(nextGene);
    setRhythmSource('all');
    setRhythmThreshold(Number(nextMetadata.rhythmicity?.default_threshold || DEFAULT_RHYTHMICITY_THRESHOLD));
    const hipDefaults = nextMetadata.hippocampus_dv || {};
    const nextHipGene = String(hipDefaults.default_gene || DEFAULT_HIPPOCAMPUS_DV_GENE);
    setHipGeneInput(nextHipGene);
    setHipGene(nextHipGene);
    setHipGeneOptions(nextHipGene ? [nextHipGene] : []);
    setHipCluster(String(hipDefaults.default_cluster || DEFAULT_HIPPOCAMPUS_DV_CLUSTER));
    setHipSplitBy(String(hipDefaults.split_by_default || 'none'));
    setHipPayload(null);
    setHipError('');
    setAllenView(nextMetadata.allen?.default_view || 'ish');
    setAllenDownsample(Number(nextMetadata.allen?.default_downsample || 4));
    const rcDefaults = nextMetadata.rostral_caudal || {};
    const nextRcGene = String(rcDefaults.default_gene || DEFAULT_ROSTRAL_CAUDAL_GENE);
    setRcGeneInput(nextRcGene);
    setRcGene(nextRcGene);
    setRcGeneOptions(nextRcGene ? [nextRcGene] : []);
    setRcCluster(String(rcDefaults.default_cluster || DEFAULT_ROSTRAL_CAUDAL_CLUSTER));
    setRcPayload(null);
    setRcError('');
    setRefreshToken((value) => value + 1);
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    async function loadMetadata() {
      try {
        setStatus('Connecting');
        const nextMetadata = await fetchJson('/metadata', {}, controller.signal);
        setMetadata(nextMetadata);
        applyDefaults(nextMetadata);
        const genes = await fetchGenes(nextMetadata.defaults?.gene || DEFAULT_GENE, controller.signal);
        setGeneOptions(genes);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setFatalError(`Could not initialize the app. ${error.message}`);
        setStatus('Error');
      }
    }
    loadMetadata();
    return () => controller.abort();
  }, [applyDefaults]);

  useEffect(() => {
    if (!metadata) return undefined;
    let controller;
    const handle = setTimeout(() => {
      controller = new AbortController();
      fetchGenes(geneInput.trim(), controller.signal)
        .then((genes) => setGeneOptions(genes))
        .catch((error) => {
          if (error.name !== 'AbortError') setGeneOptions([]);
        });
    }, 200);
    return () => {
      clearTimeout(handle);
      if (controller) controller.abort();
    };
  }, [geneInput, metadata]);

  const applyGene = useCallback((nextGene, nextSuggestions = []) => {
    const cleaned = String(nextGene || '').trim();
    if (!cleaned) return;
    setGene(cleaned);
    setGeneInput(cleaned);
    setGeneOptions(nextSuggestions.length ? nextSuggestions : [cleaned]);
    setGeneMessage(`Current plot gene: ${cleaned}`);
    setRhythmGeneInput(cleaned);
    setRhythmQuery(cleaned);
    setPlotError('');
    setSpatialError('');
    setRhythmError('');
    setPlotBasicRhythmError('');
    setRefreshToken((value) => value + 1);
  }, []);

  const commitGene = useCallback(async (candidate = geneInput) => {
    const raw = String(candidate || '').trim();
    if (!raw) {
      setGeneMessage('Enter a gene symbol, then press Enter or Apply gene.');
      return;
    }

    const localExact = exactOption(geneOptions, raw);
    if (localExact) {
      // Values returned by /api/genes are already known plot genes. Apply them immediately
      // so a selected suggestion cannot be blocked by a stale/failed resolve request.
      applyGene(localExact, geneOptions);
      return;
    }

    const controller = new AbortController();
    setGeneBusy(true);
    setGeneMessage('Checking gene…');
    try {
      const resolved = await resolveGene(raw, controller.signal);
      if (resolved.found && resolved.gene) {
        applyGene(resolved.gene, resolved.suggestions.length ? resolved.suggestions : [resolved.gene]);
      } else {
        setGeneOptions(resolved.suggestions);
        setGeneMessage(resolved.suggestions.length
          ? 'Gene not applied. Select an exact gene from the suggestions or type the full symbol.'
          : `No gene found matching “${raw}”.`);
      }
    } catch (error) {
      if (error.name !== 'AbortError') setGeneMessage(`Gene lookup failed. ${error.message}`);
    } finally {
      setGeneBusy(false);
    }
  }, [applyGene, geneInput, geneOptions]);

  const choices = useMemo(() => ({
    region: normalizeChoices(metadata, 'region'),
    age: normalizeChoices(metadata, 'age'),
    sex: normalizeChoices(metadata, 'sex'),
    genotype: normalizeChoices(metadata, 'genotype'),
    color_by: normalizeChoices(metadata, 'color_by'),
    split_by: normalizeChoices(metadata, 'split_by'),
  }), [metadata]);
  const labelCluster = useMemo(() => makeClusterLabeler(metadata), [metadata]);
  const labelVariable = useCallback((value) => variableLabel(metadata, value), [metadata]);

  const plotParams = useMemo(() => ({
    gene,
    include_region: includeRegion,
    include_age: includeAge,
    include_sex: includeSex,
    include_genotype: includeGenotype,
    color_by: colorBy,
    split_by: splitBy,
  }), [gene, includeRegion, includeAge, includeSex, includeGenotype, colorBy, splitBy]);

  const plotSvgUrl = useMemo(() => apiUrl('/plot.svg', { ...plotParams, width: 760, _: refreshToken }), [plotParams, refreshToken]);
  const plotDownloadUrl = useMemo(() => apiUrl('/plot.svg', { ...plotParams, width: 980, download: 1 }), [plotParams]);
  const hipPlotUrl = useMemo(() => apiUrl('/hippocampus-dv/plot.svg', {
    gene: hipGene,
    cluster: hipCluster,
    split_by: hipSplitBy,
    _: refreshToken,
  }), [hipGene, hipCluster, hipSplitBy, refreshToken]);
  const rcDownloadUrl = useMemo(() => apiUrl('/rostral-caudal/plot.svg', {
    gene: rcGene,
    cluster: rcCluster,
    download: 1,
  }), [rcGene, rcCluster]);

  useEffect(() => {
    if (!metadata || activeTab !== 'diurnal') return;
    setPlotError('');
    setStatus('Rendering');
  }, [metadata, activeTab, plotSvgUrl]);

  useEffect(() => {
    if (!metadata || activeTab !== 'spatial') return undefined;
    const controller = new AbortController();
    async function loadSpatial() {
      try {
        setSpatialPayload(null);
        setSpatialError('');
        setStatus('Rendering');
        const payload = await fetchJson('/spatial', { gene: plotParams.gene, gamma }, controller.signal);
        setSpatialPayload(payload);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setSpatialError(error.message);
        setStatus('Error');
      }
    }
    loadSpatial();
    return () => controller.abort();
  }, [metadata, activeTab, plotParams.gene, gamma, refreshToken]);

  useEffect(() => {
    if (!metadata) return undefined;
    const controller = new AbortController();
    async function loadPlotBasicRhythmicity() {
      try {
        setPlotBasicRhythmPayload(null);
        setPlotBasicRhythmError('');
        const payload = await fetchRhythmicityBasic({
          gene,
          clusters: includeRegion,
          threshold: metadata.rhythmicity?.default_threshold || DEFAULT_RHYTHMICITY_THRESHOLD,
          limit: 12,
        }, controller.signal);
        setPlotBasicRhythmPayload(payload);
      } catch (error) {
        if (error.name === 'AbortError') return;
        setPlotBasicRhythmError(error.message);
      }
    }
    loadPlotBasicRhythmicity();
    return () => controller.abort();
  }, [metadata, gene, includeRegion]);

  useEffect(() => {
    if (!metadata || activeTab !== 'rhythmicity') return undefined;
    const controller = new AbortController();
    async function loadRhythmicity() {
      try {
        setRhythmPayload(null);
        setRhythmError('');
        setStatus('Rendering');
        const payload = await fetchRhythmicity({
          gene: rhythmQuery,
          threshold: rhythmThreshold,
          source: rhythmSource,
          limit: 500,
        }, controller.signal);
        setRhythmPayload(payload);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setRhythmError(error.message);
        setStatus('Error');
      }
    }
    loadRhythmicity();
    return () => controller.abort();
  }, [metadata, activeTab, rhythmQuery, rhythmThreshold, rhythmSource]);

  useEffect(() => {
    if (!metadata || activeTab !== 'rostral_caudal') return undefined;
    let controller;
    const handle = setTimeout(() => {
      controller = new AbortController();
      fetchRostralCaudalGenes(rcGeneInput.trim(), controller.signal)
        .then((genes) => setRcGeneOptions(genes))
        .catch((error) => {
          if (error.name !== 'AbortError') setRcGeneOptions([]);
        });
    }, 200);
    return () => {
      clearTimeout(handle);
      if (controller) controller.abort();
    };
  }, [metadata, activeTab, rcGeneInput]);

  useEffect(() => {
    if (!metadata || activeTab !== 'rostral_caudal') return undefined;
    const controller = new AbortController();
    async function loadRostralCaudal() {
      try {
        setRcPayload(null);
        setRcError('');
        setStatus('Rendering');
        const payload = await fetchRostralCaudal({ gene: rcGene, cluster: rcCluster }, controller.signal);
        setRcPayload(payload);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setRcError(error.message);
        setStatus('Error');
      }
    }
    loadRostralCaudal();
    return () => controller.abort();
  }, [metadata, activeTab, rcGene, rcCluster, refreshToken]);

  useEffect(() => {
    if (!metadata || activeTab !== 'hippocampus') return undefined;
    let controller;
    const handle = setTimeout(() => {
      controller = new AbortController();
      fetchHippocampusDvGenes(hipGeneInput.trim(), controller.signal)
        .then((genes) => setHipGeneOptions(genes))
        .catch((error) => {
          if (error.name !== 'AbortError') setHipGeneOptions([]);
        });
    }, 200);
    return () => {
      clearTimeout(handle);
      if (controller) controller.abort();
    };
  }, [metadata, activeTab, hipGeneInput]);

  useEffect(() => {
    if (!metadata || activeTab !== 'hippocampus') return undefined;
    const controller = new AbortController();
    async function loadHippocampusDv() {
      try {
        setHipPayload(null);
        setHipError('');
        setStatus('Rendering');
        const payload = await fetchHippocampusDv({
          gene: hipGene,
          cluster: hipCluster,
          split_by: hipSplitBy,
        }, controller.signal);
        setHipPayload(payload);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setHipError(error.message);
        setStatus('Error');
      }
    }
    loadHippocampusDv();
    return () => controller.abort();
  }, [metadata, activeTab, hipGene, hipCluster, hipSplitBy]);

  useEffect(() => {
    if (!metadata || activeTab !== 'hippocampus') return undefined;
    const controller = new AbortController();
    async function loadAllenIsh() {
      try {
        setAllenPayload(null);
        setAllenError('');
        setStatus('Rendering');
        const payload = await fetchAllenIsh({ gene: hipGene, view: allenView, downsample: allenDownsample }, controller.signal);
        setAllenPayload(payload);
        setStatus('Ready');
      } catch (error) {
        if (error.name === 'AbortError') return;
        setAllenError(error.message);
        setStatus('Error');
      }
    }
    loadAllenIsh();
    return () => controller.abort();
  }, [metadata, activeTab, hipGene, allenView, allenDownsample]);

  if (fatalError) return <LoadingApp message={<div className="error-banner">{fatalError}</div>} />;
  if (!metadata) return <LoadingApp message="Loading application metadata…" />;

  const geneSelectValue = geneOptions.includes(geneInput) ? geneInput : '';
  const hideSidebar = ['rhythmicity', 'rostral_caudal', 'hippocampus'].includes(activeTab);
  const mainClassName = activeTab === 'about' ? 'layout about-layout' : hideSidebar ? 'layout full-width-layout' : 'layout';

  return (
    <div className="app-shell">
      <header className="app-header">
        <div>
          <p className="brand-kicker"><a href="https://desplatslab.org/" target="_blank" rel="noreferrer">Desplats Lab</a> × <a href="https://brainome.ucsd.edu/" target="_blank" rel="noreferrer">Mukamel Lab</a> · UC San Diego</p>
          <h1>Spatio-Temporal Atlas of the Diurnal Mouse Brain Transcriptome</h1>
          <p className="subtitle">Spatial transcriptomics of 24-hour brain transcription in healthy and APP23 mouse brain</p>
        </div>
      </header>

      <main className={mainClassName}>
        {activeTab !== 'about' && !hideSidebar ? (
        <aside className="controls" aria-label="Plot controls">
          {activeTab === 'diurnal' || activeTab === 'spatial' ? (
            <>
              <label className="control-label" htmlFor="geneInput">Gene</label>
              <input
                id="geneInput"
                className="text-input"
                list="geneOptions"
                value={geneInput}
                onChange={(event) => setGeneInput(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault();
                    commitGene(event.currentTarget.value);
                  }
                }}
                onBlur={() => {
                  const hit = exactOption(geneOptions, geneInput);
                  if (hit && hit !== gene) commitGene(hit);
                }}
                placeholder="Type gene name…"
                autoComplete="off"
                spellCheck="false"
              />
              <datalist id="geneOptions">
                {geneOptions.map((option) => <option key={option} value={option} />)}
              </datalist>
              <select
                className="gene-select"
                size={Math.min(Math.max(geneOptions.length + 1, 3), 7)}
                value={geneSelectValue}
                onChange={(event) => {
                  const selected = event.target.value;
                  if (selected) {
                    setGeneInput(selected);
                    commitGene(selected);
                  }
                }}
                aria-label="Gene suggestions"
              >
                <option value="" disabled>{geneOptions.length ? 'Select a suggested gene' : 'No suggestions'}</option>
                {geneOptions.map((option) => <option key={option} value={option}>{option}</option>)}
              </select>
              <div className="gene-button-row">
                <button type="button" className="primary-button" disabled={geneBusy} onClick={() => commitGene()}>{geneBusy ? 'Checking…' : 'Apply gene'}</button>
              </div>
              <p className="help-text">
                {metadata.gene_count?.toLocaleString?.() || metadata.gene_count} genes available. Current plot gene: <strong>{gene}</strong>.
              </p>
              {geneMessage ? <p className={`gene-message ${geneMessage.startsWith('Current') ? 'gene-message-ok' : ''}`}>{geneMessage}</p> : null}
            </>
          ) : null}

          {activeTab === 'diurnal' ? (
            <>
              <MultiSelect label="Include Regions" options={choices.region} value={includeRegion} onChange={setIncludeRegion} size={8} optionLabel={labelCluster} />
              <MultiSelect label="Include Ages" options={choices.age} value={includeAge} onChange={setIncludeAge} size={3} />
              <MultiSelect label="Include Sexes" options={choices.sex} value={includeSex} onChange={setIncludeSex} size={3} />
              <MultiSelect label="Include Genotypes" options={choices.genotype} value={includeGenotype} onChange={setIncludeGenotype} size={3} />

              <hr />

              <label className="control-label" htmlFor="colorBy">Color by</label>
              <select id="colorBy" value={colorBy} onChange={(event) => setColorBy(event.target.value)}>
                {choices.color_by.map((option) => <option key={option} value={option}>{labelVariable(option)}</option>)}
              </select>

              <CheckboxGroup label="Split by" options={choices.split_by} value={splitBy} onChange={setSplitBy} optionLabel={labelVariable} />
            </>
          ) : null}

          {activeTab === 'spatial' ? (
            <>
              <label className="control-label" htmlFor="gamma">Spatial scale contrast <span className="gamma-value">{Number(gamma).toFixed(1)}</span></label>
              <input id="gamma" type="range" min="0.5" max="3" step="0.1" value={gamma} onChange={(event) => setGamma(Number(event.target.value))} />
            </>
          ) : null}
        </aside>
        ) : null}

        <section className="content">
          <Tabs active={activeTab} onActive={setActiveTab} />

          {activeTab === 'about' ? <AboutPanel onNavigate={setActiveTab} /> : null}

          {activeTab === 'diurnal' ? (
            <section className="tab-panel active" aria-label="Diurnal Expression">
              {plotError ? <div className="error-banner">Plot request failed. {plotError}</div> : null}
              <img
                key={plotSvgUrl}
                className="plot-img"
                src={plotSvgUrl}
                alt={`Diurnal expression plot for ${plotParams.gene}`}
                onLoad={() => { setPlotError(''); setStatus('Ready'); }}
                onError={() => { setPlotError('The backend did not return a displayable SVG.'); setStatus('Error'); }}
              />
              <p className="methods-note">Small dots represent individual animals. Large dots and error bars show the mean ± 1 SD per timepoint and group. The solid line is the fitted sinusoidal model, double-plotted. Yellow/grey shading indicates light and dark phases, respectively.</p>
              <CompactRhythmicityUnderPlot gene={plotParams.gene} payload={plotBasicRhythmPayload} error={plotBasicRhythmError} labelCluster={labelCluster} />
              <a className="download-button" href={plotDownloadUrl} download={`circadian_${cleanFilename(plotParams.gene)}.svg`}>Download SVG</a>
            </section>
          ) : null}

          {activeTab === 'spatial' ? (
            <section className="tab-panel active" aria-label="Spatial mean">
              {spatialError ? <div className="error-banner">Spatial request failed. {spatialError}</div> : null}
              {!spatialPayload ? <div className="loading">Loading spatial maps…</div> : null}
              {spatialPayload ? (
                <>
                  <div className="spatial-grid">
                    {PANEL_KEYS.map((key) => (
                      <article className="spatial-card" key={key}>
                        <h2>{spatialPayload.titles?.[key] || key}</h2>
                        <div dangerouslySetInnerHTML={{ __html: spatialPayload.panels?.[key] || '<div class="spatial-svg empty">No data</div>' }} />
                      </article>
                    ))}
                  </div>
                  <div className="spatial-legend" dangerouslySetInnerHTML={{ __html: spatialPayload.legend || '' }} />
                </>
              ) : null}
            </section>
          ) : null}

          {activeTab === 'rhythmicity' ? (
            <RhythmicityPanel
              currentGene={gene}
              metadata={metadata}
              rhythmGeneInput={rhythmGeneInput}
              setRhythmGeneInput={setRhythmGeneInput}
              rhythmQuery={rhythmQuery}
              setRhythmQuery={setRhythmQuery}
              rhythmSource={rhythmSource}
              setRhythmSource={setRhythmSource}
              rhythmThreshold={rhythmThreshold}
              setRhythmThreshold={setRhythmThreshold}
              rhythmPayload={rhythmPayload}
              rhythmError={rhythmError}
              labelCluster={labelCluster}
            />
          ) : null}

          {activeTab === 'rostral_caudal' ? (
            <RostralCaudalPanel
              metadata={metadata}
              currentGene={gene}
              rcGeneInput={rcGeneInput}
              setRcGeneInput={setRcGeneInput}
              rcGene={rcGene}
              setRcGene={setRcGene}
              rcGeneOptions={rcGeneOptions}
              rcCluster={rcCluster}
              setRcCluster={setRcCluster}
              rcPayload={rcPayload}
              rcError={rcError}
              rcDownloadUrl={rcDownloadUrl}
            />
          ) : null}

          {activeTab === 'hippocampus' ? (
            <HippocampusAllenPanel
              currentGene={gene}
              metadata={metadata}
              hipGeneInput={hipGeneInput}
              setHipGeneInput={setHipGeneInput}
              hipGene={hipGene}
              setHipGene={setHipGene}
              hipGeneOptions={hipGeneOptions}
              hipCluster={hipCluster}
              setHipCluster={setHipCluster}
              hipSplitBy={hipSplitBy}
              setHipSplitBy={setHipSplitBy}
              hipPayload={hipPayload}
              hipError={hipError}
              hipPlotUrl={hipPlotUrl}
              labelCluster={labelCluster}
              allenView={allenView}
              setAllenView={setAllenView}
              allenDownsample={allenDownsample}
              setAllenDownsample={setAllenDownsample}
              allenPayload={allenPayload}
              allenError={allenError}
            />
          ) : null}
        </section>
      </main>
    </div>
  );
}
