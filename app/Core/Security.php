<?php

namespace App\Core;

class Security
{
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, config()['secret']);
    }

    public static function ip(): string
    {
        // Заголовки X-Forwarded-For не принимаются от недоверенного клиента.
        return self::hash($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function password(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function hit(string $key, int $limit, int $seconds): bool
    {
        $db = db();
        $table = $db->table('rate_limits');
        $bucket = self::hash($key);
        return $db->transaction(function () use ($db, $table, $bucket, $limit, $seconds) {
            $db->run("INSERT IGNORE INTO $table(bucket,hits,expires_at) VALUES (?,0,?)", [$bucket, gmdate('Y-m-d H:i:s', time() + $seconds)]);
            $row = $db->one("SELECT * FROM $table WHERE bucket=? FOR UPDATE", [$bucket]);
            $hits = strtotime($row['expires_at'] . ' UTC') <= time() ? 1 : (int) $row['hits'] + 1;
            $expiry = strtotime($row['expires_at'] . ' UTC') <= time() ? gmdate('Y-m-d H:i:s', time() + $seconds) : $row['expires_at'];
            $db->run("UPDATE $table SET hits=?,expires_at=? WHERE bucket=?", [$hits, $expiry, $bucket]);
            return $hits <= $limit;
        });
    }

    public static function totpSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return $out;
    }

    public static function totp(string $secret, int $step): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
            $bits .= str_pad(decbin((int) $n), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $key .= chr(bindec(substr($bits, $i, 8)));
        }
        $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
        $offset = ord($hash[19]) & 15;
        $code = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function totpStep(string $secret, string $code, int $last = -1): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = (int) floor(time() / 30);
        for ($i = $now - 1; $i <= $now + 1; $i++) {
            if ($i > $last && hash_equals(self::totp($secret, $i), $code)) {
                return $i;
            }
        }
        return null;
    }
}
