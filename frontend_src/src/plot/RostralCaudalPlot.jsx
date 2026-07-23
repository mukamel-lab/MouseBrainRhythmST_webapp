import { useId, useMemo } from 'react';
import {
  expandedDomain,
  formatNumericTick,
  linearScale,
  minorBreaks,
  niceTicks,
  stableUnitInterval,
} from './plotMath.js';
import {
  RC_PHASE_BLOCKS,
  RC_X_BREAKS,
  RC_X_MINOR_BREAKS,
  ROSTRAL_CAUDAL_THEME as THEME,
} from './rostralCaudalTheme.js';

function finiteNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function normalizeRegions(regions) {
  return (Array.isArray(regions) ? regions : [])
    .map((region, index) => ({
      id: String(region?.id ?? region?.region ?? index),
      label: String(region?.label ?? region?.id ?? region?.region ?? index),
      color: String(region?.color || '#2563EB'),
      sortOrder: finiteNumber(region?.sort_order) ?? index,
    }))
    .sort((a, b) => a.sortOrder - b.sortOrder);
}

function normalizePoints(points) {
  return (Array.isArray(points) ? points : []).flatMap((point, index) => {
    const x = finiteNumber(point?.x ?? point?.time);
    const y = finiteNumber(point?.y ?? point?.value);
    if (x === null || y === null) return [];
    return [{
      region: String(point?.region ?? ''),
      x,
      y,
      sampleKey: String(point?.sample_key ?? point?.sampleKey ?? `${index}`),
      jitterKey: String(point?.jitter_key ?? point?.jitterKey ?? point?.sample_key ?? point?.sampleKey ?? `${index}`),
    }];
  });
}

function normalizeSummaries(summaries) {
  return (Array.isArray(summaries) ? summaries : []).flatMap((summary) => {
    const x = finiteNumber(summary?.x ?? summary?.time);
    const mean = finiteNumber(summary?.mean);
    const sd = finiteNumber(summary?.sd) ?? 0;
    if (x === null || mean === null) return [];
    return [{
      region: String(summary?.region ?? ''),
      x,
      mean,
      sd: Math.max(0, sd),
    }];
  });
}

function normalizeCurves(curves) {
  return (Array.isArray(curves) ? curves : []).flatMap((curve) => {
    const points = (Array.isArray(curve?.points) ? curve.points : []).flatMap((point) => {
      const x = finiteNumber(point?.x ?? point?.time);
      const y = finiteNumber(point?.y ?? point?.value);
      return x === null || y === null ? [] : [{ x, y }];
    }).sort((a, b) => a.x - b.x);
    if (points.length < 2) return [];
    return [{ region: String(curve?.region ?? ''), points }];
  });
}

function curvePath(points, xScale, yScale) {
  return points.map((point, index) => (
    `${index ? 'L' : 'M'}${xScale(point.x).toFixed(2)},${yScale(point.y).toFixed(2)}`
  )).join(' ');
}

function estimateTextWidth(text, fontSize) {
  return String(text).length * fontSize * 0.52;
}

function legendLayout(regions) {
  const keyWidth = 36;
  const keyToTextGap = 7;
  const itemGap = 24;
  const items = regions.map((region) => ({
    ...region,
    width: keyWidth + keyToTextGap + estimateTextWidth(region.label, THEME.legendTextFontSize) + itemGap,
  }));
  const totalWidth = Math.max(0, items.reduce((sum, item) => sum + item.width, 0) - itemGap);
  return { items, totalWidth, keyWidth, keyToTextGap };
}

function LegendKey({ x, y, color }) {
  return (
    <circle
      cx={x + 18}
      cy={y}
      r={THEME.legendPointRadius}
      fill={color}
      aria-hidden="true"
    />
  );
}

function EmptyPlot({ gene, message }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      className="rostral-caudal-svg"
      data-role="rostral-caudal-plot"
      viewBox={`0 0 ${THEME.width} ${THEME.height}`}
      width={THEME.width}
      height={THEME.height}
      role="img"
      aria-label={message}
    >
      <rect width={THEME.width} height={THEME.height} fill="white" />
      <text
        x={THEME.panelX + THEME.panelWidth / 2}
        y={THEME.titleY}
        textAnchor="middle"
        fontFamily={THEME.fontFamily}
        fontSize={THEME.titleFontSize}
        fontWeight="700"
        fontStyle="italic"
        fill={THEME.ink}
      >
        {gene}
      </text>
      <text
        x={THEME.width / 2}
        y={THEME.height / 2}
        textAnchor="middle"
        fontFamily={THEME.fontFamily}
        fontSize={THEME.axisTextFontSize}
        fill={THEME.ink}
      >
        {message}
      </text>
    </svg>
  );
}

export default function RostralCaudalPlot({ payload }) {
  const rawId = useId();
  const clipId = `rc${rawId.replace(/[^A-Za-z0-9_-]/g, '')}-clip`;
  const plot = payload?.plot;

  const data = useMemo(() => ({
    regions: normalizeRegions(plot?.regions),
    points: normalizePoints(plot?.points),
    summaries: normalizeSummaries(plot?.summaries),
    curves: normalizeCurves(plot?.curves),
  }), [plot]);

  const gene = String(payload?.gene || 'Gene');
  const subtitle = String(payload?.subtitle ?? payload?.cluster_label ?? '');
  const xLabel = String(plot?.x_label || 'Zeitgeber Time (double plotted)');
  const yLabel = String(plot?.y_label || gene);

  if (!plot || (!data.summaries.length && !data.curves.length)) {
    return <EmptyPlot gene={gene} message="No rostral-caudal plot data" />;
  }

  const regionById = new Map(data.regions.map((region) => [region.id, region]));
  const colorFor = (region) => regionById.get(String(region))?.color || '#2563EB';
  const yValues = [
    ...data.points.map((point) => point.y),
    ...data.summaries.flatMap((summary) => [summary.mean - summary.sd, summary.mean + summary.sd]),
    ...data.curves.flatMap((curve) => curve.points.map((point) => point.y)),
  ];
  const yDomain = expandedDomain(yValues, THEME.yExpansionFraction, 0.05);
  const yBreaks = niceTicks(yDomain, 5);
  const yMinorBreaks = minorBreaks(yBreaks, yDomain);
  const xDomain = [
    0 - (42 * THEME.xExpansionFraction),
    42 + (42 * THEME.xExpansionFraction),
  ];
  const xScale = linearScale(xDomain, [THEME.panelX, THEME.panelX + THEME.panelWidth]);
  const yScale = linearScale(yDomain, [THEME.panelY + THEME.panelHeight, THEME.panelY]);
  const panelBottom = THEME.panelY + THEME.panelHeight;
  const panelCenterX = THEME.panelX + THEME.panelWidth / 2;
  const panelCenterY = THEME.panelY + THEME.panelHeight / 2;
  const legend = legendLayout(data.regions);
  const legendStartX = THEME.panelX + (THEME.panelWidth - legend.totalWidth) / 2;

  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      className="rostral-caudal-svg"
      data-role="rostral-caudal-plot"
      viewBox={`0 0 ${THEME.width} ${THEME.height}`}
      width={THEME.width}
      height={THEME.height}
      role="img"
      aria-label={`Rostral-caudal rhythmicity plot for ${gene}`}
    >
      <rect width={THEME.width} height={THEME.height} fill={THEME.plotBackground} />

      <text
        x={panelCenterX}
        y={THEME.titleY}
        textAnchor="middle"
        fontFamily={THEME.fontFamily}
        fontSize={THEME.titleFontSize}
        fontWeight="700"
        fontStyle="italic"
        fill={THEME.ink}
      >
        {gene}
      </text>
      {subtitle ? (
        <text
          x={THEME.panelX}
          y={THEME.subtitleY}
          textAnchor="start"
          fontFamily={THEME.fontFamily}
          fontSize={THEME.subtitleFontSize}
          fill={THEME.ink}
        >
          {subtitle}
        </text>
      ) : null}

      <defs>
        <clipPath id={clipId}>
          <rect x={THEME.panelX} y={THEME.panelY} width={THEME.panelWidth} height={THEME.panelHeight} />
        </clipPath>
      </defs>

      <rect
        x={THEME.panelX}
        y={THEME.panelY}
        width={THEME.panelWidth}
        height={THEME.panelHeight}
        fill={THEME.panelBackground}
      />

      <g clipPath={`url(#${clipId})`} aria-hidden="true">
        {RC_X_MINOR_BREAKS.map((value) => (
          <line
            key={`x-minor-${value}`}
            x1={xScale(value)}
            y1={THEME.panelY}
            x2={xScale(value)}
            y2={panelBottom}
            stroke={THEME.gridMinor}
            strokeWidth={THEME.gridMinorWidth}
          />
        ))}
        {yMinorBreaks.map((value) => (
          <line
            key={`y-minor-${value}`}
            x1={THEME.panelX}
            y1={yScale(value)}
            x2={THEME.panelX + THEME.panelWidth}
            y2={yScale(value)}
            stroke={THEME.gridMinor}
            strokeWidth={THEME.gridMinorWidth}
          />
        ))}
        {RC_X_BREAKS.map(({ value }) => (
          <line
            key={`x-major-${value}`}
            x1={xScale(value)}
            y1={THEME.panelY}
            x2={xScale(value)}
            y2={panelBottom}
            stroke={THEME.gridMajor}
            strokeWidth={THEME.gridMajorWidth}
          />
        ))}
        {yBreaks.map((value) => (
          <line
            key={`y-major-${value}`}
            x1={THEME.panelX}
            y1={yScale(value)}
            x2={THEME.panelX + THEME.panelWidth}
            y2={yScale(value)}
            stroke={THEME.gridMajor}
            strokeWidth={THEME.gridMajorWidth}
          />
        ))}

        {RC_PHASE_BLOCKS.map((block) => (
          <rect
            key={`${block.start}-${block.end}`}
            x={xScale(block.start)}
            y={THEME.panelY}
            width={xScale(block.end) - xScale(block.start)}
            height={THEME.panelHeight}
            fill={block.fill}
            fillOpacity={THEME.phaseAlpha}
          />
        ))}

        {/* Match the R layer order: curves, SD bars, means, then raw points. */}
        {data.curves.map((curve) => (
          <path
            key={`curve-${curve.region}`}
            d={curvePath(curve.points, xScale, yScale)}
            fill="none"
            stroke={colorFor(curve.region)}
            strokeWidth={THEME.curveStrokeWidth}
            strokeLinecap="butt"
            strokeLinejoin="round"
          />
        ))}

        {data.summaries.map((summary, index) => {
          const x = xScale(summary.x);
          const lowY = yScale(summary.mean - summary.sd);
          const highY = yScale(summary.mean + summary.sd);
          const capLeft = xScale(summary.x - THEME.errorBarWidthData / 2);
          const capRight = xScale(summary.x + THEME.errorBarWidthData / 2);
          const color = colorFor(summary.region);
          return (
            <g key={`error-${summary.region}-${summary.x}-${index}`}>
              <line x1={x} y1={lowY} x2={x} y2={highY} stroke={color} strokeWidth={THEME.errorBarStrokeWidth} />
              <line x1={capLeft} y1={lowY} x2={capRight} y2={lowY} stroke={color} strokeWidth={THEME.errorBarStrokeWidth} />
              <line x1={capLeft} y1={highY} x2={capRight} y2={highY} stroke={color} strokeWidth={THEME.errorBarStrokeWidth} />
            </g>
          );
        })}

        {data.summaries.map((summary, index) => (
          <circle
            key={`mean-${summary.region}-${summary.x}-${index}`}
            cx={xScale(summary.x)}
            cy={yScale(summary.mean)}
            r={THEME.meanPointRadius}
            fill={colorFor(summary.region)}
          />
        ))}

        {data.points.map((point, index) => {
          const jitter = (stableUnitInterval(point.jitterKey) - 0.5) * THEME.rawJitterWidthData * 2;
          return (
            <circle
              key={`raw-${point.sampleKey}-${index}`}
              cx={xScale(point.x + jitter)}
              cy={yScale(point.y)}
              r={THEME.rawPointRadius}
              fill={colorFor(point.region)}
              fillOpacity={THEME.rawPointAlpha}
            />
          );
        })}
      </g>

      <rect
        x={THEME.panelX}
        y={THEME.panelY}
        width={THEME.panelWidth}
        height={THEME.panelHeight}
        fill="none"
        stroke={THEME.panelBorder}
        strokeWidth={THEME.panelBorderWidth}
      />

      {RC_X_BREAKS.map(({ value, label }) => {
        const x = xScale(value);
        return (
          <g key={`x-axis-${value}`}>
            <line
              x1={x}
              y1={panelBottom}
              x2={x}
              y2={panelBottom + THEME.axisTickLength}
              stroke={THEME.axisInk}
              strokeWidth={THEME.axisTickWidth}
            />
            <text
              x={x}
              y={panelBottom + THEME.xTickLabelOffset}
              textAnchor="middle"
              fontFamily={THEME.fontFamily}
              fontSize={THEME.axisTextFontSize}
              fill={THEME.ink}
            >
              {label}
            </text>
          </g>
        );
      })}

      {yBreaks.map((value) => {
        const y = yScale(value);
        return (
          <g key={`y-axis-${value}`}>
            <line
              x1={THEME.panelX}
              y1={y}
              x2={THEME.panelX - THEME.axisTickLength}
              y2={y}
              stroke={THEME.axisInk}
              strokeWidth={THEME.axisTickWidth}
            />
            <text
              x={THEME.panelX - 10}
              y={y}
              dy="0.32em"
              textAnchor="end"
              fontFamily={THEME.fontFamily}
              fontSize={THEME.axisTextFontSize}
              fill={THEME.ink}
            >
              {formatNumericTick(value)}
            </text>
          </g>
        );
      })}

      <text
        x={panelCenterX}
        y={panelBottom + THEME.xAxisTitleOffset}
        textAnchor="middle"
        fontFamily={THEME.fontFamily}
        fontSize={THEME.axisTitleFontSize}
        fill={THEME.ink}
      >
        {xLabel}
      </text>
      <text
        x={THEME.yAxisTitleX}
        y={panelCenterY}
        transform={`rotate(-90 ${THEME.yAxisTitleX} ${panelCenterY})`}
        textAnchor="middle"
        fontFamily={THEME.fontFamily}
        fontSize={THEME.axisTitleFontSize}
        fill={THEME.ink}
      >
        {yLabel}
      </text>

      <g aria-label="Cortical position legend">
        {legend.items.map((region, index) => {
          const precedingWidth = legend.items.slice(0, index).reduce((sum, item) => sum + item.width, 0);
          const x = legendStartX + precedingWidth;
          return (
            <g key={`legend-${region.id}`}>
              <LegendKey x={x} y={THEME.legendY} color={region.color} />
              <text
                x={x + legend.keyWidth + legend.keyToTextGap}
                y={THEME.legendY}
                dy="0.32em"
                textAnchor="start"
                fontFamily={THEME.fontFamily}
                fontSize={THEME.legendTextFontSize}
                fill={THEME.ink}
              >
                {region.label}
              </text>
            </g>
          );
        })}
      </g>
    </svg>
  );
}
