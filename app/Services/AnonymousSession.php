<?php

namespace App\Services;

use App\Core\Security;

/** Восстановление только анонимной анкеты, без авторизации администратора. */
class AnonymousSession
{
    private const COOKIE = 'kgchat_resume';

    private static function options(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => base() . '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !empty(config()['https']),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function verified(): int
    {
        $value = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^([1-9][0-9]{0,18})\.([0-9]{10})\.([a-f0-9]{64})$/D', $value, $parts)) {
            return 0;
        }
        $payload = $parts[1] . '.' . $parts[2];
        if ((int) $parts[2] <= time() || !hash_equals(Security::hash('anonymous-resume:' . $payload), $parts[3])) {
            return 0;
        }
        return (int) $parts[1];
    }

    public static function restore(): void
    {
        if (!empty($_SESSION['sid'])) {
            return;
        }
        $sid = self::verified();
        // Удалённая анкета не восстанавливается; действующие баны проверяет ChatService.
        if ($sid && (new ChatService())->profile($sid)) {
            session_regenerate_id(true);
            $_SESSION['sid'] = $sid;
        }
    }

    public static function remember(int $sid): void
    {
        if (self::verified() === $sid) {
            return;
        }
        $days = max(1, min(30, (int) setting('session_days')));
        $expires = time() + $days * 86400;
        $payload = $sid . '.' . $expires;
        $value = $payload . '.' . Security::hash('anonymous-resume:' . $payload);
        setcookie(self::COOKIE, $value, self::options($expires));
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function forget(): void
    {
        setcookie(self::COOKIE, '', self::options(time() - 3600));
        unset($_COOKIE[self::COOKIE]);
    }
}
