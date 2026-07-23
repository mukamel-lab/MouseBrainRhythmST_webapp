<?php

declare(strict_types=1);

function dv_gene_search(string $query, int $limit): array
{
    return search_gene_table(open_database('dv'), $query, $limit);
}

function dv_gene_resolve(string $query, int $limit = 25): array
{
    return resolve_gene_table(open_database('dv'), $query, $limit);
}

function dv_metadata(): array
{
    try {
        $pdo = open_database('dv');
    } catch (Throwable $error) {
        return array(
            'available' => false,
            'gene_count' => 0,
            'default_gene' => 'Lct',
            'default_cluster' => 'dg_sg',
            'split_by_default' => 'none',
            'clusters' => array(),
            'choices' => array('split_by' => array('none', 'age', 'sex', 'age_sex')),
        );
    }
    $settings = read_key_value_table($pdo, 'settings');
    $clusters = db_all($pdo, 'SELECT code, label FROM clusters ORDER BY sort_order, code COLLATE NOCASE');
    return array(
        'available' => true,
        'gene_count' => (int) db_scalar($pdo, 'SELECT COUNT(*) FROM genes'),
        'default_gene' => isset($settings['default_gene']) ? (string) $settings['default_gene'] : 'Lct',
        'default_cluster' => isset($settings['default_cluster']) ? (string) $settings['default_cluster'] : ((string) ($clusters[0]['code'] ?? 'dg_sg')),
        'value_column' => 'value',
        'value_label' => isset($settings['y_axis_label']) ? (string) $settings['y_axis_label'] : 'log2(normalized counts)',
        'point_unit' => 'WT sample-level D/V hippocampus aggregate',
        'analysis_group' => 'WT only',
        'split_by_default' => isset($settings['default_split_by']) ? (string) $settings['default_split_by'] : 'none',
        'panel_text' => isset($settings['panel_text']) ? (string) $settings['panel_text'] : 'Differential expression results, dorsal-vs-ventral in WT samples.',
        'clusters' => array_map(function ($row) { return array('id' => (string) $row['code'], 'label' => (string) $row['label']); }, $clusters),
        'choices' => array('split_by' => array('none', 'age', 'sex', 'age_sex')),
    );
}

function dv_split_value(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, array('none', 'age', 'sex', 'age_sex'), true) ? $value : 'none';
}

function dv_split_label(string $value): string
{
    $labels = array('none' => 'Combined', 'age' => 'Age', 'sex' => 'Sex', 'age_sex' => 'Age + sex');
    return isset($labels[$value]) ? $labels[$value] : $value;
}

function dv_resolve_cluster(PDO $pdo, string $cluster): string
{
    $cluster = trim($cluster);
    if ($cluster === '' || strtolower($cluster) === 'all') return 'all';
    $row = db_one($pdo, 'SELECT code FROM clusters WHERE lower(code) = lower(:code) LIMIT 1', array('code' => $cluster));
    return $row === null ? 'all' : (string) $row['code'];
}

function dv_results(PDO $pdo, int $geneId, string $cluster): array
{
    $params = array('gene_id' => $geneId);
    $where = 'r.gene_id = :gene_id';
    if ($cluster !== 'all') {
        $where .= ' AND c.code = :cluster';
        $params['cluster'] = $cluster;
    }
    $rows = db_all($pdo, "SELECT r.*, c.code AS cluster, c.label AS cluster_label\n"
        . "FROM deseq_results r JOIN clusters c ON c.cluster_id = r.cluster_id\n"
        . "WHERE $where ORDER BY c.sort_order, COALESCE(r.fdr, r.padj), r.result_id", $params);
    $out = array();
    foreach ($rows as $row) {
        $fc = $row['log2_fold_change'] === null ? null : (float) $row['log2_fold_change'];
        $direction = '—';
        if ($fc !== null) {
            if ($fc > 0) $direction = 'Dorsal higher';
            elseif ($fc < 0) $direction = 'Ventral higher';
            else $direction = 'No difference';
        }
        $out[] = array(
            'gene' => null,
            'cluster' => (string) $row['cluster'],
            'cluster_display' => (string) $row['cluster_label'],
            'analysis_group' => (string) ($row['analysis_group'] ?? 'WT only'),
            'contrast' => (string) ($row['contrast'] ?? ''),
            'direction' => $direction,
            'baseMean' => $row['base_mean'] === null ? null : (float) $row['base_mean'],
            'baseMean_display' => format_small_number($row['base_mean'] ?? null),
            'log2FoldChange' => $fc,
            'log2FoldChange_display' => format_small_number($fc),
            'lfcSE' => $row['lfc_se'] === null ? null : (float) $row['lfc_se'],
            'lfcSE_display' => format_small_number($row['lfc_se'] ?? null),
            'stat' => $row['stat'] === null ? null : (float) $row['stat'],
            'stat_display' => format_small_number($row['stat'] ?? null),
            'pvalue' => $row['p_value'] === null ? null : (float) $row['p_value'],
            'pvalue_display' => format_metric_value($row['p_value'] ?? null),
            'padj' => $row['padj'] === null ? null : (float) $row['padj'],
            'padj_display' => format_metric_value($row['padj'] ?? null),
            'fdr' => $row['fdr'] === null ? null : (float) $row['fdr'],
            'fdr_display' => format_metric_value($row['fdr'] ?? null),
            'fdr_lt_0_05' => (bool) $row['fdr_lt_0_05'],
            'fdr_lt_0_10' => (bool) $row['fdr_lt_0_10'],
            'n_Dorsal' => $row['n_dorsal'] === null ? null : (int) $row['n_dorsal'],
            'n_Ventral' => $row['n_ventral'] === null ? null : (int) $row['n_ventral'],
            'n_samples_total' => $row['n_samples_total'] === null ? null : (int) $row['n_samples_total'],
        );
    }
    return $out;
}

function dv_point_count(PDO $pdo, int $geneId, string $cluster): int
{
    $params = array('gene_id' => $geneId);
    $where = 'e.gene_id = :gene_id';
    if ($cluster !== 'all') {
        $where .= ' AND c.code = :cluster';
        $params['cluster'] = $cluster;
    }
    return (int) db_scalar($pdo, 'SELECT COUNT(*) FROM expression e JOIN observations o ON o.observation_id = e.observation_id JOIN clusters c ON c.cluster_id = o.cluster_id WHERE ' . $where, $params);
}

function dv_payload(string $input, string $cluster, string $splitBy): array
{
    try {
        $pdo = open_database('dv');
    } catch (Throwable $error) {
        return array('available' => false, 'found' => false, 'input' => $input, 'gene' => null, 'message' => $error->getMessage(), 'suggestions' => array(), 'point_count' => 0, 'result_count' => 0, 'results' => array());
    }
    $resolved = resolve_gene_table($pdo, $input, 25);
    if (!$resolved['found']) {
        return array('available' => true, 'found' => false, 'input' => $input, 'gene' => null, 'suggestions' => $resolved['suggestions'], 'point_count' => 0, 'result_count' => 0, 'results' => array());
    }
    $cluster = dv_resolve_cluster($pdo, $cluster);
    $splitBy = dv_split_value($splitBy);
    $results = dv_results($pdo, (int) $resolved['gene_id'], $cluster);
    foreach ($results as &$result) $result['gene'] = $resolved['gene'];
    unset($result);
    $minFdr = null;
    foreach ($results as $result) {
        $candidate = $result['fdr'] !== null ? $result['fdr'] : $result['padj'];
        if ($candidate !== null && is_finite((float) $candidate)) {
            $minFdr = $minFdr === null ? (float) $candidate : min($minFdr, (float) $candidate);
        }
    }
    return array(
        'available' => true,
        'found' => true,
        'input' => $input,
        'gene' => $resolved['gene'],
        'filters' => array('cluster' => $cluster, 'analysis_group' => 'WT only', 'split_by' => $splitBy, 'split_by_label' => dv_split_label($splitBy)),
        'analysis_group' => 'WT only',
        'split_by' => $splitBy,
        'split_by_label' => dv_split_label($splitBy),
        'point_count' => dv_point_count($pdo, (int) $resolved['gene_id'], $cluster),
        'result_count' => count($results),
        'min_fdr' => $minFdr,
        'min_fdr_display' => format_metric_value($minFdr),
        'suggestions' => $resolved['suggestions'],
        'results' => $results,
    );
}

function dv_plot_points(PDO $pdo, int $geneId, string $cluster): array
{
    $params = array('gene_id' => $geneId);
    $where = 'e.gene_id = :gene_id';
    if ($cluster !== 'all') {
        $where .= ' AND c.code = :cluster';
        $params['cluster'] = $cluster;
    }
    return db_all($pdo, "SELECT e.value, o.observation_id, o.source_id, o.sample, o.dv_region, o.zt,\n"
        . "c.code AS cluster, c.label AS cluster_label, a.code AS age, a.label AS age_label, sx.code AS sex, sx.label AS sex_label\n"
        . "FROM expression e JOIN observations o ON o.observation_id = e.observation_id\n"
        . "JOIN clusters c ON c.cluster_id = o.cluster_id\n"
        . "LEFT JOIN ages a ON a.age_id = o.age_id\n"
        . "LEFT JOIN sexes sx ON sx.sex_id = o.sex_id\n"
        . "WHERE $where ORDER BY c.sort_order, a.sort_order, sx.sort_order, o.dv_region, o.observation_id", $params);
}

function dv_normalize_region(string $region): string
{
    $key = strtolower(trim($region));
    if ($key === 'd' || $key === 'dorsal') return 'Dorsal';
    if ($key === 'v' || $key === 'ventral') return 'Ventral';
    return ucfirst($key);
}

function dv_facet_info(array $row, string $splitBy, bool $multipleClusters): array
{
    $parts = array();
    $keys = array();
    if ($multipleClusters) {
        $keys[] = (string) $row['cluster'];
        $parts[] = (string) $row['cluster_label'];
    }
    if ($splitBy === 'age' || $splitBy === 'age_sex') {
        $keys[] = (string) ($row['age'] ?? '');
        $parts[] = 'Age ' . (string) ($row['age_label'] ?? $row['age'] ?? 'unavailable');
    }
    if ($splitBy === 'sex' || $splitBy === 'age_sex') {
        $keys[] = (string) ($row['sex'] ?? '');
        $parts[] = 'Sex ' . (string) ($row['sex_label'] ?? $row['sex'] ?? 'unavailable');
    }
    if (!count($keys)) return array('key' => '__all__', 'label' => '');
    return array('key' => implode("\x1f", $keys), 'label' => implode(' — ', $parts));
}

function dv_plot_svg(string $gene, string $cluster, string $splitBy, int $width = 780): string
{
    $pdo = open_database('dv');
    $resolved = resolve_gene_table($pdo, $gene, 25);
    if (!$resolved['found']) return svg_error_message('No D/V data for ' . $gene);
    $cluster = dv_resolve_cluster($pdo, $cluster);
    $splitBy = dv_split_value($splitBy);
    $rows = dv_plot_points($pdo, (int) $resolved['gene_id'], $cluster);
    if (!count($rows)) return svg_error_message('No D/V expression points', $resolved['gene']);

    $clustersSeen = array();
    foreach ($rows as $row) $clustersSeen[(string) $row['cluster']] = true;
    $multipleClusters = count($clustersSeen) > 1;
    $facets = array();
    $pointsByFacet = array();
    $summary = array();

    foreach ($rows as $row) {
        if (!is_numeric($row['value'])) continue;
        $region = dv_normalize_region((string) $row['dv_region']);
        if (!in_array($region, array('Dorsal', 'Ventral'), true)) continue;
        $facet = dv_facet_info($row, $splitBy, $multipleClusters);
        $facets[$facet['key']] = $facet['label'];
        $value = (float) $row['value'];
        $pointsByFacet[$facet['key']][] = array(
            'region' => $region,
            'value' => $value,
            'key' => (string) $row['source_id'] . '|' . (string) $row['observation_id'],
        );
        $sumKey = $facet['key'] . "\x1e" . $region;
        if (!isset($summary[$sumKey])) $summary[$sumKey] = array('facet' => $facet['key'], 'region' => $region, 'n' => 0, 'sum' => 0.0, 'sumsq' => 0.0);
        $summary[$sumKey]['n']++;
        $summary[$sumKey]['sum'] += $value;
        $summary[$sumKey]['sumsq'] += $value * $value;
    }
    if (!count($pointsByFacet)) return svg_error_message('No finite D/V expression values', $resolved['gene']);

    $statsByFacet = array();
    foreach ($summary as $item) {
        $mean = $item['sum'] / max(1, $item['n']);
        $variance = $item['n'] > 1 ? max(0.0, ($item['sumsq'] - $item['sum'] * $item['sum'] / $item['n']) / ($item['n'] - 1)) : 0.0;
        $sd = $item['n'] > 1 ? sqrt($variance) : 0.0;
        $item['mean'] = $mean;
        $item['sd'] = $sd;
        $statsByFacet[$item['facet']][$item['region']] = $item;
    }

    $facetKeys = array_keys($facets);
    $columns = count($facetKeys) <= 2 ? count($facetKeys) : min(3, (int) ceil(sqrt(count($facetKeys))));
    $rowsCount = (int) ceil(count($facetKeys) / max(1, $columns));
    $left = 64;
    $right = 24;
    $top = 62;
    $gapX = 18;
    $gapY = 26;
    $panelWidth = (int) floor(($width - $left - $right - ($columns - 1) * $gapX) / max(1, $columns));
    $panelHeight = 300;
    $height = $top + $rowsCount * $panelHeight + max(0, $rowsCount - 1) * $gapY + 72;
    $svg = array();
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" font-family="Arial, sans-serif" aria-label="Dorsal and ventral hippocampus expression for ' . xml_escape($resolved['gene']) . '">';
    $svg[] = '<rect width="100%" height="100%" fill="white"/>';
    $svg[] = '<text x="' . ($width / 2) . '" y="28" text-anchor="middle" font-family="Arial, sans-serif" font-size="26" font-weight="700" font-style="italic" fill="#111827">' . xml_escape($resolved['gene']) . '</text>';
    $svg[] = '<text x="' . ($width / 2) . '" y="50" text-anchor="middle" font-family="Arial, sans-serif" font-size="19" fill="#64748b">WT only; all ages and sexes included; display split: ' . xml_escape(dv_split_label($splitBy)) . '</text>';

    foreach ($facetKeys as $index => $facetKey) {
        $col = $index % $columns;
        $rowIndex = intdiv($index, $columns);
        $panelX = $left + $col * ($panelWidth + $gapX);
        $panelY = $top + $rowIndex * ($panelHeight + $gapY);
        $plotX = $panelX + 46;
        $plotY = $panelY + 32;
        $plotW = $panelWidth - 62;
        $plotH = $panelHeight - 76;
        if ($facets[$facetKey] !== '') {
            $svg[] = '<rect x="' . $panelX . '" y="' . $panelY . '" width="' . $panelWidth . '" height="24" fill="#f8fafc" stroke="#cbd5e1"/>';
            $svg[] = '<text x="' . ($panelX + $panelWidth / 2) . '" y="' . ($panelY + 18) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="18" font-weight="600" fill="#334155">' . xml_escape($facets[$facetKey]) . '</text>';
        }
        $values = array_map(function ($point) { return $point['value']; }, $pointsByFacet[$facetKey]);
        foreach ($statsByFacet[$facetKey] ?? array() as $stat) {
            $values[] = $stat['mean'] + $stat['sd'];
            $values[] = $stat['mean'] - $stat['sd'];
        }
        $rawMin = min($values);
        $rawMax = max($values);
        $padding = max(0.2, ($rawMax - $rawMin) * 0.08);
        $ticks = nice_ticks($rawMin - $padding, $rawMax + $padding, 5);
        $yMin = min($ticks);
        $yMax = max($ticks);
        $yScale = function (float $y) use ($plotY, $plotH, $yMin, $yMax): float { return $plotY + $plotH - (($y - $yMin) / ($yMax - $yMin)) * $plotH; };
        $xCenters = array('Dorsal' => $plotX + $plotW * 0.30, 'Ventral' => $plotX + $plotW * 0.70);

        foreach ($ticks as $tick) {
            $y = $yScale((float) $tick);
            $svg[] = '<line x1="' . $plotX . '" y1="' . round($y, 2) . '" x2="' . ($plotX + $plotW) . '" y2="' . round($y, 2) . '" stroke="#e5e7eb"/>';
            $svg[] = '<text x="' . ($plotX - 9) . '" y="' . round($y + 6, 2) . '" text-anchor="end" font-family="Arial, sans-serif" font-size="18" fill="#475569">' . xml_escape(svg_numeric_label((float) $tick)) . '</text>';
        }

        foreach (array('Dorsal', 'Ventral') as $region) {
            $stat = $statsByFacet[$facetKey][$region] ?? null;
            if ($stat !== null) {
                $x = $xCenters[$region];
                $zeroY = $yScale(max($yMin, min(0.0, $yMax)));
                $meanY = $yScale((float) $stat['mean']);
                $barTop = min($zeroY, $meanY);
                $barHeight = max(1.0, abs($zeroY - $meanY));
                $svg[] = '<rect x="' . round($x - 28, 2) . '" y="' . round($barTop, 2) . '" width="56" height="' . round($barHeight, 2) . '" fill="#d9e2ec" fill-opacity="0.9" stroke="#2f3a45"/>';
                $lowY = $yScale((float) ($stat['mean'] - $stat['sd']));
                $highY = $yScale((float) ($stat['mean'] + $stat['sd']));
                $svg[] = '<line x1="' . $x . '" y1="' . round($lowY, 2) . '" x2="' . $x . '" y2="' . round($highY, 2) . '" stroke="#2f3a45" stroke-width="1.2"/>';
                $svg[] = '<line x1="' . ($x - 7) . '" y1="' . round($lowY, 2) . '" x2="' . ($x + 7) . '" y2="' . round($lowY, 2) . '" stroke="#2f3a45"/>';
                $svg[] = '<line x1="' . ($x - 7) . '" y1="' . round($highY, 2) . '" x2="' . ($x + 7) . '" y2="' . round($highY, 2) . '" stroke="#2f3a45"/>';
            }
        }

        foreach ($pointsByFacet[$facetKey] as $point) {
            $x = $xCenters[$point['region']] + (deterministic_unit_interval($point['key']) - 0.5) * 34;
            $svg[] = '<circle cx="' . round($x, 2) . '" cy="' . round($yScale((float) $point['value']), 2) . '" r="2" fill="#1f2937" fill-opacity="0.22"/>';
        }
        $svg[] = '<rect x="' . $plotX . '" y="' . $plotY . '" width="' . $plotW . '" height="' . $plotH . '" fill="none" stroke="#475569"/>';
        foreach ($xCenters as $region => $x) {
            $svg[] = '<text x="' . $x . '" y="' . ($plotY + $plotH + 23) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="21" font-weight="600" fill="#334155">' . $region . '</text>';
        }
    }
    $plotBottom = $top + $rowsCount * $panelHeight + max(0, $rowsCount - 1) * $gapY;
    $svg[] = '<text x="68" y="' . ($top + ($plotBottom - $top) / 2) . '" transform="rotate(-90 68 ' . ($top + ($plotBottom - $top) / 2) . ')" text-anchor="middle" font-family="Arial, sans-serif" font-size="19" font-weight="600" fill="#111827">log2 Normalized mRNA Expression</text>';
    $svg[] = '<text x="' . $left . '" y="' . ($height - 24) . '" font-family="Arial, sans-serif" font-size="18" fill="#64748b">Bars show mean ± SD; points are WT sample-level observations.</text>';
    $svg[] = '</svg>';
    return implode('', $svg);
}
