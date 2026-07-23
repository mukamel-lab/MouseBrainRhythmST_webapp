import test from 'node:test';
import assert from 'node:assert/strict';
import { makePlotFixture } from './testFixture.js';
import { aggregatePlotData, meanAndSampleSd, minorBreaks } from '../plot/plotMath.js';

const fixture = makePlotFixture();

test('sample SD matches the R n - 1 definition', () => {
  const result = meanAndSampleSd([1, 2, 3]);
  assert.equal(result.mean, 2);
  assert.equal(result.sd, 1);
});

test('aggregation follows color plus split grouping', () => {
  const result = aggregatePlotData({
    observations: fixture.observations,
    coefficients: fixture.coefficients,
    colorBy: 'genotype',
    splitBy: ['region', 'age', 'sex'],
  });

  // 4 regions × 2 ages × 2 sexes × 2 genotypes × 4 observed timepoints
  assert.equal(result.summaries.length, 128);
  // Same grouping, 100 model timepoints.
  assert.equal(result.predictions.length, 3200);
  assert.ok(result.summaries.every((row) => row.n === 3));
});


test('minor breaks extrapolate one major interval at expanded scale edges', () => {
  assert.deepEqual(minorBreaks([0, 12, 24, 36], [-2.1, 44.1]), [6, 18, 30, 42]);
});
