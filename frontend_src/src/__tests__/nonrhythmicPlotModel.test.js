import test from 'node:test';
import assert from 'node:assert/strict';
import {
  buildNonrhythmicPlotModel,
  nonrhythmicExpressionDomain,
} from '../plot/nonrhythmicPlotModel.js';

const expression = [];
for (const [ageIndex, age] of ['7 months', '14 months'].entries()) {
  for (const sex of ['F', 'M']) {
    for (const genotype of ['NTG', 'APP23']) {
      for (let replicate = 0; replicate < 2; replicate += 1) {
        expression.push({
          age_id: ageIndex + 1,
          age,
          sex,
          genotype,
          sample_key: `${age}-${sex}-${genotype}-${replicate}`,
          value: ageIndex + replicate,
        });
      }
    }
  }
}

test('plot model pools hidden age and sex strata', () => {
  const modes = [
    [{ splitAge: false, splitSex: false }, 1, 8],
    [{ splitAge: true, splitSex: false }, 2, 4],
    [{ splitAge: false, splitSex: true }, 2, 4],
    [{ splitAge: true, splitSex: true }, 4, 2],
  ];

  for (const [mode, facetCount, groupN] of modes) {
    const model = buildNonrhythmicPlotModel(expression, mode);
    assert.equal(model.facets.length, facetCount);
    assert.ok(model.facets.every((facet) => facet.groups.every((group) => group.n === groupN)));
  }
});

test('plot model order and compact values are independent of input row order', () => {
  const compact = (model) => ({
    ages: model.ages,
    sexes: model.sexes,
    genotypes: model.genotypes,
    facets: model.facets.map((facet) => ({
      key: facet.key,
      groups: facet.groups.map(({ genotype, n, values }) => ({ genotype, n, values })),
    })),
  });
  assert.deepEqual(
    compact(buildNonrhythmicPlotModel(expression)),
    compact(buildNonrhythmicPlotModel([...expression].reverse())),
  );
});

test('expression domain focuses on the shared finite observation range', () => {
  assert.deepEqual(nonrhythmicExpressionDomain([8, 10.2, 9]), [7.5, 10.7]);
  assert.deepEqual(nonrhythmicExpressionDomain([0.2, 1]), [0, 1.5]);
  assert.deepEqual(nonrhythmicExpressionDomain([4]), [3.5, 4.5]);
  assert.deepEqual(
    nonrhythmicExpressionDomain([Number.NaN, 3, Number.POSITIVE_INFINITY, 5]),
    [2.5, 5.5],
  );
  assert.deepEqual(
    nonrhythmicExpressionDomain([null, undefined, ' ', 'not-a-number', 3, 5]),
    [2.5, 5.5],
  );
  assert.deepEqual(nonrhythmicExpressionDomain([]), [0, 1]);
  assert.deepEqual(nonrhythmicExpressionDomain([Number.NaN, Number.NEGATIVE_INFINITY]), [0, 1]);
});
