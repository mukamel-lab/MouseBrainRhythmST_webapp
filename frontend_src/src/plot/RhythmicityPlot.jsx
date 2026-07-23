import { useId, useMemo } from 'react';
import { buildNestedFacetLayout } from './facetLayout.js';
import {
  aggregatePlotData,
  expandedDomain,
  facetKeyFor,
  formatNumericTick,
  groupByFacet,
  groupRows,
  linearScale,
  minorBreaks,
  niceTicks,
  stableUnitInterval,
} from './plotMath.js';
import {
  PHASE_BLOCKS,
  PLOT_THEME,
  X_BREAKS,
  X_MINOR_BREAKS,
} from './plotTheme.js';
import {
  DIURNAL_LEGEND_ROW_HEIGHT,
  FIXED_DIURNAL_PLOT_SIZE,
  fixedDiurnalPlotGeometry,
} from './plotLayout.js';

function dimensionEntry(dimensions, variable, value) {
  return dimensions?.[variable]?.find((entry) => String(entry.value) === String(value));
}

function colorFor(dimensions, variable, value) {
  return dimensionEntry(dimensions, variable, value)?.color ?? '#2563EB';
}

function presentColorEntries(observations, dimensions, colorBy) {
  const present = new Set(observations.map((row) => String(row[colorBy])));
  const configured = (dimensions?.[colorBy] || []).filter((entry) => present.has(String(entry.value)));
  const configuredValues = new Set(configured.map((entry) => String(entry.value)));
  const extra = [...present]
    .filter((value) => !configuredValues.has(value))
    .sort((a, b) => a.localeCompare(b))
    .map((value) => ({ value, label: value, color: '#2563EB' }));
  return [...configured, ...extra];
}

function estimateTextWidth(text, fontSize) {
  return String(text).length * fontSize * 0.50;
}

function makePath(rows, xScale, yScale, yKey) {
  return [...rows]
    .sort((a, b) => Number(a.ZT) - Number(b.ZT))
    .map((row, index) => `${index ? 'L' : 'M'}${xScale(Number(row.ZT)).toFixed(2)},${yScale(Number(row[yKey])).toFixed(2)}`)
    .join(' ');
}

function phaseFill(kind) {
  return kind === 'light' ? PLOT_THEME.lightPhase : PLOT_THEME.darkPhase;
}

function LegendKey({ x, y, color }) {
  return (
    <circle
      cx={x + 15}
      cy={y + 9}
      r={PLOT_THEME.legendPointRadius}
      fill={color}
      aria-hidden="true"
    />
  );
}

function computeLegendRows(entries, title, availableWidth) {
  const titleWidth = estimateTextWidth(title, PLOT_THEME.legendTitleFontSize) + 20;
  const rows = [];
  let current = [];
  let used = titleWidth;

  for (const entry of entries) {
    const width = 42 + estimateTextWidth(entry.label, PLOT_THEME.legendTextFontSize) + 18;
    if (current.length && used + width > availableWidth) {
      rows.push({ entries: current, width: used });
      current = [];
      used = 0;
    }
    current.push({ ...entry, itemWidth: width });
    used += width;
  }
  if (current.length || !rows.length) rows.push({ entries: current, width: used });
  return { rows, titleWidth };
}

function EmptyPlot({ gene, message }) {
  const { width, height } = FIXED_DIURNAL_PLOT_SIZE;
  return (
    <svg xmlns="http://www.w3.org/2000/svg" className="rhythmicity-svg" data-role="rhythmicity-plot" viewBox={`0 0 ${width} ${height}`} width={width} height={height} role="img" aria-label={message}>
      <rect width={width} height={height} fill="white" />
      <text
        x={width / 2}
        y="55"
        textAnchor="middle"
        fontFamily={PLOT_THEME.fontFamily}
        fontSize={PLOT_THEME.titleFontSize}
        fontWeight="700"
        fontStyle="italic"
      >
        {gene}
      </text>
      <text x={width / 2} y={height / 2} textAnchor="middle" fontFamily={PLOT_THEME.fontFamily} fontSize="16">
        {message}
      </text>
    </svg>
  );
}

export default function RhythmicityPlot({
  gene = 'Gene',
  observations = [],
  coefficients = [],
  dimensions = {},
  variableLabels = {},
  colorBy = 'genotype',
  splitBy = [],
  xLabel = 'Zeitgeber Time (double plotted)',
  yLabel = 'log2 Normalized mRNA Expression',
}) {
  const rawId = useId();
  const idPrefix = `rhythm${rawId.replace(/[^A-Za-z0-9_-]/g, '')}`;

  const aggregated = useMemo(() => aggregatePlotData({
    observations,
    coefficients,
    colorBy,
    splitBy,
  }), [observations, coefficients, colorBy, splitBy]);

  const facetLayout = useMemo(() => buildNestedFacetLayout({
    rows: observations,
    splitBy,
    dimensions,
  }), [observations, splitBy, dimensions]);

  if (!observations.length || !coefficients.length) {
    return <EmptyPlot gene={gene} message="No data for current filters" />;
  }

  const colorEntries = presentColorEntries(observations, dimensions, colorBy);
  const rowCount = Math.max(1, facetLayout.rowCombinations.length);
  const columnCount = Math.max(1, facetLayout.columnCombinations.length);
  const panelGapX = PLOT_THEME.panelGapX;
  const panelGapY = PLOT_THEME.panelGapY;
  const topMargin = PLOT_THEME.topMargin;
  const titleHeight = PLOT_THEME.titleHeight;
  const legendTitle = variableLabels?.[colorBy] ?? colorBy;
  const initialGeometry = fixedDiurnalPlotGeometry({
    columnCount,
    rowCount,
    columnVariableCount: facetLayout.columnVariables.length,
    rowVariableCount: facetLayout.rowVariables.length,
    legendRowCount: 1,
  });
  const legendLayout = computeLegendRows(
    colorEntries,
    legendTitle,
    initialGeometry.panelMatrixWidth,
  );
  const {
    width,
    height,
    panel,
    panelStartX,
    panelStartY,
    panelMatrixWidth,
    panelMatrixHeight,
    panelMatrixRight,
    xAxisTitleY,
    legendTop,
  } = fixedDiurnalPlotGeometry({
    columnCount,
    rowCount,
    columnVariableCount: facetLayout.columnVariables.length,
    rowVariableCount: facetLayout.rowVariables.length,
    legendRowCount: legendLayout.rows.length,
  });
  const legendRowHeight = DIURNAL_LEGEND_ROW_HEIGHT;

  const yValues = [
    ...aggregated.observations.map((row) => Number(row.normExpr)),
    ...aggregated.summaries.flatMap((row) => [Number(row.ymin), Number(row.ymax)]),
    ...aggregated.predictions.map((row) => Number(row.predExpr)),
  ];
  const yDomain = expandedDomain(yValues, PLOT_THEME.yExpansionFraction, 0.05);
  const yBreaks = niceTicks(yDomain, 5);
  const yMinorBreaks = minorBreaks(yBreaks, yDomain);
  const xDomain = [
    0 - (42 * PLOT_THEME.xExpansionFraction),
    42 + (42 * PLOT_THEME.xExpansionFraction),
  ];

  const observationsByFacet = groupByFacet(aggregated.observations, splitBy);
  const summariesByFacet = groupByFacet(aggregated.summaries, splitBy);
  const predictionsByFacet = groupByFacet(aggregated.predictions, splitBy);

  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      className="rhythmicity-svg"
      data-role="rhythmicity-plot"
      viewBox={`0 0 ${width} ${height}`}
      width={width}
      height={height}
      role="img"
      aria-label={`Diurnal expression plot for ${gene}`}
    >
      <rect width={width} height={height} fill={PLOT_THEME.plotBackground} />
      <text
        x={panelStartX + panelMatrixWidth / 2}
        y={topMargin + PLOT_THEME.titleBaselineOffset}
        textAnchor="middle"
        fontFamily={PLOT_THEME.fontFamily}
        fontSize={PLOT_THEME.titleFontSize}
        fontWeight={PLOT_THEME.titleFontWeight}
        fontStyle="italic"
        fill={PLOT_THEME.ink}
      >
        {gene}
      </text>

      <defs>
        {facetLayout.panels.map((facetPanel) => {
          const x = panelStartX + facetPanel.columnIndex * (panel.width + panelGapX);
          const y = panelStartY + facetPanel.rowIndex * (panel.height + panelGapY);
          return (
            <clipPath id={`${idPrefix}-panel-${facetPanel.rowIndex}-${facetPanel.columnIndex}`} key={`clip-${facetPanel.key}`}>
              <rect x={x} y={y} width={panel.width} height={panel.height} />
            </clipPath>
          );
        })}
        {facetLayout.columnStripGroups.flatMap((groups, levelIndex) => groups.map((group) => {
          const stripY = topMargin + titleHeight + levelIndex * PLOT_THEME.stripHeight;
          const x = panelStartX + group.start * (panel.width + panelGapX);
          const endX = panelStartX + (group.end - 1) * (panel.width + panelGapX) + panel.width;
          return (
            <clipPath
              id={`${idPrefix}-column-strip-${levelIndex}-${group.start}-${group.end}`}
              key={`column-strip-clip-${levelIndex}-${group.start}-${group.end}`}
            >
              <rect x={x} y={stripY} width={endX - x} height={PLOT_THEME.stripHeight} />
            </clipPath>
          );
        }))}
      </defs>

      {facetLayout.columnStripGroups.map((groups, levelIndex) => {
        const stripY = topMargin + titleHeight + levelIndex * PLOT_THEME.stripHeight;
        return groups.map((group) => {
          const x = panelStartX + group.start * (panel.width + panelGapX);
          const endX = panelStartX + (group.end - 1) * (panel.width + panelGapX) + panel.width;
          return (
            <g key={`column-strip-${levelIndex}-${group.prefixKey}`}>
              <rect
                x={x}
                y={stripY}
                width={endX - x}
                height={PLOT_THEME.stripHeight}
                fill="white"
              />
              <text
                x={(x + endX) / 2}
                y={stripY + PLOT_THEME.stripHeight / 2}
                textAnchor="middle"
                dominantBaseline="middle"
                fontFamily={PLOT_THEME.fontFamily}
                fontSize={PLOT_THEME.stripFontSize}
                fill={PLOT_THEME.ink}
                clipPath={`url(#${idPrefix}-column-strip-${levelIndex}-${group.start}-${group.end})`}
              >
                {group.label}
              </text>
            </g>
          );
        });
      })}

      {facetLayout.rowStripGroups.map((groups, levelIndex) => groups.map((group) => {
        const stripX = panelMatrixRight
          + ((facetLayout.rowVariables.length - 1 - levelIndex) * PLOT_THEME.rowStripWidth);
        const y = panelStartY + group.start * (panel.height + panelGapY);
        const endY = panelStartY + (group.end - 1) * (panel.height + panelGapY) + panel.height;
        const centerX = stripX + PLOT_THEME.rowStripWidth / 2;
        const centerY = (y + endY) / 2;
        return (
          <g key={`row-strip-${levelIndex}-${group.prefixKey}`}>
            <rect
              x={stripX}
              y={y}
              width={PLOT_THEME.rowStripWidth}
              height={endY - y}
              fill="white"
            />
            <text
              x={centerX}
              y={centerY}
              transform={`rotate(90 ${centerX} ${centerY})`}
              textAnchor="middle"
              dominantBaseline="middle"
              fontFamily={PLOT_THEME.fontFamily}
              fontSize={PLOT_THEME.stripFontSize}
              fill={PLOT_THEME.ink}
            >
              {group.label}
            </text>
          </g>
        );
      }))}

      {facetLayout.panels.map((facetPanel) => {
        const panelX = panelStartX + facetPanel.columnIndex * (panel.width + panelGapX);
        const panelY = panelStartY + facetPanel.rowIndex * (panel.height + panelGapY);
        const xScale = linearScale(xDomain, [panelX, panelX + panel.width]);
        const yScale = linearScale(yDomain, [panelY + panel.height, panelY]);
        const facetKey = facetPanel.key;
        const panelObservations = observationsByFacet.get(facetKey) || [];
        const panelSummaries = summariesByFacet.get(facetKey) || [];
        const panelPredictions = predictionsByFacet.get(facetKey) || [];
        const predictionsByColor = groupRows(panelPredictions, (row) => String(row[colorBy]));
        const clipPath = `url(#${idPrefix}-panel-${facetPanel.rowIndex}-${facetPanel.columnIndex})`;
        const showX = facetPanel.rowIndex === rowCount - 1;
        const showY = facetPanel.columnIndex === 0;

        return (
          <g key={`panel-${facetKey}`}>
            <rect x={panelX} y={panelY} width={panel.width} height={panel.height} fill={PLOT_THEME.panelBackground} />

            <g clipPath={clipPath} aria-hidden="true">
              {X_MINOR_BREAKS.map((value) => (
                <line
                  key={`x-minor-${value}`}
                  x1={xScale(value)}
                  y1={panelY}
                  x2={xScale(value)}
                  y2={panelY + panel.height}
                  stroke={PLOT_THEME.gridMinor}
                  strokeWidth={PLOT_THEME.gridMinorWidth}
                />
              ))}
              {yMinorBreaks.map((value) => (
                <line
                  key={`y-minor-${value}`}
                  x1={panelX}
                  y1={yScale(value)}
                  x2={panelX + panel.width}
                  y2={yScale(value)}
                  stroke={PLOT_THEME.gridMinor}
                  strokeWidth={PLOT_THEME.gridMinorWidth}
                />
              ))}
              {X_BREAKS.map(({ value }) => (
                <line
                  key={`x-major-${value}`}
                  x1={xScale(value)}
                  y1={panelY}
                  x2={xScale(value)}
                  y2={panelY + panel.height}
                  stroke={PLOT_THEME.gridMajor}
                  strokeWidth={PLOT_THEME.gridMajorWidth}
                />
              ))}
              {yBreaks.map((value) => (
                <line
                  key={`y-major-${value}`}
                  x1={panelX}
                  y1={yScale(value)}
                  x2={panelX + panel.width}
                  y2={yScale(value)}
                  stroke={PLOT_THEME.gridMajor}
                  strokeWidth={PLOT_THEME.gridMajorWidth}
                />
              ))}

              {PHASE_BLOCKS.map((block) => (
                <rect
                  key={`${block.kind}-${block.start}`}
                  x={xScale(block.start)}
                  y={panelY}
                  width={xScale(block.end) - xScale(block.start)}
                  height={panel.height}
                  fill={phaseFill(block.kind)}
                  fillOpacity={PLOT_THEME.phaseAlpha}
                />
              ))}

              {panelObservations.map((row) => {
                const jitter = (stableUnitInterval(row.sampleKey) - 0.5)
                  * PLOT_THEME.jitterWidthData * 2;
                return (
                  <circle
                    key={row.sampleKey}
                    cx={xScale(Number(row.ZT) + jitter)}
                    cy={yScale(Number(row.normExpr))}
                    r={PLOT_THEME.jitterPointRadius}
                    fill={colorFor(dimensions, colorBy, row[colorBy])}
                    fillOpacity={PLOT_THEME.jitterPointAlpha}
                  />
                );
              })}

              {panelSummaries.map((row) => {
                const x = xScale(Number(row.ZT));
                const capLeft = xScale(Number(row.ZT) - PLOT_THEME.errorBarWidthData / 2);
                const capRight = xScale(Number(row.ZT) + PLOT_THEME.errorBarWidthData / 2);
                const lowY = yScale(Number(row.ymin));
                const highY = yScale(Number(row.ymax));
                const color = colorFor(dimensions, colorBy, row[colorBy]);
                const key = `error-${facetKeyFor(row, splitBy)}-${row[colorBy]}-${row.ZT}`;
                return (
                  <g key={key}>
                    <line x1={x} y1={lowY} x2={x} y2={highY} stroke={color} strokeWidth={PLOT_THEME.errorBarStrokeWidth} />
                    <line x1={capLeft} y1={lowY} x2={capRight} y2={lowY} stroke={color} strokeWidth={PLOT_THEME.errorBarStrokeWidth} />
                    <line x1={capLeft} y1={highY} x2={capRight} y2={highY} stroke={color} strokeWidth={PLOT_THEME.errorBarStrokeWidth} />
                  </g>
                );
              })}

              {panelSummaries.map((row) => (
                <circle
                  key={`mean-${facetKeyFor(row, splitBy)}-${row[colorBy]}-${row.ZT}`}
                  cx={xScale(Number(row.ZT))}
                  cy={yScale(Number(row.mean))}
                  r={PLOT_THEME.meanPointRadius}
                  fill={colorFor(dimensions, colorBy, row[colorBy])}
                />
              ))}

              {[...predictionsByColor.entries()].map(([colorValue, rows]) => (
                <path
                  key={`line-${colorValue}`}
                  d={makePath(rows, xScale, yScale, 'predExpr')}
                  fill="none"
                  stroke={colorFor(dimensions, colorBy, colorValue)}
                  strokeWidth={PLOT_THEME.modelLineStrokeWidth}
                  strokeLinejoin="round"
                  strokeLinecap="butt"
                />
              ))}
            </g>

            <rect
              x={panelX}
              y={panelY}
              width={panel.width}
              height={panel.height}
              fill="none"
              stroke={PLOT_THEME.panelBorder}
              strokeWidth={PLOT_THEME.panelBorderWidth}
            />

            {showX ? X_BREAKS.map(({ value, label }) => {
              const x = xScale(value);
              return (
                <g key={`x-axis-${value}`}>
                  <line
                    x1={x}
                    y1={panelY + panel.height}
                    x2={x}
                    y2={panelY + panel.height + PLOT_THEME.axisTickLength}
                    stroke={PLOT_THEME.mutedInk}
                    strokeWidth={PLOT_THEME.axisTickWidth}
                  />
                  <text
                    x={x}
                    y={panelY + panel.height + PLOT_THEME.xTickLabelOffset}
                    textAnchor="middle"
                    fontFamily={PLOT_THEME.fontFamily}
                    fontSize={PLOT_THEME.axisTextFontSize}
                    fill={PLOT_THEME.ink}
                  >
                    {label}
                  </text>
                </g>
              );
            }) : null}

            {showY ? yBreaks.map((value) => {
              const y = yScale(value);
              return (
                <g key={`y-axis-${value}`}>
                  <line
                    x1={panelX - PLOT_THEME.axisTickLength}
                    y1={y}
                    x2={panelX}
                    y2={y}
                    stroke={PLOT_THEME.mutedInk}
                    strokeWidth={PLOT_THEME.axisTickWidth}
                  />
                  <text
                    x={panelX - PLOT_THEME.axisTickLength - 5}
                    y={y}
                    textAnchor="end"
                    dominantBaseline="middle"
                    fontFamily={PLOT_THEME.fontFamily}
                    fontSize={PLOT_THEME.axisTextFontSize}
                    fill={PLOT_THEME.ink}
                  >
                    {formatNumericTick(value)}
                  </text>
                </g>
              );
            }) : null}
          </g>
        );
      })}

      <text
        x={panelStartX + panelMatrixWidth / 2}
        y={xAxisTitleY}
        textAnchor="middle"
        fontFamily={PLOT_THEME.fontFamily}
        fontSize={PLOT_THEME.axisTitleFontSize}
        fill={PLOT_THEME.ink}
      >
        {xLabel}
      </text>
      <text
        x="20"
        y={panelStartY + panelMatrixHeight / 2}
        transform={`rotate(-90 20 ${panelStartY + panelMatrixHeight / 2})`}
        textAnchor="middle"
        fontFamily={PLOT_THEME.fontFamily}
        fontSize={PLOT_THEME.axisTitleFontSize}
        fill={PLOT_THEME.ink}
      >
        {yLabel}
      </text>

      {legendLayout.rows.map((row, rowIndex) => {
        const includeTitle = rowIndex === 0;
        const rowWidth = row.entries.reduce((sum, entry) => sum + entry.itemWidth, 0)
          + (includeTitle ? legendLayout.titleWidth : 0);
        let cursor = panelStartX + (panelMatrixWidth - rowWidth) / 2;
        const y = legendTop + rowIndex * legendRowHeight;
        const elements = [];

        if (includeTitle) {
          elements.push(
            <text
              key="legend-title"
              x={cursor}
              y={y + 10}
              dominantBaseline="middle"
              fontFamily={PLOT_THEME.fontFamily}
              fontSize={PLOT_THEME.legendTitleFontSize}
              fill={PLOT_THEME.ink}
            >
              {legendTitle}
            </text>,
          );
          cursor += legendLayout.titleWidth;
        }

        row.entries.forEach((entry) => {
          const itemX = cursor;
          elements.push(
            <g key={`legend-${entry.value}`}>
              <LegendKey x={itemX} y={y} color={entry.color} />
              <text
                x={itemX + 38}
                y={y + 10}
                dominantBaseline="middle"
                fontFamily={PLOT_THEME.fontFamily}
                fontSize={PLOT_THEME.legendTextFontSize}
                fill={PLOT_THEME.ink}
              >
                {entry.label}
              </text>
            </g>,
          );
          cursor += entry.itemWidth;
        });

        return <g key={`legend-row-${rowIndex}`}>{elements}</g>;
      })}
    </svg>
  );
}
