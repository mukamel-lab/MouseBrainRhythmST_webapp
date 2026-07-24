<?php
/**
 * Cluster-level, non-rhythmic APP23-versus-NTG Wald contrasts.
 *
 * The SQLite database contains the six fitted DESeq2 coefficients and their
 * complete 6x6 covariance matrix for every gene/model. Numeric contrasts are
 * evaluated dynamically, then BH-adjusted across every finite gene in the
 * selected cluster model.
 */

declare(strict_types=1);

const NR_SCHEMA_VERSION = '3';
const NR_VARIANCE_TOLERANCE = 1.0e-5;
const NR_CACHE_VERSION = 4;
const NR_DEFAULT_GENE = 'Idi1';
const NR_DEFAULT_CONTRAST = 'genotype_altage_marginal_sex_equal';

function nr_table_exists(PDO $pdo, string $table): bool
{
    return db_one(
        $pdo,
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1",
        array('name' => $table)
    ) !== null;
}

function nr_unavailable_metadata(string $message = 'Non-rhythmic APP23 Wald database is not installed.'): array
{
    return array(
        'available' => false,
        'default_gene' => NR_DEFAULT_GENE,
        'default_cluster' => 'L23',
        'default_contrast' => NR_DEFAULT_CONTRAST,
        'clusters' => array(),
        'contrasts' => array(),
        'ages' => array(),
        'gene_count' => 0,
        'model_count' => 0,
        'message' => $message,
        'database_file' => basename(database_filename('nonrhythmic')),
    );
}

function nr_available(PDO $pdo = null): bool
{
    try {
        $pdo = $pdo ?: open_database('nonrhythmic');
        $required = array(
            'nr_schema_info',
            'nr_genes',
            'nr_clusters',
            'nr_ages',
            'nr_models',
            'nr_model_coefficients',
            'nr_design_cells',
            'nr_samples',
            'nr_expression',
            'nr_wald_basis',
            'nr_contrast_catalog',
        );
        foreach ($required as $table) {
            if (!nr_table_exists($pdo, $table)) return false;
        }
        $version = db_scalar($pdo, "SELECT value FROM nr_schema_info WHERE key = 'schema_version' LIMIT 1");
        if ((string) $version !== NR_SCHEMA_VERSION) return false;
        $modelCount = (int) db_scalar($pdo, 'SELECT COUNT(*) FROM nr_models');
        if ($modelCount <= 0) return false;
        $invalidModels = (int) db_scalar(
            $pdo,
            'SELECT COUNT(*) FROM nr_models m WHERE m.n_coefficients <> 6 '
            . 'OR (SELECT COUNT(*) FROM nr_model_coefficients mc WHERE mc.model_id = m.model_id) <> 6 '
            . 'OR (SELECT COUNT(*) FROM nr_design_cells dc WHERE dc.model_id = m.model_id) <> 8 '
            . 'OR (SELECT COUNT(*) FROM nr_contrast_catalog cc WHERE cc.model_id = m.model_id) = 0 '
            . 'OR NOT EXISTS (SELECT 1 FROM nr_wald_basis wb WHERE wb.model_id = m.model_id)'
        );
        return $invalidModels === 0;
    } catch (Throwable $ignored) {
        return false;
    }
}

function nr_setting(PDO $pdo, string $key, string $default): string
{
    try {
        if (!nr_table_exists($pdo, 'settings')) return $default;
        $value = db_scalar($pdo, 'SELECT value FROM settings WHERE key = :key LIMIT 1', array('key' => $key));
        if ($value !== false && $value !== null && trim((string) $value) !== '') return trim((string) $value);
    } catch (Throwable $ignored) {
    }
    return $default;
}

function nr_cluster_models(PDO $pdo): array
{
    return db_all(
        $pdo,
        'SELECT c.cluster_id, c.code, c.label, c.sort_order, m.model_id, m.n_samples, m.n_genes '
        . 'FROM nr_clusters c JOIN nr_models m ON m.cluster_id = c.cluster_id '
        . 'ORDER BY c.sort_order, c.code COLLATE NOCASE'
    );
}

function nr_cluster_model(PDO $pdo, string $cluster): array
{
    $cluster = trim($cluster);
    if ($cluster === '') $cluster = nr_setting($pdo, 'nonrhythmic_default_cluster', 'L23');
    $row = db_one(
        $pdo,
        'SELECT c.cluster_id, c.code, c.label, c.sort_order, m.* '
        . 'FROM nr_clusters c JOIN nr_models m ON m.cluster_id = c.cluster_id '
        . 'WHERE lower(c.code) = lower(:cluster) OR lower(c.label) = lower(:cluster) LIMIT 1',
        array('cluster' => $cluster)
    );
    if ($row === null) {
        throw new ApiException('Unknown or unavailable non-rhythmic cluster: ' . $cluster . '.', 404);
    }
    return $row;
}

function nr_vector_from_row(array $row, string $prefix): array
{
    $vector = array();
    for ($index = 0; $index < 6; $index++) {
        $key = $prefix . $index;
        $vector[] = isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : NAN;
    }
    return $vector;
}

function nr_contrasts_for_model(PDO $pdo, int $modelId): array
{
    $rows = db_all(
        $pdo,
        'SELECT contrast_id, code, label, family, description, age_mode, sex_mode, weighting, is_default, '
        . 'c0, c1, c2, c3, c4, c5 FROM nr_contrast_catalog '
        . 'WHERE model_id = :model_id ORDER BY contrast_id',
        array('model_id' => $modelId)
    );
    return array_map(function (array $row): array {
        return array(
            'id' => (int) $row['contrast_id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'family' => (string) $row['family'],
            'description' => (string) $row['description'],
            'age_mode' => (string) $row['age_mode'],
            'sex_mode' => (string) $row['sex_mode'],
            'weighting' => (string) $row['weighting'],
            'is_default' => (string) $row['code'] === NR_DEFAULT_CONTRAST,
            'vector' => nr_vector_from_row($row, 'c'),
        );
    }, $rows);
}

function nr_default_gene_for_model(PDO $pdo, int $modelId, string $preferred): string
{
    $row = db_one(
        $pdo,
        'SELECT g.symbol FROM nr_wald_basis w JOIN nr_genes g ON g.gene_id = w.gene_id '
        . 'WHERE w.model_id = :model_id AND g.symbol_upper = :upper LIMIT 1',
        array('model_id' => $modelId, 'upper' => strtoupper($preferred))
    );
    if ($row !== null) return (string) $row['symbol'];
    $row = db_one(
        $pdo,
        'SELECT g.symbol FROM nr_wald_basis w JOIN nr_genes g ON g.gene_id = w.gene_id '
        . 'WHERE w.model_id = :model_id ORDER BY g.sort_order, g.symbol COLLATE NOCASE LIMIT 1',
        array('model_id' => $modelId)
    );
    return $row === null ? $preferred : (string) $row['symbol'];
}

function nr_metadata(): array
{
    try {
        $pdo = open_database('nonrhythmic');
    } catch (Throwable $error) {
        return nr_unavailable_metadata();
    }
    if (!nr_available($pdo)) {
        return nr_unavailable_metadata('The non-rhythmic APP23 database is present but does not satisfy the schema version ' . NR_SCHEMA_VERSION . ' contract.');
    }

    try {
    $clusters = nr_cluster_models($pdo);
    $preferredCluster = nr_setting($pdo, 'nonrhythmic_default_cluster', 'L23');
    $defaultCluster = $clusters[0];
    foreach ($clusters as $cluster) {
        if (strcasecmp((string) $cluster['code'], $preferredCluster) === 0) {
            $defaultCluster = $cluster;
            break;
        }
    }
    $modelId = (int) $defaultCluster['model_id'];
    $contrasts = nr_contrasts_for_model($pdo, $modelId);
    $preferredContrast = NR_DEFAULT_CONTRAST;
    $defaultContrast = count($contrasts) ? (string) $contrasts[0]['code'] : $preferredContrast;
    foreach ($contrasts as $contrast) {
        if ((string) $contrast['code'] === $preferredContrast || !empty($contrast['is_default'])) {
            $defaultContrast = (string) $contrast['code'];
            if ((string) $contrast['code'] === $preferredContrast) break;
        }
    }
    $preferredGene = NR_DEFAULT_GENE;
    $defaultGene = nr_default_gene_for_model($pdo, $modelId, $preferredGene);
    $ages = db_all($pdo, 'SELECT age_id AS id, label, role, sort_order FROM nr_ages ORDER BY sort_order, age_id');
    $schemaRows = db_all($pdo, 'SELECT key, value FROM nr_schema_info ORDER BY key');
    $schema = array();
    foreach ($schemaRows as $row) $schema[(string) $row['key']] = (string) $row['value'];

    return array(
        'available' => true,
        'schema_version' => NR_SCHEMA_VERSION,
        'database_file' => basename(database_filename('nonrhythmic')),
        'default_gene' => $defaultGene,
        'default_cluster' => (string) $defaultCluster['code'],
        'default_contrast' => $defaultContrast,
        'default_hypothesis' => 'zero',
        'default_threshold' => 0.5,
        'gene_count' => (int) db_scalar($pdo, 'SELECT COUNT(DISTINCT gene_id) FROM nr_wald_basis'),
        'model_count' => count($clusters),
        'clusters' => array_map(function (array $row): array {
            return array(
                'id' => (string) $row['code'],
                'label' => (string) $row['label'],
                'sort_order' => (int) $row['sort_order'],
                'model_id' => (int) $row['model_id'],
                'n_samples' => (int) $row['n_samples'],
                'n_genes' => (int) $row['n_genes'],
            );
        }, $clusters),
        'ages' => array_map(function (array $row): array {
            return array(
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'role' => (string) $row['role'],
                'sort_order' => (int) $row['sort_order'],
            );
        }, $ages),
        'contrasts' => $contrasts,
        'hypotheses' => array(
            array('id' => 'zero', 'label' => 'Effect differs from zero', 'requires_threshold' => false),
            array('id' => 'greater', 'label' => 'Effect is greater than +threshold', 'requires_threshold' => true),
            array('id' => 'less', 'label' => 'Effect is less than −threshold', 'requires_threshold' => true),
            array('id' => 'greaterAbs', 'label' => 'Absolute effect exceeds threshold', 'requires_threshold' => true),
            array('id' => 'lessAbs', 'label' => 'Equivalent within ±threshold', 'requires_threshold' => true),
        ),
        'analysis' => array(
            'design' => (string) ($schema['model'] ?? nr_setting($pdo, 'nonrhythmic_design', '~ sex + age + genotype + genotype:age + genotype:sex')),
            'expression_value' => (string) ($schema['expression_value'] ?? 'log2(size-factor-normalized count + 1)'),
            'dynamic_test_scope' => (string) ($schema['dynamic_test_scope'] ?? 'one-dimensional numeric Wald contrasts'),
        ),
    );
    } catch (Throwable $error) {
        return nr_unavailable_metadata('The non-rhythmic APP23 database is present but its metadata could not be read.');
    }
}

function nr_gene_search(string $query, int $limit, string $cluster = ''): array
{
    $pdo = open_database('nonrhythmic');
    if (!nr_available($pdo)) return array();
    $limit = max(1, min(500, $limit));
    $query = trim($query);
    $params = array();
    $join = ' JOIN nr_wald_basis w ON w.gene_id = g.gene_id ';
    $where = array();
    if ($cluster !== '') {
        $model = nr_cluster_model($pdo, $cluster);
        $where[] = 'w.model_id = :model_id';
        $params['model_id'] = (int) $model['model_id'];
    }
    if ($query !== '') {
        $where[] = 'g.symbol_upper LIKE :contains';
        $params['contains'] = '%' . strtoupper($query) . '%';
    }
    $sql = 'SELECT DISTINCT g.symbol, g.symbol_upper, g.sort_order FROM nr_genes g' . $join;
    if (count($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY '
        . ($query !== '' ? 'CASE WHEN g.symbol_upper = :exact THEN 0 WHEN g.symbol_upper LIKE :prefix THEN 1 ELSE 2 END, ' : '')
        . 'g.sort_order, g.symbol COLLATE NOCASE LIMIT :limit';
    $statement = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    if ($query !== '') {
        $statement->bindValue(':exact', strtoupper($query), PDO::PARAM_STR);
        $statement->bindValue(':prefix', strtoupper($query) . '%', PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map(function (array $row): string { return (string) $row['symbol']; }, $statement->fetchAll());
}

function nr_gene_row(PDO $pdo, string $input, int $modelId = 0): ?array
{
    $sql = 'SELECT g.gene_id, g.symbol FROM nr_genes g';
    $params = array('upper' => strtoupper(trim($input)));
    if ($modelId > 0) {
        $sql .= ' JOIN nr_wald_basis w ON w.gene_id = g.gene_id AND w.model_id = :model_id';
        $params['model_id'] = $modelId;
    }
    $sql .= ' WHERE g.symbol_upper = :upper LIMIT 1';
    return db_one($pdo, $sql, $params);
}

function nr_gene_resolve(string $input, int $limit = 25, string $cluster = ''): array
{
    $input = trim($input);
    $pdo = open_database('nonrhythmic');
    if (!nr_available($pdo)) return array('input' => $input, 'found' => false, 'gene' => null, 'suggestions' => array());
    $modelId = 0;
    if ($cluster !== '') $modelId = (int) nr_cluster_model($pdo, $cluster)['model_id'];
    $suggestions = nr_gene_search($input, $limit, $cluster);
    if ($input === '') return array('input' => '', 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    $row = nr_gene_row($pdo, $input, $modelId);
    if ($row === null) return array('input' => $input, 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    $gene = (string) $row['symbol'];
    if (!in_array($gene, $suggestions, true)) array_unshift($suggestions, $gene);
    return array(
        'input' => $input,
        'found' => true,
        'gene' => $gene,
        'gene_id' => (int) $row['gene_id'],
        'suggestions' => array_values(array_unique($suggestions)),
    );
}

function nr_parse_custom_contrast(string $raw): array
{
    $parts = explode(',', trim($raw));
    if (count($parts) !== 6) throw new ApiException('A custom contrast must contain exactly six comma-separated numbers.', 400);
    $vector = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || !is_numeric($part)) throw new ApiException('Every custom contrast entry must be numeric.', 400);
        $value = (float) $part;
        if (!is_finite($value) || abs($value) > 1000000) throw new ApiException('Custom contrast entries must be finite and reasonably scaled.', 400);
        $vector[] = abs($value) < 1.0e-14 ? 0.0 : $value;
    }
    $nonzero = array_filter($vector, function (float $value): bool { return abs($value) > 0.0; });
    if (!count($nonzero)) throw new ApiException('The custom contrast cannot be all zeros.', 400);
    return $vector;
}

function nr_resolve_contrast(PDO $pdo, int $modelId, string $code, string $customRaw): array
{
    if (trim($customRaw) !== '') {
        return array(
            'id' => null,
            'code' => 'custom',
            'label' => 'Custom numeric contrast',
            'family' => 'custom',
            'description' => 'User-supplied six-element numeric contrast.',
            'age_mode' => 'custom',
            'sex_mode' => 'custom',
            'weighting' => 'custom',
            'is_default' => false,
            'source' => 'custom',
            'vector' => nr_parse_custom_contrast($customRaw),
        );
    }
    $code = trim($code);
    if ($code === '') $code = NR_DEFAULT_CONTRAST;
    $row = db_one(
        $pdo,
        'SELECT contrast_id, code, label, family, description, age_mode, sex_mode, weighting, is_default, '
        . 'c0, c1, c2, c3, c4, c5 FROM nr_contrast_catalog '
        . 'WHERE model_id = :model_id AND code = :code LIMIT 1',
        array('model_id' => $modelId, 'code' => $code)
    );
    if ($row === null) throw new ApiException('Unknown contrast for this cluster: ' . $code . '.', 400);
    return array(
        'id' => (int) $row['contrast_id'],
        'code' => (string) $row['code'],
        'label' => (string) $row['label'],
        'family' => (string) $row['family'],
        'description' => (string) $row['description'],
        'age_mode' => (string) $row['age_mode'],
        'sex_mode' => (string) $row['sex_mode'],
        'weighting' => (string) $row['weighting'],
        'is_default' => (string) $row['code'] === NR_DEFAULT_CONTRAST,
        'source' => 'preset',
        'vector' => nr_vector_from_row($row, 'c'),
    );
}

function nr_normal_upper_tail(float $z): float
{
    if (!is_finite($z)) return $z > 0.0 ? 0.0 : 1.0;
    if ($z === 0.0) return 0.5;
    if ($z < 0.0) return 1.0 - nr_normal_upper_tail(-$z);

    // Stable Numerical Recipes erfc approximation. Calculating the upper tail
    // directly avoids cancellation for large positive Wald statistics.
    $scaled = $z / sqrt(2.0);
    $t = 1.0 / (1.0 + 0.5 * $scaled);
    $polynomial = 0.17087277;
    $polynomial = -0.82215223 + $t * $polynomial;
    $polynomial = 1.48851587 + $t * $polynomial;
    $polynomial = -1.13520398 + $t * $polynomial;
    $polynomial = 0.27886807 + $t * $polynomial;
    $polynomial = -0.18628806 + $t * $polynomial;
    $polynomial = 0.09678418 + $t * $polynomial;
    $polynomial = 0.37409196 + $t * $polynomial;
    $polynomial = 1.00002368 + $t * $polynomial;
    $erfc = $t * exp(-$scaled * $scaled - 1.26551223 + $t * $polynomial);
    return nr_probability(0.5 * $erfc);
}

function nr_normal_cdf(float $z): float
{
    return nr_probability(1.0 - nr_normal_upper_tail($z));
}

function nr_probability(float $value): float
{
    return max(0.0, min(1.0, $value));
}

function nr_hypothesis(string $hypothesis, float $threshold): array
{
    $allowed = array(
        'zero' => 'Effect differs from zero',
        'greater' => 'Effect is greater than +threshold',
        'less' => 'Effect is less than −threshold',
        'greaterAbs' => 'Absolute effect exceeds threshold',
        'lessAbs' => 'Equivalent within ±threshold',
    );
    if (!isset($allowed[$hypothesis])) throw new ApiException('Unknown Wald hypothesis.', 400, array_keys($allowed));
    if ($hypothesis === 'zero') $threshold = 0.0;
    elseif (!is_finite($threshold) || $threshold <= 0.0 || $threshold > 100.0) {
        throw new ApiException('LFC threshold must be greater than zero and no more than 100.', 400);
    }
    return array('id' => $hypothesis, 'label' => $allowed[$hypothesis], 'threshold' => $threshold);
}

function nr_wald_test(float $estimate, float $standardError, array $hypothesis): ?array
{
    if (!is_finite($estimate) || !is_finite($standardError) || $standardError <= 0.0) return null;
    $kind = (string) $hypothesis['id'];
    $threshold = (float) $hypothesis['threshold'];
    if ($kind === 'zero') {
        $stat = $estimate / $standardError;
        $pvalue = 2.0 * nr_normal_upper_tail(abs($stat));
    } elseif ($kind === 'greater') {
        $stat = ($estimate - $threshold) / $standardError;
        $pvalue = nr_normal_upper_tail($stat);
    } elseif ($kind === 'less') {
        $stat = ($estimate + $threshold) / $standardError;
        $pvalue = nr_normal_upper_tail(-$stat);
    } elseif ($kind === 'greaterAbs') {
        $evidence = max(0.0, (abs($estimate) - $threshold) / $standardError);
        $stat = ($estimate < 0.0 ? -1.0 : 1.0) * $evidence;
        $pvalue = 2.0 * nr_normal_upper_tail($evidence);
    } else {
        $lowerStat = ($estimate + $threshold) / $standardError;
        $upperStat = ($threshold - $estimate) / $standardError;
        $stat = min($lowerStat, $upperStat);
        $pvalue = max(nr_normal_upper_tail($lowerStat), nr_normal_upper_tail($upperStat));
    }
    if (!is_finite($stat) || !is_finite($pvalue)) return null;
    return array('stat' => $stat, 'pvalue' => nr_probability($pvalue));
}

function nr_adjust_bh(array $tests): array
{
    usort($tests, function (array $left, array $right): int {
        $cmp = $left['pvalue'] <=> $right['pvalue'];
        return $cmp !== 0 ? $cmp : ($left['gene_id'] <=> $right['gene_id']);
    });
    $count = count($tests);
    $running = 1.0;
    for ($index = $count - 1; $index >= 0; $index--) {
        $adjusted = min(1.0, ((float) $tests[$index]['pvalue']) * $count / ($index + 1));
        $running = min($running, $adjusted);
        $tests[$index]['padj'] = $running;
    }
    $byGene = array();
    foreach ($tests as $test) $byGene[(string) $test['gene_id']] = $test;
    return $byGene;
}

function nr_cache_file(PDO $pdo, int $modelId, array $vector, array $hypothesis): ?string
{
    $config = app_config();
    $cacheDir = (string) $config['cache_dir'] . DIRECTORY_SEPARATOR . 'nonrhythmic';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) return null;
    if (!is_writable($cacheDir)) return null;
    $dbPath = database_filename('nonrhythmic');
    $signature = array(
        'version' => NR_CACHE_VERSION,
        'database_mtime' => @filemtime($dbPath),
        'database_ctime' => @filectime($dbPath),
        'database_inode' => @fileinode($dbPath),
        'database_size' => @filesize($dbPath),
        'database_created_at' => (string) db_scalar($pdo, "SELECT value FROM nr_schema_info WHERE key = 'created_at' LIMIT 1"),
        'model_id' => $modelId,
        'vector' => array_map(function (float $value): string { return sprintf('%.17g', $value); }, $vector),
        'hypothesis' => $hypothesis,
    );
    return $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', json_encode($signature)) . '.json';
}

function nr_compute_wald(PDO $pdo, int $modelId, array $vector, array $hypothesis, bool $allowPersistentCache = false): array
{
    $cacheFile = $allowPersistentCache ? nr_cache_file($pdo, $modelId, $vector, $hypothesis) : null;
    if ($cacheFile !== null && is_file($cacheFile) && is_readable($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded) && ($decoded['cache_version'] ?? null) === NR_CACHE_VERSION && isset($decoded['results']) && is_array($decoded['results'])) {
            return $decoded;
        }
    }

    $betaColumns = array();
    $covarianceColumns = array();
    for ($index = 0; $index < 6; $index++) $betaColumns[] = 'beta' . $index;
    for ($i = 0; $i < 6; $i++) {
        for ($j = $i; $j < 6; $j++) $covarianceColumns[] = 'cov' . $i . $j;
    }
    $statement = $pdo->prepare(
        'SELECT w.gene_id, g.symbol AS gene, w.base_mean, w.dispersion, w.beta_converged, w.max_cooks, '
        . implode(', ', array_merge($betaColumns, $covarianceColumns))
        . ' FROM nr_wald_basis w JOIN nr_genes g ON g.gene_id = w.gene_id '
        . 'WHERE w.model_id = :model_id'
    );
    $statement->bindValue(':model_id', $modelId, PDO::PARAM_INT);
    $statement->execute();

    $tests = array();
    $invalidVarianceCount = 0;
    $untestableGeneCount = 0;
    while (($row = $statement->fetch()) !== false) {
        $betas = nr_vector_from_row($row, 'beta');
        $valid = true;
        foreach ($betas as $value) {
            if (!is_finite($value)) { $valid = false; break; }
        }
        if (!$valid) { $untestableGeneCount++; continue; }
        $estimate = 0.0;
        for ($i = 0; $i < 6; $i++) $estimate += $vector[$i] * $betas[$i];
        $variance = 0.0;
        for ($i = 0; $i < 6 && $valid; $i++) {
            for ($j = $i; $j < 6; $j++) {
                $key = 'cov' . $i . $j;
                if (!isset($row[$key]) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
                    $valid = false;
                    break;
                }
                $factor = $i === $j ? 1.0 : 2.0;
                $variance += $factor * $vector[$i] * $vector[$j] * (float) $row[$key];
            }
        }
        if (!$valid || !is_finite($estimate) || !is_finite($variance)) { $untestableGeneCount++; continue; }
        if ($variance < -NR_VARIANCE_TOLERANCE) {
            $invalidVarianceCount++;
            $untestableGeneCount++;
            continue;
        }
        if ($variance < 0.0) $variance = 0.0;
        $standardError = sqrt($variance);
        $test = nr_wald_test($estimate, $standardError, $hypothesis);
        if ($test === null) { $untestableGeneCount++; continue; }
        $tests[] = array(
            'gene_id' => (int) $row['gene_id'],
            'gene' => (string) $row['gene'],
            'log2FoldChange' => $estimate,
            'lfcSE' => $standardError,
            'stat' => (float) $test['stat'],
            'pvalue' => (float) $test['pvalue'],
            'baseMean' => isset($row['base_mean']) && is_numeric($row['base_mean']) ? (float) $row['base_mean'] : null,
            'dispersion' => isset($row['dispersion']) && is_numeric($row['dispersion']) ? (float) $row['dispersion'] : null,
            'betaConverged' => isset($row['beta_converged']) ? (bool) $row['beta_converged'] : null,
            'maxCooks' => isset($row['max_cooks']) && is_numeric($row['max_cooks']) ? (float) $row['max_cooks'] : null,
        );
    }
    $results = nr_adjust_bh($tests);
    $payload = array(
        'cache_version' => NR_CACHE_VERSION,
        'tested_gene_count' => count($results),
        'untestable_gene_count' => $untestableGeneCount,
        'invalid_variance_count' => $invalidVarianceCount,
        'results' => $results,
    );
    if ($cacheFile !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json !== false) {
            $temporary = $cacheFile . '.' . getmypid() . '.' . mt_rand(1000, 999999) . '.tmp';
            if (@file_put_contents($temporary, $json, LOCK_EX) !== false) @rename($temporary, $cacheFile);
            if (is_file($temporary)) @unlink($temporary);
        }
    }
    return $payload;
}

function nr_display_wald(array $result): array
{
    $result['log2FoldChange_display'] = format_metric_value($result['log2FoldChange'] ?? null, 4);
    $result['lfcSE_display'] = format_metric_value($result['lfcSE'] ?? null, 4);
    $result['stat_display'] = format_metric_value($result['stat'] ?? null, 4);
    $result['pvalue_display'] = format_small_number($result['pvalue'] ?? null);
    $result['padj_display'] = format_small_number($result['padj'] ?? null);
    $result['baseMean_display'] = format_metric_value($result['baseMean'] ?? null, 4);
    $result['dispersion_display'] = format_metric_value($result['dispersion'] ?? null, 4);
    $result['maxCooks_display'] = format_metric_value($result['maxCooks'] ?? null, 4);
    return $result;
}

function nr_public_cluster(array $model): array
{
    return array(
        'id' => (string) $model['code'],
        'label' => (string) $model['label'],
        'sort_order' => (int) $model['sort_order'],
    );
}

function nr_public_model(PDO $pdo, array $model): array
{
    $modelId = (int) $model['model_id'];
    $coefficients = db_all(
        $pdo,
        'SELECT coef_index, result_name, model_matrix_name, term_name FROM nr_model_coefficients '
        . 'WHERE model_id = :model_id ORDER BY coef_index',
        array('model_id' => $modelId)
    );
    $coefficients = array_map(function (array $row): array {
        return array(
            'index' => (int) $row['coef_index'],
            'result_name' => (string) $row['result_name'],
            'model_matrix_name' => (string) $row['model_matrix_name'],
            'term_name' => (string) $row['term_name'],
        );
    }, $coefficients);

    return array(
        'id' => $modelId,
        'design' => (string) $model['design'],
        'fit_type' => (string) $model['fit_type'],
        'references' => array(
            'age' => (string) $model['reference_age'],
            'sex' => (string) $model['reference_sex'],
            'genotype' => (string) $model['reference_genotype'],
        ),
        'comparisons' => array(
            'age' => (string) $model['comparison_age'],
            'sex' => (string) $model['comparison_sex'],
            'genotype' => (string) $model['comparison_genotype'],
        ),
        'n_samples' => (int) $model['n_samples'],
        'n_genes' => (int) $model['n_genes'],
        'n_coefficients' => (int) $model['n_coefficients'],
        'covariance_method' => (string) $model['covariance_method'],
        'coefficients' => $coefficients,
    );
}

function nr_expression_data(PDO $pdo, int $modelId, int $geneId): array
{
    $expressionRows = db_all(
        $pdo,
        'SELECT e.value, s.sample_key, s.sample, s.age_id, s.age_label, s.sex, s.genotype, s.time_label, s.zt '
        . 'FROM nr_expression e JOIN nr_samples s ON s.sample_id = e.sample_id '
        . 'WHERE e.gene_id = :gene_id AND s.model_id = :model_id '
        . 'ORDER BY s.age_id, s.sex, s.genotype, s.zt, s.sample',
        array('gene_id' => $geneId, 'model_id' => $modelId)
    );
    $expression = array_map(function (array $row): array {
        return array(
            'value' => (float) $row['value'],
            'sample_key' => (string) $row['sample_key'],
            'sample' => (string) $row['sample'],
            'age_id' => (int) $row['age_id'],
            'age' => (string) $row['age_label'],
            'sex' => (string) $row['sex'],
            'genotype' => (string) $row['genotype'],
            'time_label' => $row['time_label'] === null ? null : (string) $row['time_label'],
            'zt' => $row['zt'] === null ? null : (float) $row['zt'],
        );
    }, $expressionRows);

    $cellRows = db_all(
        $pdo,
        'SELECT cell_id, age_id, age_label, sex, genotype, sample_n, x0, x1, x2, x3, x4, x5 '
        . 'FROM nr_design_cells WHERE model_id = :model_id ORDER BY age_id, sex, genotype DESC',
        array('model_id' => $modelId)
    );
    $cells = array_map(function (array $row): array {
        return array(
            'id' => (int) $row['cell_id'],
            'age_id' => (int) $row['age_id'],
            'age' => (string) $row['age_label'],
            'sex' => (string) $row['sex'],
            'genotype' => (string) $row['genotype'],
            'sample_n' => (int) $row['sample_n'],
            'design' => nr_vector_from_row($row, 'x'),
        );
    }, $cellRows);

    $expressionLabel = (string) db_scalar($pdo, "SELECT value FROM nr_schema_info WHERE key = 'expression_value' LIMIT 1");
    if (trim($expressionLabel) === '') $expressionLabel = 'log2(size-factor-normalized count + 1)';

    return array(
        'expression' => $expression,
        'expression_count' => count($expression),
        'expression_label' => $expressionLabel,
        'cells' => $cells,
    );
}

function nr_results_payload(string $cluster, string $contrastCode, string $customContrast): array
{
    try {
        $pdo = open_database('nonrhythmic');
    } catch (Throwable $error) {
        return array('available' => false, 'message' => 'Non-rhythmic APP23 Wald database is not installed.', 'rows' => array());
    }
    if (!nr_available($pdo)) {
        return array('available' => false, 'message' => 'Non-rhythmic APP23 Wald database is unavailable or incompatible.', 'rows' => array());
    }

    $model = nr_cluster_model($pdo, $cluster);
    $modelId = (int) $model['model_id'];
    $contrast = nr_resolve_contrast($pdo, $modelId, $contrastCode, $customContrast);
    foreach ($contrast['vector'] as $value) {
        if (!is_finite((float) $value)) throw new ApiException('The stored contrast contains a non-finite value.', 500);
    }

    // The table is a single, conventional two-sided Wald analysis. The UI's
    // Log2FC threshold is a reporting filter and does not alter this null.
    $hypothesis = nr_hypothesis('zero', 0.0);
    $allowPersistentCache = $contrast['source'] === 'preset';
    $waldSet = nr_compute_wald($pdo, $modelId, $contrast['vector'], $hypothesis, $allowPersistentCache);
    $rows = array();
    foreach ($waldSet['results'] as $result) {
        $displayed = nr_display_wald($result);
        $rows[] = array(
            'gene_id' => (int) $result['gene_id'],
            'gene' => (string) $result['gene'],
            'log2FoldChange' => (float) $result['log2FoldChange'],
            'lfcSE' => (float) $result['lfcSE'],
            'stat' => (float) $result['stat'],
            'pvalue' => (float) $result['pvalue'],
            'padj' => (float) $result['padj'],
            'log2FoldChange_display' => (string) $displayed['log2FoldChange_display'],
            'lfcSE_display' => (string) $displayed['lfcSE_display'],
            'stat_display' => (string) $displayed['stat_display'],
            'pvalue_display' => (string) $displayed['pvalue_display'],
            'padj_display' => (string) $displayed['padj_display'],
        );
    }
    usort($rows, function (array $left, array $right): int {
        $cmp = $left['padj'] <=> $right['padj'];
        if ($cmp !== 0) return $cmp;
        $cmp = $left['pvalue'] <=> $right['pvalue'];
        if ($cmp !== 0) return $cmp;
        return strcasecmp((string) $left['gene'], (string) $right['gene']);
    });

    return array(
        'available' => true,
        'cluster' => nr_public_cluster($model),
        'model' => nr_public_model($pdo, $model),
        'contrast' => $contrast,
        'contrasts' => nr_contrasts_for_model($pdo, $modelId),
        'hypothesis' => $hypothesis,
        'rows' => $rows,
        'row_count' => count($rows),
        'tested_gene_count' => (int) $waldSet['tested_gene_count'],
        'untestable_gene_count' => (int) ($waldSet['untestable_gene_count'] ?? 0),
        'invalid_variance_count' => (int) $waldSet['invalid_variance_count'],
    );
}

function nr_expression_payload(string $gene, string $cluster): array
{
    try {
        $pdo = open_database('nonrhythmic');
    } catch (Throwable $error) {
        return array('available' => false, 'found' => false, 'message' => 'Non-rhythmic APP23 expression database is not installed.');
    }
    if (!nr_available($pdo)) {
        return array('available' => false, 'found' => false, 'message' => 'Non-rhythmic APP23 expression database is unavailable or incompatible.');
    }

    $model = nr_cluster_model($pdo, $cluster);
    $modelId = (int) $model['model_id'];
    $input = trim($gene);
    $resolved = $input === '' ? null : nr_gene_row($pdo, $input, $modelId);
    if ($resolved === null) {
        return array(
            'available' => true,
            'found' => false,
            'input' => $gene,
            'gene' => null,
            'cluster' => nr_public_cluster($model),
            'suggestions' => nr_gene_search($input, 25, (string) $model['code']),
        );
    }

    $data = nr_expression_data($pdo, $modelId, (int) $resolved['gene_id']);
    return array_merge(array(
        'available' => true,
        'found' => true,
        'input' => $gene,
        'gene' => (string) $resolved['symbol'],
        'gene_id' => (int) $resolved['gene_id'],
        'cluster' => nr_public_cluster($model),
        'model' => nr_public_model($pdo, $model),
    ), $data);
}

function nr_payload(string $gene, string $cluster, string $contrastCode, string $customContrast, string $hypothesisId, float $threshold): array
{
    try {
        $pdo = open_database('nonrhythmic');
    } catch (Throwable $error) {
        return array('available' => false, 'found' => false, 'message' => 'Non-rhythmic APP23 Wald database is not installed.');
    }
    if (!nr_available($pdo)) {
        return array('available' => false, 'found' => false, 'message' => 'Non-rhythmic APP23 Wald database is unavailable or incompatible.');
    }

    $model = nr_cluster_model($pdo, $cluster);
    $modelId = (int) $model['model_id'];
    $contrast = nr_resolve_contrast($pdo, $modelId, $contrastCode, $customContrast);
    foreach ($contrast['vector'] as $value) {
        if (!is_finite((float) $value)) throw new ApiException('The stored contrast contains a non-finite value.', 500);
    }
    $hypothesis = nr_hypothesis($hypothesisId, $threshold);
    $resolved = nr_gene_resolve($gene, 25, (string) $model['code']);
    $contrasts = nr_contrasts_for_model($pdo, $modelId);
    if (!$resolved['found']) {
        return array(
            'available' => true,
            'found' => false,
            'input' => $gene,
            'gene' => null,
            'cluster' => array('id' => (string) $model['code'], 'label' => (string) $model['label']),
            'contrast' => $contrast,
            'hypothesis' => $hypothesis,
            'contrasts' => $contrasts,
            'suggestions' => $resolved['suggestions'],
        );
    }

    $geneId = (int) $resolved['gene_id'];
    $allowPersistentCache = $contrast['source'] === 'preset' && $hypothesis['id'] === 'zero';
    $waldSet = nr_compute_wald($pdo, $modelId, $contrast['vector'], $hypothesis, $allowPersistentCache);
    $wald = $waldSet['results'][(string) $geneId] ?? null;
    if (is_array($wald)) $wald = nr_display_wald($wald);

    $expressionRows = db_all(
        $pdo,
        'SELECT e.value, s.sample_key, s.sample, s.age_id, s.age_label, s.sex, s.genotype, s.time_label, s.zt '
        . 'FROM nr_expression e JOIN nr_samples s ON s.sample_id = e.sample_id '
        . 'WHERE e.gene_id = :gene_id AND s.model_id = :model_id '
        . 'ORDER BY s.age_id, s.sex, s.genotype, s.zt, s.sample',
        array('gene_id' => $geneId, 'model_id' => $modelId)
    );
    $expression = array_map(function (array $row): array {
        return array(
            'value' => (float) $row['value'],
            'sample_key' => (string) $row['sample_key'],
            'sample' => (string) $row['sample'],
            'age_id' => (int) $row['age_id'],
            'age' => (string) $row['age_label'],
            'sex' => (string) $row['sex'],
            'genotype' => (string) $row['genotype'],
            'time_label' => $row['time_label'] === null ? null : (string) $row['time_label'],
            'zt' => $row['zt'] === null ? null : (float) $row['zt'],
        );
    }, $expressionRows);

    $cellRows = db_all(
        $pdo,
        'SELECT cell_id, age_id, age_label, sex, genotype, sample_n, x0, x1, x2, x3, x4, x5 '
        . 'FROM nr_design_cells WHERE model_id = :model_id ORDER BY age_id, sex, genotype DESC',
        array('model_id' => $modelId)
    );
    $cells = array_map(function (array $row): array {
        return array(
            'id' => (int) $row['cell_id'],
            'age_id' => (int) $row['age_id'],
            'age' => (string) $row['age_label'],
            'sex' => (string) $row['sex'],
            'genotype' => (string) $row['genotype'],
            'sample_n' => (int) $row['sample_n'],
            'design' => nr_vector_from_row($row, 'x'),
        );
    }, $cellRows);
    $coefficients = db_all(
        $pdo,
        'SELECT coef_index, result_name, model_matrix_name, term_name FROM nr_model_coefficients '
        . 'WHERE model_id = :model_id ORDER BY coef_index',
        array('model_id' => $modelId)
    );
    $coefficients = array_map(function (array $row): array {
        return array(
            'index' => (int) $row['coef_index'],
            'result_name' => (string) $row['result_name'],
            'model_matrix_name' => (string) $row['model_matrix_name'],
            'term_name' => (string) $row['term_name'],
        );
    }, $coefficients);
    $expressionLabel = (string) db_scalar($pdo, "SELECT value FROM nr_schema_info WHERE key = 'expression_value' LIMIT 1");
    if (trim($expressionLabel) === '') $expressionLabel = 'log2(size-factor-normalized count + 1)';

    return array(
        'available' => true,
        'found' => true,
        'testable' => $wald !== null,
        'input' => $gene,
        'gene' => (string) $resolved['gene'],
        'cluster' => array(
            'id' => (string) $model['code'],
            'label' => (string) $model['label'],
            'sort_order' => (int) $model['sort_order'],
        ),
        'model' => array(
            'id' => $modelId,
            'design' => (string) $model['design'],
            'fit_type' => (string) $model['fit_type'],
            'references' => array(
                'age' => (string) $model['reference_age'],
                'sex' => (string) $model['reference_sex'],
                'genotype' => (string) $model['reference_genotype'],
            ),
            'comparisons' => array(
                'age' => (string) $model['comparison_age'],
                'sex' => (string) $model['comparison_sex'],
                'genotype' => (string) $model['comparison_genotype'],
            ),
            'n_samples' => (int) $model['n_samples'],
            'n_genes' => (int) $model['n_genes'],
            'n_coefficients' => (int) $model['n_coefficients'],
            'covariance_method' => (string) $model['covariance_method'],
            'coefficients' => $coefficients,
        ),
        'contrast' => $contrast,
        'contrasts' => $contrasts,
        'hypothesis' => $hypothesis,
        'wald' => $wald,
        'tested_gene_count' => (int) $waldSet['tested_gene_count'],
        'untestable_gene_count' => (int) ($waldSet['untestable_gene_count'] ?? 0),
        'invalid_variance_count' => (int) $waldSet['invalid_variance_count'],
        'expression' => $expression,
        'expression_count' => count($expression),
        'expression_label' => $expressionLabel,
        'cells' => $cells,
    );
}
