<?php

namespace App\Core;

class Http
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function input(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            self::json(['error' => t('too_long')], 413);
        }
        $data = json_decode(file_get_contents('php://input', false, null, 0, 16385), true);
        if (!is_array($data)) {
            self::json(['error' => t('invalid')], 400);
        }
        return $data;
    }

    public static function csrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            self::json(['error' => t('csrf')], 419);
        }
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . url($path), true, 303);
        exit;
    }

    public static function view(string $file, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require ROOT . '/app/Views/' . $file . '.php';
    }
}
