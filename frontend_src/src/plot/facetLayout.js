const KEY_SEPARATOR = '\u001f';

export function uniqueVariables(values) {
  return [...new Set((values || []).filter(Boolean))];
}

export function combinationKey(values, variables) {
  if (!variables.length) return '__all__';
  return variables.map((variable) => String(values?.[variable] ?? '')).join(KEY_SEPARATOR);
}

function dimensionEntries(dimensions, variable) {
  return Array.isArray(dimensions?.[variable]) ? dimensions[variable] : [];
}

export function orderedPresentLevels(rows, dimensions, variable) {
  const present = new Set(rows.map((row) => String(row?.[variable] ?? '')));
  const configured = dimensionEntries(dimensions, variable)
    .filter((entry) => present.has(String(entry.value)))
    .map((entry) => String(entry.value));

  const configuredSet = new Set(configured);
  const remaining = [...present]
    .filter((value) => value && !configuredSet.has(value))
    .sort((a, b) => a.localeCompare(b));

  return [...configured, ...remaining];
}

export function cartesianCombinations(variables, levelsByVariable) {
  if (!variables.length) return [{}];

  return variables.reduce(
    (combinations, variable) => combinations.flatMap((combination) => (
      (levelsByVariable[variable] || []).map((value) => ({
        ...combination,
        [variable]: value,
      }))
    )),
    [{}],
  );
}

/**
 * Returns only combinations that occur in the data, ordered by factor-level
 * metadata. This matches facet_grid nesting: variables on the same side of
 * the formula are nested rather than blindly crossed.
 */
export function orderedPresentCombinations(rows, variables, levelsByVariable) {
  if (!variables.length) return [{}];

  const unique = new Map();
  for (const row of rows) {
    const values = Object.fromEntries(
      variables.map((variable) => [variable, String(row?.[variable] ?? '')]),
    );
    if (variables.some((variable) => !values[variable])) continue;
    unique.set(combinationKey(values, variables), values);
  }

  const ranks = Object.fromEntries(variables.map((variable) => [
    variable,
    new Map((levelsByVariable[variable] || []).map((value, index) => [String(value), index])),
  ]));

  return [...unique.values()].sort((left, right) => {
    for (const variable of variables) {
      const leftValue = String(left[variable]);
      const rightValue = String(right[variable]);
      const leftRank = ranks[variable].get(leftValue) ?? Number.MAX_SAFE_INTEGER;
      const rightRank = ranks[variable].get(rightValue) ?? Number.MAX_SAFE_INTEGER;
      if (leftRank !== rightRank) return leftRank - rightRank;
      const lexical = leftValue.localeCompare(rightValue);
      if (lexical) return lexical;
    }
    return 0;
  });
}

function labelFor(dimensions, variable, value) {
  const match = dimensionEntries(dimensions, variable)
    .find((entry) => String(entry.value) === String(value));
  return match?.label ?? String(value);
}

/**
 * Groups adjacent leaves for nested strips.
 *
 * Prefix grouping reproduces ggh4x's default bleed = FALSE behavior: a lower
 * strip may merge only when every outer strip above it is also identical.
 */
export function buildNestedStripGroups(combinations, variables, dimensions) {
  return variables.map((variable, levelIndex) => {
    const prefixVariables = variables.slice(0, levelIndex + 1);
    const groups = [];
    let start = 0;

    while (start < combinations.length) {
      const prefixKey = combinationKey(combinations[start], prefixVariables);
      let end = start + 1;
      while (
        end < combinations.length
        && combinationKey(combinations[end], prefixVariables) === prefixKey
      ) {
        end += 1;
      }

      const value = combinations[start][variable];
      groups.push({
        variable,
        levelIndex,
        value,
        label: labelFor(dimensions, variable, value),
        start,
        end,
        span: end - start,
        prefixKey,
      });
      start = end;
    }

    return groups;
  });
}

/**
 * Mirrors the exact formula construction in the supplied R code:
 *
 *   split length 0: no facet
 *   split length 1: ~ split[0]
 *   split length 2+: split[0] ~ split[1] + split[2] + ...
 */
export function buildNestedFacetLayout({ rows, splitBy, dimensions }) {
  const variables = uniqueVariables(splitBy);
  const rowVariables = variables.length > 1 ? [variables[0]] : [];
  const columnVariables = variables.length === 1 ? [variables[0]] : variables.slice(1);
  const levelsByVariable = {};

  for (const variable of variables) {
    levelsByVariable[variable] = orderedPresentLevels(rows, dimensions, variable);
  }

  const rowCombinations = orderedPresentCombinations(rows, rowVariables, levelsByVariable);
  const columnCombinations = orderedPresentCombinations(rows, columnVariables, levelsByVariable);

  const panels = rowCombinations.flatMap((rowValues, rowIndex) => (
    columnCombinations.map((columnValues, columnIndex) => {
      const values = { ...rowValues, ...columnValues };
      return {
        rowIndex,
        columnIndex,
        values,
        key: combinationKey(values, variables),
      };
    })
  ));

  return {
    variables,
    rowVariables,
    columnVariables,
    levelsByVariable,
    rowCombinations,
    columnCombinations,
    panels,
    columnStripGroups: buildNestedStripGroups(columnCombinations, columnVariables, dimensions),
    rowStripGroups: buildNestedStripGroups(rowCombinations, rowVariables, dimensions),
  };
}

export function facetFormula(splitBy) {
  const variables = uniqueVariables(splitBy);
  if (!variables.length) return 'no facets';
  if (variables.length === 1) return `facet_nested(~ ${variables[0]})`;
  return `facet_nested(${variables[0]} ~ ${variables.slice(1).join(' + ')})`;
}
