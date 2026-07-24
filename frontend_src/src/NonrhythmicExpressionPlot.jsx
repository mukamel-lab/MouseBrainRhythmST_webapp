import { useId, useMemo } from 'react';
import { boxWhiskerSummary, kernelDensity } from './plot/nonrhythmicDistribution.js';
import {
  buildNonrhythmicPlotLayout,
  displaySex,
  NONRHYTHMIC_PLOT_WIDTH,
} from './plot/nonrhythmicPlotLayout.js';
import {
  buildNonrhythmicPlotModel,
  nonrhythmicExpressionDomain,
} from './plot/nonrhythmicPlotModel.js';
import {
  formatNumericTick,
  linearScale,
  niceTicks,
  stableUnitInterval,
} from './plot/plotMath.js';

const FONT = 'Arial, ArialMT, "Helvetica Neue", sans-serif';
const EMPTY_HEIGHT = 703;
const COLORS = Object.freeze({
  NTG: '#2563eb',
  APP23: '#dc2626',
});
const FALLBACK_COLORS = Object.freeze(['#2563eb', '#dc2626', '#7c3aed', '#059669']);

function colorForGenotype(genotype, index = 0) {
  return COLORS[String(genotype).toUpperCase()]
    ?? FALLBACK_COLORS[index % FALLBACK_COLORS.length];
}

function roundedCoordinate(value) {
  return Number(value.toFixed(2));
}

function violinPath(density, centerX, scaleY, halfWidth) {
  const maximumDensity = Math.max(...density.map((point) => point.density), 0);
  if (!(maximumDensity > 0)) return '';
  const edge = (point, direction) => {
    const width = (point.density / maximumDensity) * halfWidth;
    return `${roundedCoordinate(centerX + direction * width)},${roundedCoordinate(scaleY(point.value))}`;
  };
  const taperedDensity = [
    { ...density[0], density: 0 },
    ...density,
    { ...density.at(-1), density: 0 },
  ];
  const left = taperedDensity.map((point) => edge(point, -1));
  const right = [...taperedDensity].reverse().map((point) => edge(point, 1));
  return `M ${left.join(' L ')} L ${right.join(' L ')} Z`;
}

function legendGeometry(genotypes, width) {
  const widths = genotypes.map((genotype) => (
    Math.max(102, 39 + String(genotype).length * 8.5)
  ));
  const totalWidth = widths.reduce((sum, width) => sum + width, 0);
  let cursor = width / 2 - totalWidth / 2;
  return genotypes.map((genotype, index) => {
    const geometry = { genotype, index, x: cursor };
    cursor += widths[index];
    return geometry;
  });
}

function EmptyPlot({ gene, message }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      className="nonrhythmic-expression-svg"
      data-role="nonrhythmic-expression-plot"
      viewBox={`0 0 ${NONRHYTHMIC_PLOT_WIDTH} ${EMPTY_HEIGHT}`}
      width={NONRHYTHMIC_PLOT_WIDTH}
      height={EMPTY_HEIGHT}
      role="img"
      aria-label={message}
      style={{
        display: 'block',
        width: '100%',
        height: 'auto',
        fontFamily: FONT,
        background: '#fff',
      }}
    >
      <rect width={NONRHYTHMIC_PLOT_WIDTH} height={EMPTY_HEIGHT} fill="#fff" />
      <text
        x={NONRHYTHMIC_PLOT_WIDTH / 2}
        y={52}
        textAnchor="middle"
        fontFamily={FONT}
        fontSize="23"
        fontWeight="700"
        fontStyle="italic"
      >
        {gene}
      </text>
      <text
        x={NONRHYTHMIC_PLOT_WIDTH / 2}
        y={EMPTY_HEIGHT / 2}
        textAnchor="middle"
        fontFamily={FONT}
        fontSize="17"
        fill="#475569"
      >
        {message}
      </text>
    </svg>
  );
}

export default function NonrhythmicExpressionPlot({
  payload,
  splitByAge = true,
  splitBySex = true,
}) {
  const rawId = useId();
  const idPrefix = `nr${rawId.replace(/[^A-Za-z0-9_-]/g, '')}`;
  const model = useMemo(
    () => buildNonrhythmicPlotModel(payload?.expression, {
      splitAge: splitByAge,
      splitSex: splitBySex,
    }),
    [payload?.expression, splitByAge, splitBySex],
  );
  const layout = useMemo(
    () => buildNonrhythmicPlotLayout({
      ages: model.ages,
      sexes: model.sexes,
      splitByAge,
      splitBySex,
    }),
    [model.ages, model.sexes, splitByAge, splitBySex],
  );

  const gene = String(payload?.gene || 'Gene');
  if (!model.rows.length || !model.genotypes.length) {
    return <EmptyPlot gene={gene} message="No expression observations are available" />;
  }

  const yDomain = nonrhythmicExpressionDomain(model.rows.map((row) => row.value));
  const yTicks = niceTicks(yDomain, 5);
  const expressionLabel = 'log2 Normalized mRNA Expression';
  const clusterLabel = String(payload?.cluster?.label || payload?.cluster?.id || '');
  const legend = legendGeometry(model.genotypes, layout.width);
  const facetsByPosition = new Map(
    model.facets.map((facet) => [`${facet.rowIndex}-${facet.columnIndex}`, facet]),
  );
  const titleId = `${idPrefix}-title`;
  const descriptionId = `${idPrefix}-description`;

  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      className="nonrhythmic-expression-svg"
      data-role="nonrhythmic-expression-plot"
      viewBox={`0 0 ${layout.width} ${layout.height}`}
      width={layout.width}
      height={layout.height}
      role="img"
      aria-labelledby={`${titleId} ${descriptionId}`}
      style={{
        display: 'block',
        width: '100%',
        height: 'auto',
        fontFamily: FONT,
        background: '#fff',
      }}
    >
      <title id={titleId}>{`${gene}: APP23 versus NTG expression`}</title>
      <desc id={descriptionId}>
        Translucent violins show sample density, boxes show the interquartile range and median,
        whiskers extend to observations within 1.5 times the interquartile range, and transparent
        dots show individual samples.
      </desc>
      <rect width={layout.width} height={layout.height} fill="#fff" />

      <text
        x={layout.width / 2}
        y={layout.header.titleY}
        textAnchor="middle"
        fontFamily={FONT}
        fontSize="23"
        fontWeight="700"
        fontStyle="italic"
        fill="#111827"
      >
        {gene}
      </text>
      {clusterLabel ? (
        <text
          x={layout.width / 2}
          y={layout.header.clusterY}
          textAnchor="middle"
          fontFamily={FONT}
          fontSize="15"
          fill="#475569"
        >
          {clusterLabel}
        </text>
      ) : null}

      <g aria-label="Genotype legend">
        {legend.map(({ genotype, index, x }) => {
          const color = colorForGenotype(genotype, index);
          return (
            <g key={`legend-${genotype}`}>
              <circle
                cx={x + 8}
                cy={layout.header.legendY}
                r={layout.header.legendRadius}
                fill={color}
              />
              <text
                x={x + 25}
                y={layout.header.legendY}
                dy="0.34em"
                fontFamily={FONT}
                fontSize="15"
                fontWeight="600"
                fill="#1f2937"
              >
                {genotype}
              </text>
            </g>
          );
        })}
      </g>

      <defs>
        {layout.facets.map((facet) => (
          <clipPath
            key={`clip-${facet.key}`}
            id={`${idPrefix}-clip-${facet.rowIndex}-${facet.columnIndex}`}
          >
            <rect
              x={facet.panelLeft}
              y={facet.plotTop}
              width={facet.panelWidth}
              height={facet.plotHeight}
            />
          </clipPath>
        ))}
      </defs>

      {layout.facets.map((facet) => {
        const facetModel = facetsByPosition.get(`${facet.rowIndex}-${facet.columnIndex}`);
        const scaleY = linearScale(yDomain, [facet.plotBottom, facet.plotTop]);
        const categorySpacing = facet.panelWidth / (model.genotypes.length + 1);
        const categoryX = (genotype) => (
          facet.panelLeft + categorySpacing * (model.genotypes.indexOf(genotype) + 1)
        );
        const violinHalfWidth = Math.min(72, Math.max(32, categorySpacing * 0.28));
        const boxHalfWidth = Math.min(25, Math.max(15, violinHalfWidth * 0.45));
        const bottomRow = facet.rowIndex === layout.rowCount - 1;
        const clipPath = `url(#${idPrefix}-clip-${facet.rowIndex}-${facet.columnIndex})`;

        return (
          <g key={`facet-${facet.key}`} aria-label={facet.stripLabel}>
            <rect
              x={facet.panelLeft}
              y={facet.plotTop}
              width={facet.panelWidth}
              height={facet.plotHeight}
              fill="#fdfefe"
            />
            {yTicks.map((tick) => (
              <line
                key={`grid-${facet.key}-${tick}`}
                x1={facet.panelLeft}
                y1={scaleY(tick)}
                x2={facet.panelLeft + facet.panelWidth}
                y2={scaleY(tick)}
                stroke="#e2e8f0"
                strokeWidth="1"
              />
            ))}

            <g clipPath={clipPath}>
              {facetModel?.groups.map((group, genotypeIndex) => {
                if (
                  group.n < 3
                  || group.values[0] === group.values.at(-1)
                ) return null;
                const density = kernelDensity(group.values, yDomain, 64);
                const path = violinPath(
                  density,
                  categoryX(group.genotype),
                  scaleY,
                  violinHalfWidth,
                );
                if (!path) return null;
                const color = colorForGenotype(group.genotype, genotypeIndex);
                return (
                  <path
                    key={`violin-${facet.key}-${group.genotype}`}
                    d={path}
                    fill={color}
                    fillOpacity="0.16"
                    stroke={color}
                    strokeOpacity="0.58"
                    strokeWidth="1.3"
                  >
                    <title>{`${group.genotype} density, n = ${group.n}`}</title>
                  </path>
                );
              })}

              {facetModel?.groups.map((group, genotypeIndex) => {
                if (group.n < 2) return null;
                const summary = boxWhiskerSummary(group.values);
                if (!summary) return null;
                const x = categoryX(group.genotype);
                const color = colorForGenotype(group.genotype, genotypeIndex);
                const q3Y = scaleY(summary.q3);
                const q1Y = scaleY(summary.q1);
                const upperY = scaleY(summary.upperWhisker);
                const lowerY = scaleY(summary.lowerWhisker);
                return (
                  <g key={`box-${facet.key}-${group.genotype}`}>
                    <title>
                      {`${group.genotype}: median ${formatNumericTick(summary.median)}, IQR ${formatNumericTick(summary.q1)}–${formatNumericTick(summary.q3)}, n = ${summary.n}`}
                    </title>
                    <line x1={x} y1={upperY} x2={x} y2={q3Y} stroke={color} strokeWidth="1.8" />
                    <line x1={x} y1={q1Y} x2={x} y2={lowerY} stroke={color} strokeWidth="1.8" />
                    <line x1={x - 10} y1={upperY} x2={x + 10} y2={upperY} stroke={color} strokeWidth="1.8" />
                    <line x1={x - 10} y1={lowerY} x2={x + 10} y2={lowerY} stroke={color} strokeWidth="1.8" />
                    <rect
                      x={x - boxHalfWidth}
                      y={Math.min(q3Y, q1Y)}
                      width={boxHalfWidth * 2}
                      height={Math.max(2, Math.abs(q1Y - q3Y))}
                      fill="#fff"
                      fillOpacity="0.82"
                      stroke={color}
                      strokeWidth="1.8"
                    />
                    <line
                      x1={x - boxHalfWidth}
                      y1={scaleY(summary.median)}
                      x2={x + boxHalfWidth}
                      y2={scaleY(summary.median)}
                      stroke={color}
                      strokeWidth="2.5"
                    />
                  </g>
                );
              })}

              {facetModel?.groups.flatMap((group, genotypeIndex) => {
                const x = categoryX(group.genotype);
                const jitterHalfWidth = violinHalfWidth * 0.88;
                const color = colorForGenotype(group.genotype, genotypeIndex);
                return group.rows.map((row, pointIndex) => {
                  const jitter = (
                    stableUnitInterval(`${facetModel.key}|${row.sampleKey}|${row.value}`)
                    - 0.5
                  ) * jitterHalfWidth * 2;
                  return (
                    <circle
                      key={`point-${facet.key}-${group.genotype}-${row.sampleKey}-${pointIndex}`}
                      cx={x + jitter}
                      cy={scaleY(row.value)}
                      r="3.5"
                      fill={color}
                      fillOpacity="0.55"
                      stroke="#fff"
                      strokeOpacity="0.72"
                      strokeWidth="0.65"
                    >
                      <title>{`${row.sampleKey} · ${row.age} · ${displaySex(row.sex)} · ${row.genotype}: ${formatNumericTick(row.value)}`}</title>
                    </circle>
                  );
                });
              })}
            </g>

            <rect
              x={facet.panelLeft}
              y={facet.plotTop}
              width={facet.panelWidth}
              height={facet.plotHeight}
              fill="none"
              stroke="#94a3b8"
              strokeWidth="1.1"
            />
            {facet.stripLabel ? (
              <>
                <rect
                  x={facet.panelLeft}
                  y={facet.stripTop}
                  width={facet.panelWidth}
                  height={layout.stripHeight}
                  rx="3"
                  fill="#eef2f7"
                  stroke="#cbd5e1"
                  strokeWidth="1"
                />
                <text
                  x={facet.panelLeft + facet.panelWidth / 2}
                  y={facet.stripTop + layout.stripHeight / 2}
                  dy="0.34em"
                  textAnchor="middle"
                  fontFamily={FONT}
                  fontSize="14"
                  fontWeight="700"
                  fill="#1f2937"
                >
                  {facet.stripLabel}
                </text>
              </>
            ) : null}

            {facet.columnIndex === 0 ? yTicks.map((tick) => (
              <g key={`axis-${facet.key}-${tick}`}>
                <line
                  x1={facet.panelLeft - 5}
                  y1={scaleY(tick)}
                  x2={facet.panelLeft}
                  y2={scaleY(tick)}
                  stroke="#475569"
                  strokeWidth="1"
                />
                <text
                  x={facet.panelLeft - 10}
                  y={scaleY(tick)}
                  dy="0.34em"
                  textAnchor="end"
                  fontFamily={FONT}
                  fontSize="13.5"
                  fill="#334155"
                >
                  {formatNumericTick(tick)}
                </text>
              </g>
            )) : null}

            {facetModel?.groups.map((group) => (
              <g key={`category-${facet.key}-${group.genotype}`}>
                {bottomRow ? (
                  <text
                    x={categoryX(group.genotype)}
                    y={facet.xLabelY}
                    textAnchor="middle"
                    fontFamily={FONT}
                    fontSize="14"
                    fontWeight="700"
                    fill="#1f2937"
                  >
                    {group.genotype}
                  </text>
                ) : null}
                <text
                  x={categoryX(group.genotype)}
                  y={facet.plotBottom + (bottomRow ? 44 : 24)}
                  textAnchor="middle"
                  fontFamily={FONT}
                  fontSize="12.5"
                  fill="#64748b"
                >
                  {`n = ${group.n}`}
                </text>
              </g>
            ))}

            {facet.rowLabel && facet.columnIndex === layout.columnCount - 1 ? (
              <text
                x={facet.panelLeft + facet.panelWidth + 22}
                y={facet.plotTop + facet.plotHeight / 2}
                transform={`rotate(90 ${facet.panelLeft + facet.panelWidth + 22} ${facet.plotTop + facet.plotHeight / 2})`}
                textAnchor="middle"
                fontFamily={FONT}
                fontSize="14"
                fontWeight="700"
                fill="#334155"
              >
                {facet.rowLabel}
              </text>
            ) : null}
          </g>
        );
      })}

      <text
        x={26}
        y={layout.yAxisCenter}
        transform={`rotate(-90 26 ${layout.yAxisCenter})`}
        textAnchor="middle"
        fontFamily={FONT}
        fontSize="16"
        fontWeight="600"
        fill="#111827"
      >
        {expressionLabel}
      </text>
      <text
        x={layout.margin.left + (
          layout.width - layout.margin.left - layout.margin.right
        ) / 2}
        y={layout.xAxisTitleY}
        textAnchor="middle"
        fontFamily={FONT}
        fontSize="16"
        fontWeight="600"
        fill="#111827"
      >
        Genotype
      </text>
    </svg>
  );
}
