<?php
require_once __DIR__ . '/api/lib/common.php';

function check_row($label, $ok, $detail = '') {
    echo '<tr><th>' . html_escape($label) . '</th><td class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'Problem') . '</td><td><code>' . html_escape($detail) . '</code></td></tr>';
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diurnal app environment check</title>
<style>body{font-family:system-ui,sans-serif;max-width:980px;margin:40px auto;padding:0 18px;color:#162236}h1{color:#0f3c64}table{border-collapse:collapse;width:100%;background:#fff}th,td{border:1px solid #d7e0ea;padding:10px;text-align:left;vertical-align:top}th{width:220px;background:#f4f8fb}.ok{color:#08783e;font-weight:700}.bad{color:#b42318;font-weight:700}code{white-space:pre-wrap;word-break:break-word}.note{padding:14px;background:#fff8e5;border:1px solid #efd489;border-radius:8px;margin-top:18px}</style>
</head><body><h1>Diurnal Brain Transcriptome Atlas: environment check</h1><table>
<?php
check_row('PHP version', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
check_row('PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'enabled' : 'missing; ask the server administrator to enable pdo_sqlite');
check_row('Allen HTTPS support', extension_loaded('curl') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'cURL=' . (extension_loaded('curl') ? 'yes' : 'no') . '; allow_url_fopen=' . ini_get('allow_url_fopen'));
check_row('Configured database directory', is_dir(db_directory()), db_directory());
foreach (array('diurnal', 'dv', 'supplemental') as $domain) {
    $path = database_filename($domain);
    $ok = is_file($path) && is_readable($path);
    $detail = $path;
    if ($ok && extension_loaded('pdo_sqlite')) {
        try {
            $pdo = open_database($domain);
            $detail .= '; quick_check=' . db_scalar($pdo, 'PRAGMA quick_check');
        } catch (Throwable $error) {
            $ok = false;
            $detail .= '; ' . $error->getMessage();
        }
    }
    check_row($domain . ' database', $ok, $detail);
}
check_row('Compiled frontend', is_file(__DIR__ . '/index.html') && is_dir(__DIR__ . '/assets'), __DIR__ . '/index.html');
?>
</table>
<p class="note"><strong>After setup succeeds:</strong> open <a href="api/index.php?route=health">API health</a>, then <a href="./">the application</a>. Rename or remove <code>check.php</code> after deployment.</p>
</body></html>
