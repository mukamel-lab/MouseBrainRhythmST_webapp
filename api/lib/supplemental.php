<?php

declare(strict_types=1);

function rhythm_source_order(): array
{
    return array('S1', 'S2', 'S10', 'S3', 'S6');
}

function supplemental_gene_search(string $query, int $limit): array
{
    return search_gene_table(open_database('supplemental'), $query, $limit);
}

function supplemental_gene_resolve(string $query, int $limit = 25): array
{
    return resolve_gene_table(open_database('supplemental'), $query, $limit);
}

function supplemental_metadata(): array
{
    try {
        $pdo = open_database('supplemental');
    } catch (Throwable $error) {
        return array('available' => false, 'default_threshold' => 0.1, 'sources' => array(), 'row_count' => 0, 'gene_count' => 0);
    }
    $settings = read_key_value_table($pdo, 'settings');
    $rows = db_all($pdo, 'SELECT s.source_id, s.label, s.sort_order, COUNT(r.result_id) AS row_count FROM sources s LEFT JOIN rhythmicity_results r ON r.source_id = s.source_id GROUP BY s.source_id, s.label, s.sort_order ORDER BY s.sort_order');
    $sources = array();
    $rowCount = 0;
    foreach ($rows as $row) {
        $count = (int) $row['row_count'];
        $rowCount += $count;
        $sources[] = array('table_id' => (string) $row['source_id'], 'label' => (string) $row['label'], 'row_count' => $count);
    }
    return array(
        'available' => true,
        'default_threshold' => isset($settings['default_threshold']) ? (float) $settings['default_threshold'] : 0.1,
        'sources' => $sources,
        'row_count' => $rowCount,
        'gene_count' => (int) db_scalar($pdo, 'SELECT COUNT(*) FROM genes'),
    );
}

function rhythm_safe_threshold($value): float
{
    if (!is_numeric($value)) return 0.1;
    $threshold = (float) $value;
    if (!is_finite($threshold) || $threshold <= 0) return 0.1;
    return min(0.1, $threshold);
}

function rhythm_source(string $source): string
{
    $source = strtoupper(trim($source));
    return in_array($source, rhythm_source_order(), true) ? $source : 'all';
}

function rhythm_row_matches_cluster(array $row, string $cluster): bool
{
    $wanted = cluster_synonym_keys($cluster);
    if (!count($wanted)) return true;
    $rowKeys = array(
        normalize_label_key($row['cluster_code'] ?? ''),
        normalize_label_key($row['sheet'] ?? ''),
    );
    foreach ($rowKeys as $key) {
        if ($key !== '' && in_array($key, $wanted, true)) return true;
    }
    return false;
}

function rhythm_map_row(array $row, string $gene): array
{
    return array(
        'gene' => $gene,
        'table_id' => (string) $row['source_id'],
        'table_name' => (string) ($row['table_name'] ?? ''),
        'result_type' => (string) ($row['result_type'] ?? ''),
        'sheet' => (string) ($row['sheet'] ?? ''),
        'sheet_display' => (string) ($row['sheet_display'] ?? $row['sheet'] ?? ''),
        'context' => (string) ($row['context'] ?? ''),
        'context_display' => (string) ($row['context_display'] ?? $row['context'] ?? ''),
        'cluster' => (string) ($row['cluster_code'] ?? ''),
        'cluster_display' => (string) ($row['cluster_display'] ?? $row['cluster_code'] ?? ''),
        'comparison' => (string) ($row['comparison'] ?? ''),
        'comparison_display' => (string) ($row['comparison_display'] ?? $row['comparison'] ?? ''),
        'genotype' => (string) ($row['genotype'] ?? ''),
        'age' => (string) ($row['age'] ?? ''),
        'significance_metric' => (string) ($row['significance_metric'] ?? ''),
        'significance' => $row['significance'] === null ? null : (float) $row['significance'],
        'significance_display' => format_metric_value($row['significance'] ?? null),
        'pvalue_metric' => (string) ($row['pvalue_metric'] ?? ''),
        'pvalue' => $row['p_value'] === null ? null : (float) $row['p_value'],
        'pvalue_display' => format_metric_value($row['p_value'] ?? null),
        'amplitude' => $row['amplitude'] === null ? null : (float) $row['amplitude'],
        'amplitude_display' => format_small_number($row['amplitude'] ?? null),
        'phase_hr' => $row['phase_hr'] === null ? null : (float) $row['phase_hr'],
        'phase_hr_display' => format_small_number($row['phase_hr'] ?? null),
        'amplitude_2' => $row['amplitude_2'] === null ? null : (float) $row['amplitude_2'],
        'amplitude_2_display' => format_small_number($row['amplitude_2'] ?? null),
        'phase_hr_2' => $row['phase_hr_2'] === null ? null : (float) $row['phase_hr_2'],
        'phase_hr_2_display' => format_small_number($row['phase_hr_2'] ?? null),
        'detail' => (string) ($row['detail'] ?? ''),
        'detail_display' => (string) ($row['detail_display'] ?? $row['detail'] ?? ''),
    );
}

function rhythm_fetch_rows(PDO $pdo, int $geneId, float $threshold, string $source, bool $basicOnly = false): array
{
    $params = array('gene_id' => $geneId, 'threshold' => $threshold);
    $where = array('r.gene_id = :gene_id', 'r.significance IS NOT NULL', 'r.significance < :threshold');
    if ($source !== 'all') {
        $where[] = 'r.source_id = :source';
        $params['source'] = $source;
    }
    if ($basicOnly) {
        $where[] = "r.source_id IN ('S1','S2')";
    }
    $orderCases = array();
    foreach (rhythm_source_order() as $index => $code) {
        $orderCases[] = "WHEN '" . $code . "' THEN " . $index;
    }
    $sql = 'SELECT r.* FROM rhythmicity_results r WHERE ' . implode(' AND ', $where)
        . ' ORDER BY CASE r.source_id ' . implode(' ', $orderCases) . ' ELSE 99 END, r.significance, r.context_display, r.result_id';
    return db_all($pdo, $sql, $params);
}

function rhythm_balanced_rows(array $rows, int $limit, string $source): array
{
    $limit = max(1, min(5000, $limit));
    if (count($rows) <= $limit || $source !== 'all') return array_slice($rows, 0, $limit);
    $bySource = array();
    foreach ($rows as $row) {
        $bySource[(string) $row['source_id']][] = $row;
    }
    $order = rhythm_source_order();
    foreach (array_keys($bySource) as $sourceCode) {
        if (!in_array($sourceCode, $order, true)) $order[] = $sourceCode;
    }
    $chosen = array();
    for ($i = 0; count($chosen) < $limit; $i++) {
        $added = false;
        foreach ($order as $sourceCode) {
            if (isset($bySource[$sourceCode][$i])) {
                $chosen[] = $bySource[$sourceCode][$i];
                $added = true;
                if (count($chosen) >= $limit) break;
            }
        }
        if (!$added) break;
    }
    return $chosen;
}

function supplemental_search_payload(string $input, float $threshold, string $source, int $limit, string $cluster = '', bool $basicOnly = false): array
{
    try {
        $pdo = open_database('supplemental');
    } catch (Throwable $error) {
        return array('available' => false, 'input' => $input, 'found' => false, 'gene' => null, 'threshold' => $threshold, 'source' => $source, 'cluster' => $cluster, 'basic_only' => $basicOnly, 'count' => 0, 'displayed_count' => 0, 'limited' => false, 'source_counts' => array(), 'suggestions' => array(), 'rows' => array());
    }
    $resolved = resolve_gene_table($pdo, $input, 25);
    $empty = array('available' => true, 'input' => $input, 'found' => false, 'gene' => null, 'threshold' => $threshold, 'source' => $source, 'cluster' => $cluster, 'basic_only' => $basicOnly, 'count' => 0, 'displayed_count' => 0, 'limited' => false, 'source_counts' => array(), 'suggestions' => $resolved['suggestions'], 'rows' => array());
    if (!$resolved['found']) return $empty;

    $rows = rhythm_fetch_rows($pdo, (int) $resolved['gene_id'], $threshold, $source, $basicOnly);
    if ($cluster !== '') {
        $rows = array_values(array_filter($rows, function ($row) use ($cluster) { return rhythm_row_matches_cluster($row, $cluster); }));
    }
    $count = count($rows);
    $sourceCounts = array();
    foreach ($rows as $row) {
        $code = (string) $row['source_id'];
        $sourceCounts[$code] = isset($sourceCounts[$code]) ? $sourceCounts[$code] + 1 : 1;
    }
    $displayRows = rhythm_balanced_rows($rows, $limit, $source);
    $mapped = array_map(function ($row) use ($resolved) { return rhythm_map_row($row, (string) $resolved['gene']); }, $displayRows);
    return array(
        'available' => true,
        'input' => $input,
        'found' => true,
        'gene' => $resolved['gene'],
        'threshold' => $threshold,
        'source' => $source,
        'cluster' => $cluster,
        'basic_only' => $basicOnly,
        'count' => $count,
        'displayed_count' => count($mapped),
        'limited' => $count > count($mapped),
        'source_counts' => $sourceCounts,
        'suggestions' => $resolved['suggestions'],
        'rows' => $mapped,
    );
}

function rhythm_basic_call(array $rows, string $genotype, string $gene): ?array
{
    $target = strtoupper($genotype);
    foreach ($rows as $row) {
        if (strtoupper(trim((string) ($row['genotype'] ?? ''))) !== $target) continue;
        $mapped = rhythm_map_row($row, $gene);
        return array(
            'table_id' => $mapped['table_id'],
            'table_name' => $mapped['table_name'],
            'result_type' => $mapped['result_type'],
            'significance_metric' => $mapped['significance_metric'],
            'significance' => $mapped['significance'],
            'significance_display' => $mapped['significance_display'],
            'pvalue_metric' => $mapped['pvalue_metric'],
            'pvalue' => $mapped['pvalue'],
            'pvalue_display' => $mapped['pvalue_display'],
            'amplitude' => $mapped['amplitude_display'],
            'phase_hr' => $mapped['phase_hr_display'],
            'detail' => $mapped['detail'],
            'detail_display' => $mapped['detail_display'],
        );
    }
    return null;
}

function supplemental_basic_payload(string $input, array $clusters, float $threshold, int $limit): array
{
    try {
        $pdo = open_database('supplemental');
    } catch (Throwable $error) {
        return array('available' => false, 'input' => $input, 'found' => false, 'gene' => null, 'threshold' => $threshold, 'clusters_requested' => $clusters, 'count' => 0, 'displayed_count' => 0, 'limited' => false, 'rows' => array(), 'suggestions' => array());
    }
    $resolved = resolve_gene_table($pdo, $input, 25);
    if (!$resolved['found']) {
        return array('available' => true, 'input' => $input, 'found' => false, 'gene' => null, 'threshold' => $threshold, 'clusters_requested' => $clusters, 'count' => 0, 'displayed_count' => 0, 'limited' => false, 'rows' => array(), 'suggestions' => $resolved['suggestions']);
    }
    $allRows = rhythm_fetch_rows($pdo, (int) $resolved['gene_id'], $threshold, 'all', true);
    $requested = $clusters;
    if (!count($requested)) {
        $seen = array();
        foreach ($allRows as $row) {
            $label = trim((string) ($row['cluster_code'] ?? ''));
            if ($label === '') $label = trim((string) ($row['sheet'] ?? ''));
            $key = normalize_label_key($label);
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = $label;
            }
        }
        $requested = array_values($seen);
    }

    $resultRows = array();
    $nonemptyCount = 0;
    foreach ($requested as $cluster) {
        $hits = array_values(array_filter($allRows, function ($row) use ($cluster) { return rhythm_row_matches_cluster($row, $cluster); }));
        $ntg = rhythm_basic_call($hits, 'NTG', (string) $resolved['gene']);
        $app23 = rhythm_basic_call($hits, 'APP23', (string) $resolved['gene']);
        if ($ntg !== null || $app23 !== null) $nonemptyCount++;
        $display = $cluster;
        foreach ($hits as $hit) {
            $candidate = trim((string) ($hit['cluster_display'] ?? ''));
            if ($candidate !== '') { $display = $candidate; break; }
        }
        $resultRows[] = array(
            'gene' => $resolved['gene'],
            'cluster' => $cluster,
            'cluster_display' => $display,
            'requested_cluster' => $cluster,
            'requested_cluster_display' => $display,
            'ntg' => $ntg,
            'app23' => $app23,
        );
    }
    $limited = count($resultRows) > $limit;
    if ($limited) $resultRows = array_slice($resultRows, 0, $limit);
    return array(
        'available' => true,
        'input' => $input,
        'found' => true,
        'gene' => $resolved['gene'],
        'threshold' => $threshold,
        'clusters_requested' => $clusters,
        'count' => $nonemptyCount,
        'displayed_count' => count($resultRows),
        'limited' => $limited,
        'rows' => $resultRows,
        'suggestions' => $resolved['suggestions'],
    );
}

function supplemental_tsv(string $gene, float $threshold, string $source, int $limit): string
{
    $payload = supplemental_search_payload($gene, $threshold, $source, $limit);
    $columns = array('gene', 'table_id', 'table_name', 'result_type', 'sheet', 'context', 'cluster', 'comparison', 'genotype', 'age', 'significance_metric', 'significance', 'pvalue_metric', 'pvalue', 'amplitude', 'phase_hr', 'amplitude_2', 'phase_hr_2', 'detail');
    $out = fopen('php://temp', 'r+');
    fputcsv($out, $columns, "\t", '"', "\\");
    foreach ($payload['rows'] as $row) {
        $values = array();
        foreach ($columns as $column) $values[] = isset($row[$column]) ? $row[$column] : '';
        fputcsv($out, $values, "\t", '"', "\\");
    }
    rewind($out);
    $text = stream_get_contents($out);
    fclose($out);
    return (string) $text;
}
