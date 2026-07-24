import { useMemo, useState } from 'react';
import {
  filterAndSortNonrhythmicResults,
  formatTwoSignificantDigits,
  NONRHYTHMIC_RESULT_COLUMNS,
  NONRHYTHMIC_RESULTS_PAGE_SIZE,
  nonrhythmicResultsToCsv,
  paginateNonrhythmicResults,
} from './nonrhythmicResultsTable.js';
import './NonrhythmicResultsTable.css';

function SortableHeader({
  column,
  sortKey,
  sortDirection,
  onSort,
}) {
  const active = sortKey === column.key;
  const nextDirection = active && sortDirection === 'asc' ? 'descending' : 'ascending';

  return (
    <th
      scope="col"
      className={column.numeric ? 'nr-results-numeric' : undefined}
      aria-sort={active ? `${sortDirection}ending` : 'none'}
    >
      <button
        type="button"
        className="nr-results-sort-button"
        onClick={() => onSort(column.key)}
        aria-label={`Sort by ${column.label}, ${nextDirection}`}
      >
        <span>{column.label}</span>
        <span className="nr-results-sort-indicator" aria-hidden="true">
          {active ? (sortDirection === 'asc' ? '▲' : '▼') : '↕'}
        </span>
      </button>
    </th>
  );
}

function downloadCsv(rows, filename) {
  const csv = nonrhythmicResultsToCsv(rows);
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}

export default function NonrhythmicResultsTable({
  rows = [],
  selectedGene = '',
  onSelectGene,
  loading = false,
  error = '',
  title = 'All-gene differential expression',
  downloadFilename = 'app23-vs-ntg-results.csv',
  lfcThreshold = 0.2,
  fdrThreshold = 0.1,
}) {
  const [query, setQuery] = useState('');
  const [sortKey, setSortKey] = useState('padj');
  const [sortDirection, setSortDirection] = useState('asc');
  const [pageState, setPageState] = useState({
    page: 1,
    rows,
    lfcThreshold,
    fdrThreshold,
  });
  const page = pageState.rows === rows
    && Object.is(pageState.lfcThreshold, lfcThreshold)
    && Object.is(pageState.fdrThreshold, fdrThreshold)
    ? pageState.page
    : 1;
  const setPage = (nextPage) => {
    setPageState({
      page: nextPage,
      rows,
      lfcThreshold,
      fdrThreshold,
    });
  };
  const preparedRows = useMemo(
    () => filterAndSortNonrhythmicResults(rows, {
      query,
      lfcThreshold,
      fdrThreshold,
      sortKey,
      sortDirection,
    }),
    [rows, query, lfcThreshold, fdrThreshold, sortKey, sortDirection],
  );
  const thresholdRows = useMemo(
    () => filterAndSortNonrhythmicResults(rows, {
      lfcThreshold,
      fdrThreshold,
    }),
    [rows, lfcThreshold, fdrThreshold],
  );
  const pagination = paginateNonrhythmicResults(
    preparedRows,
    page,
    NONRHYTHMIC_RESULTS_PAGE_SIZE,
  );

  const chooseSort = (key) => {
    setSortDirection((currentDirection) => (
      key === sortKey && currentDirection === 'asc' ? 'desc' : 'asc'
    ));
    setSortKey(key);
    setPage(1);
  };

  const chooseGene = (row) => {
    if (typeof onSelectGene === 'function' && row.gene) {
      onSelectGene(row.gene, row.source);
    }
  };

  const changeQuery = (event) => {
    setQuery(event.target.value);
    setPage(1);
  };

  const noResults = !loading && !error && pagination.rows.length === 0;
  const thresholdText = String(lfcThreshold ?? '').trim();
  const thresholdNumber = Number(thresholdText);
  const validLfcThreshold = thresholdText !== ''
    && Number.isFinite(thresholdNumber)
    && thresholdNumber >= 0;
  const fdrThresholdText = String(fdrThreshold ?? '').trim();
  const fdrThresholdNumber = Number(fdrThresholdText);
  const validFdrThreshold = fdrThresholdText !== ''
    && Number.isFinite(fdrThresholdNumber)
    && fdrThresholdNumber >= 0
    && fdrThresholdNumber <= 1;
  const activeThresholds = [
    validFdrThreshold
      ? `BH FDR ≤ ${formatTwoSignificantDigits(fdrThresholdNumber)}`
      : null,
    validLfcThreshold
      ? `|Log2FC| ≥ ${formatTwoSignificantDigits(thresholdNumber)}`
      : null,
  ].filter(Boolean);
  const thresholdLabel = [
    `${thresholdRows.length.toLocaleString()} of ${rows.length.toLocaleString()} genes`,
    ...activeThresholds,
  ].join(' · ');
  const totalLabel = query
    ? `${preparedRows.length.toLocaleString()} search matches · ${thresholdLabel}`
    : thresholdLabel;

  return (
    <section
      className="nr-results-table-panel"
      aria-labelledby="nr-results-table-title"
      aria-busy={loading}
    >
      <div className="nr-results-table-heading">
        <div>
          <h2 id="nr-results-table-title">{title}</h2>
          <p className="nr-results-count" aria-live="polite">{totalLabel}</p>
        </div>
        <button
          type="button"
          className="secondary-button nr-results-download"
          onClick={() => downloadCsv(preparedRows, downloadFilename)}
          disabled={loading || preparedRows.length === 0}
        >
          Download CSV
        </button>
      </div>

      <label className="nr-results-search">
        <span>Search genes</span>
        <input
          type="search"
          value={query}
          onChange={changeQuery}
          placeholder="Gene symbol"
          autoComplete="off"
        />
      </label>

      {loading ? <p className="nr-results-status" role="status">Loading all-gene results…</p> : null}
      {error ? <p className="nr-results-status error" role="alert">{error}</p> : null}

      {!loading && !error ? (
        <div className="nr-results-table-scroll">
          <table className="nr-results-table" aria-label={title}>
            <thead>
              <tr>
                {NONRHYTHMIC_RESULT_COLUMNS.map((column) => (
                  <SortableHeader
                    key={column.key}
                    column={column}
                    sortKey={sortKey}
                    sortDirection={sortDirection}
                    onSort={chooseSort}
                  />
                ))}
              </tr>
            </thead>
            <tbody>
              {pagination.rows.map((row) => {
                const selected = row.gene.localeCompare(
                  String(selectedGene),
                  undefined,
                  { sensitivity: 'base' },
                ) === 0;
                return (
                  <tr
                    key={`${row.gene || 'missing-gene'}-${row.sourceIndex}`}
                    className={selected ? 'is-selected' : undefined}
                    aria-selected={selected}
                    onClick={() => chooseGene(row)}
                  >
                    <th scope="row">
                      <button
                        type="button"
                        className="nr-results-gene-button"
                        onClick={(event) => {
                          event.stopPropagation();
                          chooseGene(row);
                        }}
                        aria-current={selected ? 'true' : undefined}
                      >
                        {row.gene || '—'}
                      </button>
                    </th>
                    {NONRHYTHMIC_RESULT_COLUMNS.slice(1).map(({ key }) => (
                      <td
                        key={key}
                        className="nr-results-numeric"
                        title={row[key] === null ? undefined : String(row[key])}
                      >
                        {formatTwoSignificantDigits(row[key])}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {noResults ? (
        <div className="nr-results-empty">
          <p>
            {query
              ? `No genes match “${query}”.`
              : 'No differential-expression results are available for this comparison.'}
          </p>
          {query ? (
            <button
              type="button"
              className="link-button"
              onClick={() => {
                setQuery('');
                setPage(1);
              }}
            >
              Clear search
            </button>
          ) : null}
        </div>
      ) : null}

      {!loading && !error && preparedRows.length > 0 ? (
        <nav className="nr-results-pagination" aria-label="Differential-expression result pages">
          <button
            type="button"
            className="secondary-button"
            onClick={() => setPage(Math.max(1, pagination.currentPage - 1))}
            disabled={pagination.currentPage === 1}
          >
            Previous
          </button>
          <span>
            {(pagination.startIndex + 1).toLocaleString()}–{pagination.endIndex.toLocaleString()}
            {' of '}
            {preparedRows.length.toLocaleString()}
          </span>
          <button
            type="button"
            className="secondary-button"
            onClick={() => setPage(Math.min(pagination.pageCount, pagination.currentPage + 1))}
            disabled={pagination.currentPage === pagination.pageCount}
          >
            Next
          </button>
        </nav>
      ) : null}
    </section>
  );
}
