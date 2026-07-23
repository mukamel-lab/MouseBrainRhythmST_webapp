import { PLOT_THEME } from './plotTheme.js';

export const DIURNAL_LEGEND_ROW_HEIGHT = 28;
export const DIURNAL_LEGEND_EXTRA_HEIGHT = 14;
export const DIURNAL_BOTTOM_PADDING = 8;

const REFERENCE_TWO_VARIABLE_LAYOUT = Object.freeze({
  columnCount: 2,
  rowCount: 2,
  columnVariableCount: 1,
  rowVariableCount: 1,
  panelWidth: 350,
  panelHeight: 175,
  legendRowCount: 1,
});

const referencePanelMatrixWidth = (
  REFERENCE_TWO_VARIABLE_LAYOUT.columnCount * REFERENCE_TWO_VARIABLE_LAYOUT.panelWidth
) + (
  (REFERENCE_TWO_VARIABLE_LAYOUT.columnCount - 1) * PLOT_THEME.panelGapX
);

const referencePanelMatrixHeight = (
  REFERENCE_TWO_VARIABLE_LAYOUT.rowCount * REFERENCE_TWO_VARIABLE_LAYOUT.panelHeight
) + (
  (REFERENCE_TWO_VARIABLE_LAYOUT.rowCount - 1) * PLOT_THEME.panelGapY
);

export const REFERENCE_TWO_VARIABLE_PLOT_SIZE = Object.freeze({
  width: PLOT_THEME.leftMargin
    + referencePanelMatrixWidth
    + (REFERENCE_TWO_VARIABLE_LAYOUT.rowVariableCount * PLOT_THEME.rowStripWidth)
    + PLOT_THEME.rightMargin,
  height: PLOT_THEME.topMargin
    + PLOT_THEME.titleHeight
    + (REFERENCE_TWO_VARIABLE_LAYOUT.columnVariableCount * PLOT_THEME.stripHeight)
    + referencePanelMatrixHeight
    + PLOT_THEME.xAxisTitleOffset
    + PLOT_THEME.legendTopGap
    + (REFERENCE_TWO_VARIABLE_LAYOUT.legendRowCount * DIURNAL_LEGEND_ROW_HEIGHT)
    + DIURNAL_LEGEND_EXTRA_HEIGHT
    + DIURNAL_BOTTOM_PADDING,
});

export const FIXED_DIURNAL_PLOT_SIZE = Object.freeze({
  width: REFERENCE_TWO_VARIABLE_PLOT_SIZE.width,
  height: REFERENCE_TWO_VARIABLE_PLOT_SIZE.height * 1.05,
});

export function fixedDiurnalPlotGeometry({
  columnCount,
  rowCount,
  columnVariableCount,
  rowVariableCount,
  legendRowCount,
}) {
  const columns = Math.max(1, columnCount);
  const rows = Math.max(1, rowCount);
  const columnVariables = Math.max(0, columnVariableCount);
  const rowVariables = Math.max(0, rowVariableCount);
  const legendRows = Math.max(1, legendRowCount);
  const width = FIXED_DIURNAL_PLOT_SIZE.width;
  const height = FIXED_DIURNAL_PLOT_SIZE.height;
  const rowStripStackWidth = rowVariables * PLOT_THEME.rowStripWidth;
  const stripStackHeight = columnVariables * PLOT_THEME.stripHeight;
  const panelStartX = PLOT_THEME.leftMargin;
  const panelStartY = PLOT_THEME.topMargin + PLOT_THEME.titleHeight + stripStackHeight;
  const panelMatrixWidth = width
    - panelStartX
    - rowStripStackWidth
    - PLOT_THEME.rightMargin;
  const legendHeight = (legendRows * DIURNAL_LEGEND_ROW_HEIGHT) + DIURNAL_LEGEND_EXTRA_HEIGHT;
  const panelMatrixHeight = height
    - panelStartY
    - PLOT_THEME.xAxisTitleOffset
    - PLOT_THEME.legendTopGap
    - legendHeight
    - DIURNAL_BOTTOM_PADDING;
  const panelWidth = (
    panelMatrixWidth - ((columns - 1) * PLOT_THEME.panelGapX)
  ) / columns;
  const panelHeight = (
    panelMatrixHeight - ((rows - 1) * PLOT_THEME.panelGapY)
  ) / rows;
  const panelMatrixRight = panelStartX + panelMatrixWidth;
  const panelMatrixBottom = panelStartY + panelMatrixHeight;
  const xAxisTitleY = panelMatrixBottom + PLOT_THEME.xAxisTitleOffset;
  const legendTop = xAxisTitleY + PLOT_THEME.legendTopGap;

  return {
    width,
    height,
    panel: { width: panelWidth, height: panelHeight },
    panelStartX,
    panelStartY,
    panelMatrixWidth,
    panelMatrixHeight,
    panelMatrixRight,
    panelMatrixBottom,
    rowStripStackWidth,
    stripStackHeight,
    xAxisTitleY,
    legendTop,
    legendHeight,
  };
}
