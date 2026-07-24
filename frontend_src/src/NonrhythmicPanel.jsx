import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  fetchNonrhythmicExpression,
  fetchNonrhythmicGenes,
  fetchNonrhythmicResults,
  resolveNonrhythmicGene,
} from './api.js';
import {
  NonrhythmicComparisonHelp,
  NonrhythmicStatisticsHelp,
} from './NonrhythmicHelp.jsx';
import NonrhythmicExpressionPlot from './NonrhythmicExpressionPlot.jsx';
import NonrhythmicResult from './NonrhythmicResult.jsx';
import NonrhythmicResultsTable from './NonrhythmicResultsTable.jsx';
import {
  comparisonFamilyLabel,
  contrastOptionLabel,
  contrastSelectLabel,
  visibleContrastCatalog,
} from './nonrhythmicUi.js';
import { displaySex, displaySexText } from './plot/nonrhythmicPlotLayout.js';

const FALLBACK_GENE = 'Idi1';
const FALLBACK_CLUSTER = 'L23';
const FALLBACK_CONTRAST = 'genotype_altage_marginal_sex_equal';
const CUSTOM_COEFFICIENTS = Object.freeze([
  {
    label: 'β_intercept',
    description: 'Baseline expression: NTG, 7 months, Female.',
  },
  {
    label: 'β_sex',
    description: 'Male − Female in NTG (shared across ages).',
  },
  {
    label: 'β_age',
    description: '14 months − 7 months in NTG (shared across sexes).',
  },
  {
    label: 'β_genotype',
    description: 'APP23 − NTG at 7 months in Female samples.',
  },
  {
    label: 'β_genotype×age',
    description: 'Change in the APP23 − NTG effect at 14 months.',
  },
  {
    label: 'β_genotype×sex',
    description: 'Change in the APP23 − NTG effect in Male samples.',
  },
]);

function asList(value) {
  return Array.isArray(value) ? value : [];
}

function formatCount(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number.toLocaleString() : '—';
}

function formatMetric(value, digits = 4) {
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  if (number !== 0 && (Math.abs(number) < 0.001 || Math.abs(number) >= 10000)) {
    return number.toExponential(3).replace('+', '');
  }
  return number.toFixed(digits).replace(/\.?0+$/, '');
}

function customVectorDraft(vector) {
  return Array.from({ length: CUSTOM_COEFFICIENTS.length }, (_, index) => {
    const value = asList(vector)[index];
    return value === undefined || value === null ? '' : String(value);
  });
}

function parseCustomVector(raw) {
  const parts = (Array.isArray(raw) ? raw : String(raw).split(','))
    .map((part) => String(part).trim());
  if (parts.length !== CUSTOM_COEFFICIENTS.length) {
    return { error: 'Enter a value for all six model coefficients.' };
  }
  const values = parts.map(Number);
  if (parts.some((part) => part === '') || values.some((value) => !Number.isFinite(value))) {
    return { error: 'Every custom contrast entry must be a finite number.' };
  }
  if (!values.some((value) => value !== 0)) return { error: 'The custom contrast cannot be all zeros.' };
  return { value: values.join(',') };
}

function selectedContrast(catalog, code) {
  return catalog.find((contrast) => String(contrast?.code) === String(code)) || null;
}

function HelpButton({ label, onClick }) {
  return (
    <button
      type="button"
      className="nr-help-button"
      aria-label={label}
      title={label}
      onClick={onClick}
    >
      ?
    </button>
  );
}

function HelpDialog({
  open,
  onClose,
  title,
  children,
}) {
  const rawId = useId();
  const titleId = `nr-help-${rawId.replace(/[^A-Za-z0-9_-]/g, '')}`;
  const closeButtonRef = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const previouslyFocused = document.activeElement;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        onClose();
      } else if (event.key === 'Tab') {
        event.preventDefault();
        closeButtonRef.current?.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    closeButtonRef.current?.focus();
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      previouslyFocused?.focus?.();
    };
  }, [onClose, open]);

  if (!open) return null;
  return (
    <div
      className="nr-dialog-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <section
        className="nr-help-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        <header>
          <h2 id={titleId}>{title}</h2>
          <button
            ref={closeButtonRef}
            type="button"
            className="nr-dialog-close"
            aria-label="Close help"
            onClick={onClose}
          >
            ×
          </button>
        </header>
        <div className="nr-dialog-body">{children}</div>
      </section>
    </div>
  );
}

export default function NonrhythmicPanel({ metadata, currentGene, onStatusChange }) {
  const nrMeta = metadata?.nonrhythmic || metadata || {};
  const available = Boolean(nrMeta.available);
  const clusters = asList(nrMeta.clusters);
  const metadataContrasts = visibleContrastCatalog(asList(nrMeta.contrasts));
  const defaultGene = String(nrMeta.default_gene || FALLBACK_GENE);
  const defaultCluster = String(nrMeta.default_cluster || clusters[0]?.id || FALLBACK_CLUSTER);
  const defaultContrast = String(nrMeta.default_contrast || metadataContrasts[0]?.code || FALLBACK_CONTRAST);

  const [geneInput, setGeneInput] = useState(defaultGene);
  const [gene, setGene] = useState(defaultGene);
  const [geneOptions, setGeneOptions] = useState([]);
  const [geneMessage, setGeneMessage] = useState('');
  const [resolvingGene, setResolvingGene] = useState(false);
  const [cluster, setCluster] = useState(defaultCluster);
  const [contrastCode, setContrastCode] = useState(defaultContrast);
  const [family, setFamily] = useState(() => selectedContrast(metadataContrasts, defaultContrast)?.family || metadataContrasts[0]?.family || '');
  const [fdrThreshold, setFdrThreshold] = useState('0.1');
  const [lfcThreshold, setLfcThreshold] = useState('0.2');
  const [customDraft, setCustomDraft] = useState(() => {
    const contrast = selectedContrast(metadataContrasts, defaultContrast);
    return customVectorDraft(contrast?.vector);
  });
  const [customContrast, setCustomContrast] = useState('');
  const [plotFacetMode, setPlotFacetMode] = useState('age');
  const [customError, setCustomError] = useState('');
  const [analysisPayload, setAnalysisPayload] = useState(null);
  const [expressionPayload, setExpressionPayload] = useState(null);
  const [analysisLoading, setAnalysisLoading] = useState(available);
  const [expressionLoading, setExpressionLoading] = useState(available);
  const [analysisError, setAnalysisError] = useState('');
  const [expressionError, setExpressionError] = useState('');
  const [helpTopic, setHelpTopic] = useState('');
  const closeHelp = useCallback(() => setHelpTopic(''), []);

  const catalog = useMemo(() => {
    const responseCatalog = asList(analysisPayload?.contrasts);
    return visibleContrastCatalog(responseCatalog.length ? responseCatalog : metadataContrasts);
  }, [analysisPayload?.contrasts, metadataContrasts]);
  const families = useMemo(
    () => [...new Set(catalog.map((contrast) => String(contrast?.family || 'other')))],
    [catalog],
  );
  const familyContrasts = useMemo(() => {
    const options = catalog.filter((contrast) => String(contrast?.family || 'other') === family);
    return [...options].sort((left, right) => (
      Number(Boolean(right?.is_default || right?.code === defaultContrast))
      - Number(Boolean(left?.is_default || left?.code === defaultContrast))
    ));
  }, [catalog, defaultContrast, family]);
  const requestedContrastCode = customContrast ? 'custom' : contrastCode;
  const responseContrast = analysisPayload?.contrast;
  const activeContrast = String(responseContrast?.code || '') === requestedContrastCode
    ? responseContrast
    : customContrast
      ? { code: 'custom', label: 'Custom numeric contrast', vector: customContrast.split(',').map(Number) }
      : selectedContrast(catalog, contrastCode);
  const lfcThresholdNumber = Number(lfcThreshold);
  const lfcThresholdValid = lfcThreshold.trim() !== ''
    && Number.isFinite(lfcThresholdNumber)
    && lfcThresholdNumber >= 0
    && lfcThresholdNumber <= 100;
  const fdrThresholdNumber = Number(fdrThreshold);
  const fdrThresholdValid = fdrThreshold.trim() !== ''
    && Number.isFinite(fdrThresholdNumber)
    && fdrThresholdNumber >= 0
    && fdrThresholdNumber <= 1;
  useEffect(() => {
    if (!available || !cluster) return undefined;
    let controller;
    const handle = window.setTimeout(() => {
      controller = new AbortController();
      fetchNonrhythmicGenes(geneInput.trim(), cluster, controller.signal, 80)
        .then((genes) => setGeneOptions(asList(genes).map(String)))
        .catch((lookupError) => {
          if (lookupError.name !== 'AbortError') setGeneOptions([]);
        });
    }, 200);
    return () => {
      window.clearTimeout(handle);
      controller?.abort();
    };
  }, [available, cluster, geneInput]);

  useEffect(() => {
    if (!available || !cluster || !contrastCode) return undefined;
    const controller = new AbortController();
    setAnalysisPayload(null);
    setAnalysisError('');
    setAnalysisLoading(true);
    const params = {
      cluster,
      contrast: customContrast ? 'custom' : contrastCode,
      hypothesis: 'zero',
      threshold: 0,
    };
    if (customContrast) params.c = customContrast;
    fetchNonrhythmicResults(params, controller.signal)
      .then((response) => {
        setAnalysisPayload(response);
        setAnalysisLoading(false);
      })
      .catch((requestError) => {
        if (requestError.name === 'AbortError') return;
        setAnalysisError(requestError.message);
        setAnalysisLoading(false);
      });
    return () => controller.abort();
  }, [
    available,
    cluster,
    contrastCode,
    customContrast,
  ]);

  useEffect(() => {
    if (!available || !gene || !cluster) return undefined;
    const controller = new AbortController();
    setExpressionPayload(null);
    setExpressionError('');
    setExpressionLoading(true);
    fetchNonrhythmicExpression({ gene, cluster }, controller.signal)
      .then((response) => {
        setExpressionPayload(response);
        setExpressionLoading(false);
      })
      .catch((requestError) => {
        if (requestError.name === 'AbortError') return;
        setExpressionError(requestError.message);
        setExpressionLoading(false);
      });
    return () => controller.abort();
  }, [available, cluster, gene]);

  useEffect(() => {
    if (!available) return;
    if (analysisLoading || expressionLoading) {
      onStatusChange?.('Rendering');
    } else if (analysisError || expressionError) {
      onStatusChange?.('Error');
    } else {
      onStatusChange?.('Ready');
    }
  }, [
    analysisError,
    analysisLoading,
    available,
    expressionError,
    expressionLoading,
    onStatusChange,
  ]);

  useEffect(() => {
    if (customContrast) return;
    const vector = asList(analysisPayload?.contrast?.vector);
    if (vector.length === CUSTOM_COEFFICIENTS.length) setCustomDraft(customVectorDraft(vector));
  }, [analysisPayload?.contrast, customContrast]);

  const selectedResult = useMemo(() => asList(analysisPayload?.rows).find((row) => (
    String(row?.gene || '').localeCompare(gene, undefined, { sensitivity: 'base' }) === 0
  )) || null, [analysisPayload?.rows, gene]);
  const selectedGenePayload = useMemo(() => {
    if (!expressionPayload) return null;
    return {
      ...analysisPayload,
      ...expressionPayload,
      wald: selectedResult,
    };
  }, [analysisPayload, expressionPayload, selectedResult]);

  async function applyGene(event) {
    event?.preventDefault();
    if (!available || resolvingGene) return;
    const query = String(geneInput || '').trim() || defaultGene;
    const controller = new AbortController();
    setResolvingGene(true);
    setGeneMessage('');
    try {
      const result = await resolveNonrhythmicGene(query, cluster, controller.signal);
      if (result?.found && result.gene) {
        const resolved = String(result.gene);
        setGeneInput(resolved);
        setGene(resolved);
        setGeneMessage(resolved.toLowerCase() === query.toLowerCase() ? '' : `Using ${resolved}.`);
      } else {
        const suggestions = asList(result?.suggestions).slice(0, 8);
        setGeneMessage(suggestions.length
          ? `No exact match for “${query}”. Try ${suggestions.join(', ')}.`
          : `No gene matching “${query}” is available for this cluster.`);
      }
    } catch (requestError) {
      if (requestError.name !== 'AbortError') setGeneMessage(`Gene lookup failed. ${requestError.message}`);
    } finally {
      setResolvingGene(false);
    }
  }

  function useCurrentGene() {
    const next = String(currentGene || '').trim();
    if (!next) return;
    setGeneInput(next);
    setGeneMessage('');
    setGene(next);
  }

  function selectResultGene(nextGene) {
    const next = String(nextGene || '').trim();
    if (!next) return;
    setGeneInput(next);
    setGeneMessage('');
    setGene(next);
  }

  function chooseCluster(nextCluster) {
    setCluster(nextCluster);
    setAnalysisPayload(null);
    setExpressionPayload(null);
    setGeneMessage('');
    setCustomContrast('');
    setCustomError('');
    const preset = selectedContrast(metadataContrasts, defaultContrast);
    setFamily(String(preset?.family || metadataContrasts[0]?.family || ''));
    setContrastCode(defaultContrast);
  }

  function chooseFamily(nextFamily) {
    const options = catalog.filter((contrast) => String(contrast?.family || 'other') === nextFamily);
    const first = options.find((contrast) => contrast?.code === defaultContrast)
      || options.find((contrast) => contrast?.is_default)
      || options[0];
    setFamily(nextFamily);
    setCustomContrast('');
    if (first?.code) setContrastCode(String(first.code));
    if (asList(first?.vector).length === CUSTOM_COEFFICIENTS.length) {
      setCustomDraft(customVectorDraft(first.vector));
    }
  }

  function choosePreset(nextCode) {
    const next = selectedContrast(catalog, nextCode);
    setContrastCode(nextCode);
    setFamily(String(next?.family || family));
    setCustomContrast('');
    setCustomError('');
    if (asList(next?.vector).length === CUSTOM_COEFFICIENTS.length) {
      setCustomDraft(customVectorDraft(next.vector));
    }
  }

  function updateCustomCoefficient(index, value) {
    setCustomDraft((current) => current.map((entry, entryIndex) => (
      entryIndex === index ? value : entry
    )));
  }

  function applyCustomContrast() {
    const parsed = parseCustomVector(customDraft);
    if (parsed.error) {
      setCustomError(parsed.error);
      return;
    }
    setCustomError('');
    setCustomContrast(parsed.value);
  }

  function restorePreset() {
    setCustomContrast('');
    setCustomError('');
    const preset = selectedContrast(catalog, contrastCode);
    if (asList(preset?.vector).length === CUSTOM_COEFFICIENTS.length) {
      setCustomDraft(customVectorDraft(preset.vector));
    }
  }

  if (!available) {
    return (
      <section className="tab-panel active nonrhythmic-panel" aria-label="APP23 vs. NTG Differential Expression">
        <div className="rc-panel-header">
          <div>
            <h2>APP23 vs. NTG Differential Expression</h2>
            <p className="methods-note">Time-agnostic differential expression modeled across genotype, age, and sex.</p>
          </div>
        </div>
        <div className="empty-results">
          <h2>APP23 differential-expression data not installed</h2>
          <p>{nrMeta.message || <>Install <code>data-private/nonrhythmic_app23_wald.sqlite</code> to enable this tab.</>}</p>
        </div>
      </section>
    );
  }

  return (
    <section className="tab-panel active nonrhythmic-panel" aria-label="APP23 vs. NTG Differential Expression">
      <div className="nr-workspace">
        <aside className="controls nr-sidebar" aria-label="Differential expression controls">
          <div className="nr-sidebar-header">
            <h2>APP23 vs. NTG Differential Expression</h2>
            <p>Define one cluster-level differential-expression analysis, then explore its genes in the table and plot.</p>
            <span className="source-badge">{formatCount(nrMeta.gene_count)} genes</span>
          </div>

          <div className="rhythm-search-form nr-controls">
            <div className="nr-control-grid">
              <div className="nr-control-heading">
                <h3>1. Choose a brain region</h3>
              </div>
              <label>
                <span className="control-label">Brain region</span>
                <select value={cluster} onChange={(event) => chooseCluster(event.target.value)}>
                  {clusters.map((option) => (
                    <option key={option.id} value={option.id}>{option.label || option.id}</option>
                  ))}
                </select>
              </label>
            </div>

        <div className="nr-control-grid nr-estimand-grid">
          <div className="nr-control-heading">
            <h3>2. Choose the biological question</h3>
          </div>
          <label>
            <span className="control-label">What do you want to compare?</span>
            <select value={family} onChange={(event) => chooseFamily(event.target.value)} disabled={Boolean(customContrast)}>
              {families.map((option) => <option key={option} value={option}>{comparisonFamilyLabel(option)}</option>)}
            </select>
          </label>
          <label>
            <span className="control-label">Exact comparison</span>
            <select value={contrastCode} onChange={(event) => choosePreset(event.target.value)} disabled={Boolean(customContrast)}>
              {familyContrasts.map((option) => <option key={option.code} value={option.code}>{contrastSelectLabel(option)}</option>)}
            </select>
          </label>
        </div>

        <div className="nr-advanced-shell">
          <details className="nr-advanced">
            <summary>Advanced: custom model contrast</summary>
            <p className="help-text">
              Set the weight applied to each fitted model coefficient. Custom contrasts are
              BH-adjusted across every testable gene in this cluster.
            </p>
            <div className="nr-custom-coefficients">
              {CUSTOM_COEFFICIENTS.map((coefficient, index) => (
                <label className="nr-custom-coefficient" key={coefficient.label}>
                  <span className="nr-custom-coefficient-copy">
                    <code>{coefficient.label}</code>
                    <small>{coefficient.description}</small>
                  </span>
                  <input
                    className="text-input"
                    type="number"
                    step="any"
                    value={customDraft[index]}
                    onChange={(event) => updateCustomCoefficient(index, event.target.value)}
                    aria-label={"Custom contrast weight for " + coefficient.label}
                  />
                </label>
              ))}
            </div>
            <div className="nr-custom-row">
              <button type="button" className="primary-button" onClick={applyCustomContrast}>Use custom</button>
              {customContrast ? <button type="button" onClick={restorePreset}>Restore preset</button> : null}
            </div>
            {customError ? <div className="gene-message">{customError}</div> : null}
            {customContrast ? <p className="help-text">Active custom vector: {customContrast}</p> : null}
          </details>
          <HelpButton
            label="Explain custom model contrasts"
            onClick={() => setHelpTopic('comparison')}
          />
        </div>
          </div>
          {analysisPayload?.available ? (
            <details className="nr-sidebar-analysis">
              <summary>Analysis details</summary>
          <div className="nr-detail-grid">
            <article className="nr-detail-card">
              <h3>Model comparison</h3>
              <p><strong>{contrastOptionLabel(activeContrast)}</strong></p>
              {activeContrast?.description ? <p>{displaySexText(activeContrast.description)}</p> : null}
              <dl className="nr-definition-list">
                <div><dt>Test</dt><dd>Two-sided Wald test: H₀ = 0</dd></div>
                <div>
                  <dt>Log2FC cutoff</dt>
                  <dd>{lfcThresholdValid ? <>{formatMetric(lfcThresholdNumber)} (descriptive)</> : <>Not applied</>}</dd>
                </div>
                <div><dt>Weighting</dt><dd>{activeContrast?.weighting || 'custom'}</dd></div>
                <div><dt>Vector</dt><dd><code>[{asList(activeContrast?.vector).map((value) => formatMetric(value)).join(', ')}]</code></dd></div>
              </dl>
            </article>
            <article className="nr-detail-card">
              <h3>Cluster model</h3>
              <dl className="nr-definition-list">
                <div><dt>Design</dt><dd><code>{analysisPayload.model?.design}</code></dd></div>
                <div><dt>Reference age</dt><dd>{analysisPayload.model?.references?.age || '—'}</dd></div>
                <div><dt>Reference sex</dt><dd>{displaySex(analysisPayload.model?.references?.sex || '—')}</dd></div>
                <div><dt>Reference genotype</dt><dd>{analysisPayload.model?.references?.genotype || '—'}</dd></div>
                <div><dt>Samples</dt><dd>{formatCount(analysisPayload.model?.n_samples)}</dd></div>
                <div><dt>Covariance</dt><dd>{analysisPayload.model?.covariance_method || '—'}</dd></div>
              </dl>
            </article>
          </div>

          <details className="nr-result-details">
            <summary>Coefficient order and cell sample counts</summary>
            <div className="results-table-wrap nr-table-wrap">
              <table className="results-table nr-cell-table">
                <thead>
                  <tr><th>Age</th><th>Sex</th><th>Genotype</th><th>Samples</th></tr>
                </thead>
                <tbody>
                  {asList(expressionPayload?.cells).map((cell) => (
                    <tr key={cell.id}>
                      <td>{cell.age}</td>
                      <td>{displaySex(cell.sex)}</td>
                      <td>{cell.genotype}</td>
                      <td>{formatCount(cell.sample_n)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ol className="nr-coefficient-list" start="0">
              {asList(analysisPayload.model?.coefficients).map((coefficient) => (
                <li key={coefficient.index}>
                  <code>c{coefficient.index}</code> — {displaySexText(coefficient.model_matrix_name || coefficient.result_name)}
                </li>
              ))}
            </ol>
          </details>
            </details>
          ) : null}
        </aside>

        <section className="content nr-results-column" aria-label="All-gene differential-expression results">
          <div className="nr-table-filter">
            <label htmlFor="nrFdrThreshold">
              <span className="control-label">FDR threshold</span>
              <input
                id="nrFdrThreshold"
                className="text-input threshold-input"
                type="number"
                min="0"
                max="1"
                step="0.01"
                value={fdrThreshold}
                onChange={(event) => setFdrThreshold(event.target.value)}
                aria-invalid={!fdrThresholdValid}
              />
            </label>
            <label htmlFor="nrLfcThreshold">
              <span className="control-label">Log2FC threshold</span>
              <input
                id="nrLfcThreshold"
                className="text-input threshold-input"
                type="number"
                min="0"
                max="100"
                step="0.1"
                value={lfcThreshold}
                onChange={(event) => setLfcThreshold(event.target.value)}
                aria-invalid={!lfcThresholdValid}
              />
            </label>
            {!fdrThresholdValid ? <div className="gene-message">FDR threshold must be between 0 and 1.</div> : null}
            {!lfcThresholdValid ? <div className="gene-message">Log2FC threshold must be between 0 and 100.</div> : null}
          </div>
          <NonrhythmicResultsTable
            rows={asList(analysisPayload?.rows)}
            selectedGene={gene}
            onSelectGene={selectResultGene}
            loading={analysisLoading}
            error={analysisError || (analysisPayload && !analysisPayload.available
              ? analysisPayload.message || 'The differential-expression database is unavailable.'
              : '')}
            title="All-gene results"
            downloadFilename="app23-vs-ntg-results.csv"
            fdrThreshold={fdrThresholdValid ? fdrThresholdNumber : null}
            lfcThreshold={lfcThresholdValid ? lfcThresholdNumber : null}
          />
        </section>

        <section className="content nr-inspector" aria-label="Selected gene plot and statistics">
          <div className="nr-inspector-heading">
            <div>
              <span className="nr-eyebrow">Gene expression</span>
              <h2>{gene}</h2>
            </div>
          </div>
          <form className="nr-inspector-controls" onSubmit={applyGene}>
            <label className="nr-inspector-gene">
              <span className="control-label">Gene</span>
              <input
                className="text-input"
                list="nonrhythmicGeneOptions"
                value={geneInput}
                onChange={(event) => setGeneInput(event.target.value)}
                placeholder="Gene symbol"
                autoComplete="off"
                spellCheck="false"
              />
              <datalist id="nonrhythmicGeneOptions">
                {geneOptions.map((option) => <option key={option} value={option} />)}
              </datalist>
            </label>
            <div className="nr-inspector-buttons">
              <button type="submit" className="primary-button" disabled={resolvingGene}>
                {resolvingGene ? 'Checking…' : 'Apply'}
              </button>
              <button type="button" onClick={useCurrentGene} disabled={!String(currentGene || '').trim()}>
                Use current
              </button>
            </div>
            <label className="nr-inspector-split" htmlFor="nrPlotFacetMode">
              <span className="control-label">Split plot</span>
              <select id="nrPlotFacetMode" value={plotFacetMode} onChange={(event) => setPlotFacetMode(event.target.value)}>
                <option value="overall">None</option>
                <option value="age">Age</option>
                <option value="sex">Sex</option>
                <option value="age_sex">Age and sex</option>
              </select>
            </label>
          </form>
          {geneMessage ? <div className="gene-message">{geneMessage}</div> : null}
          {expressionError ? <div className="error-banner">Expression request failed. {expressionError}</div> : null}
          {expressionLoading ? <div className="loading">Loading expression for {gene}…</div> : null}
          {expressionPayload && !expressionPayload.available ? (
            <div className="empty-results">
              <h2>Expression data unavailable</h2>
              <p>{expressionPayload.message || 'The database could not be opened.'}</p>
            </div>
          ) : null}
          {expressionPayload?.available && !expressionPayload.found ? (
            <div className="empty-results">
              <h2>No expression observations for “{expressionPayload.input || gene}”</h2>
              <p>Choose a gene from the results table or search for another symbol.</p>
            </div>
          ) : null}
          {selectedGenePayload?.found ? (
            <>
              <div className="nr-plot-frame">
                <NonrhythmicExpressionPlot
                  payload={selectedGenePayload}
                  splitByAge={plotFacetMode === 'age' || plotFacetMode === 'age_sex'}
                  splitBySex={plotFacetMode === 'sex' || plotFacetMode === 'age_sex'}
                />
              </div>
              <p className="methods-note nr-figure-legend">
                Expression values are log2(size-factor-normalized count + 1). Differential-expression estimates and P values were obtained from the DESeq2 count model.
              </p>
              {analysisLoading ? <div className="loading">Loading statistics for this comparison…</div> : null}
              {!analysisLoading && analysisPayload?.available ? (
                <NonrhythmicResult
                  payload={selectedGenePayload}
                  contrast={activeContrast}
                  lfcThreshold={lfcThresholdValid ? lfcThresholdNumber : null}
                  helpButton={(
                    <HelpButton
                      label="Explain the differential-expression statistics and mathematics"
                      onClick={() => setHelpTopic('statistics')}
                    />
                  )}
                />
              ) : null}
            </>
          ) : null}
        </section>
      </div>
      <HelpDialog
        open={helpTopic === 'comparison'}
        onClose={closeHelp}
        title="How do custom model contrasts work?"
      >
        <NonrhythmicComparisonHelp />
      </HelpDialog>
      <HelpDialog
        open={helpTopic === 'statistics'}
        onClose={closeHelp}
        title="How are the test and FDR calculated?"
      >
        <NonrhythmicStatisticsHelp />
      </HelpDialog>
    </section>
  );
}
