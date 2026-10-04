<?php

namespace App\Services;

use App\Core\Http;
use App\Core\Security;

class ChatService
{
    private static $likesSchemaReady = false;
    private static $repliesSchemaReady = false;
    private $db;

    public function __construct()
    {
        $this->db = db();
    }

    private function ensureLikesSchema(): void
    {
        if (self::$likesSchemaReady || setting('_message_likes_schema') === '1') {
            self::$likesSchemaReady = true;
            return;
        }
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS ' . $this->db->table('message_likes') . ' (
                message_id BIGINT UNSIGNED NOT NULL,
                session_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(message_id,session_id),
                INDEX(session_id),
                FOREIGN KEY(message_id) REFERENCES ' . $this->db->table('messages') . '(id) ON DELETE CASCADE,
                FOREIGN KEY(session_id) REFERENCES ' . $this->db->table('sessions') . '(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->run(
            'INSERT INTO ' . $this->db->table('settings') . '(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
            ['_message_likes_schema', '1']
        );
        $GLOBALS['settings']['_message_likes_schema'] = '1';
        self::$likesSchemaReady = true;
    }

    private function ensureRepliesSchema(): void
    {
        if (self::$repliesSchemaReady || setting('_message_replies_schema') === '1') {
            self::$repliesSchemaReady = true;
            return;
        }
        $this->db->run(
            'CREATE TABLE IF NOT EXISTS ' . $this->db->table('message_replies') . ' (
                message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                reply_to_message_id BIGINT UNSIGNED NOT NULL,
                FOREIGN KEY(message_id) REFERENCES ' . $this->db->table('messages') . '(id) ON DELETE CASCADE,
                FOREIGN KEY(reply_to_message_id) REFERENCES ' . $this->db->table('messages') . '(id) ON DELETE CASCADE,
                INDEX(reply_to_message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->db->run(
            'INSERT INTO ' . $this->db->table('settings') . '(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
            ['_message_replies_schema', '1']
        );
        $GLOBALS['settings']['_message_replies_schema'] = '1';
        self::$repliesSchemaReady = true;
    }

    public function mutex(): void
    {
        $this->db->one('SELECT value FROM ' . $this->db->table('settings') . " WHERE name='_match_lock' FOR UPDATE");
    }

    public function profile(int $sid): ?array
    {
        return $this->db->one('SELECT s.*,u.nickname,u.gender,u.age,u.city_id,c.name_ru,c.name_ky FROM ' . $this->db->table('sessions') . ' s JOIN ' . $this->db->table('users') . ' u ON u.id=s.user_id JOIN ' . $this->db->table('cities') . ' c ON c.id=u.city_id WHERE s.id=?', [$sid]);
    }

    public function publicProfile(array $row): array
    {
        return ['nickname' => $row['nickname'], 'gender' => $row['gender'], 'age' => (int) $row['age'], 'city' => $row['name_' . lang()]];
    }

    public function updateProfile(int $sid, array $input): void
    {
        $rawNickname = $input['nickname'] ?? '';
        $gender = $input['gender'] ?? null;
        $rawAge = $input['age'] ?? null;
        $rawCity = $input['city'] ?? null;
        if (
            !is_string($rawNickname) ||
            !is_string($gender) ||
            (!is_string($rawAge) && !is_int($rawAge)) ||
            (!is_string($rawCity) && !is_int($rawCity))
        ) {
            Http::json(['error' => t('invalid')], 422);
        }
        $nickname = trim($rawNickname);
        $age = filter_var($rawAge, FILTER_VALIDATE_INT);
        $city = filter_var($rawCity, FILTER_VALIDATE_INT);
        if (
            mb_strlen($nickname) > 40 ||
            !in_array($gender, ['m', 'f'], true) ||
            $age === false ||
            $age < max(18, (int) setting('min_age')) ||
            $age > (int) setting('max_age') ||
            $city === false ||
            $city < 1
        ) {
            Http::json(['error' => t('invalid')], 422);
        }
        if (!$this->db->one('SELECT id FROM ' . $this->db->table('cities') . ' WHERE id=? AND enabled=1', [$city])) {
            Http::json(['error' => t('invalid')], 422);
        }
        $this->db->run('UPDATE ' . $this->db->table('users') . ' SET nickname=?,gender=?,age=?,city_id=? WHERE id=(SELECT user_id FROM ' . $this->db->table('sessions') . ' WHERE id=?)', [$nickname === '' ? t('anonymous') : $nickname, $gender, $age, $city, $sid]);
    }

    public function ban(array $profile): ?array
    {
        return $this->db->one('SELECT reason,expires_at FROM ' . $this->db->table('bans') . " WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP()) AND ((scope='session' AND value=?) OR (scope='ip' AND value=?) OR (scope='fingerprint' AND value=?)) ORDER BY id DESC LIMIT 1", [(string) $profile['id'], $profile['ip_hash'], $profile['fingerprint']]);
    }

    public function active(int $sid, bool $lock = false): ?array
    {
        return $this->db->one('SELECT * FROM ' . $this->db->table('chats') . ' WHERE ended_at IS NULL AND (a=? OR b=?) LIMIT 1' . ($lock ? ' FOR UPDATE' : ''), [$sid, $sid]);
    }

    public function end(int $sid, string $reason = 'left'): void
    {
        $this->db->run('UPDATE ' . $this->db->table('chats') . ' SET ended_at=UTC_TIMESTAMP(),end_reason=? WHERE ended_at IS NULL AND (a=? OR b=?)', [$reason, $sid, $sid]);
        $this->db->run('DELETE FROM ' . $this->db->table('queue') . ' WHERE session_id=?', [$sid]);
        $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET typing_until=NULL WHERE id=?', [$sid]);
    }

    private function stale(): void
    {
        $this->db->run('DELETE q FROM ' . $this->db->table('queue') . ' q JOIN ' . $this->db->table('sessions') . ' s ON s.id=q.session_id WHERE q.expires_at<=UTC_TIMESTAMP() OR s.last_seen<?', [gmdate('Y-m-d H:i:s', time() - (int) setting('heartbeat_timeout'))]);
        $cut = gmdate('Y-m-d H:i:s', time() - (int) setting('heartbeat_timeout'));
        $idle = gmdate('Y-m-d H:i:s', time() - (int) setting('idle_timeout'));
        $this->db->run('UPDATE ' . $this->db->table('chats') . ' c JOIN ' . $this->db->table('sessions') . ' a ON a.id=c.a JOIN ' . $this->db->table('sessions') . " b ON b.id=c.b SET c.ended_at=UTC_TIMESTAMP(),c.end_reason='timeout' WHERE c.ended_at IS NULL AND (a.last_seen<? OR b.last_seen<? OR c.last_activity<?)", [$cut, $cut, $idle]);
    }

    private function match(int $sid): void
    {
        $own = $this->db->one('SELECT * FROM ' . $this->db->table('queue') . ' WHERE session_id=? FOR UPDATE', [$sid]);
        if (!$own || $this->active($sid)) {
            return;
        }
        $p = $this->profile($sid);
        if (!$p || $this->ban($p)) {
            $this->end($sid, 'banned');
            return;
        }
        // Фильтры взаимные. Глобальная строка mutex исключает двойные пары.
        $sql = 'SELECT q.session_id FROM ' . $this->db->table('queue') . ' q JOIN ' . $this->db->table('sessions') . ' s ON s.id=q.session_id JOIN ' . $this->db->table('users') . ' u ON u.id=s.user_id WHERE q.session_id<>? AND u.age BETWEEN ? AND ? AND (? IS NULL OR u.city_id=?) AND (? IS NULL OR u.gender=?) AND (q.city_id IS NULL OR q.city_id=?) AND (q.gender IS NULL OR q.gender=?) AND ? BETWEEN q.min_age AND q.max_age ORDER BY q.joined_at LIMIT 30 FOR UPDATE';
        $candidates = $this->db->all($sql, [$sid, $own['min_age'], $own['max_age'], $own['city_id'], $own['city_id'], $own['gender'], $own['gender'], $p['city_id'], $p['gender'], $p['age']]);
        foreach ($candidates as $candidate) {
            $other = (int) $candidate['session_id'];
            if ($this->active($other) || $this->ban($this->profile($other))) {
                $this->end($other, 'banned');
                continue;
            }
            $this->db->run('INSERT INTO ' . $this->db->table('chats') . '(a,b,started_at,last_activity) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$sid, $other]);
            $this->db->run('DELETE FROM ' . $this->db->table('queue') . ' WHERE session_id IN (?,?)', [$sid, $other]);
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET delivered_id=0,read_id=0,typing_until=NULL WHERE id IN (?,?)', [$sid, $other]);
            return;
        }
    }

    public function filters(array $input): array
    {
        $city = (int) ($input['city'] ?? 0);
        $gender = $input['gender'] ?? '';
        $min = (int) ($input['min_age'] ?? 18);
        $max = (int) ($input['max_age'] ?? 99);
        if (!in_array($gender, ['', 'm', 'f'], true) || $min < max(18, (int) setting('min_age')) || $max > (int) setting('max_age') || $min > $max) {
            Http::json(['error' => t('invalid')], 422);
        }
        if ($city && !$this->db->one('SELECT id FROM ' . $this->db->table('cities') . ' WHERE id=? AND enabled=1', [$city])) {
            Http::json(['error' => t('invalid')], 422);
        }
        return [$city ?: null, $gender ?: null, $min, $max];
    }

    public function join(int $sid, array $filters, bool $next = false): void
    {
        $this->db->transaction(function () use ($sid, $filters, $next) {
            $this->mutex();
            $this->stale();
            if ($next) {
                $this->end($sid, 'next');
            }
            $p = $this->profile($sid);
            if (!$p || $this->ban($p)) {
                throw new \RuntimeException('Banned or expired');
            }
            if ($this->active($sid)) {
                return;
            }
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET last_seen=UTC_TIMESTAMP() WHERE id=?', [$sid]);
            $this->db->run('INSERT INTO ' . $this->db->table('queue') . '(session_id,city_id,gender,min_age,max_age,joined_at,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE city_id=VALUES(city_id),gender=VALUES(gender),min_age=VALUES(min_age),max_age=VALUES(max_age)', array_merge([$sid], $filters, [gmdate('Y-m-d H:i:s', time() + (int) setting('queue_timeout'))]));
            $this->match($sid);
        });
    }

    public function leave(int $sid): void
    {
        $this->db->transaction(function () use ($sid) {
            $this->mutex();
            $this->end($sid);
        });
    }

    public function snapshot(int $sid, int $cursor = 0, array $messageIds = []): array
    {
        $this->ensureLikesSchema();
        $this->ensureRepliesSchema();
        return $this->db->transaction(function () use ($sid, $cursor, $messageIds) {
            $this->mutex();
            // Сначала проверяем старые heartbeat; поздний запрос не оживляет старый чат.
            $this->stale();
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET last_seen=UTC_TIMESTAMP() WHERE id=?', [$sid]);
            $p = $this->profile($sid);
            if (!$p) {
                return ['state' => 'expired'];
            }
            $ban = $this->ban($p);
            if ($ban || setting('chat_enabled') !== '1') {
                $this->end($sid, $ban ? 'banned' : 'maintenance');
                return ['state' => $ban ? 'banned' : 'maintenance', 'reason' => $ban['reason'] ?? t('maintenance')];
            }
            $this->match($sid);
            $chat = $this->active($sid);
            $queue = $this->db->one('SELECT joined_at,expires_at FROM ' . $this->db->table('queue') . ' WHERE session_id=?', [$sid]);
            $cut = gmdate('Y-m-d H:i:s', time() - (int) setting('heartbeat_timeout'));
            $online = (int) $this->db->one('SELECT COUNT(*) n FROM ' . $this->db->table('sessions') . ' WHERE last_seen>?', [$cut])['n'];
            $queued = (int) $this->db->one('SELECT COUNT(*) n FROM ' . $this->db->table('queue'))['n'];
            $result = ['state' => $chat ? 'chat' : ($queue ? 'queue' : 'idle'), 'me' => $this->publicProfile($p), 'editable_profile' => ['nickname' => $p['nickname'], 'gender' => $p['gender'], 'age' => (int) $p['age'], 'city_id' => (int) $p['city_id']], 'online' => $online, 'queued' => $queued, 'warning' => $p['warning'], 'queue' => $queue];
            if ($chat) {
                $other = $this->profile((int) ($chat['a'] == $sid ? $chat['b'] : $chat['a']));
                $messages = $this->db->all(
                    'SELECT m.id,m.sender_id,m.body,m.created_at,(SELECT COUNT(*) FROM ' . $this->db->table('message_likes') . ' ml WHERE ml.message_id=m.id) like_count,EXISTS(SELECT 1 FROM ' . $this->db->table('message_likes') . ' own_like WHERE own_like.message_id=m.id AND own_like.session_id=?) liked_by_me,r.reply_to_message_id,quoted.sender_id reply_sender_id,quoted.body reply_body FROM ' . $this->db->table('messages') . ' m LEFT JOIN ' . $this->db->table('message_replies') . ' r ON r.message_id=m.id LEFT JOIN ' . $this->db->table('messages') . ' quoted ON quoted.id=r.reply_to_message_id AND quoted.chat_id=m.chat_id WHERE m.chat_id=? AND m.id>? AND m.created_at>=? ORDER BY m.id LIMIT 100',
                    [$sid, $chat['id'], $cursor, setting('log_messages') === '1' ? gmdate('Y-m-d H:i:s', time() - (int) setting('message_days') * 86400) : gmdate('Y-m-d H:i:s', time() - 120)]
                );
                $delivered = (int) $p['delivered_id'];
                foreach ($messages as &$message) {
                    $message['mine'] = (int) $message['sender_id'] === $sid;
                    $message['id'] = (int) $message['id'];
                    $message['like_count'] = (int) $message['like_count'];
                    $message['liked_by_me'] = (bool) $message['liked_by_me'];
                    if ($message['reply_to_message_id'] !== null && $message['reply_body'] !== null) {
                        $message['reply'] = [
                            'mine' => (int) $message['reply_sender_id'] === $sid,
                            'body' => $message['reply_body'],
                        ];
                    } else {
                        $message['reply'] = null;
                    }
                    unset($message['reply_to_message_id'], $message['reply_sender_id'], $message['reply_body']);
                    $delivered = max($delivered, $message['id']);
                    unset($message['sender_id']);
                }
                unset($message);
                $messageIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $messageIds), static function (int $id): bool {
                    return $id > 0;
                }))), 0, 300);
                $likeUpdates = [];
                if ($messageIds) {
                    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                    $likeUpdates = $this->db->all(
                        'SELECT m.id,COUNT(ml.session_id) like_count,COALESCE(MAX(ml.session_id=?),0) liked_by_me FROM ' . $this->db->table('messages') . ' m LEFT JOIN ' . $this->db->table('message_likes') . ' ml ON ml.message_id=m.id WHERE m.chat_id=? AND m.id IN (' . $placeholders . ') GROUP BY m.id',
                        array_merge([$sid, $chat['id']], $messageIds)
                    );
                    foreach ($likeUpdates as &$likeUpdate) {
                        $likeUpdate['id'] = (int) $likeUpdate['id'];
                        $likeUpdate['like_count'] = (int) $likeUpdate['like_count'];
                        $likeUpdate['liked_by_me'] = (bool) $likeUpdate['liked_by_me'];
                    }
                    unset($likeUpdate);
                }
                $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET delivered_id=GREATEST(delivered_id,?) WHERE id=?', [$delivered, $sid]);
                $result += ['chat_id' => (int) $chat['id'], 'peer' => $this->publicProfile($other), 'typing' => !empty($other['typing_until']) && strtotime($other['typing_until'] . ' UTC') > time(), 'peer_delivered' => (int) $other['delivered_id'], 'peer_read' => (int) $other['read_id'], 'messages' => $messages, 'like_updates' => $likeUpdates];
            } else {
                $last = $this->db->one('SELECT end_reason FROM ' . $this->db->table('chats') . ' WHERE a=? OR b=? ORDER BY id DESC LIMIT 1', [$sid, $sid]);
                $result['end_reason'] = $last['end_reason'] ?? null;
            }
            return $result;
        });
    }

    public function receipt(int $sid, int $chatId, int $read): void
    {
        $this->db->transaction(function () use ($sid, $chatId, $read) {
            $chat = $this->active($sid, true);
            if (!$chat || (int) $chat['id'] !== $chatId) {
                return;
            }

            $max = $this->db->one('SELECT MAX(id) n FROM ' . $this->db->table('messages') . ' WHERE chat_id=?', [$chatId]);
            $read = min(max(0, $read), (int) $max['n']);
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET read_id=GREATEST(read_id,LEAST(delivered_id,?)) WHERE id=?', [$read, $sid]);
        });
    }

    public function toggleLike(int $sid, int $chatId, int $messageId): array
    {
        if ($chatId < 1 || $messageId < 1) {
            Http::json(['error' => t('invalid')], 422);
        }
        $this->ensureLikesSchema();
        $result = $this->db->transaction(function () use ($sid, $chatId, $messageId): ?array {
            $chat = $this->active($sid, true);
            if (!$chat || (int) $chat['id'] !== $chatId) {
                return null;
            }
            $message = $this->db->one('SELECT id FROM ' . $this->db->table('messages') . ' WHERE id=? AND chat_id=? FOR UPDATE', [$messageId, $chatId]);
            if (!$message) {
                return null;
            }
            $like = $this->db->one('SELECT session_id FROM ' . $this->db->table('message_likes') . ' WHERE message_id=? AND session_id=?', [$messageId, $sid]);
            if ($like) {
                $this->db->run('DELETE FROM ' . $this->db->table('message_likes') . ' WHERE message_id=? AND session_id=?', [$messageId, $sid]);
                $liked = false;
            } else {
                $this->db->run('INSERT INTO ' . $this->db->table('message_likes') . '(message_id,session_id,created_at) VALUES (?,?,UTC_TIMESTAMP())', [$messageId, $sid]);
                $liked = true;
            }
            $count = (int) $this->db->one('SELECT COUNT(*) n FROM ' . $this->db->table('message_likes') . ' WHERE message_id=?', [$messageId])['n'];
            return ['liked' => $liked, 'like_count' => $count];
        });
        if ($result === null) {
            Http::json(['error' => t('chat_ended')], 409);
        }
        return $result;
    }

    public function typing(int $sid, int $chatId, bool $typing): void
    {
        $chat = $this->active($sid);
        if ($chat && (int) $chat['id'] === $chatId) {
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET typing_until=? WHERE id=?', [$typing ? gmdate('Y-m-d H:i:s', time() + 5) : null, $sid]);
        }
    }

    public function message(int $sid, array $input): int
    {
        $body = trim((string) ($input['body'] ?? ''));
        $nonce = (string) ($input['nonce'] ?? '');
        $chatId = (int) ($input['chat_id'] ?? 0);
        $replyTo = max(0, (int) ($input['reply_to_id'] ?? 0));
        if ($body === '' || mb_strlen($body) > (int) setting('message_length') || !preg_match('/^[a-zA-Z0-9-]{16,64}$/', $nonce)) {
            Http::json(['error' => t('too_long')], 422);
        }
        $this->ensureRepliesSchema();
        [$body, $auto] = (new ModerationService())->filter($body);
        return $this->db->transaction(function () use ($sid, $body, $nonce, $chatId, $replyTo, $auto) {
            $chat = $this->active($sid, true);
            if (!$chat || (int) $chat['id'] !== $chatId || $this->ban($this->profile($sid)) || setting('chat_enabled') !== '1') {
                Http::json(['error' => t('chat_ended')], 409);
            }
            if ($replyTo > 0 && !$this->db->one('SELECT id FROM ' . $this->db->table('messages') . ' WHERE id=? AND chat_id=?', [$replyTo, $chatId])) {
                Http::json(['error' => t('invalid')], 422);
            }
            $existing = $this->db->one('SELECT id FROM ' . $this->db->table('messages') . ' WHERE chat_id=? AND sender_id=? AND nonce=?', [$chatId, $sid, $nonce]);
            if ($existing) {
                return (int) $existing['id'];
            }
            $this->db->run('INSERT INTO ' . $this->db->table('messages') . '(chat_id,sender_id,body,nonce,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$chatId, $sid, $body, $nonce]);
            $id = $this->db->id();
            if ($replyTo > 0) {
                $this->db->run(
                    'INSERT INTO ' . $this->db->table('message_replies') . '(message_id,reply_to_message_id) VALUES (?,?)',
                    [$id, $replyTo]
                );
            }
            $this->db->run('UPDATE ' . $this->db->table('chats') . ' SET last_activity=UTC_TIMESTAMP() WHERE id=?', [$chatId]);
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET typing_until=NULL,last_seen=UTC_TIMESTAMP() WHERE id=?', [$sid]);
            if ($auto) {
                (new ModerationService())->saveReport($chat, null, $sid, 'auto_filter', $auto);
            }
            return $id;
        });
    }
}
