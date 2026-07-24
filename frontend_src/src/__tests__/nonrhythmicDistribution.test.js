import test from 'node:test';
import assert from 'node:assert/strict';
import {
  boxWhiskerSummary,
  kernelDensity,
  quantile,
} from '../plot/nonrhythmicDistribution.js';

test('quantile uses linear interpolation and ignores non-finite values', () => {
  assert.equal(quantile([4, Number.NaN, 1, 3, 2], 0.25), 1.75);
  assert.equal(quantile([4, 1, 3, 2], 0.5), 2.5);
});

test('box whiskers stop at the most extreme observations within 1.5 IQR', () => {
  const summary = boxWhiskerSummary([1, 2, 3, 4, 100]);
  assert.deepEqual(
    {
      q1: summary.q1,
      median: summary.median,
      q3: summary.q3,
      lowerWhisker: summary.lowerWhisker,
      upperWhisker: summary.upperWhisker,
    },
    {
      q1: 2,
      median: 3,
      q3: 4,
      lowerWhisker: 1,
      upperWhisker: 4,
    },
  );
});

test('kernel density handles empty, singleton, two-point, and constant samples', () => {
  assert.deepEqual(kernelDensity([], [0, 5], 32), []);

  for (const values of [[2], [1, 4], [2, 2, 2]]) {
    const density = kernelDensity(values, [0, 5], 32);
    assert.equal(density.length, 32);
    assert.ok(density.at(-1).value > density[0].value);
    assert.ok(density.every((point, index) => (
      Number.isFinite(point.value)
      && Number.isFinite(point.density)
      && point.density >= 0
      && point.value >= 0
      && point.value <= 5
      && (index === 0 || point.value >= density[index - 1].value)
    )));
  }
});
