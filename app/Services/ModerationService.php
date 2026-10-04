<?php

namespace App\Services;

use App\Core\Http;

class ModerationService
{
    public function filter(string $body): array
    {
        $auto = '';
        foreach (db()->all('SELECT * FROM ' . db()->table('stopwords') . ' WHERE enabled=1') as $rule) {
            $patterns = [
                'link' => '~(?:https?://|www\.|\b[a-z0-9-]+\.(?:com|ru|kg|net|org|io|me|kz)\b)\S*~iu',
                'phone' => '~(?:\+?\d[\s().-]*){8,15}~u',
                'messenger' => '~(?:@[a-z0-9_]{4,32}|(?:t\.me|telegram\.me|wa\.me)/\S+|(?:telegram|whatsapp|телеграм|ватсап)\s*[:=]?\s*\S*)~iu',
            ];
            $pattern = $patterns[$rule['kind']] ?? '~' . preg_quote($rule['pattern'], '~') . '~iu';
            if (!preg_match($pattern, $body)) {
                continue;
            }
            if ($rule['action'] === 'block') {
                Http::json(['error' => t('filtered')], 422);
            }
            if ($rule['action'] === 'replace') {
                $replacement = $rule['replacement'];
                $body = preg_replace_callback($pattern, function () use ($replacement) {
                    return $replacement;
                }, $body);
            } elseif ($rule['action'] === 'report') {
                $auto = 'rule:' . $rule['id'];
            }
        }
        return [$body, $auto];
    }

    public function saveReport(array $chat, ?int $reporter, int $target, string $reason, string $detail): int
    {
        $service = new ChatService();
        $evidence = ['participants' => [], 'messages' => []];
        foreach ([$chat['a'], $chat['b']] as $sid) {
            $row = $service->profile((int) $sid);
            $evidence['participants'][] = ['session' => (int) $sid, 'nickname' => $row['nickname'], 'gender' => $row['gender'], 'age' => (int) $row['age'], 'city' => $row['name_ru']];
        }
        // Не доверяем переданной клиентом переписке. Снимок — только из БД.
        $evidence['messages'] = array_reverse(db()->all('SELECT id,sender_id,body,created_at FROM ' . db()->table('messages') . ' WHERE chat_id=? ORDER BY id DESC LIMIT 200', [$chat['id']]));
        db()->run('INSERT INTO ' . db()->table('reports') . '(chat_id,reporter_id,target_id,reason,detail,priority,evidence,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())', [$chat['id'], $reporter, $target, $reason, mb_substr($detail, 0, 500), $reason === 'minor' ? 10 : 0, json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        return db()->id();
    }

    public function report(int $sid, array $data): int
    {
        $reason = (string) ($data['reason'] ?? '');
        if (!in_array($reason, ['spam', 'abuse', 'advertising', 'explicit', 'minor', 'fraud', 'other'], true)) {
            Http::json(['error' => t('invalid')], 422);
        }
        return db()->transaction(function () use ($sid, $data, $reason) {
            $service = new ChatService();
            $service->mutex();
            $chat = db()->one('SELECT * FROM ' . db()->table('chats') . ' WHERE id=? AND (a=? OR b=?) AND (ended_at IS NULL OR ended_at>?) FOR UPDATE', [(int) ($data['chat_id'] ?? 0), $sid, $sid, gmdate('Y-m-d H:i:s', time() - 600)]);
            if (!$chat) {
                Http::json(['error' => t('chat_ended')], 409);
            }
            if (db()->one('SELECT id FROM ' . db()->table('reports') . ' WHERE chat_id=? AND reporter_id=?', [$chat['id'], $sid])) {
                Http::json(['error' => t('already_reported')], 409);
            }
            $target = (int) ($chat['a'] == $sid ? $chat['b'] : $chat['a']);
            $id = $this->saveReport($chat, $sid, $target, $reason, (string) ($data['detail'] ?? ''));
            if ($reason === 'minor') {
                // Временная изоляция, не окончательный вывод о возрасте.
                $this->addBan($target, 'session', 1, 'Проверка возраста / Жашын текшерүү', null);
            }
            $service->end($sid, 'report');
            return $id;
        });
    }

    public function addBan(int $sid, string $scope, int $hours, string $reason, ?int $admin): void
    {
        $service = new ChatService();
        $p = $service->profile($sid);
        $values = $p ? ['session' => (string) $sid, 'ip' => $p['ip_hash'], 'fingerprint' => $p['fingerprint']] : [];
        if (empty($values[$scope])) {
            throw new \InvalidArgumentException(t('identity_expired'));
        }
        db()->run('INSERT INTO ' . db()->table('bans') . '(scope,value,reason,expires_at,created_at,admin_id) VALUES (?,?,?,?,UTC_TIMESTAMP(),?)', [$scope, $values[$scope], mb_substr($reason, 0, 500), $hours > 0 ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null, $admin]);
        if ($scope === 'session') {
            $affected = [['id' => $sid]];
        } else {
            $column = $scope === 'ip' ? 'ip_hash' : 'fingerprint';
            $affected = db()->all('SELECT id FROM ' . db()->table('sessions') . " WHERE $column=?", [$values[$scope]]);
        }
        foreach ($affected as $row) {
            $service->end((int) $row['id'], 'banned');
        }
    }
}
