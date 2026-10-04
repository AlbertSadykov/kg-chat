<?php

use App\Core\Db;
use App\Models\Settings;

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') === 0) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/storage/php-errors.log');
$GLOBALS['config'] = is_file(ROOT . '/config/config.php') ? require ROOT . '/config/config.php' : [];
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !empty($GLOBALS['config']['https']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// Изолируем PHP-сессии от других сайтов на том же аккаунте хостинга.
$sessionDirectory = ROOT . '/storage/sessions';
if (!is_dir($sessionDirectory) && is_writable(ROOT . '/storage')) {
    @mkdir($sessionDirectory, 0700, true);
}
// До установки оставляем системное хранилище, если storage не writable:
// мастер сможет показать проверку прав вместо ошибки до первого шага.
if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    ini_set('session.save_path', $sessionDirectory);
}
ini_set('session.gc_maxlifetime', '1800');
session_name('kgchat');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ru', 'ky'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$GLOBALS['lang'] = $_SESSION['lang'] ?? 'ru';
$GLOBALS['dict'] = require ROOT . '/app/Lang/' . $GLOBALS['lang'] . '.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}

function config(): array
{
    return $GLOBALS['config'];
}
function db(): Db
{
    static $db;
    if (!$db) {
        $db = new Db(config()['db']);
    }
    return $db;
}
function settings(): array
{
    if (!isset($GLOBALS['settings'])) {
        $GLOBALS['settings'] = Settings::load();
    }
    return $GLOBALS['settings'];
}
function setting(string $key): string
{
    return (string) (settings()[$key] ?? '');
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function t(string $key): string
{
    return $GLOBALS['dict'][$key] ?? $key;
}
function lang(): string
{
    return $GLOBALS['lang'];
}
function base(): string
{
    if (!empty(config()['url'])) {
        return rtrim((string) parse_url(config()['url'], PHP_URL_PATH), '/');
    }
    return rtrim(str_replace('/public', '', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
}
function url(string $path = ''): string
{
    return base() . '/' . ltrim($path, '/');
}
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">';
}
