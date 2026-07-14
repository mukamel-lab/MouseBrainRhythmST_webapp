<?php
/**
 * R/ggplot rendering bridge for selected SVG plot endpoints.
 *
 * The app remains PHP + SQLite. PHP extracts small, plot-specific TSV payloads
 * and either sends them to a resident local R worker or falls back to a one-shot
 * Rscript call. ggplot2 + ggh4x are loaded by the R worker once; SVGs are produced with grDevices::svg to match the original R app.
 */

declare(strict_types=1);

function rplot_script_path(string $script): string
{
    $path = app_root() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) throw new ApiException('ggplot render script is missing.', 500, $path);
    return $path;
}

function rplot_cache_dir(): string
{
    $dir = (string) app_config()['cache_dir'] . DIRECTORY_SEPARATOR . 'rplots';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) throw new ApiException('R plot cache directory is not writable.', 500, $dir);
    return $dir;
}

function rplot_temp_base_dir(): string
{
    $dir = rplot_cache_dir() . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) throw new ApiException('R plot temporary directory is not writable.', 500, $dir);
    return $dir;
}

function rplot_temp_dir(): string
{
    $base = rplot_temp_base_dir();
    $suffix = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : str_replace('.', '', uniqid('', true));
    $dir = $base . DIRECTORY_SEPARATOR . 'mbrhythm_rplot_' . $suffix;
    if (!mkdir($dir, 0777, true)) throw new ApiException('Could not create temporary R plot directory.', 500, $dir);
    @chmod($dir, 0777);
    return $dir;
}

function rplot_rm_rf(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) rplot_rm_rf($full);
        else @unlink($full);
    }
    @rmdir($path);
}

function rplot_write_tsv(string $path, array $columns, array $rows): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) throw new ApiException('Could not write temporary TSV.', 500, $path);
    fputcsv($fh, $columns, "\t");
    foreach ($rows as $row) {
        $line = array();
        foreach ($columns as $column) $line[] = array_key_exists($column, $row) ? $row[$column] : '';
        fputcsv($fh, $line, "\t");
    }
    fclose($fh);
    @chmod($path, 0666);
}

function rplot_cache_file_for_key(string $cacheKey): string
{
    $safeKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $cacheKey);
    return rplot_cache_dir() . DIRECTORY_SEPARATOR . $safeKey . '.svg';
}

function rplot_worker_job_dir(): string
{
    $dir = rplot_cache_dir() . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) throw new ApiException('R plot worker job directory is not writable.', 500, $dir);
    return $dir;
}

function rplot_worker_heartbeat_file(): string
{
    return rplot_cache_dir() . DIRECTORY_SEPARATOR . 'worker.heartbeat';
}

function rplot_worker_available(): bool
{
    $heartbeat = rplot_worker_heartbeat_file();
    if (!is_file($heartbeat)) return false;
    $age = time() - (int) @filemtime($heartbeat);
    if ($age < 0 || $age > 30) return false;
    try {
        rplot_worker_job_dir();
        return true;
    } catch (Throwable $ignored) {
        return false;
    }
}

function rplot_write_job_kv(string $path, array $values): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) throw new ApiException('Could not write R plot worker job file.', 500, $path);
    foreach ($values as $key => $value) {
        $key = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $key);
        $value = str_replace(array("\r", "\n", "\t"), ' ', (string) $value);
        fwrite($fh, $key . "\t" . $value . "\n");
    }
    fclose($fh);
}

function rplot_read_small_file(string $path): string
{
    if (!is_file($path)) return '';
    return trim((string) @file_get_contents($path));
}

function rplot_environment(): array
{
    $env = $_ENV;
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) && is_scalar($value)) {
            $env[$key] = (string) $value;
        }
    }
    $appRLib = app_root() . DIRECTORY_SEPARATOR . 'R-library';
    if (is_dir($appRLib)) $env['R_LIBS_USER'] = $appRLib;
    $env['TMPDIR'] = rplot_temp_base_dir();
    $env['HOME'] = app_root();
    return $env;
}

function rplot_run_script_with_worker(string $scriptName, array $args, string $cacheFile, int $timeout): string
{
    if (!rplot_worker_available()) throw new ApiException('R plot worker is not running.', 503);

    $jobsDir = rplot_worker_job_dir();
    $suffix = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : str_replace('.', '', uniqid('', true));
    $jobBase = $jobsDir . DIRECTORY_SEPARATOR . 'job_' . $suffix;
    $jobTmp = $jobBase . '.tmp';
    $jobFile = $jobBase . '.job';
    $doneFile = $jobBase . '.done';
    $errorFile = $jobBase . '.error';

    $payload = array_merge(array('script' => $scriptName, 'done_file' => $doneFile, 'error_file' => $errorFile), $args);
    rplot_write_job_kv($jobTmp, $payload);
    if (!@rename($jobTmp, $jobFile)) {
        @unlink($jobTmp);
        throw new ApiException('Could not enqueue R plot worker job.', 500, $jobFile);
    }

    $deadline = microtime(true) + max(5, $timeout);
    while (microtime(true) < $deadline) {
        if (is_file($doneFile)) {
            $out = isset($args['out']) ? (string) $args['out'] : '';
            if ($out === '' || !is_file($out) || filesize($out) <= 100) {
                $details = rplot_read_small_file($errorFile) ?: 'Worker marked job done but no SVG was produced.';
                throw new ApiException('R plot worker did not produce an SVG.', 500, $details);
            }
            $svg = (string) file_get_contents($out);
            @file_put_contents($cacheFile, $svg, LOCK_EX);
            @unlink($doneFile); @unlink($errorFile); @unlink($jobFile); @unlink($jobBase . '.running');
            return $svg;
        }
        if (is_file($errorFile)) {
            $details = rplot_read_small_file($errorFile);
            @unlink($doneFile); @unlink($errorFile); @unlink($jobFile); @unlink($jobBase . '.running');
            throw new ApiException('R plot worker failed.', 500, $details);
        }
        clearstatcache(false, $doneFile);
        clearstatcache(false, $errorFile);
        usleep(100000);
    }
    @unlink($jobFile);
    throw new ApiException('R plot worker timed out.', 500, 'Worker did not finish before timeout.');
}

function rplot_run_script_direct(string $scriptName, array $args, string $cacheFile, int $timeout): string
{
    if (!function_exists('proc_open')) throw new ApiException('R ggplot rendering requires PHP proc_open.', 500);
    $script = rplot_script_path($scriptName);
    $rscript = trim((string) (app_config()['rscript_path'] ?? getenv('RSCRIPT') ?: 'Rscript'));
    if ($rscript === '') $rscript = 'Rscript';

    $cmdParts = array(escapeshellarg($rscript), escapeshellarg($script));
    foreach ($args as $key => $value) $cmdParts[] = escapeshellarg('--' . $key . '=' . (string) $value);
    $cmd = implode(' ', $cmdParts);

    $pipes = array();
    $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, app_root(), rplot_environment());
    if (!is_resource($proc)) throw new ApiException('Could not start Rscript for ggplot rendering.', 500);

    $start = time();
    $timedOut = false;
    do {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (time() - $start > $timeout) {
            $timedOut = true;
            proc_terminate($proc);
            break;
        }
        usleep(100000);
    } while (true);

    $stdout = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $stderr = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    $exit = proc_close($proc);

    if ($timedOut) throw new ApiException('R ggplot rendering timed out.', 500, $stderr);
    if ($exit !== 0) throw new ApiException('R ggplot rendering failed.', 500, trim($stderr ?: $stdout));

    $out = isset($args['out']) ? (string) $args['out'] : '';
    if ($out === '' || !is_file($out) || filesize($out) <= 100) {
        throw new ApiException('R ggplot renderer did not produce an SVG.', 500, $out . "\n" . $stderr);
    }
    $svg = (string) file_get_contents($out);
    @file_put_contents($cacheFile, $svg, LOCK_EX);
    return $svg;
}

function rplot_run_script(string $scriptName, array $args, string $cacheKey): string
{
    $cacheFile = rplot_cache_file_for_key($cacheKey);
    if (is_file($cacheFile) && filesize($cacheFile) > 100) return (string) file_get_contents($cacheFile);

    $timeout = max(5, min(180, (int) (app_config()['r_plot_timeout_seconds'] ?? 45)));
    if (rplot_worker_available()) {
        try {
            return rplot_run_script_with_worker($scriptName, $args, $cacheFile, $timeout);
        } catch (ApiException $e) {
            // A live worker should normally be faster, but a stale/broken worker should
            // not break plotting. Fall back to the one-shot Rscript path and include the
            // worker error only if that path also fails.
            try {
                return rplot_run_script_direct($scriptName, $args, $cacheFile, $timeout);
            } catch (ApiException $direct) {
                throw new ApiException($direct->getMessage(), 500, 'Worker error: ' . (string) ($e->details ?? $e->getMessage()) . "\nDirect Rscript error: " . (string) ($direct->details ?? $direct->getMessage()));
            }
        }
    }
    return rplot_run_script_direct($scriptName, $args, $cacheFile, $timeout);
}

function rplot_display_label(array $row, string $variable): string
{
    return diurnal_row_label($row, $variable);
}

function diurnal_plot_ggplot_svg(string $gene, array $filters, string $colorBy, array $splitBy, int $width = 520): string
{
    $pdo = open_database('diurnal');
    $resolved = require_diurnal_gene($pdo, $gene);
    $points = diurnal_points($pdo, (int) $resolved['gene_id'], $filters);
    $coefficients = diurnal_coefficients($pdo, (int) $resolved['gene_id'], $filters);
    if (!count($points)) throw new ApiException('No data for current filters.', 404, $resolved['gene']);

    $validVariables = array('region', 'age', 'sex', 'genotype');
    if (!in_array($colorBy, $validVariables, true)) $colorBy = 'region';
    $splitBy = array_values(array_unique(array_intersect($splitBy, $validVariables)));

    $obsRows = array();
    $colors = array();
    $allVariables = array('region', 'age', 'sex', 'genotype');
    foreach ($points as $row) {
        if (!is_numeric($row['value']) || !is_numeric($row['zt'])) continue;
        $group = diurnal_group_key($row, $colorBy, $splitBy);
        $colors[$group['color_key']] = array('color_label' => $group['color_label'], 'color' => $group['color']);
        $out = array('ZT' => (float) $row['zt'], 'norm_expr' => (float) $row['value'], 'color_key' => $group['color_key'], 'color_label' => $group['color_label'], 'sample_key' => (string) $row['sample_key']);
        foreach ($allVariables as $variable) $out[$variable] = rplot_display_label($row, $variable);
        $obsRows[] = $out;
    }

    $lineAgg = array();
    $timeCount = 120;
    foreach ($coefficients as $row) {
        $group = diurnal_group_key($row, $colorBy, $splitBy);
        $colors[$group['color_key']] = array('color_label' => $group['color_label'], 'color' => $group['color']);
        $weight = max(1, (int) $row['n_samples']);
        for ($i = 0; $i < $timeCount; $i++) {
            $time = 42.0 * $i / ($timeCount - 1);
            $phase = fmod($time, 24.0) * 2.0 * M_PI / 24.0;
            $prediction = (float) $row['intercept'] + (float) $row['sin_coef'] * sin($phase) + (float) $row['cos_coef'] * cos($phase);
            $key = $group['facet_key'] . "\x1e" . $group['color_key'] . "\x1e" . $i;
            if (!isset($lineAgg[$key])) {
                $entry = array('ZT' => $time, 'color_key' => $group['color_key'], 'color_label' => $group['color_label'], 'weighted_sum' => 0.0, 'weight' => 0);
                foreach ($allVariables as $variable) $entry[$variable] = rplot_display_label($row, $variable);
                $lineAgg[$key] = $entry;
            }
            $lineAgg[$key]['weighted_sum'] += $prediction * $weight;
            $lineAgg[$key]['weight'] += $weight;
        }
    }

    $predRows = array();
    foreach ($lineAgg as $row) {
        if ($row['weight'] <= 0) continue;
        $out = array('ZT' => $row['ZT'], 'pred_expr' => $row['weighted_sum'] / $row['weight'], 'color_key' => $row['color_key'], 'color_label' => $row['color_label']);
        foreach ($allVariables as $variable) $out[$variable] = $row[$variable] ?? '';
        $predRows[] = $out;
    }
    if (!count($obsRows) || !count($predRows)) throw new ApiException('No finite rows for ggplot rendering.', 404);

    $tmp = rplot_temp_dir();
    try {
        $obsPath = $tmp . DIRECTORY_SEPARATOR . 'obs.tsv';
        $predPath = $tmp . DIRECTORY_SEPARATOR . 'pred.tsv';
        $colorsPath = $tmp . DIRECTORY_SEPARATOR . 'colors.tsv';
        $outPath = $tmp . DIRECTORY_SEPARATOR . 'plot.svg';
        rplot_write_tsv($obsPath, array('ZT', 'norm_expr', 'color_key', 'color_label', 'sample_key', 'region', 'age', 'sex', 'genotype'), $obsRows);
        rplot_write_tsv($predPath, array('ZT', 'pred_expr', 'color_key', 'color_label', 'region', 'age', 'sex', 'genotype'), $predRows);
        rplot_write_tsv($colorsPath, array('color_label', 'color'), array_values($colors));

        $facetCount = 1;
        if (count($splitBy)) {
            $seen = array();
            foreach ($obsRows as $row) {
                $parts = array();
                foreach ($splitBy as $variable) $parts[] = (string) ($row[$variable] ?? '');
                $seen[implode("\x1f", $parts)] = true;
            }
            $facetCount = max(1, count($seen));
        }
        // Match the original R/httpuv app device size: render at 7 x 5 inches
        // and let the browser/CSS scale the SVG display size. This preserves the
        // ggplot text/layout proportions from the original app.
        $widthIn = 7.0;
        $heightIn = 5.0;
        $cacheKey = 'diurnal-ggplot-grdevices-' . md5(json_encode(array('gene' => $resolved['gene'], 'filters' => $filters, 'color_by' => $colorBy, 'split_by' => $splitBy, 'width' => $width, 'device_width' => $widthIn, 'device_height' => $heightIn, 'db_mtime' => @filemtime(database_filename('diurnal')), 'rplot_mtime' => @filemtime(__FILE__), 'core_mtime' => @filemtime(rplot_script_path('ggplot_render_core.R')))));

        return rplot_run_script('render_diurnal_ggplot.R', array(
            'obs' => $obsPath, 'pred' => $predPath, 'colors' => $colorsPath, 'out' => $outPath,
            'gene' => $resolved['gene'], 'color_name' => diurnal_variable_label($colorBy), 'split_by' => implode(',', $splitBy),
            'x_label' => 'Zeitgeber Time (double plotted)', 'y_label' => 'log2 Normalized mRNA Expression',
            'width' => sprintf('%.3f', $widthIn), 'height' => sprintf('%.3f', $heightIn),
        ), $cacheKey);
    } finally {
        rplot_rm_rf($tmp);
    }
}

function dv_plot_ggplot_svg(string $gene, string $cluster, string $splitBy, int $width = 780): string
{
    $pdo = open_database('dv');
    $resolved = resolve_gene_table($pdo, $gene, 25);
    if (!$resolved['found']) throw new ApiException('No D/V data for ' . $gene, 404);
    $cluster = dv_resolve_cluster($pdo, $cluster);
    $splitBy = dv_split_value($splitBy);
    $rows = dv_plot_points($pdo, (int) $resolved['gene_id'], $cluster);
    if (!count($rows)) throw new ApiException('No D/V expression points.', 404, $resolved['gene']);

    $clustersSeen = array();
    foreach ($rows as $row) $clustersSeen[(string) $row['cluster']] = true;
    $multipleClusters = count($clustersSeen) > 1;
    $obsRows = array();
    $facetSeen = array();
    foreach ($rows as $row) {
        if (!is_numeric($row['value'])) continue;
        $region = dv_normalize_region((string) $row['dv_region']);
        if (!in_array($region, array('Dorsal', 'Ventral'), true)) continue;
        $facet = dv_facet_info($row, $splitBy, $multipleClusters);
        $facetSeen[$facet['key']] = true;
        $obsRows[] = array(
            'value' => (float) $row['value'],
            'dv_region' => $region,
            'facet_key' => $facet['key'],
            'facet_label' => $facet['label'],
            'jitter_key' => (string) $row['source_id'] . '|' . (string) $row['observation_id'],
        );
    }
    if (!count($obsRows)) throw new ApiException('No finite D/V expression values.', 404);
    $tmp = rplot_temp_dir();
    try {
        $obsPath = $tmp . DIRECTORY_SEPARATOR . 'dv_obs.tsv';
        $outPath = $tmp . DIRECTORY_SEPARATOR . 'dv_plot.svg';
        rplot_write_tsv($obsPath, array('value', 'dv_region', 'facet_key', 'facet_label', 'jitter_key'), $obsRows);
        $facetCount = max(1, count($facetSeen));
        $widthIn = max(5.0, min(7.0, $width / 120.0));
        $heightIn = $facetCount > 1 ? max(3.8, $widthIn * 0.78) : 3.6;
        $cacheKey = 'dv-ggplot-' . md5(json_encode(array('gene' => $resolved['gene'], 'cluster' => $cluster, 'split_by' => $splitBy, 'width' => $width, 'db_mtime' => @filemtime(database_filename('dv')), 'core_mtime' => @filemtime(rplot_script_path('ggplot_render_core.R')))));
        return rplot_run_script('render_dv_ggplot.R', array('obs' => $obsPath, 'out' => $outPath, 'gene' => $resolved['gene'], 'subtitle' => 'WT only; display split: ' . dv_split_label($splitBy), 'width' => sprintf('%.3f', $widthIn), 'height' => sprintf('%.3f', $heightIn)), $cacheKey);
    } finally {
        rplot_rm_rf($tmp);
    }
}

function rc_plot_ggplot_svg(string $gene, string $cluster, int $width = 760): string
{
    $dataset = rc_plot_dataset($gene, $cluster);
    if (empty($dataset['available'])) throw new ApiException('Rostral-caudal rhythmicity database is not installed.', 503);
    if (empty($dataset['found'])) throw new ApiException('No rostral-caudal data for ' . $gene, 404);
    $plot = $dataset['plot'] ?? array();
    $regions = $plot['regions'] ?? array();
    $points = $plot['points'] ?? array();
    $summaries = $plot['summaries'] ?? array();
    $curves = $plot['curves'] ?? array();
    if (!count($summaries) && !count($curves)) throw new ApiException('No rostral-caudal plot rows.', 404);

    $regionRows = array();
    foreach ($regions as $region) {
        $regionRows[] = array('region' => (string) ($region['id'] ?? ''), 'label' => (string) ($region['label'] ?? ''), 'color' => (string) ($region['color'] ?? '#2563eb'));
    }
    $pointRows = array();
    foreach ($points as $point) {
        $x = isset($point['x']) ? (float) $point['x'] : null;
        if ($x === null || $x > 24.001) continue;
        $pointRows[] = array('region' => (string) $point['region'], 'time' => $x, 'value' => (float) $point['y'], 'sample_key' => (string) ($point['sample_key'] ?? ''));
    }
    $summaryRows = array();
    foreach ($summaries as $summary) {
        $x = isset($summary['x']) ? (float) $summary['x'] : null;
        if ($x === null || $x > 24.001) continue;
        $summaryRows[] = array('region' => (string) $summary['region'], 'time' => $x, 'mean' => (float) $summary['mean'], 'sd' => (float) ($summary['sd'] ?? 0));
    }
    $curveRows = array();
    foreach ($curves as $curve) {
        $region = (string) ($curve['region'] ?? '');
        foreach (($curve['points'] ?? array()) as $point) {
            $curveRows[] = array('region' => $region, 'time' => (float) $point['x'], 'value' => (float) $point['y']);
        }
    }

    $tmp = rplot_temp_dir();
    try {
        $regionsPath = $tmp . DIRECTORY_SEPARATOR . 'rc_regions.tsv';
        $pointsPath = $tmp . DIRECTORY_SEPARATOR . 'rc_points.tsv';
        $summaryPath = $tmp . DIRECTORY_SEPARATOR . 'rc_summary.tsv';
        $curvesPath = $tmp . DIRECTORY_SEPARATOR . 'rc_curves.tsv';
        $outPath = $tmp . DIRECTORY_SEPARATOR . 'rc_plot.svg';
        rplot_write_tsv($regionsPath, array('region', 'label', 'color'), $regionRows);
        rplot_write_tsv($pointsPath, array('region', 'time', 'value', 'sample_key'), $pointRows);
        rplot_write_tsv($summaryPath, array('region', 'time', 'mean', 'sd'), $summaryRows);
        rplot_write_tsv($curvesPath, array('region', 'time', 'value'), $curveRows);
        $widthIn = max(5.6, min(7.4, $width / 120.0));
        $heightIn = max(3.8, $widthIn * 0.72);
        $cacheKey = 'rc-ggplot-' . md5(json_encode(array('gene' => $dataset['gene'], 'cluster' => $dataset['cluster'], 'width' => $width, 'db_mtime' => @filemtime(database_filename('rostral_caudal')), 'core_mtime' => @filemtime(rplot_script_path('ggplot_render_core.R')))));
        return rplot_run_script('render_rostral_caudal_ggplot.R', array('regions' => $regionsPath, 'points' => $pointsPath, 'summaries' => $summaryPath, 'curves' => $curvesPath, 'out' => $outPath, 'gene' => (string) $dataset['gene'], 'subtitle' => (string) ($dataset['cluster_label'] ?? $dataset['cluster'] ?? ''), 'width' => sprintf('%.3f', $widthIn), 'height' => sprintf('%.3f', $heightIn)), $cacheKey);
    } finally {
        rplot_rm_rf($tmp);
    }
}
