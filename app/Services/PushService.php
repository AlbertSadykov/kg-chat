<?php

namespace App\Services;

use App\Core\Security;
use App\Models\Settings;
use App\Notifications\WebPush;

class PushService
{
    private static $ready;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool) db()->one('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [config()['db']['prefix'] . 'push_subscriptions']);
        }
        return self::$ready;
    }

    public static function supported(): bool
    {
        return (config()['push_enabled'] ?? true) !== false && function_exists('openssl_pkey_derive') && (function_exists('curl_init') || (bool) ini_get('allow_url_fopen'));
    }

    public static function seal(string $value): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', config()['secret'], true), OPENSSL_RAW_DATA, $iv, $tag, 'kgchat-push', 16);
        if ($encrypted === false) {
            throw new \RuntimeException('Push storage encryption failed');
        }
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function unseal(string $value): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Invalid push storage');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', config()['secret'], true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'kgchat-push');
        if ($plain === false) {
            throw new \RuntimeException('Push storage authentication failed');
        }
        return $plain;
    }

    public static function prepare(): string
    {
        if (!self::supported()) {
            throw new \InvalidArgumentException('Web Push недоступен: нужны OpenSSL ECDH и исходящий HTTPS (cURL или allow_url_fopen).');
        }
        $prefix = config()['db']['prefix'];
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,20}$/D', $prefix)) {
            throw new \RuntimeException('Invalid prefix');
        }
        if (!self::ready()) {
            db()->run(str_replace('{{p}}', $prefix, file_get_contents(ROOT . '/app/Notifications/schema.sql')));
            self::$ready = true;
        }
        return db()->transaction(function () {
            // Строка-мьютекс исключает генерацию разных VAPID-ключей двумя вкладками.
            db()->run('INSERT IGNORE INTO ' . db()->table('settings') . '(name,value) VALUES (?,?)', ['_push_vapid_lock', '1']);
            db()->one('SELECT value FROM ' . db()->table('settings') . ' WHERE name=? FOR UPDATE', ['_push_vapid_lock']);
            $key = db()->one('SELECT value FROM ' . db()->table('settings') . ' WHERE name=?', ['_push_vapid_public']);
            if ($key) {
                return $key['value'];
            }
            $keys = WebPush::keyPair();
            Settings::put('_push_vapid_private', self::seal($keys['private']));
            Settings::put('_push_vapid_public', $keys['public']);
            return $keys['public'];
        });
    }

    public static function endpoint(string $value): bool
    {
        if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return false;
        }
        $parts = parse_url($value);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        // Запрещаем произвольные URL: POST разрешён только службам Web Push браузеров.
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com'], true)
            || (bool) preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:notify\.windows\.com|push\.apple\.com)$/D', $host);
    }

    public static function subscribe(int $sid, array $data): void
    {
        $endpoint = (string) ($data['endpoint'] ?? '');
        $keys = $data['keys'] ?? [];
        if (!self::endpoint($endpoint) || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null) || strlen($keys['p256dh']) > 100 || strlen($keys['auth']) > 32) {
            throw new \InvalidArgumentException('Неподдерживаемая push-подписка.');
        }
        $public = WebPush::decode($keys['p256dh']);
        $auth = WebPush::decode($keys['auth']);
        if (strlen($auth) !== 16 || !openssl_pkey_get_public(WebPush::publicPem($public))) {
            throw new \InvalidArgumentException('Некорректные ключи подписки.');
        }
        self::prepare();
        $hash = Security::hash($endpoint);
        $payload = self::seal(json_encode(['endpoint' => $endpoint, 'keys' => $keys], JSON_UNESCAPED_SLASHES));
        db()->transaction(function () use ($sid, $hash, $payload) {
            // Блокировка сессии ограничивает число подписок даже при параллельных запросах.
            if (!db()->one('SELECT id FROM ' . db()->table('sessions') . ' WHERE id=? FOR UPDATE', [$sid])) {
                throw new \InvalidArgumentException('Анкета удалена.');
            }
            $existing = db()->one('SELECT id,session_id FROM ' . db()->table('push_subscriptions') . ' WHERE endpoint_hash=? FOR UPDATE', [$hash]);
            $count = (int) db()->one('SELECT COUNT(*) n FROM ' . db()->table('push_subscriptions') . ' WHERE session_id=?', [$sid])['n'];
            if ((!$existing || (int) $existing['session_id'] !== $sid) && $count >= 3) {
                throw new \InvalidArgumentException('Доступно не более трёх push-подписок для анкеты.');
            }
            db()->run('INSERT INTO ' . db()->table('push_subscriptions') . '(session_id,endpoint_hash,payload,created_at,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_message_id=IF(session_id=VALUES(session_id),last_message_id,0),session_id=VALUES(session_id),payload=VALUES(payload),updated_at=UTC_TIMESTAMP()', [$sid, $hash, $payload]);
        });
    }

    public static function unsubscribe(int $sid, string $endpoint): void
    {
        if (self::ready() && self::endpoint($endpoint)) {
            db()->run('DELETE FROM ' . db()->table('push_subscriptions') . ' WHERE session_id=? AND endpoint_hash=?', [$sid, Security::hash($endpoint)]);
        }
    }

    public static function message(int $sender, int $chatId, int $messageId): void
    {
        try {
            if (!self::supported() || !self::ready()) {
                return;
            }
            $chat = db()->one('SELECT a,b FROM ' . db()->table('chats') . ' WHERE id=? AND ended_at IS NULL AND (a=? OR b=?)', [$chatId, $sender, $sender]);
            if (!$chat) {
                return;
            }
            $target = (int) ($chat['a'] == $sender ? $chat['b'] : $chat['a']);
            $profile = (new ChatService())->profile($target);
            if (!$profile || (new ChatService())->ban($profile)) {
                return;
            }
            $subscriptions = db()->all('SELECT id,payload FROM ' . db()->table('push_subscriptions') . ' WHERE session_id=? AND updated_at>UTC_TIMESTAMP()-INTERVAL 30 DAY ORDER BY updated_at DESC LIMIT 3', [$target]);
            if (!$subscriptions) {
                return;
            }
            $keys = [];
            foreach (db()->all('SELECT name,value FROM ' . db()->table('settings') . ' WHERE name IN (?,?)', ['_push_vapid_private', '_push_vapid_public']) as $row) {
                $keys[$row['name'] === '_push_vapid_private' ? 'private' : 'public'] = $row['value'];
            }
            if (count($keys) !== 2) {
                return;
            }
            $keys['private'] = self::unseal($keys['private']);
            // В push нет текста, ника, возраста и города собеседника.
            $payload = json_encode(['type' => 'message', 'client' => Security::hash('client:' . $target), 'chat' => $chatId, 'id' => $messageId, 'time' => time()], JSON_UNESCAPED_SLASHES);
            $subject = filter_var(setting('support_email'), FILTER_VALIDATE_EMAIL) ? 'mailto:' . setting('support_email') : preg_replace('/^http:/', 'https:', config()['url']);
            $deadline = microtime(true) + 5;
            foreach ($subscriptions as $subscription) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                // Один nonce сообщения не порождает повторные push при повторной отправке.
                $claim = db()->run('UPDATE ' . db()->table('push_subscriptions') . ' SET last_message_id=? WHERE id=? AND session_id=? AND last_message_id<?', [$messageId, $subscription['id'], $target, $messageId]);
                if (!$claim->rowCount()) {
                    continue;
                }
                $sub = json_decode(self::unseal($subscription['payload']), true);
                if (!is_array($sub) || !self::endpoint($sub['endpoint'] ?? '')) {
                    continue;
                }
                $body = WebPush::encrypt($payload, WebPush::decode($sub['keys']['p256dh']), WebPush::decode($sub['keys']['auth']));
                $origin = 'https://' . strtolower(parse_url($sub['endpoint'], PHP_URL_HOST));
                $headers = ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 45', 'Urgency: high', 'Topic: ' . WebPush::encode(substr(hash('sha256', $target . ':' . $chatId, true), 0, 16)), 'Authorization: ' . WebPush::authorization($origin, $keys, $subject)];
                $status = self::post($sub['endpoint'], $headers, $body, max(0.1, min(1.5, $deadline - microtime(true))));
                if ($status === 404 || $status === 410) {
                    db()->run('DELETE FROM ' . db()->table('push_subscriptions') . ' WHERE id=?', [$subscription['id']]);
                } elseif ($status < 200 || $status >= 300) {
                    // Только код ответа: URL и секреты подписок не попадают в журнал.
                    error_log('WebPush delivery status:' . $status);
                }
            }
        } catch (\Throwable $exception) {
            // Уведомления не отменяют уже сохранённое сообщение.
            error_log('WebPush failure:' . get_class($exception));
        }
    }

    private static function post(string $endpoint, array $headers, string $body, float $timeout): int
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 700, CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            return $status;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", array_merge($headers, ['Content-Length: ' . strlen($body), 'Connection: close'])), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'protocol_version' => 1.1], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($endpoint, 'rb', false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($stream) {
            fclose($stream);
        }
        return $status;
    }
}
