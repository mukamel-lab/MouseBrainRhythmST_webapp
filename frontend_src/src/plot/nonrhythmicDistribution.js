function finiteSorted(values) {
  return (Array.isArray(values) ? values : [])
    .map(Number)
    .filter(Number.isFinite)
    .sort((left, right) => left - right);
}

export function quantile(values, probability) {
  const sorted = finiteSorted(values);
  if (!sorted.length) return Number.NaN;
  if (sorted.length === 1) return sorted[0];

  const boundedProbability = Math.min(1, Math.max(0, Number(probability)));
  const index = (sorted.length - 1) * boundedProbability;
  const lowerIndex = Math.floor(index);
  const upperIndex = Math.ceil(index);
  const fraction = index - lowerIndex;
  return sorted[lowerIndex] + (sorted[upperIndex] - sorted[lowerIndex]) * fraction;
}

export function boxWhiskerSummary(values) {
  const sorted = finiteSorted(values);
  if (!sorted.length) return null;

  const q1 = quantile(sorted, 0.25);
  const median = quantile(sorted, 0.5);
  const q3 = quantile(sorted, 0.75);
  const iqr = q3 - q1;
  const lowerFence = q1 - 1.5 * iqr;
  const upperFence = q3 + 1.5 * iqr;
  const lowerWhisker = sorted.find((value) => value >= lowerFence) ?? sorted[0];
  const upperWhisker = [...sorted].reverse().find((value) => value <= upperFence) ?? sorted.at(-1);

  return {
    n: sorted.length,
    minimum: sorted[0],
    q1,
    median,
    q3,
    maximum: sorted.at(-1),
    iqr,
    lowerWhisker,
    upperWhisker,
  };
}

function sampleStandardDeviation(sorted) {
  if (sorted.length < 2) return 0;
  const mean = sorted.reduce((sum, value) => sum + value, 0) / sorted.length;
  const sumSquares = sorted.reduce((sum, value) => sum + ((value - mean) ** 2), 0);
  return Math.sqrt(sumSquares / (sorted.length - 1));
}

export function kernelDensity(values, domain, sampleCount = 64) {
  const sorted = finiteSorted(values);
  if (!sorted.length) return [];

  const [domainMinimum, domainMaximum] = domain.map(Number);
  const domainSpan = Math.max(1e-9, domainMaximum - domainMinimum);
  const q1 = quantile(sorted, 0.25);
  const q3 = quantile(sorted, 0.75);
  const standardDeviation = sampleStandardDeviation(sorted);
  const robustScale = Math.min(
    ...[standardDeviation, (q3 - q1) / 1.34].filter((value) => value > 1e-12),
  );
  const fallbackScale = Math.max(domainSpan * 0.055, Math.abs(quantile(sorted, 0.5)) * 0.025, 0.08);
  const scale = Number.isFinite(robustScale) ? robustScale : fallbackScale;
  const bandwidth = Math.max(domainSpan * 0.018, 0.9 * scale * (sorted.length ** -0.2));
  const observedMinimum = sorted[0];
  const observedMaximum = sorted.at(-1);
  const equalValues = Math.abs(observedMaximum - observedMinimum) < 1e-12;
  const densityMinimum = Math.max(
    domainMinimum,
    equalValues ? observedMinimum - bandwidth : observedMinimum,
  );
  const densityMaximum = Math.min(
    domainMaximum,
    equalValues ? observedMaximum + bandwidth : observedMaximum,
  );
  const count = Math.max(24, Math.floor(sampleCount));
  const denominator = sorted.length * bandwidth * Math.sqrt(2 * Math.PI);

  return Array.from({ length: count }, (_, index) => {
    const value = densityMinimum + ((densityMaximum - densityMinimum) * index) / (count - 1);
    const kernelSum = sorted.reduce((sum, observation) => {
      const standardized = (value - observation) / bandwidth;
      return sum + Math.exp(-0.5 * standardized * standardized);
    }, 0);
    return { value, density: kernelSum / denominator };
  });
}
