import {
  contrastOptionLabel,
  effectDirection,
  foldRatio,
} from './nonrhythmicUi.js';

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

function formatSignedMetric(value, digits = 4) {
  const formatted = formatMetric(value, digits);
  if (formatted === '—') return formatted;
  return Number(value) > 0 ? `+${formatted}` : formatted;
}

function StatCard({ label, value, detail }) {
  return (
    <div className="nr-stat-card">
      <dt>{label}</dt>
      <dd>{value}</dd>
      {detail ? <span>{detail}</span> : null}
    </div>
  );
}

export default function NonrhythmicResult({
  payload,
  contrast,
  lfcThreshold,
  helpButton,
}) {
  const wald = payload?.wald;
  const ratio = foldRatio(wald?.log2FoldChange);
  const threshold = Number(lfcThreshold);
  const absoluteEffect = Math.abs(Number(wald?.log2FoldChange));
  const showThreshold = lfcThreshold !== null
    && Number.isFinite(threshold)
    && Number.isFinite(absoluteEffect);
  const ratioKind = String(contrast?.family) === 'interaction'
    ? 'ratio-of-ratios magnitude'
    : 'magnitude on the ratio scale';

  return (
    <>
      <div className="nr-result-heading">
        <div>
          <span className="nr-eyebrow">Statistical result</span>
          <h2>{payload.gene}</h2>
        </div>
        {helpButton}
      </div>
      <p className="nr-result-context">
        {payload.cluster?.label || payload.cluster?.id}
        {' · '}
        {formatCount(payload.expression_count)} observations
        {' · '}
        {contrastOptionLabel(contrast)}
      </p>

      {wald ? (
        <>
          <div className="nr-result-interpretation" aria-live="polite">
            <article className="nr-primary-result-card">
              <span>Log2 Fold Change</span>
              <strong>{formatSignedMetric(wald.log2FoldChange)}</strong>
              <p>
                {effectDirection(contrast, wald.log2FoldChange)}
                {ratio ? ` · ${formatMetric(ratio, 2)}× ${ratioKind}` : ''}
              </p>
              {showThreshold ? (
                <p>
                  |Log2FC| {absoluteEffect >= threshold ? '≥' : '<'} {formatMetric(threshold)} (descriptive cutoff).
                </p>
              ) : null}
            </article>
            <article className="nr-primary-result-card">
              <span>BH-adjusted P value</span>
              <strong>{wald.padj_display || formatMetric(wald.padj)}</strong>
              <p>
                Two-sided Wald test of H₀: Log2FC = 0; Benjamini–Hochberg correction across {formatCount(payload.tested_gene_count)} genes.
              </p>
            </article>
          </div>

          <details className="nr-statistical-details">
            <summary>More statistical details</summary>
            <p className="nr-active-test">
              <strong>Test:</strong> two-sided Wald test of H₀: Log2FC = 0
            </p>
            <dl className="nr-stats-grid" aria-label="Detailed Wald test statistics">
              <StatCard
                label="Standard error"
                value={wald.lfcSE_display || formatMetric(wald.lfcSE)}
                detail="Model uncertainty; this is not the SD of the sample dots."
              />
              <StatCard label="Test statistic" value={wald.stat_display || formatMetric(wald.stat)} />
              <StatCard label="Raw P value" value={wald.pvalue_display || formatMetric(wald.pvalue)} />
              <StatCard
                label="Genes tested together"
                value={formatCount(payload.tested_gene_count)}
                detail={payload.untestable_gene_count
                  ? `${formatCount(payload.untestable_gene_count)} untestable excluded${payload.invalid_variance_count ? ` (${formatCount(payload.invalid_variance_count)} invalid variances)` : ''}`
                  : ''}
              />
            </dl>
            <p className="methods-note">
              BH adjustment is recomputed across every testable gene for this brain region,
              comparison, and test. No independent filtering or Cook&apos;s-distance cutoff is hidden.
            </p>
          </details>
        </>
      ) : (
        <div className="empty-results compact-empty">
          <h2>This comparison is not testable for {payload.gene}</h2>
          <p>The estimate or covariance was unavailable or had zero variance. Expression observations are still shown above.</p>
        </div>
      )}
    </>
  );
}
