/**
 * Visual constants for the rostral/intermediate/caudal plot.
 *
 * The source R device is 6.3 × 4.2 inches. The web canvas is intentionally
 * 5% narrower and 5% taller while retaining 120 user units per inch.
 */
export const RC_PX_PER_INCH = 120;
export const RC_MM_TO_PX = RC_PX_PER_INCH / 25.4;
export const RC_PT_TO_PX = RC_PX_PER_INCH / 72;

export const ROSTRAL_CAUDAL_THEME = Object.freeze({
  width: 6.3 * RC_PX_PER_INCH * 0.95,
  height: 4.2 * RC_PX_PER_INCH * 1.05,
  fontFamily: 'ArialMT, Arial, "Helvetica Neue", sans-serif',
  ink: '#000000',
  axisInk: '#333333',
  panelBackground: '#FFFFFF',
  plotBackground: '#FFFFFF',
  gridMajor: '#EBEBEB',
  gridMinor: '#EBEBEB',
  panelBorder: '#333333',
  lightPhase: '#F6F18F',
  darkPhase: '#606161',
  phaseAlpha: 0.1,

  // plot_theme(base_size = 12)
  titleFontSize: 12 * RC_PT_TO_PX,
  subtitleFontSize: 9.6 * RC_PT_TO_PX,
  axisTitleFontSize: 10 * RC_PT_TO_PX,
  axisTextFontSize: 9.6 * RC_PT_TO_PX,
  legendTextFontSize: 9.6 * RC_PT_TO_PX,

  panelX: 72,
  panelY: 69,
  panelWidth: 666 - (6.3 * RC_PX_PER_INCH * 0.05),
  panelHeight: 286 + (4.2 * RC_PX_PER_INCH * 0.05),
  titleY: 24,
  subtitleY: 49,
  yAxisTitleX: 19,
  xTickLabelOffset: 24,
  xAxisTitleOffset: 55,
  legendY: 463 + (4.2 * RC_PX_PER_INCH * 0.05),

  panelBorderWidth: 0.55 * RC_PT_TO_PX,
  gridMajorWidth: 0.5 * RC_PT_TO_PX,
  gridMinorWidth: 0.25 * RC_PT_TO_PX,
  axisTickWidth: 0.5 * RC_PT_TO_PX,
  axisTickLength: 4.6,

  // ggplot2 geometry units from render_rostral_caudal_ggplot().
  curveStrokeWidth: 0.55 * RC_MM_TO_PX,
  errorBarStrokeWidth: 0.25 * RC_MM_TO_PX,
  errorBarWidthData: 0.18,
  meanPointRadius: (1.6 * RC_MM_TO_PX) / 2,
  rawPointRadius: (0.55 * RC_MM_TO_PX) / 2,
  rawPointAlpha: 0.55,
  legendPointRadius: 6,
  rawJitterWidthData: 0.18,

  xExpansionFraction: 0.05,
  yExpansionFraction: 0.05,
});

export const RC_X_BREAKS = Object.freeze([
  { value: 0, label: '0' },
  { value: 12, label: '12' },
  { value: 24, label: '0' },
  { value: 36, label: '12' },
]);

export const RC_X_MINOR_BREAKS = Object.freeze([6, 18, 30, 42]);

export const RC_PHASE_BLOCKS = Object.freeze([
  { start: 0, end: 12, fill: ROSTRAL_CAUDAL_THEME.lightPhase },
  { start: 12, end: 24, fill: ROSTRAL_CAUDAL_THEME.darkPhase },
  { start: 24, end: 36, fill: ROSTRAL_CAUDAL_THEME.lightPhase },
  { start: 36, end: 42, fill: ROSTRAL_CAUDAL_THEME.darkPhase },
]);
