<?php

declare(strict_types=1);

function diurnal_dimension_rows(PDO $pdo, string $table, string $idColumn): array
{
    $allowed = array(
        'clusters' => 'cluster_id',
        'ages' => 'age_id',
        'sexes' => 'sex_id',
        'genotypes' => 'genotype_id',
    );
    if (!isset($allowed[$table]) || $allowed[$table] !== $idColumn) {
        throw new ApiException('Invalid dimension table.', 500);
    }
    return db_all($pdo, 'SELECT ' . $idColumn . ' AS id, code, label, sort_order, color FROM ' . $table . ' ORDER BY sort_order, code COLLATE NOCASE');
}

function diurnal_dimensions(PDO $pdo): array
{
    return array(
        'region' => diurnal_dimension_rows($pdo, 'clusters', 'cluster_id'),
        'age' => diurnal_dimension_rows($pdo, 'ages', 'age_id'),
        'sex' => diurnal_dimension_rows($pdo, 'sexes', 'sex_id'),
        'genotype' => diurnal_dimension_rows($pdo, 'genotypes', 'genotype_id'),
    );
}

function dimension_codes(array $rows): array
{
    return array_map(function ($row) { return (string) $row['code']; }, $rows);
}

function dimension_label_map(array $rows): array
{
    $out = array();
    foreach ($rows as $row) {
        $out[(string) $row['code']] = (string) $row['label'];
    }
    return $out;
}

function dimension_color_map(array $rows): array
{
    $out = array();
    foreach ($rows as $row) {
        $color = isset($row['color']) ? trim((string) $row['color']) : '';
        if ($color !== '') {
            $out[(string) $row['code']] = substr($color, 0, 7);
        }
    }
    return $out;
}

function canonical_dimension_values(?array $requested, array $dimensionRows, array $default): array
{
    $map = array();
    foreach ($dimensionRows as $row) {
        $map[strtolower((string) $row['code'])] = (string) $row['code'];
    }
    $source = $requested;
    if ($source === null) {
        $source = $default;
    }
    $out = array();
    foreach ($source as $value) {
        $key = strtolower(trim((string) $value));
        if ($key !== '' && isset($map[$key]) && !in_array($map[$key], $out, true)) {
            $out[] = $map[$key];
        }
    }
    return $out;
}

function diurnal_base_metadata(): array
{
    $pdo = open_database('diurnal');
    $settings = read_key_value_table($pdo, 'settings');
    $dims = diurnal_dimensions($pdo);
    $geneCount = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM genes');

    $defaultGene = isset($settings['default_gene']) ? (string) $settings['default_gene'] : 'Dbp';
    $defaultCluster = isset($settings['default_cluster']) ? (string) $settings['default_cluster'] : (dimension_codes($dims['region'])[0] ?? '');
    $defaultGenotype = isset($settings['default_genotype']) ? (string) $settings['default_genotype'] : (dimension_codes($dims['genotype'])[0] ?? '');

    return array(
        'app' => 'Diurnal transcriptome explorer',
        'version' => '3.0.0-php-sqlite',
        'backend' => 'PHP/SQLite',
        'gene_count' => $geneCount,
        'defaults' => array(
            'gene' => $defaultGene,
            'include_region' => $defaultCluster,
            'include_age' => dimension_codes($dims['age']),
            'include_sex' => dimension_codes($dims['sex']),
            'include_genotype' => $defaultGenotype,
            'color_by' => 'region',
            'split_by' => array(),
            'gamma' => 1.7,
        ),
        'choices' => array(
            'region' => dimension_codes($dims['region']),
            'age' => dimension_codes($dims['age']),
            'sex' => dimension_codes($dims['sex']),
            'genotype' => dimension_codes($dims['genotype']),
            'color_by' => array('region', 'age', 'sex', 'genotype'),
            'split_by' => array('region', 'age', 'sex', 'genotype'),
        ),
        'labels' => array(
            'region' => dimension_label_map($dims['region']),
            'age' => dimension_label_map($dims['age']),
            'sex' => array_replace(dimension_label_map($dims['sex']), array('F' => 'Female', 'M' => 'Male')),
            'genotype' => dimension_label_map($dims['genotype']),
            'variables' => array('region' => 'Region', 'age' => 'Age', 'sex' => 'Sex', 'genotype' => 'Genotype'),
        ),
        'constraints' => array(
            'gamma' => array('min' => 0.5, 'max' => 3.0, 'step' => 0.1),
            'gene_search_limit' => 500,
        ),
        'plot' => array(
            'x_axis_label' => isset($settings['x_axis_label']) ? $settings['x_axis_label'] : 'Zeitgeber Time (double plotted)',
            'y_axis_label' => isset($settings['y_axis_label']) ? $settings['y_axis_label'] : 'log2 Normalized mRNA Expression',
            'spatial_legend_label' => isset($settings['spatial_legend_label']) ? $settings['spatial_legend_label'] : 'log2(normalized counts)',
        ),
        'allen' => array(
            'default_gene' => $defaultGene,
            'default_cut' => 'visium_sagittal',
            'default_view' => 'ish',
            'default_downsample' => 4,
            'atlas_id' => isset($settings['allen_atlas_id']) ? (int) $settings['allen_atlas_id'] : 2,
            'locked_atlas_section_ordinal' => isset($settings['allen_atlas_plate_ordinal']) ? (int) $settings['allen_atlas_plate_ordinal'] : 7,
            'reference_space_id' => 10,
        ),
    );
}

function diurnal_gene_search(string $query, int $limit): array
{
    return search_gene_table(open_database('diurnal'), $query, $limit);
}

function diurnal_gene_resolve(string $query, int $limit = 25): array
{
    return resolve_gene_table(open_database('diurnal'), $query, $limit);
}

function require_diurnal_gene(PDO $pdo, string $gene): array
{
    $resolved = resolve_gene_table($pdo, $gene, 25);
    if (!$resolved['found']) {
        throw new ApiException('Unknown gene: ' . $gene . '.', 400, count($resolved['suggestions']) ? 'Suggestions: ' . implode(', ', array_slice($resolved['suggestions'], 0, 5)) : null);
    }
    return $resolved;
}

function diurnal_filter_config(PDO $pdo): array
{
    $settings = read_key_value_table($pdo, 'settings');
    $dims = diurnal_dimensions($pdo);
    return array($settings, $dims);
}

function diurnal_filtered_values(array $dims, array $settings): array
{
    $regionParam = array_key_exists('include_region', $_GET) ? request_csv('include_region') : null;
    $ageParam = array_key_exists('include_age', $_GET) ? request_csv('include_age') : null;
    $sexParam = array_key_exists('include_sex', $_GET) ? request_csv('include_sex') : null;
    $genotypeParam = array_key_exists('include_genotype', $_GET) ? request_csv('include_genotype') : null;

    $defaultRegion = isset($settings['default_cluster']) ? array((string) $settings['default_cluster']) : dimension_codes($dims['region']);
    $defaultGenotype = isset($settings['default_genotype']) ? array((string) $settings['default_genotype']) : dimension_codes($dims['genotype']);

    return array(
        'region' => canonical_dimension_values($regionParam, $dims['region'], $defaultRegion),
        'age' => canonical_dimension_values($ageParam, $dims['age'], dimension_codes($dims['age'])),
        'sex' => canonical_dimension_values($sexParam, $dims['sex'], dimension_codes($dims['sex'])),
        'genotype' => canonical_dimension_values($genotypeParam, $dims['genotype'], $defaultGenotype),
    );
}

function diurnal_points(PDO $pdo, int $geneId, array $filters): array
{
    foreach ($filters as $values) {
        if (!count($values)) {
            return array();
        }
    }
    $params = array('gene_id' => $geneId);
    $regionIn = sql_in_clause($filters['region'], 'region', $params);
    $ageIn = sql_in_clause($filters['age'], 'age', $params);
    $sexIn = sql_in_clause($filters['sex'], 'sex', $params);
    $genotypeIn = sql_in_clause($filters['genotype'], 'genotype', $params);

    $sql = "SELECT e.value, s.sample_id, s.sample_key, s.zt, s.time_label,\n"
        . "c.code AS region, c.label AS region_label, c.color AS region_color,\n"
        . "a.code AS age, a.label AS age_label, a.color AS age_color,\n"
        . "sx.code AS sex, sx.label AS sex_label, sx.color AS sex_color,\n"
        . "gt.code AS genotype, gt.label AS genotype_label, gt.color AS genotype_color\n"
        . "FROM expression e\n"
        . "JOIN samples s ON s.sample_id = e.sample_id\n"
        . "JOIN clusters c ON c.cluster_id = s.cluster_id\n"
        . "JOIN ages a ON a.age_id = s.age_id\n"
        . "JOIN sexes sx ON sx.sex_id = s.sex_id\n"
        . "JOIN genotypes gt ON gt.genotype_id = s.genotype_id\n"
        . "WHERE e.gene_id = :gene_id\n"
        . "AND c.code IN $regionIn AND a.code IN $ageIn AND sx.code IN $sexIn AND gt.code IN $genotypeIn\n"
        . "ORDER BY c.sort_order, a.sort_order, sx.sort_order, gt.sort_order, s.zt, s.sample_id";
    return db_all($pdo, $sql, $params);
}

function diurnal_coefficients(PDO $pdo, int $geneId, array $filters): array
{
    foreach ($filters as $values) {
        if (!count($values)) {
            return array();
        }
    }
    $params = array('gene_id' => $geneId);
    $regionIn = sql_in_clause($filters['region'], 'mregion', $params);
    $ageIn = sql_in_clause($filters['age'], 'mage', $params);
    $sexIn = sql_in_clause($filters['sex'], 'msex', $params);
    $genotypeIn = sql_in_clause($filters['genotype'], 'mgenotype', $params);

    $sql = "SELECT mc.n_samples, mc.intercept, mc.sin_coef, mc.cos_coef,\n"
        . "c.code AS region, c.label AS region_label, c.color AS region_color,\n"
        . "a.code AS age, a.label AS age_label, a.color AS age_color,\n"
        . "sx.code AS sex, sx.label AS sex_label, sx.color AS sex_color,\n"
        . "gt.code AS genotype, gt.label AS genotype_label, gt.color AS genotype_color\n"
        . "FROM model_coefficients mc\n"
        . "JOIN clusters c ON c.cluster_id = mc.cluster_id\n"
        . "JOIN ages a ON a.age_id = mc.age_id\n"
        . "JOIN sexes sx ON sx.sex_id = mc.sex_id\n"
        . "JOIN genotypes gt ON gt.genotype_id = mc.genotype_id\n"
        . "WHERE mc.gene_id = :gene_id\n"
        . "AND c.code IN $regionIn AND a.code IN $ageIn AND sx.code IN $sexIn AND gt.code IN $genotypeIn\n"
        . "ORDER BY c.sort_order, a.sort_order, sx.sort_order, gt.sort_order";
    return db_all($pdo, $sql, $params);
}

function diurnal_variable_label(string $variable): string
{
    $labels = array('region' => 'Region', 'age' => 'Age', 'sex' => 'Sex', 'genotype' => 'Genotype');
    return isset($labels[$variable]) ? $labels[$variable] : $variable;
}

function diurnal_row_label(array $row, string $variable): string
{
    $labelKey = $variable . '_label';
    $value = trim((string) ($row[$labelKey] ?? $row[$variable] ?? ''));
    if ($variable === 'sex') {
        $normalized = strtoupper(trim((string) ($row[$variable] ?? $value)));
        if ($normalized === 'F' || strcasecmp($value, 'F') === 0) return 'Female';
        if ($normalized === 'M' || strcasecmp($value, 'M') === 0) return 'Male';
    }
    return $value;
}

function diurnal_row_color(array $row, string $variable): string
{
    $colorKey = $variable . '_color';
    $color = isset($row[$colorKey]) ? substr((string) $row[$colorKey], 0, 7) : '';
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }
    $value = trim((string) ($row[$variable] ?? $variable));
    $normalized = strtoupper($value);
    if ($variable === 'age' && ($normalized === '7' || $normalized === '7M' || $normalized === '7MO' || $normalized === '7MONTH' || $normalized === '7MONTHS' || $normalized === '7MONTHSOLD' || $normalized === '7MTH')) {
        return '#B68A00';
    }
    $fallbacks = array('#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#be123c');
    $index = (int) floor(deterministic_unit_interval((string) $value) * count($fallbacks));
    return $fallbacks[min(count($fallbacks) - 1, $index)];
}

function diurnal_facet_info(array $row, array $splitBy): array
{
    if (!count($splitBy)) {
        return array('key' => '__all__', 'label' => '');
    }
    $parts = array();
    $keys = array();
    foreach ($splitBy as $variable) {
        $value = trim((string) ($row[$variable] ?? ''));
        if ($value === '') continue;
        $keys[] = $value;
        $parts[] = diurnal_row_label($row, $variable);
    }
    return array('key' => implode("\x1f", $keys), 'label' => implode(' · ', $parts));
}

function diurnal_group_key(array $row, string $colorBy, array $splitBy): array
{
    $facet = diurnal_facet_info($row, $splitBy);
    $colorValue = (string) ($row[$colorBy] ?? '');
    return array(
        'facet_key' => $facet['key'],
        'facet_label' => $facet['label'],
        'color_key' => $colorValue,
        'color_label' => diurnal_row_label($row, $colorBy),
        'color' => diurnal_row_color($row, $colorBy),
    );
}

function diurnal_plot_svg(string $gene, array $filters, string $colorBy, array $splitBy, int $width = 860): string
{
    $pdo = open_database('diurnal');
    $resolved = require_diurnal_gene($pdo, $gene);
    $points = diurnal_points($pdo, (int) $resolved['gene_id'], $filters);
    $coefficients = diurnal_coefficients($pdo, (int) $resolved['gene_id'], $filters);
    if (!count($points)) {
        return svg_error_message('No data for current filters', $resolved['gene']);
    }

    $validVariables = array('region', 'age', 'sex', 'genotype');
    if (!in_array($colorBy, $validVariables, true)) {
        $colorBy = 'region';
    }
    $splitBy = array_values(array_unique(array_intersect($splitBy, $validVariables)));

    $facets = array();
    $colors = array();
    $pointGroups = array();
    $summary = array();
    $allY = array();

    foreach ($points as $row) {
        if (!is_numeric($row['value']) || !is_numeric($row['zt'])) {
            continue;
        }
        $group = diurnal_group_key($row, $colorBy, $splitBy);
        $facets[$group['facet_key']] = $group['facet_label'];
        $colors[$group['color_key']] = array('label' => $group['color_label'], 'color' => $group['color']);
        $pointGroups[$group['facet_key']][] = array(
            'x' => (float) $row['zt'],
            'y' => (float) $row['value'],
            'color_key' => $group['color_key'],
            'sample_key' => (string) $row['sample_key'],
        );
        $timeKey = sprintf('%.6f', (float) $row['zt']);
        $key = $group['facet_key'] . "\x1e" . $group['color_key'] . "\x1e" . $timeKey;
        if (!isset($summary[$key])) {
            $summary[$key] = array('facet_key' => $group['facet_key'], 'color_key' => $group['color_key'], 'x' => (float) $row['zt'], 'n' => 0, 'sum' => 0.0, 'sumsq' => 0.0);
        }
        $value = (float) $row['value'];
        $summary[$key]['n']++;
        $summary[$key]['sum'] += $value;
        $summary[$key]['sumsq'] += $value * $value;
        $allY[] = $value;
    }

    $lineAgg = array();
    $timeCount = 100;
    foreach ($coefficients as $row) {
        $group = diurnal_group_key($row, $colorBy, $splitBy);
        $facets[$group['facet_key']] = $group['facet_label'];
        $colors[$group['color_key']] = array('label' => $group['color_label'], 'color' => $group['color']);
        $weight = max(1, (int) $row['n_samples']);
        for ($i = 0; $i < $timeCount; $i++) {
            $time = 42.0 * $i / ($timeCount - 1);
            $phase = fmod($time, 24.0) * 2.0 * M_PI / 24.0;
            $prediction = (float) $row['intercept'] + (float) $row['sin_coef'] * sin($phase) + (float) $row['cos_coef'] * cos($phase);
            $key = $group['facet_key'] . "\x1e" . $group['color_key'] . "\x1e" . $i;
            if (!isset($lineAgg[$key])) {
                $lineAgg[$key] = array('facet_key' => $group['facet_key'], 'color_key' => $group['color_key'], 'x' => $time, 'weighted_sum' => 0.0, 'weight' => 0);
            }
            $lineAgg[$key]['weighted_sum'] += $prediction * $weight;
            $lineAgg[$key]['weight'] += $weight;
        }
    }

    $summariesByFacet = array();
    foreach ($summary as $row) {
        $mean = $row['sum'] / max(1, $row['n']);
        $variance = $row['n'] > 1 ? max(0.0, ($row['sumsq'] - ($row['sum'] * $row['sum']) / $row['n']) / ($row['n'] - 1)) : 0.0;
        $sd = sqrt($variance);
        $row['mean'] = $mean;
        $row['sd'] = $sd;
        $summariesByFacet[$row['facet_key']][] = $row;
        $allY[] = $mean - $sd;
        $allY[] = $mean + $sd;
    }

    $linesByFacet = array();
    foreach ($lineAgg as $row) {
        if ($row['weight'] <= 0) continue;
        $row['y'] = $row['weighted_sum'] / $row['weight'];
        $linesByFacet[$row['facet_key']][$row['color_key']][] = $row;
        $allY[] = $row['y'];
    }

    if (!count($allY)) {
        return svg_error_message('No finite expression values', $resolved['gene']);
    }
    $rawMin = min($allY);
    $rawMax = max($allY);
    $padding = max(0.25, ($rawMax - $rawMin) * 0.06);
    $ticks = nice_ticks($rawMin - $padding, $rawMax + $padding, 5);
    $yMin = min($ticks);
    $yMax = max($ticks);

    $facetKeys = array_keys($facets);
    if (!count($facetKeys)) $facetKeys = array('__all__');
    $facetCount = count($facetKeys);
    $columns = $facetCount <= 2 ? $facetCount : min(3, (int) ceil(sqrt($facetCount)));
    $rowsCount = (int) ceil($facetCount / max(1, $columns));
    // Layout tuned to resemble the original ggplot2 output: a large plotting
    // panel, normal-sized text, and a compact legend below the axis. The
    // previous PHP SVG used the same overall dimensions but left too much
    // whitespace around a small panel, which made the plot look undersized.
    $outerLeft = 16;
    $outerRight = 16;
    $top = 52;
    $panelGapX = 18;
    $panelGapY = 30;
    $panelWidth = (int) floor(($width - $outerLeft - $outerRight - ($columns - 1) * $panelGapX) / max(1, $columns));
    $panelHeight = $facetCount === 1 ? 430 : 350;
    $legendHeight = max(78, count($colors) * 26 + 50);
    $height = $top + $rowsCount * $panelHeight + max(0, $rowsCount - 1) * $panelGapY + $legendHeight + 48;

    $svg = array();
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Diurnal expression plot for ' . xml_escape($resolved['gene']) . '">';
    $svg[] = '<rect width="100%" height="100%" fill="white"/>';
    $svg[] = '<text x="' . ($width / 2) . '" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="22" font-weight="700" font-style="italic" fill="#111827">' . xml_escape($resolved['gene']) . '</text>';
    $svg[] = '<defs>';
    foreach ($facetKeys as $index => $facetKey) {
        $col = $index % $columns;
        $rowIndex = intdiv($index, $columns);
        $panelX = $outerLeft + $col * ($panelWidth + $panelGapX);
        $panelY = $top + $rowIndex * ($panelHeight + $panelGapY);
        $hasFacetLabel = $facets[$facetKey] !== '';
        $plotX = $panelX + 58;
        $plotY = $panelY + ($hasFacetLabel ? 34 : 10);
        $plotW = $panelWidth - 70;
        $plotH = $panelHeight - ($hasFacetLabel ? 92 : 66);
        $svg[] = '<clipPath id="clip' . $index . '"><rect x="' . $plotX . '" y="' . $plotY . '" width="' . $plotW . '" height="' . $plotH . '"/></clipPath>';
    }
    $svg[] = '</defs>';

    foreach ($facetKeys as $index => $facetKey) {
        $col = $index % $columns;
        $rowIndex = intdiv($index, $columns);
        $panelX = $outerLeft + $col * ($panelWidth + $panelGapX);
        $panelY = $top + $rowIndex * ($panelHeight + $panelGapY);
        $hasFacetLabel = $facets[$facetKey] !== '';
        $plotX = $panelX + 58;
        $plotY = $panelY + ($hasFacetLabel ? 34 : 10);
        $plotW = $panelWidth - 70;
        $plotH = $panelHeight - ($hasFacetLabel ? 92 : 66);
        $xScale = function (float $x) use ($plotX, $plotW): float { return $plotX + (($x + 3.0) / 48.0) * $plotW; };
        $yScale = function (float $y) use ($plotY, $plotH, $yMin, $yMax): float { return $plotY + $plotH - (($y - $yMin) / ($yMax - $yMin)) * $plotH; };

        if ($facets[$facetKey] !== '') {
            $svg[] = '<text x="' . ($panelX + $panelWidth / 2) . '" y="' . ($panelY + 16) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="13" font-weight="600" fill="#334155">' . xml_escape($facets[$facetKey]) . '</text>';
        }

        $backgrounds = array(array(0, 12, '#F6F18F'), array(12, 24, '#606161'), array(24, 36, '#F6F18F'), array(36, 42, '#606161'));
        foreach ($backgrounds as $bg) {
            $svg[] = '<rect x="' . round($xScale($bg[0]), 2) . '" y="' . $plotY . '" width="' . round($xScale($bg[1]) - $xScale($bg[0]), 2) . '" height="' . $plotH . '" fill="' . $bg[2] . '" fill-opacity="0.10"/>';
        }

        foreach (array(0, 12, 24, 36) as $xGrid) {
            $x = $xScale((float) $xGrid);
            $svg[] = '<line x1="' . round($x, 2) . '" y1="' . $plotY . '" x2="' . round($x, 2) . '" y2="' . ($plotY + $plotH) . '" stroke="#e5e7eb" stroke-width="1"/>';
        }

        foreach ($ticks as $tick) {
            $y = $yScale((float) $tick);
            $svg[] = '<line x1="' . $plotX . '" y1="' . round($y, 2) . '" x2="' . ($plotX + $plotW) . '" y2="' . round($y, 2) . '" stroke="#e5e7eb" stroke-width="1"/>';
            if ($col === 0) {
                $svg[] = '<text x="' . ($plotX - 7) . '" y="' . round($y + 4, 2) . '" text-anchor="end" font-family="Arial, sans-serif" font-size="13" fill="#475569">' . xml_escape(svg_numeric_label((float) $tick)) . '</text>';
            }
        }

        $svg[] = '<g clip-path="url(#clip' . $index . ')">';
        foreach ($linesByFacet[$facetKey] ?? array() as $colorKey => $lineRows) {
            usort($lineRows, function ($a, $b) { return $a['x'] <=> $b['x']; });
            $poly = array();
            foreach ($lineRows as $lineRow) {
                $poly[] = array($xScale((float) $lineRow['x']), $yScale((float) $lineRow['y']));
            }
            $svg[] = svg_polyline($poly, $colors[$colorKey]['color'] ?? '#2563eb', 2.1, 1.0);
        }

        foreach ($pointGroups[$facetKey] ?? array() as $point) {
            $jitter = (deterministic_unit_interval($point['sample_key']) - 0.5) * 0.7;
            $svg[] = '<circle cx="' . round($xScale($point['x'] + $jitter), 2) . '" cy="' . round($yScale($point['y']), 2) . '" r="2.1" fill="' . xml_escape($colors[$point['color_key']]['color'] ?? '#2563eb') . '" fill-opacity="0.28"/>';
        }

        foreach ($summariesByFacet[$facetKey] ?? array() as $item) {
            $x = $xScale((float) $item['x']);
            $meanY = $yScale((float) $item['mean']);
            $lowY = $yScale((float) ($item['mean'] - $item['sd']));
            $highY = $yScale((float) ($item['mean'] + $item['sd']));
            $color = $colors[$item['color_key']]['color'] ?? '#2563eb';
            $svg[] = '<line x1="' . round($x, 2) . '" y1="' . round($lowY, 2) . '" x2="' . round($x, 2) . '" y2="' . round($highY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="1.4"/>';
            $svg[] = '<line x1="' . round($x - 3, 2) . '" y1="' . round($lowY, 2) . '" x2="' . round($x + 3, 2) . '" y2="' . round($lowY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="1.4"/>';
            $svg[] = '<line x1="' . round($x - 3, 2) . '" y1="' . round($highY, 2) . '" x2="' . round($x + 3, 2) . '" y2="' . round($highY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="1.4"/>';
            $svg[] = '<circle cx="' . round($x, 2) . '" cy="' . round($meanY, 2) . '" r="4.5" fill="' . xml_escape($color) . '" stroke="white" stroke-width="0.8"/>';
        }
        $svg[] = '</g>';

        $svg[] = '<rect x="' . $plotX . '" y="' . $plotY . '" width="' . $plotW . '" height="' . $plotH . '" fill="none" stroke="#475569" stroke-width="1"/>';
        $xTicks = array(array(0, '0'), array(12, '12'), array(24, '0'), array(36, '12'));
        foreach ($xTicks as $tick) {
            $x = $xScale((float) $tick[0]);
            $svg[] = '<line x1="' . round($x, 2) . '" y1="' . ($plotY + $plotH) . '" x2="' . round($x, 2) . '" y2="' . ($plotY + $plotH + 4) . '" stroke="#475569"/>';
            $svg[] = '<text x="' . round($x, 2) . '" y="' . ($plotY + $plotH + 17) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="13" fill="#475569">' . $tick[1] . '</text>';
        }
    }

    $plotBottom = $top + $rowsCount * $panelHeight + max(0, $rowsCount - 1) * $panelGapY;
    $svg[] = '<text x="' . ($width / 2) . '" y="' . ($plotBottom + 24) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#111827">Zeitgeber time (h) (double plotted)</text>';
    $svg[] = '<text x="18" y="' . ($top + ($plotBottom - $top) / 2) . '" transform="rotate(-90 18 ' . ($top + ($plotBottom - $top) / 2) . ')" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#111827">Normalized mRNA expression (log2 CPM)</text>';

    $legendY = $plotBottom + 52;
    $svg[] = '<text x="' . $outerLeft . '" y="' . ($legendY + 5) . '" font-family="Arial, sans-serif" font-size="14" font-weight="700" fill="#334155">' . xml_escape(diurnal_variable_label($colorBy)) . '</text>';
    $legendX = $outerLeft + 120;
    $legendRow = 0;
    foreach ($colors as $entry) {
        $x = $legendX;
        $y = $legendY + $legendRow * 26;
        $svg[] = '<line x1="' . $x . '" y1="' . $y . '" x2="' . ($x + 18) . '" y2="' . $y . '" stroke="' . xml_escape($entry['color']) . '" stroke-width="3"/>';
        $svg[] = '<text x="' . ($x + 24) . '" y="' . ($y + 4) . '" font-family="Arial, sans-serif" font-size="13" fill="#334155">' . xml_escape($entry['label']) . '</text>';
        $legendRow++;
    }
    $svg[] = '</svg>';
    return implode('', $svg);
}

function spatial_template_config(): array
{
    static $config = null;
    if ($config !== null) return $config;
    $templatePath = app_root() . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'spatial_template.svg';
    $colorsPath = app_root() . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'spatial_colors.json';
    if (!is_file($templatePath) || !is_file($colorsPath)) {
        throw new ApiException('Spatial map assets are missing.', 500);
    }
    $colors = json_decode((string) file_get_contents($colorsPath), true);
    if (!is_array($colors)) throw new ApiException('Spatial color mapping is invalid.', 500);
    $config = array('template' => (string) file_get_contents($templatePath), 'colors' => $colors);
    return $config;
}

function spatial_scope_svg(string $svg, string $scopeId): string
{
    if (preg_match('/id="[^"]+"/', $svg)) {
        $svg = preg_replace('/id="[^"]+"/', 'id="' . $scopeId . '"', $svg, 1);
    } else {
        $svg = preg_replace('/<svg\b/', '<svg id="' . $scopeId . '"', $svg, 1);
    }
    $svg = str_replace('.cls-', '#' . $scopeId . ' .cls-', $svg);
    $svg = preg_replace('/\s(width|height)=("[^"]*"|\'[^\']*\')/', '', $svg);
    return $svg;
}

function spatial_colored_svg(array $valuesByCluster, float $min, float $max, float $gamma, string $scopeId): string
{
    $config = spatial_template_config();
    $template = $config['template'];
    $originalColors = $config['colors'];
    $placeholders = array();
    $replacements = array();
    $i = 0;
    foreach ($originalColors as $cluster => $originalColor) {
        $placeholder = '__SPATIAL_COLOR_' . sprintf('%03d', $i++) . '__';
        $placeholders[$originalColor] = $placeholder;
        if (isset($valuesByCluster[$cluster]) && is_numeric($valuesByCluster[$cluster])) {
            $scaled = $max > $min ? (((float) $valuesByCluster[$cluster] - $min) / ($max - $min)) : 0.5;
            $scaled = pow(max(0.0, min(1.0, $scaled)), $gamma);
            $replacements[$placeholder] = interpolate_hex('#d3d3d3', '#0000ff', $scaled);
        } else {
            $replacements[$placeholder] = '#D9D9D9';
        }
    }
    $template = str_replace(array_keys($placeholders), array_values($placeholders), $template);
    $template = str_replace(array_keys($replacements), array_values($replacements), $template);
    return spatial_scope_svg($template, $scopeId);
}

function spatial_legend_svg(float $min, float $max, float $gamma): string
{
    $width = 500;
    $height = 70;
    $x0 = 170;
    $x1 = $width - 20;
    $stops = array();
    for ($i = 0; $i <= 40; $i++) {
        $u = $i / 40;
        $stops[] = '<stop offset="' . round(100 * $u, 2) . '%" stop-color="' . interpolate_hex('#d3d3d3', '#0000ff', pow($u, $gamma)) . '"/>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 70">'
        . '<defs><linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="0%">' . implode('', $stops) . '</linearGradient></defs>'
        . '<text x="10" y="38" font-family="Arial, sans-serif" font-size="12">log2(normalized counts)</text>'
        . '<rect x="' . $x0 . '" y="22" width="' . ($x1 - $x0) . '" height="20" fill="url(#grad)" stroke="black"/>'
        . '<text x="' . $x0 . '" y="60" font-family="Arial, sans-serif" font-size="12">' . xml_escape(svg_numeric_label($min)) . '</text>'
        . '<text x="' . $x1 . '" y="60" text-anchor="end" font-family="Arial, sans-serif" font-size="12">' . xml_escape(svg_numeric_label($max)) . '</text>'
        . '</svg>';
}

function diurnal_spatial_payload(string $gene, float $gamma): array
{
    $pdo = open_database('diurnal');
    $resolved = require_diurnal_gene($pdo, $gene);
    $rows = db_all($pdo, "SELECT sm.mean_value, c.code AS region, gt.code AS genotype, gt.label AS genotype_label, gt.sort_order AS genotype_order, a.code AS age, a.label AS age_label, a.sort_order AS age_order\n"
        . "FROM spatial_means sm\n"
        . "JOIN clusters c ON c.cluster_id = sm.cluster_id\n"
        . "JOIN genotypes gt ON gt.genotype_id = sm.genotype_id\n"
        . "JOIN ages a ON a.age_id = sm.age_id\n"
        . "WHERE sm.gene_id = :gene_id\n"
        . "ORDER BY gt.sort_order, a.sort_order, c.sort_order", array('gene_id' => (int) $resolved['gene_id']));
    if (!count($rows)) {
        return array('gene' => $resolved['gene'], 'gamma' => $gamma, 'limits' => array(0, 1), 'titles' => array(), 'panels' => array(), 'legend' => spatial_legend_svg(0, 1, $gamma));
    }
    $values = array_map(function ($row) { return (float) $row['mean_value']; }, $rows);
    $min = floor(min($values));
    $max = ceil(max($values));
    if ($min === $max) $max = $min + 1;

    $genotypes = array();
    $ages = array();
    foreach ($rows as $row) {
        $genotypes[(string) $row['genotype']] = array('label' => (string) $row['genotype_label'], 'order' => (int) $row['genotype_order']);
        $ages[(string) $row['age']] = array('label' => (string) $row['age_label'], 'order' => (int) $row['age_order']);
    }
    uasort($genotypes, function ($a, $b) { return $a['order'] <=> $b['order']; });
    uasort($ages, function ($a, $b) { return $a['order'] <=> $b['order']; });
    $genotypeCodes = array_keys($genotypes);
    $ageCodes = array_keys($ages);
    $ntg = null;
    $app = null;
    foreach ($genotypeCodes as $code) {
        if (strtoupper($code) === 'NTG' || strtoupper($code) === 'WT') $ntg = $code;
        if (strtoupper($code) === 'APP23' || strpos(strtoupper($code), 'APP') !== false) $app = $code;
    }
    if ($ntg === null) $ntg = $genotypeCodes[0] ?? '';
    if ($app === null) $app = $genotypeCodes[1] ?? $ntg;
    $age7 = null;
    $age14 = null;
    foreach ($ageCodes as $code) {
        if (preg_match('/(^|\D)7(\D|$)/', $code)) $age7 = $code;
        if (preg_match('/(^|\D)14(\D|$)/', $code)) $age14 = $code;
    }
    if ($age7 === null) $age7 = $ageCodes[0] ?? '';
    if ($age14 === null) $age14 = $ageCodes[1] ?? $age7;

    $panelSpecs = array(
        'map_ntg_7' => array($ntg, $age7),
        'map_ntg_14' => array($ntg, $age14),
        'map_app_7' => array($app, $age7),
        'map_app_14' => array($app, $age14),
    );
    $titles = array();
    $panels = array();
    foreach ($panelSpecs as $key => $spec) {
        $valueMap = array();
        foreach ($rows as $row) {
            if ((string) $row['genotype'] === $spec[0] && (string) $row['age'] === $spec[1]) {
                $valueMap[(string) $row['region']] = (float) $row['mean_value'];
            }
        }
        $gtLabel = isset($genotypes[$spec[0]]) ? $genotypes[$spec[0]]['label'] : $spec[0];
        $ageLabel = isset($ages[$spec[1]]) ? $ages[$spec[1]]['label'] : $spec[1];
        $titles[$key] = $gtLabel . ', ' . $ageLabel;
        $panels[$key] = '<div class="spatial-svg">' . spatial_colored_svg($valueMap, (float) $min, (float) $max, $gamma, $key) . '</div>';
    }
    return array(
        'gene' => $resolved['gene'],
        'gamma' => $gamma,
        'limits' => array($min, $max),
        'titles' => $titles,
        'panels' => $panels,
        'legend' => spatial_legend_svg((float) $min, (float) $max, $gamma),
    );
}
