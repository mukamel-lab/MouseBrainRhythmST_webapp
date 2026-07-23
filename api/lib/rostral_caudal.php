<?php
/**
 * Rostral/intermediate/caudal cortical rhythmicity helper functions.
 * Data are stored in a separate rostral_caudal.sqlite database with rc_* tables.
 */

declare(strict_types=1);

function rc_table_exists(PDO $pdo, string $table): bool
{
    $row = db_one($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1", array('name' => $table));
    return $row !== null;
}

function rc_available(PDO $pdo = null): bool
{
    try {
        $pdo = $pdo ?: open_database('rostral_caudal');
        if (!rc_table_exists($pdo, 'rc_genes') || !rc_table_exists($pdo, 'rc_expression') || !rc_table_exists($pdo, 'rc_model_coefficients')) {
            return false;
        }
        return ((int) db_scalar($pdo, 'SELECT COUNT(*) FROM rc_genes')) > 0;
    } catch (Throwable $ignored) {
        return false;
    }
}

function rc_default_setting(PDO $pdo, string $key, string $default): string
{
    try {
        if (rc_table_exists($pdo, 'settings')) {
            $value = db_scalar($pdo, 'SELECT value FROM settings WHERE key = :key LIMIT 1', array('key' => $key));
            if ($value !== false && $value !== null && trim((string) $value) !== '') return (string) $value;
        }
    } catch (Throwable $ignored) {
    }
    return $default;
}

function rc_gene_row(PDO $pdo, string $input): ?array
{
    return db_one($pdo, 'SELECT gene_id, symbol FROM rc_genes WHERE symbol_upper = :upper LIMIT 1', array('upper' => strtoupper(trim($input))));
}

function rc_gene_search(string $query, int $limit): array
{
    $pdo = open_database('rostral_caudal');
    if (!rc_available($pdo)) return array();
    $limit = max(1, min(500, $limit));
    $query = trim($query);
    if ($query === '') {
        $statement = $pdo->prepare('SELECT symbol FROM rc_genes ORDER BY sort_order, symbol COLLATE NOCASE LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(function ($row) { return (string) $row['symbol']; }, $statement->fetchAll());
    }
    $upper = strtoupper($query);
    $statement = $pdo->prepare(
        'SELECT symbol FROM rc_genes '
        . 'WHERE symbol_upper LIKE :contains '
        . 'ORDER BY CASE WHEN symbol_upper = :exact THEN 0 WHEN symbol_upper LIKE :prefix THEN 1 ELSE 2 END, symbol COLLATE NOCASE '
        . 'LIMIT :limit'
    );
    $statement->bindValue(':contains', '%' . $upper . '%', PDO::PARAM_STR);
    $statement->bindValue(':exact', $upper, PDO::PARAM_STR);
    $statement->bindValue(':prefix', $upper . '%', PDO::PARAM_STR);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map(function ($row) { return (string) $row['symbol']; }, $statement->fetchAll());
}

function rc_gene_resolve(string $input, int $limit = 25): array
{
    $input = trim($input);
    $suggestions = rc_gene_search($input, $limit);
    if ($input === '') return array('input' => '', 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    $pdo = open_database('rostral_caudal');
    if (!rc_available($pdo)) return array('input' => $input, 'found' => false, 'gene' => null, 'suggestions' => array());
    $row = rc_gene_row($pdo, $input);
    if ($row === null) return array('input' => $input, 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    $gene = (string) $row['symbol'];
    if (!in_array($gene, $suggestions, true)) {
        array_unshift($suggestions, $gene);
        $suggestions = array_values(array_unique($suggestions));
    }
    return array('input' => $input, 'found' => true, 'gene' => $gene, 'gene_id' => (int) $row['gene_id'], 'suggestions' => $suggestions);
}

function rc_metadata(): array
{
    try {
        $pdo = open_database('rostral_caudal');
    } catch (Throwable $error) {
        return array(
            'available' => false,
            'default_gene' => 'Dbp',
            'default_cluster' => 'L23',
            'clusters' => array(),
            'regions' => array(),
            'gene_count' => 0,
            'message' => 'Rostral-caudal rhythmicity database is not installed.',
        );
    }
    $available = rc_available($pdo);
    if (!$available) {
        return array(
            'available' => false,
            'default_gene' => 'Dbp',
            'default_cluster' => 'L23',
            'clusters' => array(),
            'regions' => array(),
            'gene_count' => 0,
            'message' => 'Rostral-caudal rhythmicity database is not installed.',
        );
    }
    $clusters = db_all($pdo, 'SELECT code AS id, label, sort_order FROM rc_clusters ORDER BY sort_order, code COLLATE NOCASE');
    $regions = db_all($pdo, 'SELECT code AS id, label, color, sort_order FROM rc_regions ORDER BY sort_order');
    $defaultGene = rc_default_setting($pdo, 'rostral_caudal_default_gene', 'Dbp');
    $defaultCluster = rc_default_setting($pdo, 'rostral_caudal_default_cluster', 'L23');
    return array(
        'available' => true,
        'default_gene' => $defaultGene,
        'default_cluster' => $defaultCluster,
        'gene_count' => (int) db_scalar($pdo, 'SELECT COUNT(*) FROM rc_genes'),
        'clusters' => array_map(function ($row) {
            return array('id' => (string) $row['id'], 'label' => (string) $row['label'], 'sort_order' => (int) $row['sort_order']);
        }, $clusters),
        'regions' => array_map(function ($row) {
            return array('id' => (string) $row['id'], 'label' => (string) $row['label'], 'color' => (string) $row['color'], 'sort_order' => (int) $row['sort_order']);
        }, $regions),
    );
}

function rc_cluster_row(PDO $pdo, string $cluster): ?array
{
    $cluster = trim($cluster);
    if ($cluster === '') $cluster = 'L23';
    return db_one($pdo, 'SELECT cluster_id, code, label FROM rc_clusters WHERE lower(code) = lower(:cluster) OR lower(label) = lower(:cluster) LIMIT 1', array('cluster' => $cluster));
}


function rc_plot_dataset(string $gene, string $cluster): array
{
    $pdo = open_database('rostral_caudal');
    if (!rc_available($pdo)) {
        return array('available' => false, 'found' => false, 'input' => $gene, 'gene' => null, 'message' => 'Rostral-caudal rhythmicity database is not installed.');
    }

    $resolved = rc_gene_resolve($gene, 25);
    if (!$resolved['found']) {
        return array('available' => true, 'found' => false, 'input' => $gene, 'gene' => null, 'suggestions' => $resolved['suggestions']);
    }
    $clusterRow = rc_cluster_row($pdo, $cluster);
    if ($clusterRow === null) {
        return array('available' => true, 'found' => false, 'input' => $gene, 'gene' => $resolved['gene'], 'message' => 'Unknown cortical cluster: ' . $cluster . '.');
    }

    $geneId = (int) $resolved['gene_id'];
    $clusterId = (int) $clusterRow['cluster_id'];
    $pointRows = db_all($pdo,
        "SELECT e.value, s.sample_key, s.zt, s.age, s.sex, r.code AS region, r.label AS region_label, r.color AS region_color, r.sort_order AS region_order\n"
        . "FROM rc_expression e JOIN rc_samples s ON s.sample_id = e.sample_id\n"
        . "JOIN rc_regions r ON r.region_id = s.region_id\n"
        . "WHERE e.gene_id = :gene_id AND s.cluster_id = :cluster_id\n"
        . "ORDER BY r.sort_order, s.zt, s.sample_key",
        array('gene_id' => $geneId, 'cluster_id' => $clusterId)
    );
    $coefRows = db_all($pdo,
        "SELECT mc.*, r.code AS region, r.label AS region_label, r.color AS region_color, r.sort_order AS region_order\n"
        . "FROM rc_model_coefficients mc JOIN rc_regions r ON r.region_id = mc.region_id\n"
        . "WHERE mc.gene_id = :gene_id AND mc.cluster_id = :cluster_id\n"
        . "ORDER BY r.sort_order",
        array('gene_id' => $geneId, 'cluster_id' => $clusterId)
    );

    $regions = array();
    foreach (db_all($pdo, 'SELECT code, label, color, sort_order FROM rc_regions ORDER BY sort_order') as $row) {
        $regions[(string) $row['code']] = array(
            'id' => (string) $row['code'],
            'label' => (string) $row['label'],
            'color' => (string) $row['color'],
            'sort_order' => (int) $row['sort_order'],
        );
    }

    $summaryValues = array();
    $rawPoints = array();
    $allY = array();
    $observationCount = 0;
    $seenPlotPoints = array();

    foreach ($pointRows as $row) {
        if (!is_numeric($row['value']) || !is_numeric($row['zt'])) continue;

            $baseTime = (float) $row['zt'];
            $value = (float) $row['value'];

            // Show observed data only for the original 0 <= ZT < 24 cycle.
            // ZT24 is omitted because it represents the repeated ZT0 position.
            if ($baseTime < -0.001 || $baseTime >= 24.0) continue;

            $observationCount++;
            $allY[] = $value;
            $plotTimes = array($baseTime);
        foreach ($plotTimes as $time) {
            $sampleKey = (string) $row['sample_key'];
            $plotKey = $sampleKey . "\x1e" . sprintf('%.6f', $time);
            if (isset($seenPlotPoints[$plotKey])) continue;
            $seenPlotPoints[$plotKey] = true;

            $rawPoints[] = array(
                'region' => (string) $row['region'],
                'x' => $time,
                'y' => $value,
                'sample_key' => $plotKey,
                'jitter_key' => $plotKey,
            );

            $summaryKey = (string) $row['region'] . "\x1e" . sprintf('%.6f', $time);
            if (!isset($summaryValues[$summaryKey])) {
                $summaryValues[$summaryKey] = array(
                    'region' => (string) $row['region'],
                    'x' => $time,
                    'values' => array(),
                );
            }
            $summaryValues[$summaryKey]['values'][] = $value;
        }
    }

    $summaries = array();
    foreach ($summaryValues as $item) {
        $mean = rc_mean_logcpm($item['values']);
        $sd = rc_sd($item['values']);
        $summaries[] = array(
            'region' => $item['region'],
            'x' => $item['x'],
            'mean' => $mean,
            'sd' => $sd,
            'n' => count($item['values']),
        );
        $allY[] = $mean - $sd;
        $allY[] = $mean + $sd;
    }

    $sampleCovariates = array();
    $sampleRows = db_all($pdo,
        'SELECT DISTINCT s.region_id, r.code AS region, s.sample_key, s.age, s.sex FROM rc_samples s JOIN rc_regions r ON r.region_id = s.region_id WHERE s.cluster_id = :cluster_id ORDER BY r.sort_order, s.sample_key',
        array('cluster_id' => $clusterId)
    );
    foreach ($sampleRows as $row) $sampleCovariates[(string) $row['region']][] = $row;

    $curves = array();
    $timeCount = 160;
    foreach ($coefRows as $coef) {
        $region = (string) $coef['region'];
        $samples = $sampleCovariates[$region] ?? array();
        if (!count($samples)) $samples = array(array('age' => 'O', 'sex' => 'F'));
        $curvePoints = array();
        for ($i = 0; $i < $timeCount; $i++) {
            $time = 42.0 * $i / ($timeCount - 1);
            $phase = fmod($time, 24.0) * 2.0 * M_PI / 24.0;
            $pred = array();
            foreach ($samples as $sample) {
                $value = (float) $coef['intercept'];
                if (strtoupper((string) ($sample['age'] ?? '')) === 'Y') $value += (float) $coef['age_y_vs_o'];
                if (strtoupper((string) ($sample['sex'] ?? '')) === 'M') $value += (float) $coef['sex_m_vs_f'];
                $value += (float) $coef['t_c'] * cos($phase) + (float) $coef['t_s'] * sin($phase);
                $pred[] = log(pow(2.0, $value) + 1.0, 2.0);
            }
            $y = rc_mean_logcpm($pred);
            $curvePoints[] = array('x' => $time, 'y' => $y);
            $allY[] = $y;
        }
        $curves[] = array('region' => $region, 'points' => $curvePoints);
    }

    if (!count($allY)) {
        return array('available' => true, 'found' => false, 'input' => $gene, 'gene' => (string) $resolved['gene'], 'message' => 'No finite rostral-caudal values were found.');
    }

    return array(
        'available' => true,
        'found' => count($rawPoints) > 0 || count($curves) > 0,
        'input' => $gene,
        'gene' => (string) $resolved['gene'],
        'subtitle' => (string) $clusterRow['label'],
        'cluster' => (string) $clusterRow['code'],
        'cluster_label' => (string) $clusterRow['label'],
        'point_count' => $observationCount,
        'plotted_point_count' => count($rawPoints),
        'summary_count' => count($summaries),
        'model_count' => count($coefRows),
        'plot' => array(
            'x_label' => 'Zeitgeber Time (double plotted)',
            'y_label' => (string) $resolved['gene'],
            'legend_title' => '',
            'regions' => array_values($regions),
            'points' => $rawPoints,
            'summaries' => $summaries,
            'curves' => $curves,
        ),
    );
}

function rc_payload(string $gene, string $cluster): array
{
    return rc_plot_dataset($gene, $cluster);
}

function rc_mean_logcpm(array $values): float
{
    $n = 0;
    $sum = 0.0;
    foreach ($values as $value) {
        if (!is_numeric($value)) continue;
        $sum += pow(2.0, (float) $value);
        $n++;
    }
    if ($n <= 0 || $sum <= 0) return 0.0;
    return log($sum / $n, 2.0);
}

function rc_sd(array $values): float
{
    $clean = array_values(array_filter(array_map('floatval', $values), 'is_finite'));
    $n = count($clean);
    if ($n <= 1) return 0.0;
    $mean = array_sum($clean) / $n;
    $ss = 0.0;
    foreach ($clean as $value) $ss += ($value - $mean) * ($value - $mean);
    return sqrt($ss / ($n - 1));
}
