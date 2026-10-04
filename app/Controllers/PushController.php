<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Services\AnonymousSession;
use App\Services\ChatService;
use App\Services\PushService;

class PushController
{
    public function handle(string $route, string $method): void
    {
        if ($method !== 'POST' || !in_array($route, ['prepare', 'subscribe', 'unsubscribe'], true)) {
            Http::json(['error' => t('invalid')], 405);
        }
        Http::csrf();
        AnonymousSession::restore();
        $sid = (int) ($_SESSION['sid'] ?? 0);
        $profile = $sid ? (new ChatService())->profile($sid) : null;
        if (!$profile || (int) $profile['age'] < 18) {
            Http::json(['error' => t('adult_error')], 401);
        }
        if ((new ChatService())->ban($profile)) {
            Http::json(['error' => t('banned')], 403);
        }
        if (!Security::hit('push-api:' . $sid, 30, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        $data = Http::input();
        session_write_close();
        try {
            if ($route === 'prepare') {
                Http::json(['ok' => true, 'key' => PushService::prepare()]);
            }
            if ($route === 'subscribe') {
                if (setting('chat_enabled') !== '1') {
                    Http::json(['error' => t('maintenance')], 503);
                }
                PushService::subscribe($sid, $data);
            } else {
                PushService::unsubscribe($sid, (string) ($data['endpoint'] ?? ''));
            }
            Http::json(['ok' => true]);
        } catch (\InvalidArgumentException $exception) {
            Http::json(['error' => $exception->getMessage()], 422);
        }
    }
}
