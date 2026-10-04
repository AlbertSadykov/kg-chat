<?php
// Только для локальной разработки: php -S 127.0.0.1:8080 tests/router.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/[a-zA-Z0-9_.-]+$~', $path)) {
    $file = dirname(__DIR__) . '/public' . $path;
    if (is_file($file)) {
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($file);
        return;
    }
}
if (preg_match('~^/(app|config|storage|tests|public)/~', $path) || (strpos($path, '/install/') === 0)) {
    http_response_code(403);
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/public/index.php';
