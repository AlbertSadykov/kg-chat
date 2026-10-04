<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/app/bootstrap.php';

use App\Core\Http;
use App\Controllers\ChatController;
use App\Controllers\AdminController;

$requestLogPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestLogPath = '/' . trim(rawurldecode($requestLogPath), '/');
$requestLogBase = base();
if ($requestLogBase !== '' && strpos($requestLogPath, $requestLogBase . '/') === 0) {
    $requestLogPath = substr($requestLogPath, strlen($requestLogBase));
}
if (strpos($requestLogPath, '/blog/image/') === 0) {
    $requestLogPath = '/blog/image/[media]';
}
$requestLogMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$GLOBALS['request_log_error'] = null;
register_shutdown_function(static function () use ($requestLogPath, $requestLogMethod): void {
    if (!is_file(ROOT . '/storage/installed.lock') || !config()) {
        return;
    }
    if ($requestLogMethod === 'GET' && in_array($requestLogPath, ['/api/state', '/api/read', '/api/typing'], true)) {
        return;
    }
    if ($requestLogPath === '/admin/logs' && isset($_GET['feed'])) {
        return;
    }
    $fatal = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $error = $GLOBALS['request_log_error'];
    $status = (int) http_response_code();
    if ($fatal && in_array($fatal['type'], $fatalTypes, true)) {
        $status = 500;
        $error = [
            'type' => 'FatalError',
            'source' => basename($fatal['file']) . ':' . $fatal['line'],
        ];
    }
    $status = $status > 0 ? $status : 200;
    if ($status < 400 && $requestLogMethod === 'POST' && in_array($requestLogPath, ['/api/entry', '/api/join', '/api/next', '/api/leave', '/api/send', '/api/report', '/api/forget', '/api/profile'], true)) {
        return;
    }
    $action = $status >= 400 ? 'request_error' : 'request_success';
    $detail = json_encode([
        'method' => $requestLogMethod,
        'path' => $requestLogPath,
        'status' => $status,
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    try {
        db()->run(
            'INSERT INTO ' . db()->table('admin_logs') . '(admin_id,action,detail,created_at) VALUES (NULL,?,?,UTC_TIMESTAMP())',
            [$action, $detail === false ? '{}' : $detail]
        );
    } catch (Throwable $exception) {
        error_log('Request log write failed: ' . get_class($exception));
    }
});

try {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    $base = base();
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($path === '/install' || $path === '/setup') {
        if (!is_file(ROOT . '/install/Installer.php')) {
            http_response_code(404);
            exit('Installer removed');
        }
        require ROOT . '/install/Installer.php';
        (new \App\Install\Installer())->handle();
        exit;
    }
    if (!is_file(ROOT . '/storage/installed.lock') || !config()) {
        Http::redirect('setup');
    }
    (new \App\Services\BackupService())->runDailyIfDue();
    if (!isset($_SESSION['lang'])) {
        $GLOBALS['lang'] = setting('language') === 'ky' ? 'ky' : 'ru';
        $GLOBALS['dict'] = require ROOT . '/app/Lang/' . lang() . '.php';
    }
    if (strpos($path, '/api/') === 0) {
        (new ChatController())->handle(substr($path, 5), $method);
    } elseif ($path === '/admin' || strpos($path, '/admin/') === 0) {
        (new AdminController())->handle($path, $method);
    } elseif ($method === 'GET' && ($path === '/blog' || strpos($path, '/blog/') === 0)) {
        (new \App\Controllers\BlogController())->handle($path);
    } elseif ($method === 'GET' && $path === '/') {
        Http::view('home', ['cities' => db()->all('SELECT * FROM ' . db()->table('cities') . ' WHERE enabled=1 ORDER BY name_ru')]);
    } elseif ($method === 'GET' && in_array($path, ['/rules', '/privacy', '/contacts', '/18'], true)) {
        Http::view('page', ['page' => substr($path, 1)]);
    } else {
        http_response_code(404);
        Http::view('page', ['page' => '404']);
    }
} catch (Throwable $exception) {
    // Не записываем SQL/аргументы/пароли/сообщения в журнал ошибок.
    $reference = bin2hex(random_bytes(5));
    $GLOBALS['request_log_error'] = [
        'reference' => $reference,
        'type' => get_class($exception),
        'source' => basename($exception->getFile()) . ':' . $exception->getLine(),
    ];
    error_log('Incident ' . $reference . ': ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
    if (strpos($path ?? '', '/api/') === 0) {
        Http::json(['error' => t('server_error'), 'reference' => $reference], 500);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><p>' . e(t('server_error')) . ' #' . e($reference) . '</p>';
}
