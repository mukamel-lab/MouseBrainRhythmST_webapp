import test from 'node:test';
import assert from 'node:assert/strict';
import { DIMENSIONS, TARGET_REGION_VALUES, makePlotFixture } from './testFixture.js';
import { buildNestedFacetLayout, facetFormula } from '../plot/facetLayout.js';

const fixture = makePlotFixture();

test('no split creates one panel', () => {
  const layout = buildNestedFacetLayout({
    rows: fixture.observations,
    splitBy: [],
    dimensions: DIMENSIONS,
  });
  assert.equal(layout.panels.length, 1);
  assert.equal(layout.rowCombinations.length, 1);
  assert.equal(layout.columnCombinations.length, 1);
  assert.equal(facetFormula([]), 'no facets');
});

test('one split is laid out across columns', () => {
  const layout = buildNestedFacetLayout({
    rows: fixture.observations,
    splitBy: ['age'],
    dimensions: DIMENSIONS,
  });
  assert.deepEqual(layout.rowVariables, []);
  assert.deepEqual(layout.columnVariables, ['age']);
  assert.equal(layout.rowCombinations.length, 1);
  assert.equal(layout.columnCombinations.length, 2);
  assert.equal(layout.panels.length, 2);
  assert.equal(facetFormula(['age']), 'facet_nested(~ age)');
});

test('three splits use the first variable for rows and nested remaining variables for columns', () => {
  const layout = buildNestedFacetLayout({
    rows: fixture.observations,
    splitBy: ['region', 'age', 'sex'],
    dimensions: DIMENSIONS,
  });
  assert.deepEqual(layout.rowVariables, ['region']);
  assert.deepEqual(layout.columnVariables, ['age', 'sex']);
  assert.equal(layout.rowCombinations.length, 4);
  assert.equal(layout.columnCombinations.length, 4);
  assert.equal(layout.panels.length, 16);

  const [ageGroups, sexGroups] = layout.columnStripGroups;
  assert.deepEqual(ageGroups.map((group) => group.span), [2, 2]);
  assert.deepEqual(ageGroups.map((group) => group.label), ['7 months', '14 months']);
  assert.deepEqual(sexGroups.map((group) => group.span), [1, 1, 1, 1]);
  assert.deepEqual(sexGroups.map((group) => group.label), ['Female', 'Male', 'Female', 'Male']);
  assert.equal(facetFormula(['region', 'age', 'sex']), 'facet_nested(region ~ age + sex)');
});

test('target order puts age on rows and sex then cluster across columns', () => {
  const selectedRows = fixture.observations.filter((row) => TARGET_REGION_VALUES.includes(row.region));
  const layout = buildNestedFacetLayout({
    rows: selectedRows,
    splitBy: ['age', 'sex', 'region'],
    dimensions: DIMENSIONS,
  });

  assert.deepEqual(layout.rowVariables, ['age']);
  assert.deepEqual(layout.columnVariables, ['sex', 'region']);
  assert.equal(layout.rowCombinations.length, 2);
  assert.equal(layout.columnCombinations.length, 4);
  assert.equal(layout.panels.length, 8);

  const [sexGroups, regionGroups] = layout.columnStripGroups;
  assert.deepEqual(sexGroups.map((group) => group.label), ['Female', 'Male']);
  assert.deepEqual(sexGroups.map((group) => group.span), [2, 2]);
  assert.deepEqual(regionGroups.map((group) => group.label), [
    'Cortex Layer 2/3',
    'Cortex Layer 4',
    'Cortex Layer 2/3',
    'Cortex Layer 4',
  ]);
  assert.equal(facetFormula(['age', 'sex', 'region']), 'facet_nested(age ~ sex + region)');
});

test('lower strip labels do not bleed across different outer groups', () => {
  const layout = buildNestedFacetLayout({
    rows: fixture.observations,
    splitBy: ['region', 'age', 'sex', 'genotype'],
    dimensions: DIMENSIONS,
  });
  const genotypeGroups = layout.columnStripGroups[2];
  assert.equal(genotypeGroups.length, 8);
  assert.ok(genotypeGroups.every((group) => group.span === 1));
});


test('nested variables show only combinations observed on that side of the formula', () => {
  const rows = fixture.observations.filter((row) => !(row.age === '14 months' && row.sex === 'M'));
  const layout = buildNestedFacetLayout({
    rows,
    splitBy: ['region', 'age', 'sex'],
    dimensions: DIMENSIONS,
  });

  assert.equal(layout.columnCombinations.length, 3);
  assert.deepEqual(
    layout.columnCombinations.map((values) => [values.age, values.sex]),
    [
      ['7 months', 'F'],
      ['7 months', 'M'],
      ['14 months', 'F'],
    ],
  );
  assert.equal(layout.panels.length, 12);
});
