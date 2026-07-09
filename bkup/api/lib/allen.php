<?php

declare(strict_types=1);

function allen_cut_config(): array
{
    return array(
        'cut_id' => 'visium_sagittal',
        'label' => 'Matched sagittal in situ hybridization',
        'plane' => 'sagittal',
        'atlas_id' => 2,
        'atlas_section_ordinal' => 7,
        'atlas_image_type' => 'Atlas - Adult Mouse',
        'reference_space_id' => 10,
        'viewer_x' => 3096,
        'viewer_y' => 2048,
        'viewer_z' => 1,
    );
}

function allen_api_base(): string
{
    return 'https://api.brain-map.org/api/v2';
}

function allen_url(string $path, array $params = array()): string
{
    $query = count($params) ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    return allen_api_base() . $path . $query;
}

function external_http_get(string $url, int $timeout): string
{
    if (extension_loaded('curl')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'DiurnalBrainTranscriptomeAtlas/3.0 (+https://brainome.ucsd.edu)',
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new ApiException('Allen Brain Atlas request failed.', 502, $error !== '' ? $error : 'HTTP ' . $status);
        }
        return (string) $body;
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: DiurnalBrainTranscriptomeAtlas/3.0\r\n",
        )));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ApiException('Allen Brain Atlas request failed.', 502, 'PHP could not retrieve ' . $url);
        }
        $status = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException('Allen Brain Atlas request failed.', 502, 'HTTP ' . $status);
        }
        return (string) $body;
    }

    throw new ApiException(
        'PHP cannot make outbound HTTPS requests to the Allen Brain Atlas.',
        500,
        'Enable the PHP cURL extension or allow_url_fopen.'
    );
}

function allen_cached_json(string $url): array
{
    $config = app_config();
    $cacheDir = (string) $config['cache_dir'] . DIRECTORY_SEPARATOR . 'allen';
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
    $ttl = (int) $config['allen_cache_seconds'];
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($decoded)) return $decoded;
    }

    try {
        $body = external_http_get($url, (int) $config['allen_timeout_seconds']);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new ApiException('Allen API returned invalid JSON.', 502, json_last_error_msg());
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            @file_put_contents($cacheFile . '.tmp', $body, LOCK_EX);
            @rename($cacheFile . '.tmp', $cacheFile);
        }
        return $decoded;
    } catch (Throwable $error) {
        if (is_file($cacheFile)) {
            $decoded = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($decoded)) return $decoded;
        }
        throw $error;
    }
}

function allen_payload_rows(array $payload): array
{
    $success = isset($payload['success']) && ($payload['success'] === true || strtolower((string) $payload['success']) === 'true');
    $message = $payload['msg'] ?? null;
    if (!$success) {
        $detail = is_string($message) ? $message : 'Allen API query was not successful.';
        throw new ApiException('Allen Brain Atlas query failed.', 502, $detail);
    }
    if ($message === null) return array();
    if (!is_array($message)) {
        throw new ApiException('Allen Brain Atlas returned an unexpected response.', 502);
    }
    if (!count($message)) return array();
    // A single named record is occasionally returned instead of a row list.
    $isAssoc = array_keys($message) !== range(0, count($message) - 1);
    if ($isAssoc) return array($message);
    return array_values(array_filter($message, 'is_array'));
}

function allen_rma_query(string $criteria): array
{
    return allen_payload_rows(allen_cached_json(allen_url('/data/query.json', array('criteria' => $criteria))));
}

function allen_record_int(array $record, array $fields): ?int
{
    foreach ($fields as $field) {
        if (isset($record[$field]) && is_numeric($record[$field])) return (int) $record[$field];
    }
    return null;
}

function allen_pick_sagittal_dataset(string $gene): ?array
{
    $criteria = "model::SectionDataSet,rma::criteria,[failed\$eq'false'],products[abbreviation\$eq'Mouse'],plane_of_section[name\$eq'sagittal'],genes[acronym\$eq'" . $gene . "'],rma::options[order\$eq'id']";
    $rows = allen_rma_query($criteria);
    return count($rows) ? $rows[0] : null;
}

function allen_section_images(int $dataSetId): array
{
    $criteria = "model::SectionImage,rma::criteria,[data_set_id\$eq" . $dataSetId . "],rma::options[order\$eq'section_number'][num_rows\$eqall]";
    $rows = allen_rma_query($criteria);
    usort($rows, function ($a, $b) {
        $aSection = isset($a['section_number']) && is_numeric($a['section_number']) ? (int) $a['section_number'] : PHP_INT_MAX;
        $bSection = isset($b['section_number']) && is_numeric($b['section_number']) ? (int) $b['section_number'] : PHP_INT_MAX;
        if ($aSection === $bSection) return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        return $aSection <=> $bSection;
    });
    return $rows;
}

function allen_atlas_images(array $config): array
{
    $criteria = "model::AtlasImage,rma::criteria,[annotated\$eqtrue],atlas_data_set(atlases[id\$eq" . (int) $config['atlas_id'] . "]),alternate_images[image_type\$eq'" . $config['atlas_image_type'] . "'],rma::options[order\$eq'sub_images.section_number'][num_rows\$eqall]";
    $rows = allen_rma_query($criteria);
    usort($rows, function ($a, $b) {
        $aSection = isset($a['section_number']) && is_numeric($a['section_number']) ? (int) $a['section_number'] : PHP_INT_MAX;
        $bSection = isset($b['section_number']) && is_numeric($b['section_number']) ? (int) $b['section_number'] : PHP_INT_MAX;
        if ($aSection === $bSection) return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        return $aSection <=> $bSection;
    });
    return $rows;
}

function allen_find_nested_record($value, array $fields): ?array
{
    if (!is_array($value)) return null;
    foreach ($fields as $field) {
        if (array_key_exists($field, $value)) return $value;
    }
    foreach ($value as $item) {
        $found = allen_find_nested_record($item, $fields);
        if ($found !== null) return $found;
    }
    return null;
}

function allen_image_to_image(int $seedImageId, float $x, float $y, int $targetDataSetId): ?array
{
    $payload = allen_cached_json(allen_url('/image_to_image/' . $seedImageId . '.json', array('x' => $x, 'y' => $y, 'section_data_set_ids' => $targetDataSetId)));
    $rows = allen_payload_rows($payload);
    return allen_find_nested_record($rows, array('section_image_id', 'image_id'));
}

function allen_image_to_reference(int $imageId, float $x, float $y): ?array
{
    $payload = allen_cached_json(allen_url('/image_to_reference/' . $imageId . '.json', array('x' => $x, 'y' => $y)));
    $rows = allen_payload_rows($payload);
    return allen_find_nested_record($rows, array('x', 'y', 'z'));
}

function allen_reference_to_image(int $referenceSpaceId, float $x, float $y, float $z, int $targetDataSetId): ?array
{
    $payload = allen_cached_json(allen_url('/reference_to_image/' . $referenceSpaceId . '.json', array('x' => $x, 'y' => $y, 'z' => $z, 'section_data_set_ids' => $targetDataSetId)));
    $rows = allen_payload_rows($payload);
    return allen_find_nested_record($rows, array('section_image_id', 'image_id'));
}

function allen_choose_locked_image(array $images, int $dataSetId, array $config): ?array
{
    if (!count($images)) return null;
    $atlasImages = array();
    try {
        $atlasImages = allen_atlas_images($config);
    } catch (Throwable $ignored) {
    }
    $ordinal = max(1, min(count($atlasImages) ?: count($images), (int) $config['atlas_section_ordinal']));
    $atlasImage = count($atlasImages) ? $atlasImages[$ordinal - 1] : null;
    $atlasImageId = is_array($atlasImage) ? allen_record_int($atlasImage, array('id', 'section_image_id', 'image_id')) : null;
    $byId = array();
    foreach ($images as $image) {
        $id = allen_record_int($image, array('id', 'section_image_id'));
        if ($id !== null) $byId[$id] = $image;
    }

    if ($atlasImageId !== null) {
        try {
            $sync = allen_image_to_image($atlasImageId, (float) $config['viewer_x'], (float) $config['viewer_y'], $dataSetId);
            if ($sync !== null) {
                $syncId = allen_record_int($sync, array('section_image_id', 'image_id', 'id'));
                if ($syncId !== null) {
                    $image = isset($byId[$syncId]) ? $byId[$syncId] : array('id' => $syncId, 'section_number' => $sync['section_number'] ?? null);
                    $image['match_method'] = 'atlas_image_to_image';
                    $image['atlas_section_image_id'] = $atlasImageId;
                    $image['atlas_section_ordinal'] = $ordinal;
                    return $image;
                }
            }
        } catch (Throwable $ignored) {
        }

        try {
            $reference = allen_image_to_reference($atlasImageId, (float) $config['viewer_x'], (float) $config['viewer_y']);
            if ($reference !== null && isset($reference['x'], $reference['y'], $reference['z'])) {
                $sync = allen_reference_to_image((int) $config['reference_space_id'], (float) $reference['x'], (float) $reference['y'], (float) $reference['z'], $dataSetId);
                if ($sync !== null) {
                    $syncId = allen_record_int($sync, array('section_image_id', 'image_id', 'id'));
                    if ($syncId !== null) {
                        $image = isset($byId[$syncId]) ? $byId[$syncId] : array('id' => $syncId, 'section_number' => $sync['section_number'] ?? null);
                        $image['match_method'] = 'atlas_reference_to_image';
                        $image['atlas_section_image_id'] = $atlasImageId;
                        $image['atlas_section_ordinal'] = $ordinal;
                        return $image;
                    }
                }
            }
        } catch (Throwable $ignored) {
        }
    }

    $fallbackOrdinal = max(1, min(count($images), (int) $config['atlas_section_ordinal']));
    $image = $images[$fallbackOrdinal - 1];
    $image['match_method'] = 'target_section_ordinal_fallback';
    $image['atlas_section_image_id'] = $atlasImageId;
    $image['atlas_section_ordinal'] = (int) $config['atlas_section_ordinal'];
    return $image;
}

function allen_view(string $view): string
{
    $view = strtolower(trim($view));
    return in_array($view, array('expression', 'expr', 'mask'), true) ? 'expression' : 'ish';
}

function allen_image_download_url(int $imageId, string $view, int $downsample, int $quality): string
{
    $params = array('downsample' => $downsample, 'quality' => $quality);
    if ($view === 'expression') $params['view'] = 'expression';
    return allen_url('/image_download/' . $imageId, $params);
}

function allen_ish_payload(string $input, string $view, int $downsample, int $quality = 90): array
{
    $config = allen_cut_config();
    $gene = clean_gene_input($input, 'Dbp');
    $view = allen_view($view);
    $downsample = max(1, min(8, $downsample));
    $quality = max(1, min(100, $quality));
    $error = function (string $message) use ($input, $gene, $config, $view, $downsample, $quality): array {
        return array('available' => false, 'found' => false, 'input' => $input, 'gene' => $gene, 'cut_id' => $config['cut_id'], 'cut_label' => $config['label'], 'view' => $view, 'downsample' => $downsample, 'quality' => $quality, 'message' => $message);
    };

    try {
        $dataset = allen_pick_sagittal_dataset($gene);
        if ($dataset === null) return $error('No adult mouse sagittal Allen ISH experiment was found for ' . $gene . '.');
        $dataSetId = allen_record_int($dataset, array('id', 'section_data_set_id', 'data_set_id'));
        if ($dataSetId === null) return $error('Allen experiment did not include a valid SectionDataSet id.');
        $images = allen_section_images($dataSetId);
        $image = allen_choose_locked_image($images, $dataSetId, $config);
        if ($image === null) return $error('No SectionImages were found for Allen experiment ' . $dataSetId . '.');
        $imageId = allen_record_int($image, array('id', 'section_image_id', 'image_id'));
        if ($imageId === null) return $error('Allen experiment did not include a valid SectionImage id.');
        $sectionNumber = isset($image['section_number']) && is_numeric($image['section_number']) ? (int) $image['section_number'] : null;
        $imageUrl = allen_image_download_url($imageId, $view, $downsample, $quality);
        $viewerUrl = 'https://mouse.brain-map.org/experiment/siv?id=' . $dataSetId . '&imageId=' . $imageId
            . '&initImage=ish&coordSystem=pixel&x=' . (int) $config['viewer_x'] . '&y=' . (int) $config['viewer_y'] . '&z=' . (int) $config['viewer_z'];
        return array(
            'available' => true,
            'found' => true,
            'input' => $input,
            'gene' => $gene,
            'cut_id' => $config['cut_id'],
            'cut_label' => $config['label'],
            'view' => $view,
            'downsample' => $downsample,
            'quality' => $quality,
            'atlas_id' => (int) $config['atlas_id'],
            'atlas_section_ordinal' => (int) ($image['atlas_section_ordinal'] ?? $config['atlas_section_ordinal']),
            'atlas_section_image_id' => isset($image['atlas_section_image_id']) ? $image['atlas_section_image_id'] : null,
            'match_method' => (string) ($image['match_method'] ?? 'unknown'),
            'section_count' => count($images),
            'section_data_set_id' => $dataSetId,
            'section_image_id' => $imageId,
            'section_number' => $sectionNumber,
            // Direct display avoids requiring PHP to proxy large JPEG files.
            'image_url' => $imageUrl,
            'allen_image_url' => $imageUrl,
            'viewer_url' => $viewerUrl,
        );
    } catch (Throwable $exception) {
        return $error($exception->getMessage());
    }
}
