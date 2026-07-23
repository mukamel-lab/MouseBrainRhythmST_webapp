import { combinationKey, uniqueVariables } from './facetLayout.js';

const GROUP_SEPARATOR = '\u001e';

export function stableUnitInterval(value) {
  const text = String(value ?? '');
  let hash = 2166136261;
  for (let index = 0; index < text.length; index += 1) {
    hash ^= text.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return (hash >>> 0) / 4294967295;
}

export function linearScale(domain, range) {
  const [domainStart, domainEnd] = domain;
  const [rangeStart, rangeEnd] = range;
  const span = domainEnd - domainStart || 1;
  return (value) => rangeStart + ((value - domainStart) / span) * (rangeEnd - rangeStart);
}

export function meanAndSampleSd(values) {
  if (!values.length) return { mean: Number.NaN, sd: Number.NaN };
  const mean = values.reduce((sum, value) => sum + value, 0) / values.length;
  if (values.length < 2) return { mean, sd: 0 };
  const variance = values.reduce((sum, value) => sum + ((value - mean) ** 2), 0) / (values.length - 1);
  return { mean, sd: Math.sqrt(Math.max(0, variance)) };
}

function rowGroupKey(row, variables) {
  return variables.map((variable) => String(row?.[variable] ?? '')).join(GROUP_SEPARATOR);
}

function groupValues(row, variables) {
  return Object.fromEntries(variables.map((variable) => [variable, row?.[variable]]));
}

export function predictionAt(coefficient, zt) {
  const phase = ((zt % 24) * 2 * Math.PI) / 24;
  return Number(coefficient.intercept)
    + Number(coefficient.sinCoef) * Math.sin(phase)
    + Number(coefficient.cosCoef) * Math.cos(phase);
}

/**
 * Reproduces the R grouping rules for raw summaries and model curves.
 */
export function aggregatePlotData({
  observations,
  coefficients,
  colorBy,
  splitBy,
  predictionPointCount = 100,
}) {
  const groupingVariables = uniqueVariables([colorBy, ...(splitBy || [])]);
  const summaryMap = new Map();

  for (const row of observations) {
    const zt = Number(row.ZT);
    const value = Number(row.normExpr);
    if (!Number.isFinite(zt) || !Number.isFinite(value)) continue;
    const key = `${rowGroupKey(row, groupingVariables)}${GROUP_SEPARATOR}${zt}`;
    const existing = summaryMap.get(key) || {
      ...groupValues(row, groupingVariables),
      ZT: zt,
      values: [],
    };
    existing.values.push(value);
    summaryMap.set(key, existing);
  }

  const summaries = [...summaryMap.values()].map((row) => {
    const { mean, sd } = meanAndSampleSd(row.values);
    return {
      ...groupValues(row, groupingVariables),
      ZT: row.ZT,
      mean,
      sd,
      ymin: mean - sd,
      ymax: mean + sd,
      n: row.values.length,
    };
  });

  const predictionMap = new Map();
  for (const coefficient of coefficients) {
    const weight = Math.max(1, Number(coefficient.n) || 1);
    for (let index = 0; index < predictionPointCount; index += 1) {
      const ZT = (42 * index) / (predictionPointCount - 1);
      const key = `${rowGroupKey(coefficient, groupingVariables)}${GROUP_SEPARATOR}${index}`;
      const existing = predictionMap.get(key) || {
        ...groupValues(coefficient, groupingVariables),
        ZT,
        weightedSum: 0,
        weight: 0,
      };
      existing.weightedSum += predictionAt(coefficient, ZT) * weight;
      existing.weight += weight;
      predictionMap.set(key, existing);
    }
  }

  const predictions = [...predictionMap.values()].map((row) => ({
    ...groupValues(row, groupingVariables),
    ZT: row.ZT,
    predExpr: row.weightedSum / row.weight,
  }));

  return { observations, summaries, predictions, groupingVariables };
}

export function facetKeyFor(row, splitBy) {
  return combinationKey(row, uniqueVariables(splitBy));
}

export function groupByFacet(rows, splitBy) {
  const grouped = new Map();
  for (const row of rows) {
    const key = facetKeyFor(row, splitBy);
    const values = grouped.get(key) || [];
    values.push(row);
    grouped.set(key, values);
  }
  return grouped;
}

export function groupRows(rows, keyFunction) {
  const grouped = new Map();
  for (const row of rows) {
    const key = keyFunction(row);
    const values = grouped.get(key) || [];
    values.push(row);
    grouped.set(key, values);
  }
  return grouped;
}

export function expandedDomain(values, fraction = 0.05, minimumPadding = 0) {
  const finite = values.filter(Number.isFinite);
  if (!finite.length) return [0, 1];
  const minimum = Math.min(...finite);
  const maximum = Math.max(...finite);
  const span = maximum - minimum;
  const padding = Math.max(minimumPadding, span > 0 ? span * fraction : Math.max(0.5, Math.abs(minimum) * fraction));
  return [minimum - padding, maximum + padding];
}

function niceStep(span, targetCount) {
  const raw = span / Math.max(1, targetCount - 1);
  const power = 10 ** Math.floor(Math.log10(raw || 1));
  const normalized = raw / power;
  const candidates = [1, 2, 2.5, 5, 10];
  const selected = candidates.find((candidate) => normalized <= candidate) ?? 10;
  return selected * power;
}

export function niceTicks(domain, targetCount = 5) {
  const [minimum, maximum] = domain;
  const step = niceStep(maximum - minimum, targetCount);
  const first = Math.ceil(minimum / step) * step;
  const last = Math.floor(maximum / step) * step;
  const ticks = [];
  for (let value = first; value <= last + step * 1e-9; value += step) {
    ticks.push(Number(value.toPrecision(12)));
  }
  return ticks.length ? ticks : [minimum, maximum];
}

export function minorBreaks(majorBreaks, domain) {
  if (majorBreaks.length < 2) return [];

  const extended = [...majorBreaks];
  const firstStep = majorBreaks[1] - majorBreaks[0];
  const lastStep = majorBreaks.at(-1) - majorBreaks.at(-2);
  if (domain[0] < majorBreaks[0]) extended.unshift(majorBreaks[0] - firstStep);
  if (domain[1] > majorBreaks.at(-1)) extended.push(majorBreaks.at(-1) + lastStep);

  const breaks = [];
  for (let index = 0; index < extended.length - 1; index += 1) {
    const value = (extended[index] + extended[index + 1]) / 2;
    if (value > domain[0] && value < domain[1]) breaks.push(value);
  }
  return breaks;
}

export function formatNumericTick(value) {
  if (Math.abs(value) >= 1000 || (Math.abs(value) > 0 && Math.abs(value) < 0.01)) {
    return value.toExponential(1).replace('+', '');
  }
  const rounded = Math.abs(value) < 1e-10 ? 0 : value;
  return Number(rounded.toFixed(2)).toString();
}
