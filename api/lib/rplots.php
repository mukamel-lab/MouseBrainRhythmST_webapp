<?php
/**
 * ggplot2/ggh4x rendering bridge for the main diurnal expression plot.
 *
 * The public Brainome app remains PHP + SQLite. This file is only used by
 * api/index.php?route=plot.svg: PHP extracts a small plot-specific payload from
 * diurnal.sqlite, writes temporary TSV files, and calls Rscript so the SVG is
 * rendered with ggplot2/ggh4x in the same style as the original R backend.
 */

declare(strict_types=1);

function rplot_script_path(string $script): string
{
    $path = app_root() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        throw new ApiException('ggplot render script is missing.', 500, $path);
    }
    return $path;
}

function rplot_cache_dir(): string
{
    $dir = (string) app_config()['cache_dir'] . DIRECTORY_SEPARATOR . 'rplots';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new ApiException('R plot cache directory is not writable.', 500, $dir);
    }
    return $dir;
}

function rplot_temp_base_dir(): string
{
    $dir = rplot_cache_dir() . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new ApiException('R plot temporary directory is not writable.', 500, $dir);
    }
    return $dir;
}

function rplot_temp_dir(): string
{
    // Use an app-local temporary directory instead of /tmp. Some shared Apache
    // hosts restrict PHP/R subprocesses with open_basedir or cleanup policies,
    // and app-local temp files are easier to inspect when a plot fails.
    $base = rplot_temp_base_dir();
    $suffix = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : str_replace('.', '', uniqid('', true));
    $dir = $base . DIRECTORY_SEPARATOR . 'mbrhythm_rplot_' . $suffix;
    if (!mkdir($dir, 0775, true)) {
        throw new ApiException('Could not create temporary R plot directory.', 500, $dir);
    }
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
    if ($fh === false) {
        throw new ApiException('Could not write temporary TSV.', 500, $path);
    }
    fputcsv($fh, $columns, "\t");
    foreach ($rows as $row) {
        $line = array();
        foreach ($columns as $column) {
            $line[] = array_key_exists($column, $row) ? $row[$column] : '';
        }
        fputcsv($fh, $line, "\t");
    }
    fclose($fh);
}

function rplot_cache_file_for_key(string $cacheKey): string
{
    $safeKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $cacheKey);
    return rplot_cache_dir() . DIRECTORY_SEPARATOR . $safeKey . '.svg';
}

function rplot_worker_job_dir(): string
{
    $dir = rplot_cache_dir() . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new ApiException('R plot worker job directory is not writable.', 500, $dir);
    }
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
    } catch (Throwable $error) {
        return false;
    }
}

function rplot_write_job_kv(string $path, array $values): void
{
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new ApiException('Could not write R plot worker job file.', 500, $path);
    }
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
    $text = (string) @file_get_contents($path);
    return trim($text);
}

function rplot_run_script_with_worker(string $scriptName, array $args, string $cacheFile, int $timeout): string
{
    if (!rplot_worker_available()) {
        throw new ApiException('R plot worker is not running.', 503);
    }

    $jobsDir = rplot_worker_job_dir();
    $suffix = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : str_replace('.', '', uniqid('', true));
    $jobBase = $jobsDir . DIRECTORY_SEPARATOR . 'job_' . $suffix;
    $jobTmp = $jobBase . '.tmp';
    $jobFile = $jobBase . '.job';
    $doneFile = $jobBase . '.done';
    $errorFile = $jobBase . '.error';

    $payload = array_merge(array(
        'script' => $scriptName,
        'done_file' => $doneFile,
        'error_file' => $errorFile,
    ), $args);
    rplot_write_job_kv($jobTmp, $payload);
    if (!@rename($jobTmp, $jobFile)) {
        @unlink($jobTmp);
        throw new ApiException('Could not enqueue R plot worker job.', 500, $jobFile);
    }

    $deadline = microtime(true) + max(5, $timeout);
    while (microtime(true) < $deadline) {
        if (is_file($doneFile)) {
            if (!isset($args['out']) || !is_file((string) $args['out']) || filesize((string) $args['out']) <= 100) {
                $details = rplot_read_small_file($errorFile) ?: 'Worker marked job done but no SVG was produced.';
                throw new ApiException('R plot worker did not produce an SVG.', 500, $details);
            }
            $svg = (string) file_get_contents((string) $args['out']);
            @file_put_contents($cacheFile, $svg, LOCK_EX);
            @unlink($doneFile);
            @unlink($errorFile);
            @unlink($jobFile);
            @unlink($jobBase . '.running');
            return $svg;
        }
        if (is_file($errorFile)) {
            $details = rplot_read_small_file($errorFile);
            @unlink($doneFile);
            @unlink($errorFile);
            @unlink($jobFile);
            @unlink($jobBase . '.running');
            throw new ApiException('R plot worker failed.', 500, $details);
        }
        clearstatcache(false, $doneFile);
        clearstatcache(false, $errorFile);
        usleep(100000);
    }

    @unlink($jobFile);
    throw new ApiException('R plot worker timed out.', 500, 'Worker did not finish before timeout. Falling back to one-shot Rscript if available.');
}

function rplot_run_script_direct(string $scriptName, array $args, string $cacheFile, int $timeout): string
{
    if (!function_exists('proc_open')) {
        throw new ApiException('R ggplot rendering requires PHP proc_open.', 500);
    }

    $script = rplot_script_path($scriptName);
    $rscript = trim((string) (app_config()['rscript_path'] ?? getenv('RSCRIPT') ?: 'Rscript'));
    if ($rscript === '') $rscript = 'Rscript';

    $cmdParts = array(escapeshellarg($rscript), escapeshellarg($script));
    foreach ($args as $key => $value) {
        $cmdParts[] = escapeshellarg('--' . $key . '=' . (string) $value);
    }
    $cmd = implode(' ', $cmdParts);

    $pipes = array();
    // Make the R subprocess self-contained. Do not rely on Apache inheriting
    // an interactive user environment; point Rscript at the app-local R library
    // and app-local temp directory explicitly.
    $oldRLibsUser = getenv('R_LIBS_USER');
    $oldTmpDir = getenv('TMPDIR');
    $oldHome = getenv('HOME');
    $appRLib = app_root() . DIRECTORY_SEPARATOR . 'R-library';
    if (is_dir($appRLib)) {
        putenv('R_LIBS_USER=' . $appRLib);
    }
    putenv('TMPDIR=' . rplot_temp_base_dir());
    putenv('HOME=' . app_root());

    $proc = proc_open(
        $cmd,
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        app_root()
    );

    if ($oldRLibsUser === false) putenv('R_LIBS_USER'); else putenv('R_LIBS_USER=' . $oldRLibsUser);
    if ($oldTmpDir === false) putenv('TMPDIR'); else putenv('TMPDIR=' . $oldTmpDir);
    if ($oldHome === false) putenv('HOME'); else putenv('HOME=' . $oldHome);
    if (!is_resource($proc)) {
        throw new ApiException('Could not start Rscript for ggplot rendering.', 500);
    }

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
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) fclose($pipe);
    }
    $exit = proc_close($proc);

    if ($timedOut) {
        throw new ApiException('R ggplot rendering timed out.', 500, $stderr);
    }
    if ($exit !== 0) {
        throw new ApiException('R ggplot rendering failed.', 500, trim($stderr ?: $stdout));
    }

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
    if (is_file($cacheFile) && filesize($cacheFile) > 100) {
        return (string) file_get_contents($cacheFile);
    }

    $timeout = max(5, min(180, (int) (app_config()['r_plot_timeout_seconds'] ?? 45)));

    if (rplot_worker_available()) {
        try {
            return rplot_run_script_with_worker($scriptName, $args, $cacheFile, $timeout);
        } catch (ApiException $error) {
            // The resident worker is an optimization. If it is stale or fails,
            // keep the app usable by falling back to a one-shot Rscript call.
            // Real R errors from the direct fallback will still be surfaced.
        }
    }

    return rplot_run_script_direct($scriptName, $args, $cacheFile, $timeout);
}

function rplot_label_for_variable(array $row, string $variable): string
{
    return diurnal_row_label($row, $variable);
}

function diurnal_plot_ggplot_svg(string $gene, array $filters, string $colorBy, array $splitBy, int $width = 760): string
{
    $pdo = open_database('diurnal');
    $resolved = require_diurnal_gene($pdo, $gene);
    $points = diurnal_points($pdo, (int) $resolved['gene_id'], $filters);
    $coefficients = diurnal_coefficients($pdo, (int) $resolved['gene_id'], $filters);
    if (!count($points)) {
        throw new ApiException('No data for current filters.', 404, $resolved['gene']);
    }

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

        $out = array(
            'ZT' => (float) $row['zt'],
            'norm_expr' => (float) $row['value'],
            'color_key' => $group['color_key'],
            'color_label' => $group['color_label'],
            'sample_key' => (string) $row['sample_key'],
        );
        foreach ($allVariables as $variable) {
            $out[$variable] = rplot_label_for_variable($row, $variable);
        }
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
            $prediction = (float) $row['intercept']
                + (float) $row['sin_coef'] * sin($phase)
                + (float) $row['cos_coef'] * cos($phase);
            $key = $group['facet_key'] . "\x1e" . $group['color_key'] . "\x1e" . $i;
            if (!isset($lineAgg[$key])) {
                $entry = array(
                    'ZT' => $time,
                    'color_key' => $group['color_key'],
                    'color_label' => $group['color_label'],
                    'weighted_sum' => 0.0,
                    'weight' => 0,
                );
                foreach ($allVariables as $variable) {
                    $entry[$variable] = rplot_label_for_variable($row, $variable);
                }
                $lineAgg[$key] = $entry;
            }
            $lineAgg[$key]['weighted_sum'] += $prediction * $weight;
            $lineAgg[$key]['weight'] += $weight;
        }
    }

    $predRows = array();
    foreach ($lineAgg as $row) {
        if ($row['weight'] <= 0) continue;
        $out = array(
            'ZT' => $row['ZT'],
            'pred_expr' => $row['weighted_sum'] / $row['weight'],
            'color_key' => $row['color_key'],
            'color_label' => $row['color_label'],
        );
        foreach ($allVariables as $variable) {
            $out[$variable] = $row[$variable] ?? '';
        }
        $predRows[] = $out;
    }

    if (!count($obsRows) || !count($predRows)) {
        throw new ApiException('No finite rows for ggplot rendering.', 404);
    }

    $tmp = rplot_temp_dir();
    try {
        $obsPath = $tmp . DIRECTORY_SEPARATOR . 'obs.tsv';
        $predPath = $tmp . DIRECTORY_SEPARATOR . 'pred.tsv';
        $colorsPath = $tmp . DIRECTORY_SEPARATOR . 'colors.tsv';
        $outPath = $tmp . DIRECTORY_SEPARATOR . 'plot.svg';
        $columnsObs = array('ZT', 'norm_expr', 'color_key', 'color_label', 'sample_key', 'region', 'age', 'sex', 'genotype');
        $columnsPred = array('ZT', 'pred_expr', 'color_key', 'color_label', 'region', 'age', 'sex', 'genotype');
        rplot_write_tsv($obsPath, $columnsObs, $obsRows);
        rplot_write_tsv($predPath, $columnsPred, $predRows);
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
        $widthIn = max(4.3, min(7.2, $width / 120.0));
        $heightIn = $facetCount > 1 ? max(3.6, $widthIn * 0.82) : max(3.25, $widthIn * 0.74);
        $cacheKey = 'diurnal-ggplot-' . md5(json_encode(array(
            'gene' => $resolved['gene'],
            'filters' => $filters,
            'color_by' => $colorBy,
            'split_by' => $splitBy,
            'width' => $width,
            'db_mtime' => @filemtime(database_filename('diurnal')),
            'script_mtime' => @filemtime(rplot_script_path('render_diurnal_ggplot.R')),
            'core_mtime' => @filemtime(rplot_script_path('diurnal_ggplot_core.R')),
        )));

        return rplot_run_script('render_diurnal_ggplot.R', array(
            'obs' => $obsPath,
            'pred' => $predPath,
            'colors' => $colorsPath,
            'out' => $outPath,
            'gene' => $resolved['gene'],
            'color_name' => diurnal_variable_label($colorBy),
            'split_by' => implode(',', $splitBy),
            'x_label' => 'Zeitgeber Time (double plotted)',
            'y_label' => 'log2 Normalized mRNA Expression',
            'width' => sprintf('%.3f', $widthIn),
            'height' => sprintf('%.3f', $heightIn),
        ), $cacheKey);
    } finally {
        rplot_rm_rf($tmp);
    }
}
