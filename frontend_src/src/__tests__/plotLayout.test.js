import test from 'node:test';
import assert from 'node:assert/strict';
import {
  FIXED_DIURNAL_PLOT_SIZE,
  REFERENCE_TWO_VARIABLE_PLOT_SIZE,
  fixedDiurnalPlotGeometry,
} from '../plot/plotLayout.js';

test('fixed canvas uses the current two-variable width and 105% of its height', () => {
  assert.deepEqual(REFERENCE_TWO_VARIABLE_PLOT_SIZE, {
    width: 821,
    height: 567,
  });
  assert.deepEqual(FIXED_DIURNAL_PLOT_SIZE, {
    width: 821,
    height: 595.35,
  });
});

test('canvas dimensions stay fixed while panels shrink as facets are added', () => {
  const noSplit = fixedDiurnalPlotGeometry({
    columnCount: 1,
    rowCount: 1,
    columnVariableCount: 0,
    rowVariableCount: 0,
    legendRowCount: 1,
  });
  const twoVariables = fixedDiurnalPlotGeometry({
    columnCount: 2,
    rowCount: 2,
    columnVariableCount: 1,
    rowVariableCount: 1,
    legendRowCount: 1,
  });
  const threeVariables = fixedDiurnalPlotGeometry({
    columnCount: 4,
    rowCount: 2,
    columnVariableCount: 2,
    rowVariableCount: 1,
    legendRowCount: 1,
  });

  for (const geometry of [noSplit, twoVariables, threeVariables]) {
    assert.equal(geometry.width, FIXED_DIURNAL_PLOT_SIZE.width);
    assert.equal(geometry.height, FIXED_DIURNAL_PLOT_SIZE.height);
  }
  assert.ok(noSplit.panel.width > twoVariables.panel.width);
  assert.ok(twoVariables.panel.width > threeVariables.panel.width);
  assert.ok(noSplit.panel.height > twoVariables.panel.height);
  assert.ok(twoVariables.panel.height > threeVariables.panel.height);
});
