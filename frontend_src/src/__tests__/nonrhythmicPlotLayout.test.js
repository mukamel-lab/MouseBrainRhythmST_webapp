import test from 'node:test';
import assert from 'node:assert/strict';
import {
  buildNonrhythmicPlotLayout,
  displaySex,
  displaySexText,
  NONRHYTHMIC_PLOT_HEIGHT,
  NONRHYTHMIC_PLOT_WIDTH,
} from '../plot/nonrhythmicPlotLayout.js';

const ages = ['7 months', '14 months'];
const sexes = ['F', 'M'];
const modes = [
  { splitByAge: true, splitBySex: true, rows: 2, columns: 2 },
  { splitByAge: true, splitBySex: false, rows: 1, columns: 2 },
  { splitByAge: false, splitBySex: true, rows: 1, columns: 2 },
  { splitByAge: false, splitBySex: false, rows: 1, columns: 1 },
];

test('sex abbreviations expand only in display text', () => {
  assert.equal(displaySex('F'), 'Female');
  assert.equal(displaySex('M'), 'Male');
  assert.equal(
    displaySexText('APP23 vs NTG at 7 months, sex F'),
    'APP23 vs NTG at 7 months, sex Female',
  );
  assert.equal(displaySexText('Genotype-by-sex: M minus F'), 'Genotype-by-sex: Male minus Female');
  assert.equal(displaySexText('M vs F in NTG'), 'Male vs Female in NTG');
  assert.equal(displaySexText('sexM'), 'sexMale');
  assert.equal(displaySexText('BH FDR'), 'BH FDR');
});

test('nonrhythmic facets follow all four split modes on one fixed canvas', () => {
  const layouts = modes.map((mode) => ({
    mode,
    layout: buildNonrhythmicPlotLayout({ ages, sexes, ...mode }),
  }));

  assert.equal(NONRHYTHMIC_PLOT_WIDTH, 920);
  for (const { mode, layout } of layouts) {
    assert.deepEqual([layout.rowCount, layout.columnCount], [mode.rows, mode.columns]);
    assert.equal(layout.width, NONRHYTHMIC_PLOT_WIDTH);
    assert.equal(layout.height, NONRHYTHMIC_PLOT_HEIGHT);
  }
  assert.equal(
    layouts.at(-1).layout.facets[0].stripLabel,
    '',
  );
  assert.equal(layouts.at(-1).layout.stripHeight, 0);
  assert.ok(layouts.at(-1).layout.plotHeight > layouts[0].layout.plotHeight);
  assert.ok(layouts[0].layout.facets.every((facet) => facet.panelWidth >= 300));
  for (const { layout } of layouts) {
    assert.ok(layout.facets.every((facet) => (
      facet.panelLeft + facet.panelWidth <= layout.width - layout.margin.right
    )));
  }
});

test('legend, facets, sample sizes, and bottom axes occupy separate bands in every mode', () => {
  for (const mode of modes) {
    const layout = buildNonrhythmicPlotLayout({ ages, sexes, ...mode });
    const firstStripTop = Math.min(...layout.facets.map((facet) => facet.stripTop));
    const bottomRow = layout.facets.filter((facet) => (
      facet.rowIndex === layout.rowCount - 1
    ));

    assert.ok(layout.header.legendBottom + 8 < firstStripTop);
    assert.ok(layout.facets.every((facet) => facet.stripBottom === facet.plotTop));
    assert.ok(bottomRow.every((facet) => facet.xLabelY + 5 < layout.xAxisTitleY));
    assert.ok(layout.xAxisTitleY + 18 < layout.height);

    for (const facet of layout.facets.filter((item) => item.rowIndex < layout.rowCount - 1)) {
      const nextStripTop = layout.facets.find((item) => (
        item.rowIndex === facet.rowIndex + 1
      )).stripTop;
      assert.ok(facet.plotBottom + 30 < nextStripTop);
    }
  }
});
