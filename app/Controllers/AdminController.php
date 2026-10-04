<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Models\Settings;
use App\Services\ChatService;
use App\Services\ModerationService;
use App\Services\CleanupService;
use App\Services\BackupService;

class AdminController
{
    private $admin;
    private $sections = ['dashboard', 'users', 'reports', 'chats', 'bans', 'cities', 'stopwords', 'settings', 'admins', 'logs', 'security', 'backup', 'blog'];
    private $superOnly = ['cities', 'stopwords', 'settings', 'admins', 'logs', 'backup', 'blog'];

    private function audit(string $action, string $detail): void
    {
        db()->run('INSERT INTO ' . db()->table('admin_logs') . '(admin_id,action,detail,created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$this->admin['id'], $action, mb_substr($detail, 0, 1000)]);
    }

    private function login(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Http::csrf();
            $login = mb_substr(trim((string) ($_POST['login'] ?? '')), 0, 64);
            $password = (string) ($_POST['password'] ?? '');
            $allowedIp = Security::hit('admin-ip:' . Security::ip(), 30, 900);
            $allowedUser = Security::hit('admin-login:' . mb_strtolower($login), 10, 900);
            $captcha = (int) ($_SESSION['admin_failures'] ?? 0) < 3 || ((string) ($_POST['captcha'] ?? '') === (string) ($_SESSION['admin_captcha'] ?? 'missing'));
            $row = db()->one('SELECT * FROM ' . db()->table('admins') . ' WHERE login=? AND enabled=1', [$login]);
            // Вычисление хеша сохраняет сопоставимое время для неизвестного логина.
            $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $valid = password_verify($password, $row['password'] ?? $dummy);
            if ($allowedIp && $allowedUser && $captcha && $row && $valid && strlen($password) <= 128) {
                $twoFactor = true;
                if ($row['totp_secret']) {
                    $twoFactor = db()->transaction(function () use ($row) {
                        $fresh = db()->one('SELECT * FROM ' . db()->table('admins') . ' WHERE id=? FOR UPDATE', [$row['id']]);
                        $step = Security::totpStep($fresh['totp_secret'], (string) ($_POST['totp'] ?? ''), (int) $fresh['totp_last']);
                        if ($step === null) {
                            return false;
                        }
                        db()->run('UPDATE ' . db()->table('admins') . ' SET totp_last=? WHERE id=?', [$step, $row['id']]);
                        return true;
                    });
                }
                if ($twoFactor) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = (int) $row['id'];
                    $_SESSION['admin_at'] = time();
                    $_SESSION['admin_failures'] = 0;
                    unset($_SESSION['admin_captcha']);
                    $this->admin = $row;
                    $this->audit('login', 'success');
                    Http::redirect('admin');
                }
            }
            $_SESSION['admin_failures'] = (int) ($_SESSION['admin_failures'] ?? 0) + 1;
            $error = t('login_failed');
        }
        $question = '';
        if ((int) ($_SESSION['admin_failures'] ?? 0) >= 3) {
            $a = random_int(2, 20);
            $b = random_int(1, 10);
            $_SESSION['admin_captcha'] = (string) ($a + $b);
            $question = "$a + $b = ?";
        }
        Http::view('admin-login', ['error' => $error, 'question' => $question]);
    }

    public function handle(string $path, string $method): void
    {
        $GLOBALS['dict']['blog'] = 'Блог';
        if (isset($_SESSION['admin_id']) && time() - (int) ($_SESSION['admin_at'] ?? 0) > 1800) {
            unset($_SESSION['admin_id']);
        }
        $this->admin = isset($_SESSION['admin_id']) ? db()->one('SELECT * FROM ' . db()->table('admins') . ' WHERE id=? AND enabled=1', [$_SESSION['admin_id']]) : null;
        if (!$this->admin) {
            $this->login();
            return;
        }
        $_SESSION['admin_at'] = time();
        $section = trim(substr($path, 6), '/') ?: 'dashboard';
        if (!in_array($section, $this->sections, true)) {
            http_response_code(404);
            Http::view('page', ['page' => '404']);
            return;
        }
        if (in_array($section, $this->superOnly, true) && $this->admin['role'] !== 'super') {
            http_response_code(403);
            echo e(t('forbidden'));
            return;
        }
        if ($section === 'backup' && $method === 'GET' && isset($_GET['download'])) {
            $file = (new BackupService())->pathForDownload((string) $_GET['download']);
            if (!$file) {
                http_response_code(404);
                return;
            }
            $this->audit('backup_download', basename($file));
            session_write_close();
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Content-Length: ' . (string) filesize($file));
            readfile($file);
            return;
        }
        if ($section === 'logs' && $method === 'GET' && isset($_GET['feed'])) {
            $this->logsFeed();
            return;
        }
        if ($section === 'blog') {
            (new AdminBlogController())->handle($this->admin, $method, $this->sections, $this->superOnly);
            return;
        }
        $error = '';
        if ($method === 'POST') {
            Http::csrf();
            if (!Security::hit('admin-action:' . $this->admin['id'], 60, 60)) {
                Http::json(['error' => t('rate')], 429);
            }
            try {
                $this->action($section);
                $_SESSION['admin_flash'] = t('saved');
                Http::redirect('admin/' . $section);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        } elseif ($method !== 'GET') {
            http_response_code(405);
            return;
        }
        (new CleanupService())->run();
        $data = $this->data($section);
        Http::view('admin', $data + ['section' => $section, 'admin' => $this->admin, 'sections' => $this->sections, 'superOnly' => $this->superOnly, 'error' => $error]);
    }

    private function invalid(): void
    {
        throw new \InvalidArgumentException(t('invalid'));
    }

    private function logsFeed(): void
    {
        $level = (string) ($_GET['level'] ?? 'all');
        if (!in_array($level, ['all', 'success', 'error', 'audit', 'users'], true)) {
            $level = 'all';
        }
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $where = [];
        $args = [];
        if ($after > 0) {
            $where[] = 'l.id>?';
            $args[] = $after;
        }
        if ($level === 'success') {
            $where[] = "l.action='request_success'";
        } elseif ($level === 'error') {
            $where[] = "l.action='request_error'";
        } elseif ($level === 'audit') {
            $where[] = "l.action NOT IN ('request_success','request_error') AND LEFT(l.action,5)<>'user_'";
        } elseif ($level === 'users') {
            $where[] = "LEFT(l.action,5)='user_'";
        }
        if ($query !== '') {
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            $where[] = '(l.action LIKE ? OR l.detail LIKE ? OR a.login LIKE ?)';
            array_push($args, $search, $search, $search);
        }
        $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $rows = db()->all(
            'SELECT l.id,a.login,l.action,l.detail,l.created_at FROM ' . db()->table('admin_logs') . ' l LEFT JOIN ' . db()->table('admins') . ' a ON a.id=l.admin_id' . $condition . ' ORDER BY l.id DESC LIMIT 100',
            $args
        );
        Http::json(['rows' => $rows]);
    }

    private function requirePassword(): void
    {
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $this->admin['password'])) {
            throw new \InvalidArgumentException(t('login_failed'));
        }
    }

    private function action(string $section): void
    {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'logout') {
            $this->audit('logout', '');
            unset($_SESSION['admin_id'], $_SESSION['pending_totp']);
            session_regenerate_id(true);
            Http::redirect('admin');
        }
        if ($section === 'users' && $action === 'ban') {
            $scope = (string) ($_POST['scope'] ?? 'session');
            $hours = filter_var($_POST['hours'] ?? null, FILTER_VALIDATE_INT);
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (!in_array($scope, ['session', 'ip', 'fingerprint'], true) || $hours === false || $hours < 0 || $hours > 87600 || $reason === '') {
                $this->invalid();
            }
            db()->transaction(function () use ($id, $scope, $hours, $reason) {
                (new ChatService())->mutex();
                (new ModerationService())->addBan($id, $scope, $hours, $reason, (int) $this->admin['id']);
                $this->audit('ban', "session:$id scope:$scope hours:$hours");
            });
        } elseif ($section === 'bans' && $action === 'unban') {
            db()->run('UPDATE ' . db()->table('bans') . ' SET revoked_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
            $this->audit('unban', "ban:$id");
        } elseif ($section === 'reports' && in_array($action, ['dismiss', 'warn', 'ban'], true)) {
            db()->transaction(function () use ($id, $action) {
                $service = new ChatService();
                $service->mutex();
                $report = db()->one('SELECT * FROM ' . db()->table('reports') . " WHERE id=? AND status='pending' FOR UPDATE", [$id]);
                if (!$report) {
                    $this->invalid();
                }
                if ($action !== 'dismiss' && !$report['target_id']) {
                    $this->invalid();
                }
                if ($action === 'warn') {
                    db()->run('UPDATE ' . db()->table('sessions') . ' SET warning=? WHERE id=?', [t('moderator_warning'), $report['target_id']]);
                } elseif ($action === 'ban') {
                    $hours = (int) ($_POST['hours'] ?? 24);
                    if ($hours < 0 || $hours > 87600) {
                        $this->invalid();
                    }
                    (new ModerationService())->addBan((int) $report['target_id'], 'session', $hours, 'report #' . $id . ': ' . $report['reason'], (int) $this->admin['id']);
                }
                db()->run('UPDATE ' . db()->table('reports') . ' SET status=?,resolved_at=UTC_TIMESTAMP(),admin_id=? WHERE id=?', [$action, $this->admin['id'], $id]);
                $this->audit('report_' . $action, "report:$id");
            });
        } elseif ($section === 'cities' && $action === 'save') {
            $ru = trim((string) ($_POST['name_ru'] ?? ''));
            $ky = trim((string) ($_POST['name_ky'] ?? ''));
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            if ($ru === '' || $ky === '' || mb_strlen($ru) > 80 || mb_strlen($ky) > 80) {
                $this->invalid();
            }
            if ($id) {
                db()->run('UPDATE ' . db()->table('cities') . ' SET name_ru=?,name_ky=?,enabled=? WHERE id=?', [$ru, $ky, $enabled, $id]);
            } else {
                db()->run('INSERT INTO ' . db()->table('cities') . '(name_ru,name_ky,enabled) VALUES (?,?,?)', [$ru, $ky, $enabled]);
            }
            $this->audit('city_save', "city:$id");
        } elseif ($section === 'stopwords') {
            if ($action === 'delete') {
                db()->run('DELETE FROM ' . db()->table('stopwords') . ' WHERE id=?', [$id]);
            } elseif ($action === 'save') {
                $kind = (string) ($_POST['kind'] ?? '');
                $ruleAction = (string) ($_POST['rule_action'] ?? '');
                $pattern = trim((string) ($_POST['pattern'] ?? ''));
                $replacement = mb_substr((string) ($_POST['replacement'] ?? '***'), 0, 190);
                if (!in_array($kind, ['word', 'link', 'phone', 'messenger'], true) || !in_array($ruleAction, ['block', 'replace', 'report'], true) || mb_strlen($pattern) > 190 || ($kind === 'word' && $pattern === '')) {
                    $this->invalid();
                }
                $args = [$kind, $pattern, $ruleAction, $replacement, isset($_POST['enabled']) ? 1 : 0];
                if ($id) {
                    $args[] = $id;
                    db()->run('UPDATE ' . db()->table('stopwords') . ' SET kind=?,pattern=?,action=?,replacement=?,enabled=? WHERE id=?', $args);
                } else {
                    db()->run('INSERT INTO ' . db()->table('stopwords') . '(kind,pattern,action,replacement,enabled) VALUES (?,?,?,?,?)', $args);
                }
            } else {
                $this->invalid();
            }
            $this->audit('filter_' . $action, "rule:$id");
        } elseif ($section === 'settings' && $action === 'save') {
            $numbers = ['min_age' => [18, 99], 'max_age' => [18, 99], 'message_length' => [50, 4000], 'message_rate' => [2, 60], 'ip_message_rate' => [10, 1000], 'heartbeat_timeout' => [30, 300], 'idle_timeout' => [60, 3600], 'queue_timeout' => [30, 900], 'poll_seconds' => [2, 3], 'long_poll_seconds' => [5, 20], 'message_days' => [1, 90], 'report_days' => [1, 365], 'identity_days' => [1, 90], 'session_days' => [1, 365], 'audit_days' => [1, 365]];
            $values = [];
            foreach (Settings::defaults() as $key => $default) {
                $value = (string) ($_POST[$key] ?? $default);
                if (isset($numbers[$key])) {
                    $n = filter_var($value, FILTER_VALIDATE_INT);
                    if ($n === false || $n < $numbers[$key][0] || $n > $numbers[$key][1]) {
                        $this->invalid();
                    }
                } elseif (in_array($key, ['chat_enabled', 'entry_enabled', 'log_messages'], true)) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                } elseif ($key === 'transport' && !in_array($value, ['poll', 'long'], true)) {
                    $this->invalid();
                } elseif ($key === 'language' && !in_array($value, ['ru', 'ky'], true)) {
                    $this->invalid();
                } elseif (mb_strlen($value) > (strpos($key, 'rules_') === 0 ? 10000 : 500)) {
                    $this->invalid();
                }
                $values[$key] = $value;
            }
            if ((int) $values['min_age'] > (int) $values['max_age'] || !filter_var($values['support_email'], FILTER_VALIDATE_EMAIL) || trim($values['site_name']) === '') {
                $this->invalid();
            }
            db()->transaction(function () use ($values) {
                foreach ($values as $key => $value) {
                    Settings::put($key, $value);
                }
                $this->audit('settings_save', '');
                if ($values['chat_enabled'] !== '1') {
                    (new ChatService())->mutex();
                    db()->run('UPDATE ' . db()->table('chats') . " SET ended_at=UTC_TIMESTAMP(),end_reason='maintenance' WHERE ended_at IS NULL");
                    db()->run('DELETE FROM ' . db()->table('queue'));
                }
            });
        } elseif ($section === 'admins' && $action === 'save') {
            $this->requirePassword();
            $login = trim((string) ($_POST['login'] ?? ''));
            $email = (string) ($_POST['email'] ?? '');
            $role = (string) ($_POST['role'] ?? 'moderator');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $login) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['super', 'moderator'], true) || (!$id && strlen($password) < 12) || ($password !== '' && (strlen($password) < 12 || strlen($password) > 128))) {
                $this->invalid();
            }
            if ($id === (int) $this->admin['id'] && ($role !== 'super' || !$enabled)) {
                $this->invalid();
            }
            $dupe = db()->one('SELECT id FROM ' . db()->table('admins') . ' WHERE login=? AND id<>?', [$login, $id]);
            if ($dupe) {
                $this->invalid();
            }
            if ($id) {
                db()->run('UPDATE ' . db()->table('admins') . ' SET login=?,email=?,role=?,enabled=? WHERE id=?', [$login, $email, $role, $enabled, $id]);
                if ($password !== '') {
                    db()->run('UPDATE ' . db()->table('admins') . ' SET password=? WHERE id=?', [Security::password($password), $id]);
                }
            } else {
                db()->run('INSERT INTO ' . db()->table('admins') . '(login,password,email,role,enabled,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())', [$login, Security::password($password), $email, $role, $enabled]);
            }
            $this->audit('admin_save', "admin:$id");
        } elseif ($section === 'security') {
            $this->requirePassword();
            if ($action === 'totp_prepare' && !$this->admin['totp_secret']) {
                $_SESSION['pending_totp'] = Security::totpSecret();
            } elseif ($action === 'totp_enable' && !empty($_SESSION['pending_totp']) && !$this->admin['totp_secret']) {
                $step = Security::totpStep($_SESSION['pending_totp'], (string) ($_POST['totp'] ?? ''));
                if ($step === null) {
                    $this->invalid();
                }
                db()->run('UPDATE ' . db()->table('admins') . ' SET totp_secret=?,totp_last=? WHERE id=?', [$_SESSION['pending_totp'], $step, $this->admin['id']]);
                unset($_SESSION['pending_totp']);
                $this->audit('totp_enable', '');
            } elseif ($action === 'totp_disable' && $this->admin['totp_secret']) {
                if (Security::totpStep($this->admin['totp_secret'], (string) ($_POST['totp'] ?? ''), (int) $this->admin['totp_last']) === null) {
                    $this->invalid();
                }
                db()->run('UPDATE ' . db()->table('admins') . ' SET totp_secret=NULL,totp_last=-1 WHERE id=?', [$this->admin['id']]);
                $this->audit('totp_disable', '');
            } elseif ($action === 'password') {
                $password = (string) ($_POST['password'] ?? '');
                if (strlen($password) < 12 || strlen($password) > 128) {
                    $this->invalid();
                }
                db()->run('UPDATE ' . db()->table('admins') . ' SET password=? WHERE id=?', [Security::password($password), $this->admin['id']]);
                session_regenerate_id(true);
                $this->audit('password_change', '');
            } else {
                $this->invalid();
            }
        } elseif ($section === 'backup' && $action === 'cleanup') {
            (new CleanupService())->run(true);
            $this->audit('cleanup', 'bounded batch');
        } elseif ($section === 'backup' && $action === 'create') {
            $this->requirePassword();
            try {
                $filename = (new BackupService())->create();
            } catch (\Throwable $exception) {
                error_log('Manual backup failed: ' . get_class($exception));
                Settings::put('_backup_error', 'Backup failed: ' . get_class($exception));
                throw new \InvalidArgumentException(t('backup_failed'));
            }
            $this->audit('backup_manual', $filename);
        } elseif ($section === 'backup' && $action === 'export') {
            $this->requirePassword();
            $this->export();
        } else {
            $this->invalid();
        }
    }

    private function data(string $section): array
    {
        $page = min(10000, max(1, (int) ($_GET['page'] ?? 1)));
        $offset = ($page - 1) * 50;
        $data = ['rows' => [], 'pageNumber' => $page];
        if ($section === 'dashboard') {
            $data['stats'] = [];
            $queries = [
                'online' => ['sessions', 'last_seen>?', [gmdate('Y-m-d H:i:s', time() - (int) setting('heartbeat_timeout'))]],
                'queued' => ['queue', 'expires_at>UTC_TIMESTAMP()', []],
                'chats_today' => ['chats', 'started_at>?', [gmdate('Y-m-d H:i:s', time() - 86400)]],
                'chats_week' => ['chats', 'started_at>?', [gmdate('Y-m-d H:i:s', time() - 604800)]],
                'profiles' => ['users', 'created_at>?', [gmdate('Y-m-d H:i:s', time() - 86400)]],
                'reports' => ['reports', "status='pending'", []],
            ];
            foreach ($queries as $key => $q) {
                $data['stats'][$key] = (int) db()->one('SELECT COUNT(*) n FROM ' . db()->table($q[0]) . ' WHERE ' . $q[1], $q[2])['n'];
            }
            $data['chart'] = db()->all('SELECT DATE(started_at) day,COUNT(*) n FROM ' . db()->table('chats') . ' WHERE started_at>? GROUP BY DATE(started_at) ORDER BY day', [gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
            $data['cities'] = db()->all('SELECT c.name_' . lang() . ' label,COUNT(*) n FROM ' . db()->table('users') . ' u JOIN ' . db()->table('cities') . ' c ON c.id=u.city_id GROUP BY c.id ORDER BY n DESC LIMIT 15');
            $data['genders'] = db()->all('SELECT gender label,COUNT(*) n FROM ' . db()->table('users') . ' GROUP BY gender');
            $data['ages'] = db()->all('SELECT FLOOR(age/10)*10 label,COUNT(*) n FROM ' . db()->table('users') . ' GROUP BY FLOOR(age/10)');
            $data['views'] = db()->all('SELECT name,value FROM ' . db()->table('settings') . " WHERE name LIKE 'pv_%' ORDER BY name DESC LIMIT 14");
        } elseif ($section === 'users') {
            $q = mb_substr((string) ($_GET['q'] ?? ''), 0, 80);
            $data['query'] = $q;
            $data['rows'] = db()->all('SELECT s.id,u.nickname,u.age,u.gender,c.name_' . lang() . ' city,s.last_seen FROM ' . db()->table('sessions') . ' s JOIN ' . db()->table('users') . ' u ON u.id=s.user_id JOIN ' . db()->table('cities') . ' c ON c.id=u.city_id WHERE u.nickname LIKE ? OR s.id=? ORDER BY s.id DESC LIMIT 50 OFFSET ' . $offset, ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%', (int) $q]);
            if (isset($_GET['id'])) {
                $data['profile'] = (new ChatService())->profile((int) $_GET['id']);
            }
        } elseif ($section === 'reports') {
            $data['rows'] = db()->all('SELECT id,chat_id,reason,status,priority,created_at FROM ' . db()->table('reports') . ' ORDER BY (status=\'pending\') DESC,priority DESC,id DESC LIMIT 50 OFFSET ' . $offset);
            if (isset($_GET['id'])) {
                $data['report'] = db()->one('SELECT * FROM ' . db()->table('reports') . ' WHERE id=?', [(int) $_GET['id']]);
                if ($data['report']) {
                    $data['evidence'] = json_decode($data['report']['evidence'], true) ?: [];
                    $this->audit('report_view', 'report:' . (int) $_GET['id']);
                }
            }
        } elseif ($section === 'chats') {
            $data['active'] = (int) db()->one('SELECT COUNT(*) n FROM ' . db()->table('chats') . ' WHERE ended_at IS NULL')['n'];
            $data['rows'] = db()->all('SELECT id,a,b,started_at,ended_at,end_reason FROM ' . db()->table('chats') . ' ORDER BY id DESC LIMIT 50 OFFSET ' . $offset);
        } elseif ($section === 'bans') {
            $data['rows'] = db()->all('SELECT * FROM ' . db()->table('bans') . ' ORDER BY id DESC LIMIT 50 OFFSET ' . $offset);
        } elseif ($section === 'cities' || $section === 'stopwords') {
            $data['rows'] = db()->all('SELECT * FROM ' . db()->table($section) . ' ORDER BY id LIMIT 50 OFFSET ' . $offset);
            if (isset($_GET['id'])) {
                $data['edit'] = db()->one('SELECT * FROM ' . db()->table($section) . ' WHERE id=?', [(int) $_GET['id']]);
            }
        } elseif ($section === 'admins') {
            $data['rows'] = db()->all('SELECT id,login,email,role,enabled,(totp_secret IS NOT NULL) has_totp FROM ' . db()->table('admins') . ' ORDER BY id LIMIT 50 OFFSET ' . $offset);
            if (isset($_GET['id'])) {
                $data['edit'] = db()->one('SELECT id,login,email,role,enabled FROM ' . db()->table('admins') . ' WHERE id=?', [(int) $_GET['id']]);
            }
        } elseif ($section === 'backup') {
            $data['backup'] = (new BackupService())->status();
        }
        return $data;
    }

    private function export(): void
    {
        $this->audit('db_export', '');
        session_write_close();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="kg-chat-' . gmdate('Ymd-His') . '.sql"');
        echo "-- KG Chat backup. Contains sensitive pseudonymous data. UTC.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
        // Чтение в одной транзакции даёт согласованный снимок таблиц InnoDB.
        db()->run('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        db()->transaction(function () {
            $tables = ['cities', 'users', 'sessions', 'chats', 'queue', 'messages', 'admins', 'reports', 'bans', 'admin_logs', 'settings', 'stopwords', 'rate_limits'];
            if (\App\Models\Blog::ready()) {
                $tables = array_merge($tables, ['blog_posts', 'blog_media', 'blog_post_media']);
            }
            foreach ($tables as $name) {
                $table = db()->table($name);
                $ddl = db()->one("SHOW CREATE TABLE $table");
                echo "DROP TABLE IF EXISTS $table;\n" . $ddl['Create Table'] . ";\n";
                $offset = 0;
                $batch = in_array($name, ['reports', 'blog_posts'], true) ? 10 : 250;
                do {
                    $order = $name === 'settings' ? 'name' : ($name === 'rate_limits' ? 'bucket' : ($name === 'queue' ? 'session_id' : 'id'));
                    $orderSql = $name === 'blog_post_media' ? '`post_id`,`media_id`' : '`' . $order . '`';
                    $rows = db()->all("SELECT * FROM $table ORDER BY $orderSql LIMIT " . $batch . " OFFSET " . $offset);
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) {
                            // Hex-литералы не зависят от NO_BACKSLASH_ESCAPES/SQL mode.
                            $values[] = $value === null ? 'NULL' : "X'" . bin2hex((string) $value) . "'";
                        }
                        echo "INSERT INTO $table VALUES (" . implode(',', $values) . ");\n";
                    }
                    $offset += $batch;
                } while (count($rows) === $batch);
            }
        });
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit;
    }
}
