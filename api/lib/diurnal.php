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

function diurnal_preferred_dimension_code(array $rows, ?string $configured, array $preferredCodes): string
{
    $codes = dimension_codes($rows);
    $candidates = array();
    if ($configured !== null && trim($configured) !== '') {
        $candidates[] = trim($configured);
    }
    foreach ($preferredCodes as $code) {
        $candidates[] = (string) $code;
    }
    foreach ($candidates as $candidate) {
        foreach ($codes as $code) {
            if (strcasecmp($candidate, $code) === 0) return $code;
        }
    }
    return $codes[0] ?? '';
}

function diurnal_default_filters(array $dims, array $settings): array
{
    return array(
        'region' => array(diurnal_preferred_dimension_code($dims['region'], isset($settings['default_cluster']) ? (string) $settings['default_cluster'] : null, array('L23'))),
        'age' => dimension_codes($dims['age']),
        'sex' => dimension_codes($dims['sex']),
        'genotype' => array(diurnal_preferred_dimension_code($dims['genotype'], isset($settings['default_genotype']) ? (string) $settings['default_genotype'] : null, array('NTG', 'WT'))),
    );
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
    $plotDims = diurnal_plot_dimensions($dims);
    $plotLabels = array();
    foreach ($plotDims as $variable => $entries) {
        $plotLabels[$variable] = array();
        foreach ($entries as $entry) {
            $plotLabels[$variable][(string) $entry['value']] = (string) $entry['label'];
        }
    }
    $geneCount = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM genes');

    $defaultGene = isset($settings['default_gene']) ? (string) $settings['default_gene'] : 'Dbp';
    $defaultFilters = diurnal_default_filters($dims, $settings);
    $defaultColorBy = isset($settings['default_color_by']) ? (string) $settings['default_color_by'] : 'region';
    if (!in_array($defaultColorBy, array('region', 'age', 'sex', 'genotype'), true)) {
        $defaultColorBy = 'region';
    }

    return array(
        'app' => 'Diurnal transcriptome explorer',
        'version' => '3.1.0-react-rhythmicity',
        'backend' => 'PHP/SQLite',
        'gene_count' => $geneCount,
        'defaults' => array(
            'gene' => $defaultGene,
            'include_region' => $defaultFilters['region'],
            'include_age' => $defaultFilters['age'],
            'include_sex' => $defaultFilters['sex'],
            'include_genotype' => $defaultFilters['genotype'],
            'color_by' => $defaultColorBy,
            'split_by' => array(),
            'gamma' => 1.7,
        ),
        'choices' => array(
            'region' => dimension_codes($dims['region']),
            'age' => dimension_codes($dims['age']),
            'sex' => dimension_codes($dims['sex']),
            'genotype' => dimension_codes($dims['genotype']),
            'color_by' => array('region', 'age', 'sex', 'genotype'),
            'split_by' => array('age', 'sex', 'region', 'genotype'),
        ),
        'labels' => array(
            'region' => $plotLabels['region'],
            'age' => $plotLabels['age'],
            'sex' => $plotLabels['sex'],
            'genotype' => $plotLabels['genotype'],
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

    $defaults = diurnal_default_filters($dims, $settings);

    return array(
        'region' => canonical_dimension_values($regionParam, $dims['region'], $defaults['region']),
        'age' => canonical_dimension_values($ageParam, $dims['age'], $defaults['age']),
        'sex' => canonical_dimension_values($sexParam, $dims['sex'], $defaults['sex']),
        'genotype' => canonical_dimension_values($genotypeParam, $dims['genotype'], $defaults['genotype']),
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

function diurnal_plot_variables(): array
{
    return array('region', 'age', 'sex', 'genotype');
}

function diurnal_plot_dimension_label(string $variable, array $row): string
{
    $code = trim((string) ($row['code'] ?? ''));
    $label = trim((string) ($row['label'] ?? ''));
    $normalized = strtoupper($code);

    if ($variable === 'sex') {
        if ($normalized === 'F' || $normalized === 'FEMALE') return 'Female';
        if ($normalized === 'M' || $normalized === 'MALE') return 'Male';
    }

    // Match the short legend labels in the R/ggplot output.
    if ($variable === 'genotype') {
        return $code !== '' ? $code : ($label !== '' ? $label : 'Unknown');
    }

    return $label !== '' ? $label : $code;
}

function diurnal_plot_dimension_color(string $variable, array $row): string
{
    $code = trim((string) ($row['code'] ?? $variable));
    $normalized = strtoupper($code);
    $known = array(
        'APP23' => '#BC3C29',
        'NTG' => '#0072B5',
        'WT' => '#0072B5',
        'F' => '#E6A0C4',
        'M' => '#C6CDF7',
    );
    if (isset($known[$normalized])) return $known[$normalized];

    $color = isset($row['color']) ? substr(trim((string) $row['color']), 0, 7) : '';
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }

    if ($variable === 'age' && preg_match('/(^|\D)7(\D|$)/', $code)) {
        return '#FFFF99';
    }
    if ($variable === 'age' && preg_match('/(^|\D)14(\D|$)/', $code)) {
        return '#D8B365';
    }

    $fallbacks = array('#2563EB', '#DC2626', '#16A34A', '#9333EA', '#EA580C', '#0891B2', '#4F46E5', '#BE123C');
    $index = (int) floor(deterministic_unit_interval($code) * count($fallbacks));
    return $fallbacks[min(count($fallbacks) - 1, $index)];
}

function diurnal_plot_dimension_priority(string $variable, array $row): array
{
    $code = strtoupper(trim((string) ($row['code'] ?? '')));
    $sortOrder = isset($row['sort_order']) ? (int) $row['sort_order'] : PHP_INT_MAX;

    if ($variable === 'genotype') {
        if ($code === 'APP23') return array(0, $sortOrder, $code);
        if ($code === 'NTG' || $code === 'WT') return array(1, $sortOrder, $code);
        return array(10, $sortOrder, $code);
    }

    return array(0, $sortOrder, $code);
}

function diurnal_plot_dimensions(array $dims): array
{
    $out = array();
    foreach (diurnal_plot_variables() as $variable) {
        $rows = isset($dims[$variable]) && is_array($dims[$variable]) ? array_values($dims[$variable]) : array();
        usort($rows, function (array $left, array $right) use ($variable): int {
            return diurnal_plot_dimension_priority($variable, $left) <=> diurnal_plot_dimension_priority($variable, $right);
        });

        $out[$variable] = array_map(function (array $row) use ($variable): array {
            return array(
                'value' => (string) ($row['code'] ?? ''),
                'label' => diurnal_plot_dimension_label($variable, $row),
                'color' => diurnal_plot_dimension_color($variable, $row),
            );
        }, $rows);
    }
    return $out;
}

function diurnal_plot_payload(
    PDO $pdo,
    string $gene,
    array $filters,
    string $colorBy,
    array $splitBy,
    array $dims,
    array $settings
): array {
    $validVariables = diurnal_plot_variables();
    if (!in_array($colorBy, $validVariables, true)) {
        $colorBy = 'genotype';
    }

    $cleanSplit = array();
    foreach ($splitBy as $variable) {
        $variable = trim((string) $variable);
        if (in_array($variable, $validVariables, true) && !in_array($variable, $cleanSplit, true)) {
            $cleanSplit[] = $variable;
        }
    }

    $resolved = require_diurnal_gene($pdo, $gene);
    $pointRows = diurnal_points($pdo, (int) $resolved['gene_id'], $filters);
    $coefficientRows = diurnal_coefficients($pdo, (int) $resolved['gene_id'], $filters);

    $observations = array();
    foreach ($pointRows as $row) {
        if (!is_numeric($row['zt'] ?? null) || !is_numeric($row['value'] ?? null)) continue;
        $zt = (float) $row['zt'];
        $value = (float) $row['value'];
        if (!is_finite($zt) || !is_finite($value)) continue;

        $observations[] = array(
            'sampleKey' => (string) ($row['sample_key'] ?? $row['sample_id'] ?? ''),
            'ZT' => $zt,
            'normExpr' => $value,
            'region' => (string) ($row['region'] ?? ''),
            'age' => (string) ($row['age'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
            'genotype' => (string) ($row['genotype'] ?? ''),
        );
    }

    $coefficients = array();
    foreach ($coefficientRows as $row) {
        $numericKeys = array('intercept', 'sin_coef', 'cos_coef');
        $valid = true;
        foreach ($numericKeys as $key) {
            if (!is_numeric($row[$key] ?? null) || !is_finite((float) $row[$key])) {
                $valid = false;
                break;
            }
        }
        if (!$valid) continue;

        $coefficients[] = array(
            'n' => max(1, (int) ($row['n_samples'] ?? 1)),
            'intercept' => (float) $row['intercept'],
            'sinCoef' => (float) $row['sin_coef'],
            'cosCoef' => (float) $row['cos_coef'],
            'region' => (string) ($row['region'] ?? ''),
            'age' => (string) ($row['age'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
            'genotype' => (string) ($row['genotype'] ?? ''),
        );
    }

    return array(
        'gene' => (string) $resolved['gene'],
        'observations' => $observations,
        'coefficients' => $coefficients,
        'dimensions' => diurnal_plot_dimensions($dims),
        'variableLabels' => array(
            'region' => 'Region',
            'age' => 'Age',
            'sex' => 'Sex',
            'genotype' => 'Genotype',
        ),
        'axisLabels' => array(
            'x' => isset($settings['x_axis_label']) ? (string) $settings['x_axis_label'] : 'Zeitgeber Time (double plotted)',
            'y' => isset($settings['y_axis_label']) ? (string) $settings['y_axis_label'] : 'log2 Normalized mRNA Expression',
        ),
        'colorBy' => $colorBy,
        'splitBy' => $cleanSplit,
        'filters' => $filters,
        'counts' => array(
            'observations' => count($observations),
            'coefficients' => count($coefficients),
        ),
    );
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
