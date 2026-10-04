<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Services\ChatService;
use App\Services\ModerationService;
use App\Services\CleanupService;
use App\Services\AnonymousSession;

class ChatController
{
    private function logUserEvent(string $event, int $sid, ?int $referenceId = null): void
    {
        $detail = ['session_id' => $sid];
        if ($referenceId !== null) {
            $detail['reference_id'] = $referenceId;
        }
        try {
            db()->run(
                'INSERT INTO ' . db()->table('admin_logs') . '(admin_id,action,detail,created_at) VALUES (NULL,?,?,UTC_TIMESTAMP())',
                ['user_' . $event, json_encode($detail, JSON_UNESCAPED_SLASHES) ?: '{}']
            );
        } catch (\Throwable $exception) {
            error_log('User event log write failed: ' . get_class($exception));
        }
    }

    private function challenge(): array
    {
        $a = random_int(2, 20);
        $b = random_int(1, 15);
        $payload = base64_encode(json_encode([$a, $b, time() + 120, $_SESSION['csrf']]));
        return ['question' => "$a + $b = ?", 'token' => $payload . '.' . Security::hash($payload)];
    }

    private function captcha(array $data): bool
    {
        $parts = explode('.', (string) ($data['captcha_token'] ?? ''), 2);
        if (count($parts) !== 2 || !hash_equals(Security::hash($parts[0]), $parts[1])) {
            return false;
        }
        $p = json_decode(base64_decode($parts[0], true) ?: '', true);
        return is_array($p) && count($p) === 4 && $p[2] >= time() && hash_equals($_SESSION['csrf'], (string) $p[3]) && (string) ($p[0] + $p[1]) === trim((string) ($data['captcha_answer'] ?? ''));
    }

    private function entry(array $data): void
    {
        if (setting('entry_enabled') !== '1' || setting('chat_enabled') !== '1') {
            Http::json(['error' => t('maintenance')], 503);
        }
        if (!Security::hit('entry-hard:' . Security::ip(), 20, 3600)) {
            Http::json(['error' => t('rate')], 429);
        }
        if (!Security::hit('entry-soft:' . Security::ip(), 3, 3600) && !$this->captcha($data)) {
            Http::json(['error' => t('captcha'), 'captcha' => $this->challenge()], 429);
        }
        $age = filter_var($data['age'] ?? null, FILTER_VALIDATE_INT);
        $city = (int) ($data['city'] ?? 0);
        $gender = $data['gender'] ?? '';
        $nick = trim((string) ($data['nickname'] ?? ''));
        $device = (string) ($data['device'] ?? '');
        if ($age === false || $age < max(18, (int) setting('min_age')) || $age > (int) setting('max_age') || empty($data['adult']) || empty($data['rules']) || !in_array($gender, ['m', 'f'], true) || mb_strlen($nick) > 40 || !preg_match('/^[a-f0-9]{32,64}$/', $device) || !db()->one('SELECT id FROM ' . db()->table('cities') . ' WHERE id=? AND enabled=1', [$city])) {
            Http::json(['error' => t('adult_error')], 422);
        }
        $probe = ['id' => (int) ($_SESSION['sid'] ?? 0), 'ip_hash' => Security::ip(), 'fingerprint' => Security::hash($device)];
        $service = new ChatService();
        if ($service->ban($probe)) {
            Http::json(['error' => t('banned')], 403);
        }
        if (!empty($_SESSION['sid']) && $service->profile((int) $_SESSION['sid'])) {
            $this->logUserEvent('entry', (int) $_SESSION['sid']);
            Http::json(['ok' => true]);
        }
        $sid = db()->transaction(function () use ($nick, $gender, $age, $city, $probe) {
            db()->run('INSERT INTO ' . db()->table('users') . '(nickname,gender,age,city_id,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$nick === '' ? t('anonymous') : $nick, $gender, $age, $city]);
            $user = db()->id();
            db()->run('INSERT INTO ' . db()->table('sessions') . '(user_id,ip_hash,fingerprint,last_seen,identity_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$user, $probe['ip_hash'], $probe['fingerprint']]);
            return db()->id();
        });
        session_regenerate_id(true);
        $_SESSION['sid'] = $sid;
        AnonymousSession::remember($sid);
        $this->logUserEvent('entry', $sid);
        Http::json(['ok' => true]);
    }

    public function handle(string $route, string $method): void
    {
        if (!in_array($route, ['entry', 'state', 'join', 'next', 'leave', 'send', 'like', 'typing', 'read', 'report', 'forget', 'warning', 'profile'], true)) {
            Http::json(['error' => t('not_found')], 404);
        }
        if (($route === 'state' && $method !== 'GET') || ($route !== 'state' && $method !== 'POST')) {
            Http::json(['error' => t('invalid')], 405);
        }
        Http::csrf();
        AnonymousSession::restore();
        $data = $method === 'POST' ? Http::input() : [];
        if ($route === 'entry') {
            $this->entry($data);
        }
        $sid = (int) ($_SESSION['sid'] ?? 0);
        $service = new ChatService();
        $profile = $sid ? $service->profile($sid) : null;
        if (!$profile) {
            Http::json(['state' => 'guest'], 200);
        }
        AnonymousSession::remember($sid);
        if (!Security::hit('api:' . $sid, 150, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        if ($route === 'profile' && !Security::hit('profile:' . $sid, 10, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        if ($route === 'send') {
            if (!Security::hit('send-hard:' . $sid, (int) setting('message_rate') * 2, 60) || !Security::hit('send-ip:' . Security::ip(), (int) setting('ip_message_rate'), 60)) {
                Http::json(['error' => t('rate')], 429);
            }
            if (!Security::hit('send-soft:' . $sid, (int) setting('message_rate'), 60) && !$this->captcha($data)) {
                Http::json(['error' => t('captcha'), 'captcha' => $this->challenge()], 429);
            }
        }
        if ($route === 'like' && !Security::hit('like:' . $sid, 90, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        if (in_array($route, ['join', 'next', 'leave'], true) && !Security::hit('pair:' . $sid, 15, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        if ($route === 'report' && (!Security::hit('report:' . $sid, 3, 3600) || !Security::hit('report-ip:' . Security::ip(), 10, 3600))) {
            Http::json(['error' => t('rate')], 429);
        }
        if (in_array($route, ['join', 'next', 'send', 'like', 'typing'], true) && ($service->ban($profile) || setting('chat_enabled') !== '1')) {
            Http::json(['error' => t('banned')], 403);
        }
        if ($route === 'forget') {
            $service->leave($sid);
            db()->run('DELETE FROM ' . db()->table('users') . ' WHERE id=?', [$profile['user_id']]);
            AnonymousSession::forget();
            unset($_SESSION['sid']);
            session_regenerate_id(true);
            $this->logUserEvent('forget', $sid);
            Http::json(['ok' => true]);
        }
        if ($route === 'profile') {
            $service->updateProfile($sid, $data);
            $this->logUserEvent('profile', $sid);
            Http::json(['ok' => true]);
        }
        // Файловая PHP-сессия не должна блокировать отправку при long-poll.
        session_write_close();
        (new CleanupService())->run();
        if ($route === 'state') {
            $cursor = max(0, (int) ($_GET['cursor'] ?? 0));
            $messageIds = [];
            foreach (explode(',', (string) ($_GET['message_ids'] ?? '')) as $rawId) {
                if (preg_match('/^[1-9][0-9]{0,18}$/', $rawId)) {
                    $messageIds[] = (int) $rawId;
                }
                if (count($messageIds) >= 300) {
                    break;
                }
            }
            $messageIds = array_values(array_unique($messageIds));
            $state = $service->snapshot($sid, $cursor, $messageIds);
            $long = setting('transport') === 'long' && ($_GET['transport'] ?? '') === 'long';
            if ($long && in_array($state['state'], ['queue', 'chat'], true) && empty($state['messages'])) {
                $maxExecution = (int) ini_get('max_execution_time');
                $seconds = min(20, max(1, (int) setting('long_poll_seconds')), $maxExecution > 0 ? max(1, $maxExecution - 5) : 20);
                $until = microtime(true) + $seconds;
                $original = json_encode($state);
                while (microtime(true) < $until && !connection_aborted()) {
                    usleep(1000000);
                    $state = $service->snapshot($sid, $cursor, $messageIds);
                    if (json_encode($state) !== $original) {
                        break;
                    }
                }
            }
            Http::json($state);
        }
        if ($route === 'join' || $route === 'next') {
            $service->join($sid, $service->filters($data), $route === 'next');
            $this->logUserEvent($route, $sid);
        } elseif ($route === 'leave') {
            $service->leave($sid);
            $this->logUserEvent('leave', $sid);
        } elseif ($route === 'send') {
            $messageId = $service->message($sid, $data);
            $this->logUserEvent('message', $sid);
            Http::json(['ok' => true, 'id' => $messageId]);
        } elseif ($route === 'like') {
            $result = $service->toggleLike($sid, (int) ($data['chat_id'] ?? 0), (int) ($data['message_id'] ?? 0));
            Http::json(['ok' => true] + $result);
        } elseif ($route === 'typing') {
            $service->typing($sid, (int) ($data['chat_id'] ?? 0), !empty($data['typing']));
        } elseif ($route === 'read') {
            $service->receipt($sid, (int) ($data['chat_id'] ?? 0), (int) ($data['id'] ?? 0));
        } elseif ($route === 'report') {
            $reportId = (new ModerationService())->report($sid, $data);
            $this->logUserEvent('report', $sid, $reportId);
            Http::json(['ok' => true, 'id' => $reportId]);
        } elseif ($route === 'warning') {
            db()->run('UPDATE ' . db()->table('sessions') . ' SET warning=NULL WHERE id=?', [$sid]);
        }
        Http::json(['ok' => true]);
    }
}
