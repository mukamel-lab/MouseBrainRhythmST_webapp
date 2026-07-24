export const NONRHYTHMIC_PLOT_WIDTH = 920;
export const NONRHYTHMIC_PLOT_HEIGHT = 703;

const HEADER = Object.freeze({
  titleY: 30,
  clusterY: 54,
  legendY: 84,
  legendRadius: 7,
  firstStripTop: 116,
});

export function displaySexText(value) {
  const text = String(value ?? '');
  return text
    .replace(/sexM\b/g, 'sexMale')
    .replace(/sexF\b/g, 'sexFemale')
    .replace(/(^|[^A-Za-z0-9])M(?=$|[^A-Za-z0-9])/g, '$1Male')
    .replace(/(^|[^A-Za-z0-9])F(?=$|[^A-Za-z0-9])/g, '$1Female');
}

export function displaySex(value) {
  const normalized = String(value ?? '').trim().toUpperCase();
  if (normalized === 'F') return 'Female';
  if (normalized === 'M') return 'Male';
  return String(value ?? '');
}

function facetDefinition({
  age,
  sex,
  splitByAge,
  splitBySex,
}) {
  if (splitByAge && splitBySex) {
    return {
      stripLabel: `Sex: ${displaySex(sex)}`,
      rowLabel: `Age: ${age}`,
    };
  }
  if (splitByAge) {
    return {
      stripLabel: `Age: ${age}`,
      rowLabel: '',
    };
  }
  if (splitBySex) {
    return {
      stripLabel: `Sex: ${displaySex(sex)}`,
      rowLabel: '',
    };
  }
  return {
    stripLabel: '',
    rowLabel: '',
  };
}

export function buildNonrhythmicPlotLayout({
  ages,
  sexes,
  splitByAge = true,
  splitBySex = true,
  width = NONRHYTHMIC_PLOT_WIDTH,
}) {
  const ageValues = splitByAge ? ages : [null];
  const sexValues = splitBySex ? sexes : [null];
  const bothSplit = splitByAge && splitBySex;
  const rowValues = bothSplit ? ageValues : [null];
  const columnValues = bothSplit
    ? sexValues
    : splitByAge
      ? ageValues
      : splitBySex
        ? sexValues
        : [null];
  const rowCount = Math.max(1, rowValues.length);
  const columnCount = Math.max(1, columnValues.length);
  const margin = {
    left: 92,
    right: bothSplit ? 92 : 30,
  };
  const columnGap = 28;
  const stripHeight = splitByAge || splitBySex ? 28 : 0;
  const rowGapAfterPlot = 59;
  const plotRegionBottom = NONRHYTHMIC_PLOT_HEIGHT - 92;
  const firstPlotTop = HEADER.firstStripTop + stripHeight;
  const plotHeight = (
    plotRegionBottom
    - firstPlotTop
    - (rowCount - 1) * (stripHeight + rowGapAfterPlot)
  ) / rowCount;
  const xLabelOffset = 25;
  const rowAdvance = stripHeight + plotHeight + rowGapAfterPlot;
  const panelWidth = (
    width
    - margin.left
    - margin.right
    - columnGap * (columnCount - 1)
  ) / columnCount;

  const facets = [];
  for (let rowIndex = 0; rowIndex < rowCount; rowIndex += 1) {
    for (let columnIndex = 0; columnIndex < columnCount; columnIndex += 1) {
      const age = bothSplit ? rowValues[rowIndex] : splitByAge ? columnValues[columnIndex] : null;
      const sex = bothSplit ? columnValues[columnIndex] : splitBySex ? columnValues[columnIndex] : null;
      const stripTop = HEADER.firstStripTop + rowIndex * rowAdvance;
      const stripBottom = stripTop + stripHeight;
      const plotTop = stripBottom;
      const plotBottom = plotTop + plotHeight;
      const panelLeft = margin.left + columnIndex * (panelWidth + columnGap);
      facets.push({
        ...facetDefinition({
          age,
          sex,
          splitByAge,
          splitBySex,
        }),
        key: `${rowIndex}-${columnIndex}-${age ?? 'all-ages'}-${sex ?? 'all-sexes'}`,
        age,
        sex,
        rowIndex,
        columnIndex,
        panelLeft,
        panelWidth,
        stripTop,
        stripBottom,
        plotTop,
        plotBottom,
        plotHeight,
        xLabelY: plotBottom + xLabelOffset,
      });
    }
  }

  const lastPlotBottom = Math.max(...facets.map((facet) => facet.plotBottom));
  const xAxisTitleY = lastPlotBottom + 65;
  return {
    width,
    height: NONRHYTHMIC_PLOT_HEIGHT,
    header: {
      ...HEADER,
      legendBottom: HEADER.legendY + HEADER.legendRadius,
    },
    margin,
    rowCount,
    columnCount,
    columnGap,
    stripHeight,
    plotHeight,
    rowGapAfterPlot,
    panelWidth,
    facets,
    xAxisTitleY,
    yAxisCenter: (HEADER.firstStripTop + stripHeight + lastPlotBottom) / 2,
  };
}
