<?php

namespace App\Services;

use App\Models\Settings;

class CleanupService
{
    public function run(bool $force = false): void
    {
        // Неблокирующий файловый lock + время в БД: без обязательного cron.
        $lock = fopen(ROOT . '/storage/cleanup.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) {
                fclose($lock);
            }
            return;
        }
        try {
            $row = db()->one('SELECT value FROM ' . db()->table('settings') . " WHERE name='_cleanup_at'");
            if (!$force && (int) ($row['value'] ?? 0) > time() - 60) {
                return;
            }
            Settings::put('_cleanup_at', (string) time());
            $checked = 0;
            foreach (new \DirectoryIterator(ROOT . '/storage/sessions') as $file) {
                if (++$checked > 1000) {
                    break;
                }
                if ($file->isFile() && strpos($file->getFilename(), 'sess_') === 0 && $file->getMTime() < time() - 1800) {
                    @unlink($file->getPathname());
                }
            }
            db()->transaction(function () {
                $service = new ChatService();
                $service->mutex();
                $ttl = setting('log_messages') === '1' ? (int) setting('message_days') * 86400 : 120;
                $cut = gmdate('Y-m-d H:i:s', time() - $ttl);
                db()->run('DELETE FROM ' . db()->table('messages') . ' WHERE created_at<? LIMIT 1000', [$cut]);
                db()->run('DELETE FROM ' . db()->table('reports') . ' WHERE created_at<? LIMIT 500', [gmdate('Y-m-d H:i:s', time() - (int) setting('report_days') * 86400)]);
                db()->run('DELETE FROM ' . db()->table('admin_logs') . ' WHERE created_at<? LIMIT 1000', [gmdate('Y-m-d H:i:s', time() - (int) setting('audit_days') * 86400)]);
                db()->run('DELETE FROM ' . db()->table('rate_limits') . ' WHERE expires_at<UTC_TIMESTAMP() LIMIT 1000');
                db()->run('DELETE FROM ' . db()->table('bans') . ' WHERE (revoked_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()) AND created_at<? LIMIT 1000', [gmdate('Y-m-d H:i:s', time() - (int) setting('identity_days') * 86400)]);
                db()->run('DELETE FROM ' . db()->table('bans') . " WHERE scope IN ('ip','fingerprint') AND created_at<? LIMIT 1000", [gmdate('Y-m-d H:i:s', time() - (int) setting('identity_days') * 86400)]);
                db()->run('UPDATE ' . db()->table('sessions') . ' SET ip_hash=NULL,fingerprint=NULL WHERE identity_at<? AND (ip_hash IS NOT NULL OR fingerprint IS NOT NULL) LIMIT 1000', [gmdate('Y-m-d H:i:s', time() - (int) setting('identity_days') * 86400)]);
                db()->run('DELETE FROM ' . db()->table('chats') . ' WHERE ended_at IS NOT NULL AND ended_at<? LIMIT 500', [gmdate('Y-m-d H:i:s', time() - max((int) setting('message_days'), 1) * 86400)]);
                db()->run('DELETE u FROM ' . db()->table('users') . ' u JOIN ' . db()->table('sessions') . ' s ON s.user_id=u.id WHERE s.last_seen<?', [gmdate('Y-m-d H:i:s', time() - (int) setting('session_days') * 86400)]);
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
