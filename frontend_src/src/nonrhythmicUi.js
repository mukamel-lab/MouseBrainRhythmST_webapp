import { displaySexText } from './plot/nonrhythmicPlotLayout.js';

const FAMILY_LABELS = Object.freeze({
  genotype: 'Is expression different between APP23 and NTG?',
  interaction: 'Does the APP23–NTG difference change with age or sex?',
  age: 'Does expression change with age within one genotype?',
  sex: 'Does expression differ by sex within one genotype?',
});

const HYPOTHESIS_LABELS = Object.freeze({
  zero: 'Detect any difference from zero',
  greater: 'Test whether the difference is higher than a chosen amount',
  less: 'Test whether the difference is lower than a chosen amount',
  greaterAbs: 'Test whether the difference magnitude exceeds a chosen amount',
  lessAbs: 'Test equivalence within a chosen amount',
});

function normalizedContrast(contrast) {
  return contrast && typeof contrast === 'object' ? contrast : {};
}

function sentenceCase(value) {
  const text = String(value || '').trim();
  return text ? text.charAt(0).toUpperCase() + text.slice(1) : '';
}

function scopedGenotypeLabel(contrast) {
  const age = String(contrast.age_mode || '');
  const sex = displaySexText(contrast.sex_mode || '');
  const weighting = String(contrast.weighting || '');

  if (age === 'marginal' && sex === 'marginal') {
    return weighting === 'observed'
      ? 'Overall APP23 vs. NTG — weighted by the observed age and sex sample counts'
      : 'Overall APP23 vs. NTG — each age × sex group weighted equally';
  }
  if (age === 'marginal') {
    return weighting === 'observed'
      ? `APP23 vs. NTG in ${sex} samples — ages weighted by sample count`
      : `APP23 vs. NTG in ${sex} samples — ages weighted equally`;
  }
  if (sex === 'marginal') {
    return weighting === 'observed'
      ? `APP23 vs. NTG at ${age} — Female and Male weighted by sample count`
      : `APP23 vs. NTG at ${age} — Female and Male weighted equally`;
  }
  if (age && sex) return `APP23 vs. NTG at ${age} in ${sex} samples`;
  return '';
}

export function comparisonFamilyLabel(family) {
  return FAMILY_LABELS[String(family || '')] || sentenceCase(
    String(family || 'Other').replace(/[_-]+/g, ' '),
  );
}

export function hypothesisLabel(id, fallback = '') {
  return HYPOTHESIS_LABELS[String(id || '')] || String(fallback || id || '');
}

export function visibleContrastCatalog(value) {
  if (!Array.isArray(value)) return [];
  return value.filter(
    (contrast) => String(contrast?.weighting || '').toLowerCase() !== 'observed',
  );
}

export function contrastOptionLabel(value) {
  const contrast = normalizedContrast(value);
  const code = String(contrast.code || '');
  if (String(contrast.family) === 'genotype') {
    const scoped = scopedGenotypeLabel(contrast);
    if (scoped) return scoped;
  }
  if (code === 'genotype_by_age') return 'Change in the APP23–NTG difference with age';
  if (code === 'genotype_by_sex') return 'Change in the APP23–NTG difference between Female and Male';
  const label = displaySexText(contrast.label || code)
    .replace(/\bAPP23 vs NTG\b/g, 'APP23 vs. NTG')
    .replace(/\bage-marginalized equally\b/gi, 'ages weighted equally')
    .replace(/\bage-marginalized by observed n\b/gi, 'ages weighted by sample count')
    .replace(/\bsex-marginalized equally\b/gi, 'Female and Male weighted equally')
    .replace(/\bsex-marginalized by observed n\b/gi, 'Female and Male weighted by sample count');
  if (String(contrast.family) === 'age') {
    return label.replace(/,\s*(Female|Male)\s*$/, ' (modeled across both sexes)');
  }
  if (String(contrast.family) === 'sex') {
    return label.replace(/,\s*[^,]+$/, ' (modeled across both ages)');
  }
  return label;
}

export function contrastSelectLabel(value) {
  return contrastOptionLabel(value).replace(/\s+—\s+.*$/, '');
}

export function comparisonQuestion({ gene, cluster, contrast }) {
  const selected = normalizedContrast(contrast);
  const symbol = String(gene || 'this gene');
  const region = String(cluster || 'the selected brain region');
  const code = String(selected.code || '');
  const family = String(selected.family || '');

  if (family === 'genotype') {
    const scope = scopedGenotypeLabel(selected)
      .replace(/^Overall APP23 vs\. NTG — /, '')
      .replace(/^APP23 vs\. NTG /, '');
    return `Is ${symbol} expression different between APP23 and NTG in ${region}, ${scope}?`;
  }
  if (code === 'genotype_by_age') {
    return `Does the APP23–NTG difference in ${symbol} expression change with age in ${region}?`;
  }
  if (code === 'genotype_by_sex') {
    return `Does the APP23–NTG difference in ${symbol} expression change between Female and Male samples in ${region}?`;
  }
  if (family === 'age') {
    return `Does ${symbol} expression change with age in ${region} within the selected genotype, modeled across both sexes?`;
  }
  if (family === 'sex') {
    return `Does ${symbol} expression differ between Female and Male samples in ${region} within the selected genotype, modeled across both ages?`;
  }
  return `What is the selected model-based expression difference for ${symbol} in ${region}?`;
}

export function buildNonrhythmicRequest({
  gene,
  cluster,
  contrastCode,
  customContrast = '',
  hypothesis = 'zero',
  threshold = 0.5,
}) {
  const thresholdRequired = hypothesis !== 'zero';
  const thresholdNumber = Number(threshold);
  if (
    thresholdRequired
    && (!Number.isFinite(thresholdNumber) || thresholdNumber <= 0 || thresholdNumber > 100)
  ) {
    throw new RangeError('Threshold must be greater than zero and no more than 100.');
  }
  const params = {
    gene,
    cluster,
    contrast: customContrast ? 'custom' : contrastCode,
    hypothesis,
    threshold: thresholdRequired ? thresholdNumber : 0,
  };
  if (customContrast) params.c = customContrast;
  return params;
}

export function foldRatio(log2Difference) {
  const value = Number(log2Difference);
  if (!Number.isFinite(value)) return null;
  const ratio = 2 ** Math.abs(value);
  return Number.isFinite(ratio) ? ratio : null;
}

export function effectDirection(contrast, estimate) {
  const selected = normalizedContrast(contrast);
  const value = Number(estimate);
  if (!Number.isFinite(value)) return 'Estimate unavailable';
  if (value === 0) return 'No estimated difference';
  const direction = value > 0 ? 'higher' : 'lower';
  const family = String(selected.family || '');
  if (family === 'genotype') return `APP23 ${direction} than NTG`;
  if (family === 'age') return `comparison age ${direction} than reference age`;
  if (family === 'sex') return `Male ${direction} than Female`;
  return value > 0 ? 'Positive selected contrast' : 'Negative selected contrast';
}

export function fdrInterpretation({ padj, hypothesis, threshold }) {
  const qValue = Number(padj);
  const kind = String(hypothesis || 'zero');
  const cutoffMet = Number.isFinite(qValue) && qValue <= 0.05;
  const limit = Number(threshold);
  const formattedLimit = Number.isFinite(limit) ? String(limit) : 'the chosen threshold';

  if (!cutoffMet) {
    if (kind === 'lessAbs') {
      return 'Equivalence is not established at 5% FDR; this does not prove a meaningful difference.';
    }
    return 'The selected claim is not supported at 5% FDR; this is not evidence that there is no effect.';
  }
  if (kind === 'greater') return `Evidence that the selected difference is greater than +${formattedLimit} log2 units.`;
  if (kind === 'less') return `Evidence that the selected difference is less than −${formattedLimit} log2 units.`;
  if (kind === 'greaterAbs') return `Evidence that the absolute selected difference exceeds ${formattedLimit} log2 units.`;
  if (kind === 'lessAbs') return `Evidence that the selected difference is equivalent within ±${formattedLimit} log2 units.`;
  return 'Evidence that the selected difference is not zero.';
}
