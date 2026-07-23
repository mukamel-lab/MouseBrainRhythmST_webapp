import test from 'node:test';
import assert from 'node:assert/strict';
import { stableUnitInterval } from '../plot/plotMath.js';
import {
  RC_MM_TO_PX,
  RC_PHASE_BLOCKS,
  RC_X_BREAKS,
  ROSTRAL_CAUDAL_THEME,
} from '../plot/rostralCaudalTheme.js';

test('rostral-caudal canvas is 5% narrower and 5% taller than the R device', () => {
  assert.equal(ROSTRAL_CAUDAL_THEME.width, 6.3 * 120 * 0.95);
  assert.equal(ROSTRAL_CAUDAL_THEME.height, 4.2 * 120 * 1.05);
});

test('rostral-caudal geometry uses the supplied ggplot sizes', () => {
  assert.equal(ROSTRAL_CAUDAL_THEME.curveStrokeWidth, 0.55 * RC_MM_TO_PX);
  assert.equal(ROSTRAL_CAUDAL_THEME.errorBarStrokeWidth, 0.25 * RC_MM_TO_PX);
  assert.equal(ROSTRAL_CAUDAL_THEME.errorBarWidthData, 0.18);
  assert.equal(ROSTRAL_CAUDAL_THEME.rawJitterWidthData, 0.18);
  assert.equal(ROSTRAL_CAUDAL_THEME.rawPointAlpha, 0.55);
  assert.equal(ROSTRAL_CAUDAL_THEME.legendPointRadius, 6);
  assert.ok(ROSTRAL_CAUDAL_THEME.meanPointRadius > ROSTRAL_CAUDAL_THEME.rawPointRadius);
});

test('rostral-caudal axis and phase definitions match the double plot', () => {
  assert.deepEqual(RC_X_BREAKS.map((entry) => [entry.value, entry.label]), [
    [0, '0'],
    [12, '12'],
    [24, '0'],
    [36, '12'],
  ]);
  assert.deepEqual(RC_PHASE_BLOCKS.map((entry) => [entry.start, entry.end]), [
    [0, 12],
    [12, 24],
    [24, 36],
    [36, 42],
  ]);
});

test('deterministic browser jitter stays within the R width', () => {
  for (const key of ['sample-a', 'sample-b', 'sample-c', 'sample-d']) {
    const offset = (stableUnitInterval(key) - 0.5) * ROSTRAL_CAUDAL_THEME.rawJitterWidthData * 2;
    assert.ok(offset >= -0.18 && offset <= 0.18);
  }
});
