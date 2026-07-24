function finiteNumber(value) {
  if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) {
    return null;
  }
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

export function nonrhythmicExpressionDomain(values) {
  const finite = (Array.isArray(values) ? values : [])
    .map(finiteNumber)
    .filter((value) => value !== null);
  if (!finite.length) return [0, 1];

  const lower = Math.max(0, Math.min(...finite) - 0.5);
  const upper = Math.max(0, Math.max(...finite) + 0.5);
  return upper > lower ? [lower, upper] : [0, 1];
}

function compareText(left, right) {
  return String(left).localeCompare(String(right), undefined, {
    numeric: true,
    sensitivity: 'base',
  });
}

function priorityCompare(left, right, priority) {
  return (
    (priority.get(String(left).toUpperCase()) ?? 20)
    - (priority.get(String(right).toUpperCase()) ?? 20)
    || compareText(left, right)
  );
}

export function normalizeNonrhythmicExpression(expression) {
  return (Array.isArray(expression) ? expression : [])
    .flatMap((row, index) => {
      const value = finiteNumber(row?.value);
      if (value === null) return [];
      return [{
        value,
        ageId: finiteNumber(row?.age_id) ?? 0,
        age: String(row?.age ?? row?.age_label ?? ''),
        sex: String(row?.sex ?? ''),
        genotype: String(row?.genotype ?? ''),
        sampleKey: String(row?.sample_key ?? row?.sample ?? index),
      }];
    })
    .sort((left, right) => (
      left.ageId - right.ageId
      || compareText(left.age, right.age)
      || priorityCompare(left.sex, right.sex, new Map([['F', 0], ['M', 1]]))
      || priorityCompare(left.genotype, right.genotype, new Map([['NTG', 0], ['APP23', 1]]))
      || compareText(left.sampleKey, right.sampleKey)
      || left.value - right.value
    ));
}

function orderedAges(rows) {
  const ageIds = new Map();
  for (const row of rows) {
    if (!ageIds.has(row.age)) ageIds.set(row.age, row.ageId);
    else ageIds.set(row.age, Math.min(ageIds.get(row.age), row.ageId));
  }
  return [...ageIds]
    .sort((left, right) => left[1] - right[1] || compareText(left[0], right[0]))
    .map(([age]) => age);
}

function orderedValues(rows, key, priorityEntries = []) {
  const priority = new Map(priorityEntries);
  return [...new Set(rows.map((row) => row[key]))]
    .sort((left, right) => priorityCompare(left, right, priority));
}

export function buildNonrhythmicPlotModel(expression, {
  splitAge = true,
  splitSex = true,
} = {}) {
  const rows = normalizeNonrhythmicExpression(expression);
  const ages = orderedAges(rows);
  const sexes = orderedValues(rows, 'sex', [['F', 0], ['M', 1]]);
  const genotypes = orderedValues(rows, 'genotype', [['NTG', 0], ['APP23', 1]]);
  const bothSplit = splitAge && splitSex;
  const rowValues = bothSplit ? ages : [null];
  const columnValues = bothSplit
    ? sexes
    : splitAge
      ? ages
      : splitSex
        ? sexes
        : [null];
  const facets = [];

  for (let rowIndex = 0; rowIndex < rowValues.length; rowIndex += 1) {
    for (let columnIndex = 0; columnIndex < columnValues.length; columnIndex += 1) {
      const age = bothSplit ? rowValues[rowIndex] : splitAge ? columnValues[columnIndex] : null;
      const sex = bothSplit ? columnValues[columnIndex] : splitSex ? columnValues[columnIndex] : null;
      const facetRows = rows.filter((row) => (
        (!splitAge || row.age === age)
        && (!splitSex || row.sex === sex)
      ));
      facets.push({
        key: `age:${age ?? 'all'}|sex:${sex ?? 'all'}`,
        rowIndex,
        columnIndex,
        age,
        sex,
        groups: genotypes.map((genotype) => {
          const groupRows = facetRows.filter((row) => row.genotype === genotype);
          return {
            genotype,
            n: groupRows.length,
            values: groupRows.map((row) => row.value).sort((left, right) => left - right),
            rows: groupRows,
          };
        }),
      });
    }
  }

  return {
    rows,
    ages,
    sexes,
    genotypes,
    rowCount: rowValues.length,
    columnCount: columnValues.length,
    facets,
  };
}
