import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  filterAndSortNonrhythmicResults,
  formatTwoSignificantDigits,
  NONRHYTHMIC_RESULTS_PAGE_SIZE,
  nonrhythmicResultsToCsv,
  paginateNonrhythmicResults,
} from '../nonrhythmicResultsTable.js';

const rows = [
  {
    gene: 'Gene10',
    log2FoldChange: 1.234,
    lfcSE: 0.4321,
    pvalue: 0.001234,
    padj: 0.01,
  },
  {
    gene_symbol: 'Gene2',
    log2_fold_change: -0.876,
    standard_error: 0.222,
    p_value: 2.5e-8,
    bh_fdr: 4.5e-7,
  },
  {
    symbol: 'Other',
    wald: {
      log2FoldChange: 0,
      lfcSE: 0.1,
      pvalue: 1,
      padj: null,
    },
  },
];

test('table rows normalize common result shapes and sort the complete filtered set', () => {
  const byFdr = filterAndSortNonrhythmicResults(rows);
  assert.deepEqual(byFdr.map(({ gene }) => gene), ['Gene2', 'Gene10', 'Other']);
  assert.equal(byFdr[0].log2FoldChange, -0.876);
  assert.equal(byFdr[0].lfcSE, 0.222);

  const matching = filterAndSortNonrhythmicResults(rows, {
    query: 'gene',
    sortKey: 'gene',
    sortDirection: 'asc',
  });
  assert.deepEqual(matching.map(({ gene }) => gene), ['Gene2', 'Gene10']);

  const descending = filterAndSortNonrhythmicResults(rows, {
    sortKey: 'log2FoldChange',
    sortDirection: 'desc',
  });
  assert.deepEqual(descending.map(({ gene }) => gene), ['Gene10', 'Other', 'Gene2']);
});

test('missing numeric results always sort last', () => {
  for (const sortDirection of ['asc', 'desc']) {
    const sorted = filterAndSortNonrhythmicResults(rows, {
      sortKey: 'padj',
      sortDirection,
    });
    assert.equal(sorted.at(-1).gene, 'Other');
  }
});

test('Log2FC threshold filters by absolute effect size and invalid values are safe', () => {
  const filtered = filterAndSortNonrhythmicResults(rows, { lfcThreshold: 1 });
  assert.deepEqual(filtered.map(({ gene }) => gene), ['Gene10']);

  for (const lfcThreshold of ['', null, 'not-a-number', -1]) {
    assert.equal(
      filterAndSortNonrhythmicResults(rows, { lfcThreshold }).length,
      rows.length,
    );
  }
});

test('FDR threshold filters adjusted p values and combines with Log2FC threshold', () => {
  const fdrFiltered = filterAndSortNonrhythmicResults(rows, { fdrThreshold: 0.001 });
  assert.deepEqual(fdrFiltered.map(({ gene }) => gene), ['Gene2']);

  const combined = filterAndSortNonrhythmicResults(rows, {
    lfcThreshold: 1,
    fdrThreshold: 0.05,
  });
  assert.deepEqual(combined.map(({ gene }) => gene), ['Gene10']);

  for (const fdrThreshold of ['', null, 'not-a-number', -0.1, 1.1]) {
    assert.equal(
      filterAndSortNonrhythmicResults(rows, { fdrThreshold }).length,
      rows.length,
    );
  }
});

test('pagination shows 20 rows and clamps pages safely', () => {
  const manyRows = Array.from({ length: 45 }, (_, index) => ({ gene: `G${index + 1}` }));
  assert.equal(NONRHYTHMIC_RESULTS_PAGE_SIZE, 20);

  const middle = paginateNonrhythmicResults(manyRows, 2);
  assert.equal(middle.rows.length, 20);
  assert.equal(middle.startIndex, 20);
  assert.equal(middle.endIndex, 40);
  assert.equal(middle.pageCount, 3);

  const clamped = paginateNonrhythmicResults(manyRows, 99);
  assert.equal(clamped.currentPage, 3);
  assert.equal(clamped.rows.length, 5);
});

test('numeric display uses two significant digits', () => {
  assert.equal(formatTwoSignificantDigits(1.234), '1.2');
  assert.equal(formatTwoSignificantDigits(-0.876), '-0.88');
  assert.equal(formatTwoSignificantDigits(0.001234), '1.2e-3');
  assert.equal(formatTwoSignificantDigits(1234), '1.2e3');
  assert.equal(formatTwoSignificantDigits(null), '—');
});

test('CSV export includes all supplied rows in their current order with full precision', () => {
  const prepared = filterAndSortNonrhythmicResults(rows, {
    query: 'gene',
    sortKey: 'gene',
    sortDirection: 'asc',
  });
  const csv = nonrhythmicResultsToCsv(prepared);
  const lines = csv.split('\r\n');

  assert.equal(lines[0], 'Gene,Log2FC,SE,P value,BH FDR');
  assert.equal(lines.length, 3);
  assert.equal(lines[1], 'Gene2,-0.876,0.222,2.5e-8,4.5e-7');
  assert.equal(lines[2], 'Gene10,1.234,0.4321,0.001234,0.01');
});

test('component exposes accessible sorting, selection, and empty-state controls', () => {
  const component = readFileSync(
    new URL('../NonrhythmicResultsTable.jsx', import.meta.url),
    'utf8',
  );

  assert.match(component, /aria-sort=/);
  assert.match(component, /scope="col"/);
  assert.match(component, /scope="row"/);
  assert.match(component, /aria-current=/);
  assert.match(component, /Download CSV/);
  assert.match(component, /Clear search/);
  assert.match(component, /fdrThreshold = 0\.1/);
  assert.match(component, /lfcThreshold = 0\.2/);
  assert.match(component, /BH FDR ≤/);
  assert.match(component, /\|Log2FC\| ≥/);
  assert.match(component, /NONRHYTHMIC_RESULTS_PAGE_SIZE/);
});
