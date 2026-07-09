<?php
/**
 * Query-string router for Apache/PHP hosting without rewrite rules.
 * Example: api/index.php?route=genes&q=Dbp
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/svg.php';
require_once __DIR__ . '/lib/diurnal.php';
require_once __DIR__ . '/lib/supplemental.php';
require_once __DIR__ . '/lib/dv.php';
require_once __DIR__ . '/lib/rostral_caudal.php';
require_once __DIR__ . '/lib/allen.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, OPTIONS');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(array('error' => array('status' => 405, 'message' => 'Method not allowed')), 405, array('Allow' => 'GET, OPTIONS'));
}

try {
    $route = request_route();

    if ($route === '' || $route === 'health') {
        $checks = array();
        $ok = true;
        foreach (array('diurnal', 'dv', 'supplemental', 'rostral_caudal') as $domain) {
            try {
                $pdo = open_database($domain);
                $quick = (string) db_scalar($pdo, 'PRAGMA quick_check');
                $checks[$domain] = array('ok' => strtolower($quick) === 'ok', 'file' => basename(database_filename($domain)));
                if ($domain === 'rostral_caudal') $checks[$domain]['available'] = rc_available($pdo);
                if (!$checks[$domain]['ok']) $ok = false;
            } catch (Throwable $error) {
                $checks[$domain] = array('ok' => false, 'message' => $error->getMessage());
                $ok = false;
            }
        }
        json_response(array(
            'status' => $ok ? 'ok' : 'degraded',
            'app' => 'Diurnal Brain Transcriptome Atlas',
            'backend' => 'PHP/SQLite',
            'php_version' => PHP_VERSION,
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'database_dir' => db_directory(),
            'databases' => $checks,
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
        ), $ok ? 200 : 503);
    }

    if ($route === 'metadata') {
        $metadata = diurnal_base_metadata();
        $metadata['rhythmicity'] = supplemental_metadata();
        $metadata['hippocampus_dv'] = dv_metadata();
        $metadata['rostral_caudal'] = rc_metadata();
        json_response($metadata);
    }

    if ($route === 'genes') {
        $query = request_string('q', '');
        $limit = request_int('limit', 100, 1, 500);
        $genes = diurnal_gene_search($query, $limit);
        if (request_string('format', 'object') === 'array') json_response($genes);
        json_response(array('query' => $query, 'count' => count($genes), 'genes' => $genes));
    }

    if ($route === 'genes/resolve') {
        $query = request_string('q', request_string('gene', ''));
        json_response(diurnal_gene_resolve($query, request_int('limit', 25, 1, 100)));
    }

    if ($route === 'plot.svg') {
        $pdo = open_database('diurnal');
        list($settings, $dims) = diurnal_filter_config($pdo);
        $filters = diurnal_filtered_values($dims, $settings);
        $gene = request_string('gene', isset($settings['default_gene']) ? (string) $settings['default_gene'] : 'Dbp');
        $colorBy = request_string('color_by', 'region');
        $splitBy = request_csv('split_by', array());
        $svg = diurnal_plot_svg($gene, $filters, $colorBy, $splitBy, request_int('width', 520, 420, 1800));
        $headers = array('Cache-Control' => 'public, max-age=' . (int) app_config()['plot_cache_seconds']);
        if (request_string('download', '') !== '') {
            $headers['Content-Disposition'] = 'attachment; filename="circadian_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $gene) . '.svg"';
        }
        text_response($svg, 'image/svg+xml; charset=utf-8', 200, $headers);
    }

    if ($route === 'plot.pdf') {
        // Kept for old bookmarks; the PHP deployment exports SVG instead of invoking an R PDF device.
        throw new ApiException('PDF generation is not available on the PHP deployment. Use Download SVG.', 501);
    }

    if ($route === 'spatial') {
        $gene = request_string('gene', 'Dbp');
        $gamma = request_float('gamma', 1.7, 0.5, 3.0);
        json_response(diurnal_spatial_payload($gene, $gamma));
    }

    if ($route === 'rhythmicity/genes') {
        $query = request_string('q', '');
        $limit = request_int('limit', 100, 1, 500);
        $genes = supplemental_gene_search($query, $limit);
        if (request_string('format', 'object') === 'array') json_response($genes);
        json_response(array('query' => $query, 'count' => count($genes), 'genes' => $genes));
    }

    if ($route === 'rhythmicity/genes/resolve') {
        json_response(supplemental_gene_resolve(request_string('q', request_string('gene', '')), request_int('limit', 25, 1, 100)));
    }

    if ($route === 'rhythmicity/basic') {
        $gene = request_string('gene', 'Dbp');
        $clusters = request_csv('clusters', request_csv('cluster', array()));
        $threshold = rhythm_safe_threshold(request_string('threshold', '0.1'));
        $limit = request_int('limit', 12, 1, 50);
        json_response(supplemental_basic_payload($gene, $clusters, $threshold, $limit));
    }

    if ($route === 'rhythmicity') {
        $gene = request_string('gene', 'Dbp');
        $threshold = rhythm_safe_threshold(request_string('threshold', '0.1'));
        $source = rhythm_source(request_string('source', 'all'));
        $limit = request_int('limit', 50, 1, min(5000, (int) app_config()['max_json_rows']));
        $cluster = request_string('cluster', '');
        json_response(supplemental_search_payload($gene, $threshold, $source, $limit, $cluster));
    }

    if ($route === 'rhythmicity.tsv') {
        $gene = request_string('gene', 'Dbp');
        $threshold = rhythm_safe_threshold(request_string('threshold', '0.1'));
        $source = rhythm_source(request_string('source', 'all'));
        $limit = request_int('limit', 5000, 1, 5000);
        $filename = 'rhythmicity_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $gene) . '.tsv';
        text_response(supplemental_tsv($gene, $threshold, $source, $limit), 'text/tab-separated-values; charset=utf-8', 200, array('Content-Disposition' => 'attachment; filename="' . $filename . '"'));
    }

    if ($route === 'hippocampus-dv/metadata') {
        json_response(dv_metadata());
    }

    if ($route === 'hippocampus-dv/genes') {
        $query = request_string('q', '');
        $limit = request_int('limit', 80, 1, 500);
        $genes = dv_gene_search($query, $limit);
        if (request_string('format', 'object') === 'array') json_response($genes);
        json_response(array('query' => $query, 'count' => count($genes), 'genes' => $genes));
    }

    if ($route === 'hippocampus-dv/genes/resolve') {
        json_response(dv_gene_resolve(request_string('q', request_string('gene', '')), request_int('limit', 25, 1, 100)));
    }

    if ($route === 'hippocampus-dv') {
        json_response(dv_payload(
            request_string('gene', 'Lct'),
            request_string('cluster', 'all'),
            request_string('split_by', 'none')
        ));
    }

    if ($route === 'hippocampus-dv/plot.svg') {
        $gene = request_string('gene', 'Lct');
        $svg = dv_plot_svg($gene, request_string('cluster', 'all'), request_string('split_by', 'none'), request_int('width', 780, 520, 1500));
        $headers = array('Cache-Control' => 'public, max-age=' . (int) app_config()['plot_cache_seconds']);
        if (request_string('download', '') !== '') {
            $headers['Content-Disposition'] = 'attachment; filename="dorsal_ventral_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $gene) . '.svg"';
        }
        text_response($svg, 'image/svg+xml; charset=utf-8', 200, $headers);
    }


    if ($route === 'rostral-caudal/metadata') {
        json_response(rc_metadata());
    }

    if ($route === 'rostral-caudal/genes') {
        $query = request_string('q', '');
        $limit = request_int('limit', 80, 1, 500);
        $genes = rc_gene_search($query, $limit);
        if (request_string('format', 'object') === 'array') json_response($genes);
        json_response(array('query' => $query, 'count' => count($genes), 'genes' => $genes));
    }

    if ($route === 'rostral-caudal/genes/resolve') {
        json_response(rc_gene_resolve(request_string('q', request_string('gene', '')), request_int('limit', 25, 1, 100)));
    }

    if ($route === 'rostral-caudal') {
        json_response(rc_payload(request_string('gene', 'Dbp'), request_string('cluster', 'L23')));
    }

    if ($route === 'rostral-caudal/plot.svg') {
        $gene = request_string('gene', 'Dbp');
        $svg = rc_plot_svg($gene, request_string('cluster', 'L23'), request_int('width', 860, 600, 1800));
        $headers = array('Cache-Control' => 'public, max-age=' . (int) app_config()['plot_cache_seconds']);
        if (request_string('download', '') !== '') {
            $headers['Content-Disposition'] = 'attachment; filename="rostral_caudal_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $gene) . '.svg"';
        }
        text_response($svg, 'image/svg+xml; charset=utf-8', 200, $headers);
    }

    if ($route === 'allen/ish') {
        json_response(allen_ish_payload(
            request_string('gene', 'Dbp'),
            request_string('view', 'ish'),
            request_int('downsample', 4, 1, 8),
            request_int('quality', 90, 1, 100)
        ));
    }

    if ($route === 'allen/ish/image') {
        $imageId = request_int('section_image_id', 0, 0, PHP_INT_MAX);
        if ($imageId <= 0) throw new ApiException('Missing or invalid Allen section_image_id.', 400);
        $view = allen_view(request_string('view', 'ish'));
        $downsample = request_int('downsample', 4, 1, 8);
        $quality = request_int('quality', 90, 1, 100);
        try {
            text_response(allen_cached_image_body($imageId, $view, $downsample, $quality), 'image/jpeg', 200, array('Cache-Control' => 'public, max-age=604800'));
        } catch (Throwable $imageError) {
            // If PHP cannot fetch/cache the image, let the browser load the Allen image
            // directly rather than showing an empty panel.
            header('Location: ' . allen_image_download_url($imageId, $view, $downsample, $quality), true, 302);
            exit;
        }
    }

    throw new ApiException('API route not found.', 404, $route);
} catch (Throwable $error) {
    $status = $error instanceof ApiException ? $error->status : 500;
    json_response(error_payload($error), $status);
}
