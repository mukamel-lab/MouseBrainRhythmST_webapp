export const NONRHYTHMIC_RESULTS_PAGE_SIZE = 20;

export const NONRHYTHMIC_RESULT_COLUMNS = Object.freeze([
  { key: 'gene', label: 'Gene', numeric: false },
  { key: 'log2FoldChange', label: 'Log2FC', numeric: true },
  { key: 'lfcSE', label: 'SE', numeric: true },
  { key: 'pvalue', label: 'P value', numeric: true },
  { key: 'padj', label: 'BH FDR', numeric: true },
]);

const VALID_SORT_KEYS = new Set(NONRHYTHMIC_RESULT_COLUMNS.map(({ key }) => key));

function firstDefined(...values) {
  return values.find((value) => value !== undefined && value !== null);
}

function finiteNumber(value) {
  if (value === '' || value === null || value === undefined) return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

/**
 * Converts common API field names into the row shape used by the table.
 * The original row is retained so consumers can recover any extra metadata.
 */
export function normalizeNonrhythmicResultRow(row, sourceIndex = 0) {
  const source = row && typeof row === 'object' ? row : {};
  const wald = source.wald && typeof source.wald === 'object' ? source.wald : {};

  return {
    gene: String(firstDefined(
      source.gene,
      source.symbol,
      source.gene_symbol,
      source.geneSymbol,
      '',
    )).trim(),
    log2FoldChange: finiteNumber(firstDefined(
      source.log2FoldChange,
      source.log2fc,
      source.log2_fold_change,
      wald.log2FoldChange,
      wald.log2fc,
    )),
    lfcSE: finiteNumber(firstDefined(
      source.lfcSE,
      source.se,
      source.standard_error,
      wald.lfcSE,
      wald.se,
    )),
    pvalue: finiteNumber(firstDefined(
      source.pvalue,
      source.p_value,
      source.pValue,
      wald.pvalue,
      wald.p_value,
    )),
    padj: finiteNumber(firstDefined(
      source.padj,
      source.bh_fdr,
      source.fdr,
      source.adjusted_p_value,
      wald.padj,
      wald.bh_fdr,
    )),
    source,
    sourceIndex,
  };
}

export function formatTwoSignificantDigits(value) {
  if (value === '' || value === null || value === undefined) return '—';
  const number = Number(value);
  if (!Number.isFinite(number)) return '—';
  if (Object.is(number, -0) || number === 0) return '0';

  const absolute = Math.abs(number);
  if (absolute < 0.01 || absolute >= 100) {
    return number.toExponential(1).replace('e+', 'e');
  }
  return number.toPrecision(2);
}

function compareValues(left, right, key) {
  const leftValue = left[key];
  const rightValue = right[key];
  const leftMissing = key === 'gene' ? !leftValue : leftValue === null;
  const rightMissing = key === 'gene' ? !rightValue : rightValue === null;

  // Missing estimates remain at the end in either sort direction.
  if (leftMissing !== rightMissing) return leftMissing ? 1 : -1;
  if (leftMissing && rightMissing) return left.sourceIndex - right.sourceIndex;
  if (key === 'gene') {
    return leftValue.localeCompare(rightValue, undefined, {
      sensitivity: 'base',
      numeric: true,
    });
  }
  return leftValue - rightValue;
}

export function filterAndSortNonrhythmicResults(
  rows,
  {
    query = '',
    lfcThreshold = null,
    fdrThreshold = null,
    sortKey = 'padj',
    sortDirection = 'asc',
  } = {},
) {
  const normalizedRows = Array.isArray(rows)
    ? rows.map((row, index) => normalizeNonrhythmicResultRow(row, index))
    : [];
  const normalizedQuery = String(query).trim().toLocaleLowerCase();
  const thresholdText = String(lfcThreshold ?? '').trim();
  const thresholdNumber = Number(thresholdText);
  const applyThreshold = thresholdText !== ''
    && Number.isFinite(thresholdNumber)
    && thresholdNumber >= 0;
  const fdrThresholdText = String(fdrThreshold ?? '').trim();
  const fdrThresholdNumber = Number(fdrThresholdText);
  const applyFdrThreshold = fdrThresholdText !== ''
    && Number.isFinite(fdrThresholdNumber)
    && fdrThresholdNumber >= 0
    && fdrThresholdNumber <= 1;
  const key = VALID_SORT_KEYS.has(sortKey) ? sortKey : 'padj';
  const direction = sortDirection === 'desc' ? -1 : 1;
  const filteredRows = normalizedRows.filter((row) => {
    const matchesLfcThreshold = !applyThreshold || (
      row.log2FoldChange !== null
      && Math.abs(row.log2FoldChange) >= thresholdNumber
    );
    const matchesFdrThreshold = !applyFdrThreshold || (
      row.padj !== null
      && row.padj <= fdrThresholdNumber
    );
    const matchesQuery = !normalizedQuery
      || row.gene.toLocaleLowerCase().includes(normalizedQuery);
    return matchesLfcThreshold && matchesFdrThreshold && matchesQuery;
  });

  return [...filteredRows].sort((left, right) => {
    const comparison = compareValues(left, right, key);
    if (comparison === 0) return left.sourceIndex - right.sourceIndex;
    const leftMissing = key === 'gene' ? !left[key] : left[key] === null;
    const rightMissing = key === 'gene' ? !right[key] : right[key] === null;
    return leftMissing || rightMissing ? comparison : comparison * direction;
  });
}

export function paginateNonrhythmicResults(
  rows,
  page,
  pageSize = NONRHYTHMIC_RESULTS_PAGE_SIZE,
) {
  const safeRows = Array.isArray(rows) ? rows : [];
  const safePageSize = Number.isInteger(pageSize) && pageSize > 0
    ? pageSize
    : NONRHYTHMIC_RESULTS_PAGE_SIZE;
  const pageCount = Math.max(1, Math.ceil(safeRows.length / safePageSize));
  const currentPage = Math.min(Math.max(1, Number(page) || 1), pageCount);
  const startIndex = (currentPage - 1) * safePageSize;

  return {
    rows: safeRows.slice(startIndex, startIndex + safePageSize),
    currentPage,
    pageCount,
    startIndex,
    endIndex: Math.min(startIndex + safePageSize, safeRows.length),
  };
}

function escapeCsvCell(value) {
  const text = value === null || value === undefined ? '' : String(value);
  return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

export function nonrhythmicResultsToCsv(rows) {
  const header = NONRHYTHMIC_RESULT_COLUMNS.map(({ label }) => escapeCsvCell(label));
  const body = (Array.isArray(rows) ? rows : []).map((row, index) => {
    const normalized = row?.sourceIndex === undefined
      ? normalizeNonrhythmicResultRow(row, index)
      : row;
    return NONRHYTHMIC_RESULT_COLUMNS.map(({ key }) => escapeCsvCell(normalized[key]));
  });
  return [header, ...body].map((fields) => fields.join(',')).join('\r\n');
}
