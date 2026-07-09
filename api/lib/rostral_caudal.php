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

function rc_require_gene(PDO $pdo, string $gene): array
{
    $resolved = rc_gene_resolve($gene, 25);
    if (!$resolved['found']) {
        throw new ApiException('Unknown rostral-caudal gene: ' . $gene . '.', 400, count($resolved['suggestions']) ? 'Suggestions: ' . implode(', ', array_slice($resolved['suggestions'], 0, 5)) : null);
    }
    return $resolved;
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
    foreach ($pointRows as $row) {
        if (!is_numeric($row['value']) || !is_numeric($row['zt'])) continue;
        $baseTime = (float) $row['zt'];
        $value = (float) $row['value'];
        // Raw points and summary/error bars are shown once, in the observed 0-24h cycle.
        if ($baseTime >= -0.001 && $baseTime <= 24.001) {
            $rawPoints[] = array(
                'region' => (string) $row['region'],
                'x' => $baseTime,
                'y' => $value,
                'sample_key' => (string) $row['sample_key'],
            );
            $key = (string) $row['region'] . "\x1e" . sprintf('%.6f', $baseTime);
            if (!isset($summaryValues[$key])) {
                $summaryValues[$key] = array('region' => (string) $row['region'], 'x' => $baseTime, 'values' => array());
            }
            $summaryValues[$key]['values'][] = $value;
            $allY[] = $value;
        }
    }

    $summaries = array();
    foreach ($summaryValues as $item) {
        $mean = rc_mean_logcpm($item['values']);
        $sd = rc_sd($item['values']);
        $summaries[] = array('region' => $item['region'], 'x' => $item['x'], 'mean' => $mean, 'sd' => $sd, 'n' => count($item['values']));
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

    $rawMin = min($allY);
    $rawMax = max($allY);
    $padding = max(0.25, ($rawMax - $rawMin) * 0.08);
    $ticks = nice_ticks($rawMin - $padding, $rawMax + $padding, 4);
    $yMin = min($ticks);
    $yMax = max($ticks);
    if ($yMin === $yMax) $yMax = $yMin + 1.0;

    return array(
        'available' => true,
        'found' => count($rawPoints) > 0 || count($curves) > 0,
        'input' => $gene,
        'gene' => (string) $resolved['gene'],
        'cluster' => (string) $clusterRow['code'],
        'cluster_label' => (string) $clusterRow['label'],
        'point_count' => count($rawPoints),
        'model_count' => count($coefRows),
        'plot' => array(
            'x_min' => 0,
            'x_max' => 42,
            'x_ticks' => array(array('x' => 0, 'label' => '0'), array('x' => 12, 'label' => '12'), array('x' => 24, 'label' => '0'), array('x' => 36, 'label' => '12')),
            'y_min' => $yMin,
            'y_max' => $yMax,
            'y_ticks' => array_values(array_map('floatval', $ticks)),
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

function rc_plot_svg(string $gene, string $cluster, int $width = 980): string
{
    $pdo = open_database('rostral_caudal');
    if (!rc_available($pdo)) return svg_error_message('Rostral-caudal data not installed', 'Run the rostral-caudal SQLite export and install rostral_caudal.sqlite.', $width, 360);
    $resolved = rc_require_gene($pdo, $gene);
    $clusterRow = rc_cluster_row($pdo, $cluster);
    if ($clusterRow === null) return svg_error_message('Unknown cortical cluster', $cluster, $width, 360);

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
    if (!count($pointRows) && !count($coefRows)) return svg_error_message('No rostral-caudal data for current gene/cluster', (string) $resolved['gene'] . ' · ' . (string) $clusterRow['label'], $width, 360);

    $regions = array();
    foreach (db_all($pdo, 'SELECT code, label, color FROM rc_regions ORDER BY sort_order') as $row) {
        $regions[(string) $row['code']] = array('label' => (string) $row['label'], 'color' => (string) $row['color']);
    }

    $summaryValues = array();
    $allY = array();
    foreach ($pointRows as $row) {
        if (!is_numeric($row['value']) || !is_numeric($row['zt'])) continue;
        $baseTime = (float) $row['zt'];
        foreach (array($baseTime, $baseTime + 24.0) as $time) {
            if ($time < -0.001 || $time > 42.001) continue;
            $key = (string) $row['region'] . "\x1e" . sprintf('%.6f', $time);
            if (!isset($summaryValues[$key])) {
                $summaryValues[$key] = array('region' => (string) $row['region'], 'x' => $time, 'values' => array());
            }
            $summaryValues[$key]['values'][] = (float) $row['value'];
        }
        $allY[] = (float) $row['value'];
    }
    $summariesByRegion = array();
    foreach ($summaryValues as $item) {
        $mean = rc_mean_logcpm($item['values']);
        $sd = rc_sd($item['values']);
        $row = array('region' => $item['region'], 'x' => $item['x'], 'mean' => $mean, 'sd' => $sd, 'n' => count($item['values']));
        $summariesByRegion[$item['region']][] = $row;
        $allY[] = $mean - $sd;
        $allY[] = $mean + $sd;
    }

    $sampleCovariates = array();
    $sampleRows = db_all($pdo,
        'SELECT DISTINCT s.region_id, r.code AS region, s.sample_key, s.age, s.sex FROM rc_samples s JOIN rc_regions r ON r.region_id = s.region_id WHERE s.cluster_id = :cluster_id ORDER BY r.sort_order, s.sample_key',
        array('cluster_id' => $clusterId)
    );
    foreach ($sampleRows as $row) $sampleCovariates[(string) $row['region']][] = $row;

    $linesByRegion = array();
    $timeCount = 150;
    foreach ($coefRows as $coef) {
        $region = (string) $coef['region'];
        $samples = $sampleCovariates[$region] ?? array();
        if (!count($samples)) $samples = array(array('age' => 'O', 'sex' => 'F'));
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
            $linesByRegion[$region][] = array('x' => $time, 'y' => $y);
            $allY[] = $y;
        }
    }

    if (!count($allY)) return svg_error_message('No finite rostral-caudal expression values', (string) $resolved['gene'], $width, 360);
    $rawMin = min($allY);
    $rawMax = max($allY);
    $padding = max(0.25, ($rawMax - $rawMin) * 0.08);
    $ticks = nice_ticks($rawMin - $padding, $rawMax + $padding, 4);
    $yMin = min($ticks);
    $yMax = max($ticks);
    if ($yMin === $yMax) $yMax = $yMin + 1.0;

    $height = 560;
    $plotX = 92;
    $plotY = 64;
    $plotW = $width - 130;
    $plotH = 340;
    $xScale = function (float $x) use ($plotX, $plotW): float { return $plotX + ($x / 42.0) * $plotW; };
    $yScale = function (float $y) use ($plotY, $plotH, $yMin, $yMax): float { return $plotY + $plotH - (($y - $yMin) / ($yMax - $yMin)) * $plotH; };

    $svg = array();
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Rostral-caudal rhythmicity plot for ' . xml_escape($resolved['gene']) . '">';
    $svg[] = '<rect width="100%" height="100%" fill="white"/>';
    $svg[] = '<text x="' . ($width / 2) . '" y="30" text-anchor="middle" font-family="Arial, sans-serif" font-size="18" font-weight="700" font-style="italic" fill="#111827">' . xml_escape((string) $resolved['gene']) . '</text>';
    $svg[] = '<text x="' . ($width / 2) . '" y="49" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#475569">' . xml_escape((string) $clusterRow['label']) . '</text>';
    $svg[] = '<defs><clipPath id="rcClip"><rect x="' . $plotX . '" y="' . $plotY . '" width="' . $plotW . '" height="' . $plotH . '"/></clipPath></defs>';

    foreach (array(array(0,12,'#F6F18F'), array(12,24,'#606161'), array(24,36,'#F6F18F'), array(36,42,'#606161')) as $bg) {
        $svg[] = '<rect x="' . round($xScale((float) $bg[0]), 2) . '" y="' . $plotY . '" width="' . round($xScale((float) $bg[1]) - $xScale((float) $bg[0]), 2) . '" height="' . $plotH . '" fill="' . $bg[2] . '" fill-opacity="0.10"/>';
    }
    foreach ($ticks as $tick) {
        $y = $yScale((float) $tick);
        $svg[] = '<line x1="' . $plotX . '" y1="' . round($y, 2) . '" x2="' . ($plotX + $plotW) . '" y2="' . round($y, 2) . '" stroke="#e5e7eb" stroke-width="1"/>';
        $svg[] = '<text x="' . ($plotX - 10) . '" y="' . round($y + 4, 2) . '" text-anchor="end" font-family="Arial, sans-serif" font-size="10" fill="#475569">' . xml_escape(svg_numeric_label((float) $tick)) . '</text>';
    }

    $svg[] = '<g clip-path="url(#rcClip)">';
    foreach ($linesByRegion as $region => $lineRows) {
        $color = $regions[$region]['color'] ?? '#2563eb';
        $poly = array();
        foreach ($lineRows as $lineRow) $poly[] = array($xScale((float) $lineRow['x']), $yScale((float) $lineRow['y']));
        $svg[] = svg_polyline($poly, $color, 1.3, 1.0);
    }
    foreach ($summariesByRegion as $region => $items) {
        $color = $regions[$region]['color'] ?? '#2563eb';
        foreach ($items as $item) {
            $x = $xScale((float) $item['x']);
            $meanY = $yScale((float) $item['mean']);
            $lowY = $yScale((float) ($item['mean'] - $item['sd']));
            $highY = $yScale((float) ($item['mean'] + $item['sd']));
            $svg[] = '<line x1="' . round($x, 2) . '" y1="' . round($lowY, 2) . '" x2="' . round($x, 2) . '" y2="' . round($highY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="0.9"/>';
            $svg[] = '<line x1="' . round($x - 2.2, 2) . '" y1="' . round($lowY, 2) . '" x2="' . round($x + 2.2, 2) . '" y2="' . round($lowY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="0.9"/>';
            $svg[] = '<line x1="' . round($x - 2.2, 2) . '" y1="' . round($highY, 2) . '" x2="' . round($x + 2.2, 2) . '" y2="' . round($highY, 2) . '" stroke="' . xml_escape($color) . '" stroke-width="0.9"/>';
            $svg[] = '<circle cx="' . round($x, 2) . '" cy="' . round($meanY, 2) . '" r="2.7" fill="' . xml_escape($color) . '" stroke="white" stroke-width="0.5"/>';
        }
    }
    $svg[] = '</g>';
    $svg[] = '<rect x="' . $plotX . '" y="' . $plotY . '" width="' . $plotW . '" height="' . $plotH . '" fill="none" stroke="#475569" stroke-width="1"/>';

    foreach (array(array(0,'0'), array(12,'12'), array(24,'0'), array(36,'12')) as $tick) {
        $x = $xScale((float) $tick[0]);
        $svg[] = '<line x1="' . round($x, 2) . '" y1="' . ($plotY + $plotH) . '" x2="' . round($x, 2) . '" y2="' . ($plotY + $plotH + 4) . '" stroke="#475569"/>';
        $svg[] = '<text x="' . round($x, 2) . '" y="' . ($plotY + $plotH + 18) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#475569">' . $tick[1] . '</text>';
    }
    $svg[] = '<text x="' . ($width / 2) . '" y="' . ($plotY + $plotH + 42) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" fill="#111827">Zeitgeber Time (double plotted)</text>';
    $svg[] = '<text x="18" y="' . ($plotY + $plotH / 2) . '" transform="rotate(-90 18 ' . ($plotY + $plotH / 2) . ')" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" font-style="italic" fill="#111827">' . xml_escape((string) $resolved['gene']) . '</text>';

    $legendY = $height - 92;
    $svg[] = '<text x="' . $plotX . '" y="' . $legendY . '" font-family="Arial, sans-serif" font-size="11" font-weight="700" fill="#334155">Cortical position</text>';
    $legendX = $plotX + 132;
    $i = 0;
    foreach ($regions as $code => $entry) {
        $x = $legendX + $i * 190;
        $svg[] = '<line x1="' . $x . '" y1="' . $legendY . '" x2="' . ($x + 20) . '" y2="' . $legendY . '" stroke="' . xml_escape($entry['color']) . '" stroke-width="3"/>';
        $svg[] = '<text x="' . ($x + 28) . '" y="' . ($legendY + 4) . '" font-family="Arial, sans-serif" font-size="11" fill="#334155">' . xml_escape($entry['label']) . '</text>';
        $i++;
    }
    $svg[] = '</svg>';
    return implode('', $svg);
}
