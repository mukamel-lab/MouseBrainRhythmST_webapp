<?php
/**
 * Shared helpers for the Brainome PHP/SQLite deployment.
 * Compatible with PHP 7.4+ and intentionally framework-free.
 */

declare(strict_types=1);

class ApiException extends RuntimeException
{
    /** @var int */
    public $status;
    /** @var mixed */
    public $details;

    public function __construct(string $message, int $status = 400, $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
    }
}

function app_root(): string
{
    return dirname(__DIR__, 2);
}

function path_is_absolute(string $path): bool
{
    if ($path === '') {
        return false;
    }
    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }
    return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = array(
        'debug' => false,
        'db_dir' => '',
        'cache_dir' => app_root() . DIRECTORY_SEPARATOR . 'cache',
        'allen_timeout_seconds' => 20,
        'allen_cache_seconds' => 7 * 24 * 60 * 60,
        'allen_image_cache_seconds' => 30 * 24 * 60 * 60,
        'max_json_rows' => 5000,
        'plot_cache_seconds' => 300,
    );

    $local = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($local)) {
        $loaded = require $local;
        if (is_array($loaded)) {
            $config = array_replace($config, $loaded);
        }
    }

    $envDb = getenv('DIURNAL_DB_DIR');
    if (is_string($envDb) && trim($envDb) !== '') {
        $config['db_dir'] = trim($envDb);
    }

    $envCache = getenv('DIURNAL_CACHE_DIR');
    if (is_string($envCache) && trim($envCache) !== '') {
        $config['cache_dir'] = trim($envCache);
    }

    if (trim((string) $config['db_dir']) === '') {
        $pathFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db-path.txt';
        if (is_file($pathFile)) {
            $lines = file($pathFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $first = is_array($lines) && count($lines) ? trim((string) $lines[0]) : '';
            if ($first !== '') {
                $config['db_dir'] = $first;
            }
        }
    }

    if (trim((string) $config['db_dir']) === '') {
        $config['db_dir'] = app_root() . DIRECTORY_SEPARATOR . 'data-private';
    }

    foreach (array('db_dir', 'cache_dir') as $key) {
        $value = trim((string) $config[$key]);
        if (!path_is_absolute($value)) {
            $value = app_root() . DIRECTORY_SEPARATOR . $value;
        }
        $config[$key] = rtrim($value, DIRECTORY_SEPARATOR);
    }

    return $config;
}

function db_directory(): string
{
    $config = app_config();
    return (string) $config['db_dir'];
}

function database_filename(string $domain): string
{
    $files = array(
        'diurnal' => 'diurnal.sqlite',
        'dv' => 'dorsal_ventral.sqlite',
        'supplemental' => 'supplemental.sqlite',
        'rostral_caudal' => 'rostral_caudal.sqlite',
    );
    if (!isset($files[$domain])) {
        throw new ApiException('Unknown database domain.', 500, $domain);
    }
    return db_directory() . DIRECTORY_SEPARATOR . $files[$domain];
}

function open_database(string $domain): PDO
{
    static $connections = array();
    if (isset($connections[$domain])) {
        return $connections[$domain];
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new ApiException(
            'The PHP pdo_sqlite extension is not enabled on this server.',
            500,
            'Ask the Brainome administrator to enable PDO SQLite for Apache/PHP.'
        );
    }

    $path = database_filename($domain);
    if (!is_file($path)) {
        throw new ApiException('Required SQLite database was not found.', 503, $path);
    }
    if (!is_readable($path)) {
        throw new ApiException('Required SQLite database is not readable by PHP.', 503, $path);
    }

    try {
        $pdo = new PDO('sqlite:' . $path, null, null, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        // Safe no-op on builds that do not support mmap.
        try {
            $pdo->exec('PRAGMA mmap_size = 268435456');
        } catch (Throwable $ignored) {
        }
    } catch (Throwable $error) {
        throw new ApiException('Could not open SQLite database.', 500, $error->getMessage());
    }

    $connections[$domain] = $pdo;
    return $pdo;
}

function db_all(PDO $pdo, string $sql, array $params = array()): array
{
    $statement = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $key = is_int($name) ? $name + 1 : (strpos((string) $name, ':') === 0 ? (string) $name : ':' . $name);
        $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue($key, $value, $type);
    }
    $statement->execute();
    return $statement->fetchAll();
}

function db_one(PDO $pdo, string $sql, array $params = array()): ?array
{
    $rows = db_all($pdo, $sql, $params);
    return count($rows) ? $rows[0] : null;
}

function db_scalar(PDO $pdo, string $sql, array $params = array())
{
    $statement = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $key = is_int($name) ? $name + 1 : (strpos((string) $name, ':') === 0 ? (string) $name : ':' . $name);
        $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue($key, $value, $type);
    }
    $statement->execute();
    return $statement->fetchColumn();
}

function sql_in_clause(array $values, string $prefix, array &$params): string
{
    if (!count($values)) {
        return '(NULL)';
    }
    $holders = array();
    foreach (array_values($values) as $index => $value) {
        $name = $prefix . $index;
        $holders[] = ':' . $name;
        $params[$name] = $value;
    }
    return '(' . implode(',', $holders) . ')';
}

function decode_setting_value(string $value)
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if ($trimmed[0] === '[' || $trimmed[0] === '{' || $trimmed === 'true' || $trimmed === 'false' || $trimmed === 'null') {
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    if (is_numeric($trimmed)) {
        return strpos($trimmed, '.') !== false || stripos($trimmed, 'e') !== false ? (float) $trimmed : (int) $trimmed;
    }
    return $value;
}

function read_key_value_table(PDO $pdo, string $table): array
{
    $allowed = array('settings', 'schema_info');
    if (!in_array($table, $allowed, true)) {
        throw new ApiException('Invalid key/value table.', 500);
    }
    $rows = db_all($pdo, 'SELECT key, value FROM ' . $table);
    $out = array();
    foreach ($rows as $row) {
        $out[(string) $row['key']] = decode_setting_value((string) $row['value']);
    }
    return $out;
}

function request_route(): string
{
    $route = isset($_GET['route']) ? (string) $_GET['route'] : '';
    $route = trim($route);
    $route = preg_replace('#^/+|/+$#', '', $route);
    return (string) $route;
}

function request_string(string $name, string $default = ''): string
{
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return $default;
    }
    return trim((string) $_GET[$name]);
}

function request_int(string $name, int $default, int $min, int $max): int
{
    $raw = request_string($name, '');
    if ($raw === '' || filter_var($raw, FILTER_VALIDATE_INT) === false) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

function request_float(string $name, float $default, float $min, float $max): float
{
    $raw = request_string($name, '');
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $value = (float) $raw;
    if (!is_finite($value)) {
        return $default;
    }
    return max($min, min($max, $value));
}

function request_csv(string $name, array $default = array()): array
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    $raw = $_GET[$name];
    $values = is_array($raw) ? $raw : explode(',', (string) $raw);
    $clean = array();
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }
    return $clean;
}

function json_response($payload, int $status = 200, array $extraHeaders = array()): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new ApiException('Failed to encode JSON response.', 500, json_last_error_msg());
    }
    echo $json;
    exit;
}

function text_response(string $body, string $contentType, int $status = 200, array $extraHeaders = array()): void
{
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $body;
    exit;
}

function redirect_response(string $url, int $status = 302): void
{
    http_response_code($status);
    header('Location: ' . $url);
    header('Cache-Control: public, max-age=3600');
    exit;
}

function error_payload(Throwable $error): array
{
    $status = $error instanceof ApiException ? $error->status : 500;
    $details = $error instanceof ApiException ? $error->details : $error->getMessage();
    $config = app_config();
    $out = array('error' => array('status' => $status, 'message' => $error->getMessage()));
    if (!empty($config['debug']) && $details !== null && $details !== '') {
        $out['error']['details'] = $details;
    } elseif ($error instanceof ApiException && $status < 500 && $details !== null && $details !== '') {
        $out['error']['details'] = $details;
    }
    return $out;
}

function xml_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function html_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clamp_float($value, float $min, float $max, float $default): float
{
    if (!is_numeric($value)) {
        return $default;
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        return $default;
    }
    return max($min, min($max, $number));
}

function clean_gene_input($gene, string $default = ''): string
{
    $gene = trim((string) $gene);
    $gene = preg_replace('/[^A-Za-z0-9_.-]+/', '', $gene);
    return $gene !== '' ? (string) $gene : $default;
}

function format_metric_value($value, int $digits = 3): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        return '';
    }
    if ($number === 0.0) {
        return '0';
    }
    $absolute = abs($number);
    if ($absolute < 0.001 || $absolute >= 100000) {
        return preg_replace('/e([+-])0+/', 'e$1', sprintf('%.2e', $number));
    }
    return sprintf('%.' . max(1, min(6, $digits)) . 'g', $number);
}

function format_small_number($value): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }
    $number = (float) $value;
    if (!is_finite($number)) {
        return '';
    }
    $absolute = abs($number);
    if ($absolute > 0 && $absolute < 0.001) {
        return preg_replace('/e([+-])0+/', 'e$1', sprintf('%.2e', $number));
    }
    $digits = $absolute >= 10 ? 2 : 3;
    return rtrim(rtrim(number_format($number, $digits, '.', ''), '0'), '.');
}

function normalize_label_key($value): string
{
    return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', trim((string) $value)));
}

function cluster_synonym_keys(string $cluster): array
{
    $key = normalize_label_key($cluster);
    if ($key === '') {
        return array();
    }
    $keys = array($key);
    $groups = array(
        array('l23', 'l2', 'l3', 'l2l3', 'ctx23'),
        array('l4', 'ctx4'),
        array('l5a', 'ctx5a'),
        array('l5b', 'ctx5b'),
        array('l6a', 'ctx6a'),
        array('l6b', 'ctx6b'),
        array('ca3so', 'ca3sosr'),
        array('dgsg', 'dentategyrusgranulelayer', 'dentatesubgran', 'dentatesubgranular', 'subgran', 'subgranular'),
        array('dgmo', 'dentategyrusmolecularlayer'),
    );
    foreach ($groups as $group) {
        if (in_array($key, $group, true)) {
            $keys = array_merge($keys, $group);
        }
    }
    return array_values(array_unique($keys));
}

function search_gene_table(PDO $pdo, string $query, int $limit): array
{
    $limit = max(1, min(500, $limit));
    $query = trim($query);
    if ($query === '') {
        $statement = $pdo->prepare('SELECT symbol FROM genes ORDER BY symbol COLLATE NOCASE LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(function ($row) { return (string) $row['symbol']; }, $statement->fetchAll());
    }

    $upper = strtoupper($query);
    $statement = $pdo->prepare(
        'SELECT symbol FROM genes '
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

function resolve_gene_table(PDO $pdo, string $input, int $limit = 25): array
{
    $input = trim($input);
    $suggestions = search_gene_table($pdo, $input, $limit);
    if ($input === '') {
        return array('input' => '', 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    }
    $row = db_one($pdo, 'SELECT gene_id, symbol FROM genes WHERE symbol_upper = :upper LIMIT 1', array('upper' => strtoupper($input)));
    if ($row === null) {
        return array('input' => $input, 'found' => false, 'gene' => null, 'suggestions' => $suggestions);
    }
    $gene = (string) $row['symbol'];
    if (!in_array($gene, $suggestions, true)) {
        array_unshift($suggestions, $gene);
        $suggestions = array_values(array_unique($suggestions));
    }
    return array('input' => $input, 'found' => true, 'gene' => $gene, 'gene_id' => (int) $row['gene_id'], 'suggestions' => $suggestions);
}

function hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 8) {
        $hex = substr($hex, 0, 6);
    }
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
        return array(0, 0, 0);
    }
    return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

function rgb_to_hex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, (int) round($rgb[0]))), max(0, min(255, (int) round($rgb[1]))), max(0, min(255, (int) round($rgb[2]))));
}

function interpolate_hex(string $low, string $high, float $t): string
{
    $t = max(0.0, min(1.0, $t));
    $a = hex_to_rgb($low);
    $b = hex_to_rgb($high);
    return rgb_to_hex(array(
        $a[0] + ($b[0] - $a[0]) * $t,
        $a[1] + ($b[1] - $a[1]) * $t,
        $a[2] + ($b[2] - $a[2]) * $t,
    ));
}

function deterministic_unit_interval(string $value): float
{
    $unsigned = sprintf('%u', crc32($value));
    return ((float) $unsigned) / 4294967295.0;
}
