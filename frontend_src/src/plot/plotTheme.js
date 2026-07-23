export const MM_TO_PX = 96 / 25.4;

/**
 * Central tuning surface for visual parity with the reference R/ggh4x plot.
 *
 * ggplot size/linewidth units are converted from millimetres to CSS pixels.
 * The few deliberate deviations requested for this iteration are documented
 * next to the affected values.
 */
export const PLOT_THEME = Object.freeze({
  fontFamily: 'Arial, ArialMT, "Helvetica Neue", sans-serif',
  ink: '#000000',
  mutedInk: '#333333',
  panelBackground: '#FFFFFF',
  plotBackground: '#FFFFFF',
  gridMajor: '#EBEBEB',
  gridMinor: '#EBEBEB',
  panelBorder: '#4D4D4D',
  lightPhase: '#F6F18F',
  darkPhase: '#606161',
  phaseAlpha: 0.1,

  // The reference screenshot uses substantially larger display text than the
  // first mini-app pass.
  titleFontSize: 21,
  titleFontWeight: 700,
  axisTitleFontSize: 17,
  axisTextFontSize: 15,
  stripFontSize: 21,
  legendTitleFontSize: 19,
  legendTextFontSize: 16,

  panelBorderWidth: 0.9,
  gridMajorWidth: 0.75,
  gridMinorWidth: 0.4,
  axisTickWidth: 0.9,
  axisTickLength: 5,

  // Deliberate requested adjustment from geom_jitter(size = 0.9, width = .35):
  // slightly larger points and a slightly wider horizontal jitter.
  jitterPointRadius: (1.05 * MM_TO_PX) / 2,
  jitterPointAlpha: 0.55,
  jitterWidthData: 0.42,

  // Deliberate requested adjustment from stat_summary(size = 2.0).
  meanPointRadius: (2  * MM_TO_PX) / 2,
  legendPointRadius: 6,
  errorBarWidthData: 0.5,
  errorBarStrokeWidth: 0.5 * MM_TO_PX,
  modelLineStrokeWidth: 0.75 * MM_TO_PX,

  xExpansionFraction: 0.05,
  yExpansionFraction: 0.05,
  panelGapX: 11,
  panelGapY: 10,
  stripHeight: 38,
  rowStripWidth: 38,

  leftMargin: 58,
  rightMargin: 14,
  topMargin: 5,
  titleHeight: 34,
  titleBaselineOffset: 22,
  xTickLabelOffset: 20,
  xAxisTitleOffset: 42,
  legendTopGap: 38,
});

export const X_BREAKS = Object.freeze([
  { value: 0, label: '0' },
  { value: 12, label: '12' },
  { value: 24, label: '0' },
  { value: 36, label: '12' },
]);

// ggplot2 extrapolates the 12-hour major-break spacing, so 42 is also a
// visible minor break inside the expanded x domain.
export const X_MINOR_BREAKS = Object.freeze([6, 18, 30, 42]);

export const PHASE_BLOCKS = Object.freeze([
  { start: 0, end: 12, kind: 'light' },
  { start: 12, end: 24, kind: 'dark' },
  { start: 24, end: 36, kind: 'light' },
  { start: 36, end: 42, kind: 'dark' },
]);
