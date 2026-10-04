# Кезик — полный код проекта

```text
kg-chat/
├── .htaccess
├── README.md
├── SOURCE.md
├── app/
│   ├── .htaccess
│   ├── Controllers/
│   │   ├── AdminController.php
│   │   └── ChatController.php
│   ├── Core/
│   │   ├── Db.php
│   │   ├── Http.php
│   │   └── Security.php
│   ├── Lang/
│   │   ├── ky.php
│   │   └── ru.php
│   ├── Models/
│   │   └── Settings.php
│   ├── Services/
│   │   ├── ChatService.php
│   │   ├── CleanupService.php
│   │   └── ModerationService.php
│   ├── Views/
│   │   ├── admin-login.php
│   │   ├── admin.php
│   │   ├── footer.php
│   │   ├── header.php
│   │   ├── home.php
│   │   ├── install.php
│   │   └── page.php
│   └── bootstrap.php
├── config/
│   ├── .htaccess
│   └── config.example.php
├── install/
│   ├── .htaccess
│   ├── Installer.php
│   └── install.sql
├── public/
│   ├── .htaccess
│   ├── assets/
│   │   ├── admin.js
│   │   ├── chat.js
│   │   ├── icons.svg
│   │   ├── logo.svg
│   │   ├── style.css
│   │   └── theme.js
│   └── index.php
├── storage/
│   └── .htaccess
└── tests/
    ├── .htaccess
    ├── README.md
    ├── integration.php
    └── router.php
```

## Файл: .htaccess

```apache
Options -Indexes
DirectoryIndex public/index.php
RewriteEngine On
RewriteRule ^(?:README\.md|SOURCE\.md|install\.sql|\.env|.*\.(?:sql|log|bak|zip))$ - [F,L,NC]
# /install — виртуальный маршрут; файлы install/ недоступны напрямую.
RewriteRule ^install/?$ public/index.php [END]
RewriteRule ^(?:app|config|storage|install|tests)(?:/|$) - [F,L]
RewriteRule ^assets/(.*)$ public/assets/$1 [END]
RewriteRule ^public/ - [F,L]
RewriteRule ^ public/index.php [END,QSA]
```

## Файл: app/.htaccess

```apache
Require all denied
```

## Файл: app/Controllers/AdminController.php

```php
<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Models\Settings;
use App\Services\ChatService;
use App\Services\ModerationService;
use App\Services\CleanupService;

class AdminController
{
    private $admin;
    private $sections = ['dashboard', 'users', 'reports', 'chats', 'bans', 'cities', 'stopwords', 'settings', 'admins', 'logs', 'security', 'backup'];
    private $superOnly = ['cities', 'stopwords', 'settings', 'admins', 'logs', 'backup'];

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
        } elseif ($section === 'logs') {
            $data['rows'] = db()->all('SELECT l.id,a.login,l.action,l.detail,l.created_at FROM ' . db()->table('admin_logs') . ' l LEFT JOIN ' . db()->table('admins') . ' a ON a.id=l.admin_id ORDER BY l.id DESC LIMIT 50 OFFSET ' . $offset);
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
            foreach (['cities', 'users', 'sessions', 'chats', 'queue', 'messages', 'admins', 'reports', 'bans', 'admin_logs', 'settings', 'stopwords', 'rate_limits'] as $name) {
                $table = db()->table($name);
                $ddl = db()->one("SHOW CREATE TABLE $table");
                echo "DROP TABLE IF EXISTS $table;\n" . $ddl['Create Table'] . ";\n";
                $offset = 0;
                $batch = $name === 'reports' ? 10 : 250;
                do {
                    $order = $name === 'settings' ? 'name' : ($name === 'rate_limits' ? 'bucket' : ($name === 'queue' ? 'session_id' : 'id'));
                    $rows = db()->all("SELECT * FROM $table ORDER BY `$order` LIMIT " . $batch . " OFFSET " . $offset);
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
```

## Файл: app/Controllers/ChatController.php

```php
<?php

namespace App\Controllers;

use App\Core\Http;
use App\Core\Security;
use App\Services\ChatService;
use App\Services\ModerationService;
use App\Services\CleanupService;

class ChatController
{
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
        Http::json(['ok' => true]);
    }

    public function handle(string $route, string $method): void
    {
        if (!in_array($route, ['entry', 'state', 'join', 'next', 'leave', 'send', 'typing', 'read', 'report', 'forget', 'warning'], true)) {
            Http::json(['error' => t('not_found')], 404);
        }
        if (($route === 'state' && $method !== 'GET') || ($route !== 'state' && $method !== 'POST')) {
            Http::json(['error' => t('invalid')], 405);
        }
        Http::csrf();
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
        if (!Security::hit('api:' . $sid, 150, 60)) {
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
        if (in_array($route, ['join', 'next', 'leave'], true) && !Security::hit('pair:' . $sid, 15, 60)) {
            Http::json(['error' => t('rate')], 429);
        }
        if ($route === 'report' && (!Security::hit('report:' . $sid, 3, 3600) || !Security::hit('report-ip:' . Security::ip(), 10, 3600))) {
            Http::json(['error' => t('rate')], 429);
        }
        if (in_array($route, ['join', 'next', 'send', 'typing'], true) && ($service->ban($profile) || setting('chat_enabled') !== '1')) {
            Http::json(['error' => t('banned')], 403);
        }
        if ($route === 'forget') {
            $service->leave($sid);
            db()->run('DELETE FROM ' . db()->table('users') . ' WHERE id=?', [$profile['user_id']]);
            unset($_SESSION['sid']);
            session_regenerate_id(true);
            Http::json(['ok' => true]);
        }
        // Файловая PHP-сессия не должна блокировать отправку при long-poll.
        session_write_close();
        (new CleanupService())->run();
        if ($route === 'state') {
            $cursor = max(0, (int) ($_GET['cursor'] ?? 0));
            $state = $service->snapshot($sid, $cursor);
            $long = setting('transport') === 'long' && ($_GET['transport'] ?? '') === 'long';
            if ($long && in_array($state['state'], ['queue', 'chat'], true) && empty($state['messages'])) {
                $maxExecution = (int) ini_get('max_execution_time');
                $seconds = min(20, max(1, (int) setting('long_poll_seconds')), $maxExecution > 0 ? max(1, $maxExecution - 5) : 20);
                $until = microtime(true) + $seconds;
                $original = json_encode($state);
                while (microtime(true) < $until && !connection_aborted()) {
                    usleep(1000000);
                    $state = $service->snapshot($sid, $cursor);
                    if (json_encode($state) !== $original) {
                        break;
                    }
                }
            }
            Http::json($state);
        }
        if ($route === 'join' || $route === 'next') {
            $service->join($sid, $service->filters($data), $route === 'next');
        } elseif ($route === 'leave') {
            $service->leave($sid);
        } elseif ($route === 'send') {
            Http::json(['ok' => true, 'id' => $service->message($sid, $data)]);
        } elseif ($route === 'typing') {
            $service->typing($sid, (int) ($data['chat_id'] ?? 0), !empty($data['typing']));
        } elseif ($route === 'read') {
            $service->receipt($sid, (int) ($data['chat_id'] ?? 0), (int) ($data['id'] ?? 0));
        } elseif ($route === 'report') {
            Http::json(['ok' => true, 'id' => (new ModerationService())->report($sid, $data)]);
        } elseif ($route === 'warning') {
            db()->run('UPDATE ' . db()->table('sessions') . ' SET warning=NULL WHERE id=?', [$sid]);
        }
        Http::json(['ok' => true]);
    }
}
```

## Файл: app/Core/Db.php

```php
<?php

namespace App\Core;

use PDO;
use Throwable;

class Db
{
    private $pdo;
    private $prefix;

    public function __construct(array $config)
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,20}$/', $config['prefix'])) {
            throw new \RuntimeException('Invalid prefix');
        }
        $this->prefix = $config['prefix'];
        $this->pdo = new PDO(
            'mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $this->run("SET time_zone = '+00:00'");
        $this->run('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function table(string $name): string
    {
        if (!preg_match('/^[a-z_]+$/', $name)) {
            throw new \RuntimeException('Invalid table');
        }
        return '`' . $this->prefix . $name . '`';
    }

    public function run(string $sql, array $args = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public function one(string $sql, array $args = []): ?array
    {
        return $this->run($sql, $args)->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function all(string $sql, array $args = []): array
    {
        return $this->run($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function id(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback)
    {
        // Повторяем транзакцию целиком при deadlock/lock timeout.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->pdo->beginTransaction();
                $result = $callback();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($e instanceof \PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true) && $attempt < 2) {
                    usleep(random_int(10000, 40000));
                    continue;
                }
                throw $e;
            }
        }
    }
}
```

## Файл: app/Core/Http.php

```php
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
```

## Файл: app/Core/Security.php

```php
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
```

## Файл: app/Lang/ky.php

```php
<?php
// Локализация интерфейса.
return [
    'brand' => 'Кезик',
    'tagline' => 'Бир маек кечиңизди өзгөртөт.',
    'intro' => 'Кыргызстандан кокус маектеш. Каттоо жок. 18+ гана.',
    'enter' => 'Чатка кирүү',
    'profile' => 'Сиздин анкетаңыз',
    'nickname' => 'Ылакап ат (милдеттүү эмес)',
    'gender' => 'Жынысы',
    'm' => 'Эркек',
    'f' => 'Аял',
    'age' => 'Толук жашы',
    'city' => 'Шаар',
    'adult_check' => 'Мен 18 жашка толдум',
    'rules_check' => 'Эрежелерди жана купуялык саясатын кабыл алам',
    'adult_error' => '18 жаштан кийин гана кирүүгө болот. Жашыңызды, шаарды жана макулдукту текшериңиз.',
    'rules' => 'Эрежелер',
    'privacy' => 'Купуялык',
    'contacts' => 'Байланыш',
    '18' => '18+ гана',
    '404' => 'Барак табылган жок',
    'home' => 'Башкы бетке',
    'any' => 'Баары',
    'filters' => 'Кимди издейбиз?',
    'peer_city' => 'Маектештин шаары',
    'peer_gender' => 'Маектештин жынысы',
    'age_range' => 'Жашы баштап / чейин',
    'search' => 'Маектеш табуу',
    'cancel' => 'Жокко чыгаруу',
    'expand' => 'Чыпкаларды кеңейтүү',
    'next' => 'Кийинки',
    'end' => 'Аяктоо',
    'report' => 'Арыз берүү',
    'send' => 'Жөнөтүү',
    'message' => 'Билдирүү жазыңыз…',
    'emoji' => 'Эмодзи',
    'typing' => 'Маектеш жазып жатат…',
    'sent' => 'Жөнөтүлдү',
    'delivered' => 'Жеткирилди',
    'read' => 'Окулду',
    'online' => 'Онлайн',
    'queued' => 'Кезекте',
    'idle' => 'Жаңы маекке даярсызбы?',
    'queue' => 'Ылайыктуу маектешти издеп жатабыз…',
    'chat' => 'Сиз чаттасыз',
    'chat_ended' => 'Чат аяктады. Жаңы маектеш таба аласыз.',
    'queue_expired' => 'Издөө мөөнөтү бүттү. Кайра аракет кылыңыз же чыпкаларды кеңейтиңиз.',
    'anonymous' => 'Аноним',
    'reason' => 'Себеп',
    'detail' => 'Комментарий',
    'spam' => 'Спам',
    'abuse' => 'Кемсинтүү',
    'advertising' => 'Жарнама',
    'explicit' => 'Адепсиз мазмун',
    'minor' => 'Жашы жете элек',
    'fraud' => 'Алдамчылык',
    'other' => 'Башка',
    'auto_filter' => 'Авточыпка',
    'reported' => 'Арыз жөнөтүлдү. Чат аяктады.',
    'already_reported' => 'Бул чат боюнча арыз мурда жөнөтүлгөн.',
    'network' => 'Байланыш жок. Кайра туташууда…',
    'rate' => 'Өтө көп аракет. Бир мүнөт күтүңүз.',
    'captcha' => 'Улантуу үчүн мисалды чыгарыңыз.',
    'too_long' => 'Билдирүү бош же уруксат берилген узундуктан ашты.',
    'filtered' => 'Билдирүүдө тыюу салынган сөздөр же байланыштар бар.',
    'banned' => 'Кирүү убактылуу же толугу менен чектелди. Колдоо кызматына кайрылыңыз.',
    'maintenance' => 'Чат убактылуу тейлөө үчүн жабык.',
    'invalid' => 'Киргизилген маалыматтарды текшериңиз.',
    'csrf' => 'Барак эскирди. Кайра жүктөңүз.',
    'server_error' => 'Сервер катасы. Кийинчерээк аракет кылыңыз.',
    'not_found' => 'Табылган жок',
    'forbidden' => 'Укуктар жетишсиз',
    'identity_expired' => 'Бул бөгөт үчүн маалыматтар өчүрүлгөн. Сессия боюнча бөгөттү тандаңыз.',
    'forget' => 'Анкетаны өзгөртүү / сессияны өчүрүү',
    'forget_confirm' => 'Анкетаны өчүрүп, чатты аяктайсызбы? Арыз көчүрмөлөрү модерация мөөнөтү бүткөнгө чейин сакталат.',
    'theme' => 'Теманы өзгөртүү',
    'close' => 'Жабуу',
    'safety' => 'Акча жана жеке маалыматтарды жөнөтпөңүз. Ыңгайсыз болсо, чатты бүтүрүңүз.',
    'adult_text' => '18 жаштан баштап гана кирүүгө болот. Колдонуучу жашын өзү ырастайт; документтер текшерилбейт. Жашы жете электер катышкан сексуалдык материалдарга тыюу салынат. Шектенсеңиз, арыз баскычын колдонуңуз.',
    'admin' => 'Админ-панель',
    'login' => 'Логин',
    'password' => 'Сырсөз',
    'current_password' => 'Учурдагы сырсөз',
    'email' => 'Email',
    'totp' => '2FA коду',
    'login_failed' => 'Маалыматтар же код туура эмес, же аракеттер өтө көп.',
    'logout' => 'Чыгуу',
    'dashboard' => 'Көрсөткүчтөр',
    'users' => 'Сессиялар',
    'reports' => 'Арыздар',
    'chats' => 'Чаттар',
    'bans' => 'Бөгөттөр',
    'cities' => 'Шаарлар',
    'stopwords' => 'Чыпкалар',
    'settings' => 'Жөндөөлөр',
    'admins' => 'Администраторлор',
    'logs' => 'Аракеттер журналы',
    'security' => 'Коопсуздук',
    'backup' => 'Көчүрмө жана тазалоо',
    'chats_today' => '24 сааттагы чаттар',
    'chats_week' => 'Бир жумадагы чаттар',
    'profiles' => 'Бир күндөгү жаңы анкеталар',
    'chart' => 'Күндөр боюнча чаттар (UTC)',
    'distribution' => 'Анкеталардын бөлүштүрүлүшү',
    'registrations_note' => 'Милдеттүү каттоо жок. Бул жерде анонимдүү анкеталар саналат.',
    'active_chats' => 'Активдүү чаттар',
    'archive_note' => 'Архивде метамаалыматтар бар. Кат алышуу арыздын чегинде гана жеткиликтүү.',
    'save' => 'Сактоо',
    'saved' => 'Сакталды',
    'edit' => 'Өзгөртүү',
    'delete' => 'Өчүрүү',
    'view' => 'Ачуу',
    'enabled' => 'Иштетилген',
    'disabled' => 'Өчүрүлгөн',
    'search_label' => 'Ылакап ат же сессия ID боюнча издөө',
    'empty' => 'Азырынча маалымат жок',
    'previous' => 'Артка',
    'page' => 'Барак',
    'ban' => 'Бөгөт коюу',
    'unban' => 'Бөгөттү алуу',
    'scope' => 'Бөгөт түрү',
    'session' => 'Сессия',
    'ip' => 'IP хеши',
    'fingerprint' => 'Браузер идентификатору',
    'hours' => 'Саат (0 = түбөлүк)',
    'permanent' => 'Түбөлүк',
    'dismiss' => 'Четке кагуу',
    'warn' => 'Эскертүү',
    'moderator_warning' => 'Модератор: эрежелерди сактаңыз. Кайталап бузуу бөгөткө алып келиши мүмкүн.',
    'evidence' => 'Арыз боюнча кат алышуунун көчүрмөсү',
    'evidence_empty' => 'Сакталган билдирүүлөр жок.',
    'pending' => 'Текшерүүнү күтөт',
    'block' => 'Билдирүүнү бөгөттөө',
    'replace' => 'Алмаштыруу',
    'word' => 'Сөз / үзүндү',
    'link' => 'Шилтемелер',
    'phone' => 'Телефондор',
    'messenger' => 'Мессенджерлер / @аттар',
    'pattern' => 'Сөз («сөз» түрү үчүн)',
    'replacement' => 'Алмаштыруу тексти',
    'kind' => 'Чыпка түрү',
    'action' => 'Аракет',
    'role' => 'Роль',
    'super' => 'Башкы админ',
    'moderator' => 'Модератор',
    'new_password' => 'Жаңы сырсөз (бош = өзгөртүүсүз)',
    '2fa_note' => 'Ачкычты аутентификатор колдонмосуна кол менен кошуңуз. Башкаларга бербеңиз. Иштетүү үчүн жаңы код керек. Ачкыч жоголсо, phpMyAdmin аркылуу калыбына келтирүү README файлында жазылган.',
    'totp_prepare' => '2FA даярдоо',
    'totp_enable' => '2FA иштетүү',
    'totp_disable' => '2FA өчүрүү',
    'change_password' => 'Сырсөздү өзгөртүү',
    'export' => 'SQL көчүрмөнү жүктөө',
    'cleanup' => 'Эски маалыматтарды тазалоо',
    'backup_note' => 'SQL көчүрмө сырсөз хештерин, 2FA ачкычтарын жана арыздарды камтыйт. Аны сайттан тышкары коопсуз жерде сактаңыз. Чоң БД үчүн phpMyAdmin экспортун колдонуңуз. Тазалоо бөлүктөр менен аткарылат.',
    'privacy_title' => 'Маалыматтарды кантип колдонобуз',
    'operator' => 'Оператор',
    'hosting' => 'Сервер жайгашкан өлкө',
    'operator_missing' => 'Оператор тууралуу маалымат жарыялана элек. Колдоого кайрылыңыз.',
    'privacy_data' => 'Ылакап ат, көрсөтүлгөн жыныс жана жаш, шаар, кокус сессия ID, IP жана браузер идентификаторунун HMAC хеши сакталат. Маектеш ылакап атты, жынысты, жашты жана шаарды гана көрөт. Хештөө толук анонимдештирүү эмес.',
    'privacy_purpose' => 'Маалыматтар маектеш табуу, билдирүүлөрдү жеткирүү, кыянаттыкты токтотуу жана арыздарды кароо үчүн керек. Кирүү бул саясатка макулдугуңузга негизделет. Колдонууну токтотуп, анкетаны өчүрө аласыз.',
    'privacy_rights' => 'Маалыматтарга жетүү, оңдоо, өчүрүү, макулдукту кайтарып алуу же автоматтык бөгөткө каршы даттануу үчүн сессия ID менен колдоого жазыңыз. Сессия сиздики экенин ырастоону сурашыбыз мүмкүн, чатта паспорт суралбайт. Арыз көчүрмөлөрү кароо мөөнөтү бүткөнгө чейин сакталат. КР Жеке маалыматтарды коргоо агенттигине кайрыла аласыз.',
    'privacy_access' => 'Кат алышуу администраторлорго арыздын чегинде гана жеткиликтүү. Башкы админ БД көчүрмөсүн жүктөй алат. Хостинг провайдери техникалык маалыматтарды иштетет жана IP журналдарын жүргүзүшү мүмкүн; мөөнөттөр келишимде аныкталат. Жарнама тармактарына маалымат берилбейт. Тышкы эсептегичтер кошулбайт.',
    'privacy_cookies' => 'Милдеттүү сессия cookie, тема жана кокус браузер идентификатору үчүн localStorage колдонулат. TLS иштетилсе, чат HTTPS аркылуу өтөт. КР Санарип кодексин, анын ичинде чек ара аралык иштетүүнүн негиздерин сактоого сервис оператору жооп берет.',
    'retention' => 'Сактоо мөөнөттөрү',
    'days' => 'күн',
    'ephemeral' => 'Архив өчүрүлгөн: билдирүүлөр жеткирүү үчүн 2 мүнөткө чейин сакталат.',
    'retention_cleanup' => 'Тазалоо суроо-талаптарда бөлүктөр менен жүргүзүлөт. Колдонулбаган сайтта кийинки кирүүдө аткарылат. Хостинг көчүрмөлөрүн жана журналдарын оператор өзүнчө өчүрөт.',
    'support' => 'Колдоо',
    'my_id' => 'Сессияңыздын ID',
    'site_name' => 'Сайттын аты',
    'language' => 'Негизги тил',
    'support_email' => 'Колдоо email',
    'chat_enabled' => 'Чат иштетилген',
    'entry_enabled' => 'Анкеталарды түзүү иштетилген',
    'min_age' => 'Эң төмөн жаш (18ден төмөн эмес)',
    'max_age' => 'Эң жогорку жаш',
    'message_length' => 'Билдирүүнүн узундугу',
    'message_rate' => 'Сессияга бир мүнөттөгү билдирүүлөр',
    'ip_message_rate' => 'IP хешине бир мүнөттөгү билдирүүлөр',
    'heartbeat_timeout' => 'Heartbeat мөөнөтү, сек.',
    'idle_timeout' => 'Билдирүүсүз мөөнөт, сек.',
    'queue_timeout' => 'Издөө мөөнөтү, сек.',
    'transport' => 'Жаңылоо режими',
    'poll_seconds' => 'Polling, сек. (2–3)',
    'long_poll_seconds' => 'Long-poll, сек. (20га чейин)',
    'log_messages' => 'Билдирүүлөр архивин сактоо',
    'message_days' => 'Билдирүүлөр архиви, күн',
    'report_days' => 'Арыздар жана көчүрмөлөр, күн',
    'identity_days' => 'IP хеши / браузер ID, күн',
    'session_days' => 'Активдүү эмес анкеталар, күн',
    'audit_days' => 'Админ журналы, күн',
    'seo_description' => 'SEO сүрөттөмө',
    'operator_name' => 'Оператордун аты / уюму',
    'operator_address' => 'Оператордун дареги',
    'hosting_country' => 'Хостинг өлкөсү',
    'analytics_id' => 'Жергиликтүү эсептегичтин аты (бош = өчүк)',
    'rules_ru' => 'Орусча эрежелер',
    'rules_ky' => 'Кыргызча эрежелер',
    'views' => 'Башкы беттин көрүүлөрү',
    'id' => 'ID',
    'name_ru' => 'Орусча аталышы',
    'name_ky' => 'Кыргызча аталышы',
    'last_seen' => 'Акыркы активдүүлүк (UTC)',
    'started_at' => 'Башталышы (UTC)',
    'ended_at' => 'Аяктоосу (UTC)',
    'end_reason' => 'Аяктоо себеби',
    'created_at' => 'Түзүлгөн (UTC)',
    'expires_at' => 'Мөөнөтү бүтөт (UTC)',
    'revoked_at' => 'Алынган (UTC)',
    'has_totp' => '2FA иштетилген',
    'a' => 'Катышуучу A',
    'b' => 'Катышуучу B',
    'chat_id' => 'Чат ID',
    'value' => 'Идентификатор',
    'priority' => 'Артыкчылык',
    'status' => 'Статус',
];
```

## Файл: app/Lang/ru.php

```php
<?php
// Локализация интерфейса.
return [
    'brand' => 'Кезик',
    'tagline' => 'Один разговор может изменить вечер.',
    'intro' => 'Случайный собеседник из Кыргызстана. Без аккаунта. Только 18+.',
    'enter' => 'Войти в чат',
    'profile' => 'Ваша анкета',
    'nickname' => 'Ник (необязательно)',
    'gender' => 'Пол',
    'm' => 'Мужчина',
    'f' => 'Женщина',
    'age' => 'Полных лет',
    'city' => 'Город',
    'adult_check' => 'Мне исполнилось 18 лет',
    'rules_check' => 'Принимаю правила и политику конфиденциальности',
    'adult_error' => 'Вход только с 18 лет. Проверьте возраст, город и согласия.',
    'rules' => 'Правила',
    'privacy' => 'Конфиденциальность',
    'contacts' => 'Контакты',
    '18' => 'Только 18+',
    '404' => 'Страница не найдена',
    'home' => 'На главную',
    'any' => 'Любой',
    'filters' => 'Кого ищем?',
    'peer_city' => 'Город собеседника',
    'peer_gender' => 'Пол собеседника',
    'age_range' => 'Возраст от / до',
    'search' => 'Найти собеседника',
    'cancel' => 'Отменить',
    'expand' => 'Расширить фильтры',
    'next' => 'Следующий',
    'end' => 'Завершить',
    'report' => 'Пожаловаться',
    'send' => 'Отправить',
    'message' => 'Напишите сообщение…',
    'emoji' => 'Эмодзи',
    'typing' => 'Собеседник печатает…',
    'sent' => 'Отправлено',
    'delivered' => 'Доставлено',
    'read' => 'Прочитано',
    'online' => 'Онлайн',
    'queued' => 'В очереди',
    'idle' => 'Готовы к новому разговору?',
    'queue' => 'Ищем подходящего собеседника…',
    'chat' => 'Вы в чате',
    'chat_ended' => 'Чат завершён. Можно найти нового собеседника.',
    'queue_expired' => 'Поиск завершён по таймауту. Попробуйте снова или расширьте фильтры.',
    'anonymous' => 'Аноним',
    'reason' => 'Причина',
    'detail' => 'Комментарий',
    'spam' => 'Спам',
    'abuse' => 'Оскорбления',
    'advertising' => 'Реклама',
    'explicit' => 'Непристойное',
    'minor' => 'Несовершеннолетний',
    'fraud' => 'Мошенничество',
    'other' => 'Другое',
    'auto_filter' => 'Автофильтр',
    'reported' => 'Жалоба отправлена. Чат завершён.',
    'already_reported' => 'Жалоба на этот чат уже отправлена.',
    'network' => 'Нет связи. Повторяем подключение…',
    'rate' => 'Слишком часто. Подождите минуту.',
    'captcha' => 'Решите пример для продолжения.',
    'too_long' => 'Сообщение пустое или превышает допустимую длину.',
    'filtered' => 'Сообщение содержит запрещённые слова или контакты.',
    'banned' => 'Доступ временно или постоянно ограничен. Обратитесь в поддержку.',
    'maintenance' => 'Чат временно закрыт на обслуживание.',
    'invalid' => 'Проверьте введённые данные.',
    'csrf' => 'Страница устарела. Обновите её.',
    'server_error' => 'Ошибка сервера. Попробуйте позднее.',
    'not_found' => 'Не найдено',
    'forbidden' => 'Недостаточно прав',
    'identity_expired' => 'Данные для такого бана уже удалены. Выберите бан по сессии.',
    'forget' => 'Сменить анкету / удалить сессию',
    'forget_confirm' => 'Удалить анкету и завершить чат? Снимки жалоб сохранятся до конца срока модерации.',
    'theme' => 'Переключить тему',
    'close' => 'Закрыть',
    'safety' => 'Не отправляйте деньги и личные данные. Если вам некомфортно, завершите чат.',
    'adult_text' => 'Доступ разрешён только с 18 лет. Возраст подтверждается заявлением пользователя; документальная проверка не проводится. Любые сексуальные материалы с несовершеннолетними запрещены. Сообщайте о подозрении через кнопку жалобы.',
    'admin' => 'Админ-панель',
    'login' => 'Логин',
    'password' => 'Пароль',
    'current_password' => 'Текущий пароль',
    'email' => 'Email',
    'totp' => 'Код 2FA',
    'login_failed' => 'Неверные данные, код или слишком много попыток.',
    'logout' => 'Выйти',
    'dashboard' => 'Дашборд',
    'users' => 'Сессии',
    'reports' => 'Жалобы',
    'chats' => 'Чаты',
    'bans' => 'Баны',
    'cities' => 'Города',
    'stopwords' => 'Фильтры',
    'settings' => 'Настройки',
    'admins' => 'Администраторы',
    'logs' => 'Журнал действий',
    'security' => 'Безопасность',
    'backup' => 'Копия и очистка',
    'chats_today' => 'Чатов за 24 часа',
    'chats_week' => 'Чатов за неделю',
    'profiles' => 'Новых анкет за сутки',
    'chart' => 'Чаты по дням (UTC)',
    'distribution' => 'Распределение анкет',
    'registrations_note' => 'Обязательной регистрации нет. Здесь считаются анонимные анкеты.',
    'active_chats' => 'Активных чатов',
    'archive_note' => 'Архив содержит метаданные. Переписка доступна только в рамках жалобы.',
    'save' => 'Сохранить',
    'saved' => 'Сохранено',
    'edit' => 'Изменить',
    'delete' => 'Удалить',
    'view' => 'Открыть',
    'enabled' => 'Включено',
    'disabled' => 'Отключено',
    'search_label' => 'Поиск по нику или ID сессии',
    'empty' => 'Пока нет данных',
    'previous' => 'Назад',
    'page' => 'Страница',
    'ban' => 'Забанить',
    'unban' => 'Разбанить',
    'scope' => 'Тип бана',
    'session' => 'Сессия',
    'ip' => 'IP-хеш',
    'fingerprint' => 'Браузерный идентификатор',
    'hours' => 'Часов (0 = навсегда)',
    'permanent' => 'Навсегда',
    'dismiss' => 'Отклонить',
    'warn' => 'Предупредить',
    'moderator_warning' => 'Модератор: соблюдайте правила. Повторное нарушение может привести к бану.',
    'evidence' => 'Снимок переписки по жалобе',
    'evidence_empty' => 'Сохранённых сообщений нет.',
    'pending' => 'Ожидает проверки',
    'block' => 'Блокировать сообщение',
    'replace' => 'Заменять',
    'word' => 'Слово / фрагмент',
    'link' => 'Ссылки',
    'phone' => 'Телефоны',
    'messenger' => 'Мессенджеры / @ники',
    'pattern' => 'Слово (для типа «слово»)',
    'replacement' => 'Текст замены',
    'kind' => 'Тип фильтра',
    'action' => 'Действие',
    'role' => 'Роль',
    'super' => 'Суперадмин',
    'moderator' => 'Модератор',
    'new_password' => 'Новый пароль (пусто = без изменения)',
    '2fa_note' => 'Добавьте ключ вручную в приложение-аутентификатор. Не передавайте ключ другим людям. Для активации нужен свежий код. При потере ключа восстановление через phpMyAdmin описано в README.',
    'totp_prepare' => 'Подготовить 2FA',
    'totp_enable' => 'Включить 2FA',
    'totp_disable' => 'Отключить 2FA',
    'change_password' => 'Изменить пароль',
    'export' => 'Скачать SQL-копию',
    'cleanup' => 'Очистить устаревшие данные',
    'backup_note' => 'SQL-копия включает хеши паролей, ключи 2FA и жалобы. Храните её вне сайта в защищённом месте. Для больших БД используйте экспорт в phpMyAdmin. Очистка выполняется ограниченными порциями.',
    'privacy_title' => 'Как мы обращаемся с данными',
    'operator' => 'Оператор',
    'hosting' => 'Страна размещения сервера',
    'operator_missing' => 'Сведения об операторе ещё не опубликованы. Обратитесь в поддержку.',
    'privacy_data' => 'Храним ник, заявленные пол и возраст, город, случайный ID сессии, HMAC-хеш IP и браузерного идентификатора. Собеседник видит только ник, пол, возраст и город. Хеширование — псевдонимизация, а не полная анонимизация.',
    'privacy_purpose' => 'Данные нужны для подбора собеседника, доставки сообщений, защиты от злоупотреблений и рассмотрения жалоб. Вход основан на вашем согласии с этой политикой. Вы можете прекратить использование и удалить анкету кнопкой «Сменить анкету / удалить сессию».',
    'privacy_rights' => 'Для доступа, исправления, удаления данных, отзыва согласия или обжалования автоматической блокировки напишите в поддержку и укажите ID своей сессии. Мы можем попросить подтверждение контроля над ней, не запрашивая паспорт в чате. Снимки жалоб могут сохраняться до окончания срока рассмотрения. Вы вправе обратиться в Агентство по защите персональных данных КР.',
    'privacy_access' => 'Переписка доступна администраторам только в рамках жалобы. Резервную копию БД может выгрузить суперадмин. Провайдер хостинга обрабатывает технические данные и может вести собственные журналы IP; их сроки определяются договором с оператором. Передачи рекламным сетям нет. Сторонние счётчики не подключаются.',
    'privacy_cookies' => 'Используем обязательную сессионную cookie, localStorage для темы и случайного браузерного идентификатора. Чат передаётся по HTTPS при включённом TLS хостинга. Соблюдение требований Цифрового кодекса КР, включая основания трансграничной обработки, обеспечивает оператор сервиса.',
    'retention' => 'Сроки хранения',
    'days' => 'дней',
    'ephemeral' => 'Архив отключён: TTL доставки сообщений — 2 минуты, удаление выполняется очередной очисткой.',
    'retention_cleanup' => 'Очистка запускается при запросах, порциями. На неиспользуемом сайте она выполнится при следующем посещении. Копии и журналы хостинга удаляются оператором отдельно.',
    'support' => 'Поддержка',
    'my_id' => 'ID вашей сессии',
    'site_name' => 'Название сайта',
    'language' => 'Язык по умолчанию',
    'support_email' => 'Email поддержки',
    'chat_enabled' => 'Чат включён',
    'entry_enabled' => 'Создание анкет включено',
    'min_age' => 'Минимальный возраст (не ниже 18)',
    'max_age' => 'Максимальный возраст',
    'message_length' => 'Длина сообщения',
    'message_rate' => 'Сообщений в минуту на сессию',
    'ip_message_rate' => 'Сообщений в минуту на IP-хеш',
    'heartbeat_timeout' => 'Таймаут heartbeat, сек.',
    'idle_timeout' => 'Таймаут без сообщений, сек.',
    'queue_timeout' => 'Таймаут поиска, сек.',
    'transport' => 'Режим обновления',
    'poll_seconds' => 'Polling, сек. (2–3)',
    'long_poll_seconds' => 'Long-poll, сек. (до 20)',
    'log_messages' => 'Сохранять архив сообщений',
    'message_days' => 'Архив сообщений, дней',
    'report_days' => 'Жалобы и снимки, дней',
    'identity_days' => 'IP-хеш / браузерный ID, дней',
    'session_days' => 'Неактивные анкеты, дней',
    'audit_days' => 'Журнал админов, дней',
    'seo_description' => 'SEO-описание',
    'operator_name' => 'Имя / организация оператора',
    'operator_address' => 'Адрес оператора',
    'hosting_country' => 'Страна хостинга',
    'analytics_id' => 'Имя локального счётчика (пусто = выключен)',
    'rules_ru' => 'Правила на русском',
    'rules_ky' => 'Правила на кыргызском',
    'views' => 'Просмотры главной страницы',
    'id' => 'ID',
    'name_ru' => 'Название на русском',
    'name_ky' => 'Название на кыргызском',
    'last_seen' => 'Последняя активность (UTC)',
    'started_at' => 'Начало (UTC)',
    'ended_at' => 'Окончание (UTC)',
    'end_reason' => 'Причина завершения',
    'created_at' => 'Создано (UTC)',
    'expires_at' => 'Истекает (UTC)',
    'revoked_at' => 'Снят (UTC)',
    'has_totp' => '2FA включена',
    'a' => 'Участник A',
    'b' => 'Участник B',
    'chat_id' => 'ID чата',
    'value' => 'Идентификатор',
    'priority' => 'Приоритет',
    'status' => 'Статус',
];
```

## Файл: app/Models/Settings.php

```php
<?php

namespace App\Models;

class Settings
{
    public static function defaults(): array
    {
        return [
            'site_name' => 'Кезик • Анонимный чат', 'language' => 'ru', 'support_email' => '',
            'chat_enabled' => '1', 'entry_enabled' => '1', 'min_age' => '18', 'max_age' => '99',
            'message_length' => '1000', 'message_rate' => '12', 'ip_message_rate' => '80',
            'heartbeat_timeout' => '60', 'idle_timeout' => '600', 'queue_timeout' => '180',
            'transport' => 'poll', 'poll_seconds' => '3', 'long_poll_seconds' => '20',
            'log_messages' => '1', 'message_days' => '7', 'report_days' => '30',
            'identity_days' => '7', 'session_days' => '30', 'audit_days' => '90',
            'seo_description' => 'Анонимный чат 18+ с собеседниками из Кыргызстана.',
            'operator_name' => '', 'operator_address' => '', 'hosting_country' => '',
            'analytics_id' => '', 'rules_ru' => "Только для совершеннолетних (18+). Запрещены угрозы, травля, мошенничество, реклама, спам, сексуальная эксплуатация и любой контент с несовершеннолетними. Не отправляйте персональные данные, деньги или интимные материалы. Жалобы рассматриваются модераторами. При непосредственной опасности обращайтесь в экстренные службы.",
            'rules_ky' => "18 жаштан жогору адамдар үчүн гана. Коркутууга, куугунтуктоого, алдамчылыкка, жарнамага, спамга жана жашы жете электер катышкан сексуалдык мазмунга тыюу салынат. Жеке маалыматтарды, акчаны же интимдик материалдарды жөнөтпөңүз. Арыздарды модераторлор карайт. Кооптуу абалда шашылыш кызматтарга кайрылыңыз.",
        ];
    }

    public static function load(): array
    {
        $values = self::defaults();
        foreach (db()->all('SELECT name,value FROM ' . db()->table('settings')) as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }

    public static function put(string $name, string $value): void
    {
        db()->run('INSERT INTO ' . db()->table('settings') . '(name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, $value]);
    }
}
```

## Файл: app/Services/ChatService.php

```php
<?php

namespace App\Services;

use App\Core\Http;
use App\Core\Security;

class ChatService
{
    private $db;

    public function __construct()
    {
        $this->db = db();
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

    public function snapshot(int $sid, int $cursor = 0): array
    {
        return $this->db->transaction(function () use ($sid, $cursor) {
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
            $result = ['state' => $chat ? 'chat' : ($queue ? 'queue' : 'idle'), 'me' => $this->publicProfile($p), 'online' => $online, 'queued' => $queued, 'warning' => $p['warning'], 'queue' => $queue];
            if ($chat) {
                $other = $this->profile((int) ($chat['a'] == $sid ? $chat['b'] : $chat['a']));
                $messages = $this->db->all('SELECT id,sender_id,body,created_at FROM ' . $this->db->table('messages') . ' WHERE chat_id=? AND id>? AND created_at>=? ORDER BY id LIMIT 100', [$chat['id'], $cursor, setting('log_messages') === '1' ? gmdate('Y-m-d H:i:s', time() - (int) setting('message_days') * 86400) : gmdate('Y-m-d H:i:s', time() - 120)]);
                $delivered = (int) $p['delivered_id'];
                foreach ($messages as &$message) {
                    $message['mine'] = (int) $message['sender_id'] === $sid;
                    $message['id'] = (int) $message['id'];
                    $delivered = max($delivered, $message['id']);
                    unset($message['sender_id']);
                }
                unset($message);
                $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET delivered_id=GREATEST(delivered_id,?) WHERE id=?', [$delivered, $sid]);
                $result += ['chat_id' => (int) $chat['id'], 'peer' => $this->publicProfile($other), 'typing' => !empty($other['typing_until']) && strtotime($other['typing_until'] . ' UTC') > time(), 'peer_delivered' => (int) $other['delivered_id'], 'peer_read' => (int) $other['read_id'], 'messages' => $messages];
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
        if ($body === '' || mb_strlen($body) > (int) setting('message_length') || !preg_match('/^[a-zA-Z0-9-]{16,64}$/', $nonce)) {
            Http::json(['error' => t('too_long')], 422);
        }
        [$body, $auto] = (new ModerationService())->filter($body);
        return $this->db->transaction(function () use ($sid, $body, $nonce, $chatId, $auto) {
            $chat = $this->active($sid, true);
            if (!$chat || (int) $chat['id'] !== $chatId || $this->ban($this->profile($sid)) || setting('chat_enabled') !== '1') {
                Http::json(['error' => t('chat_ended')], 409);
            }
            $existing = $this->db->one('SELECT id FROM ' . $this->db->table('messages') . ' WHERE chat_id=? AND sender_id=? AND nonce=?', [$chatId, $sid, $nonce]);
            if ($existing) {
                return (int) $existing['id'];
            }
            $this->db->run('INSERT INTO ' . $this->db->table('messages') . '(chat_id,sender_id,body,nonce,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$chatId, $sid, $body, $nonce]);
            $id = $this->db->id();
            $this->db->run('UPDATE ' . $this->db->table('chats') . ' SET last_activity=UTC_TIMESTAMP() WHERE id=?', [$chatId]);
            $this->db->run('UPDATE ' . $this->db->table('sessions') . ' SET typing_until=NULL,last_seen=UTC_TIMESTAMP() WHERE id=?', [$sid]);
            if ($auto) {
                (new ModerationService())->saveReport($chat, null, $sid, 'auto_filter', $auto);
            }
            return $id;
        });
    }
}
```

## Файл: app/Services/CleanupService.php

```php
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
```

## Файл: app/Services/ModerationService.php

```php
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
```

## Файл: app/Views/admin-login.php

```php
<?php $title = t('admin'); require ROOT . '/app/Views/header.php'; ?>
<main class="install panel"><h1><?= e(t('admin')) ?></h1><?php if ($error): ?><p class="notice danger" role="alert"><?= e($error) ?></p><?php endif; ?><form method="post"><?= csrfField() ?><label><?= e(t('login')) ?><input name="login" required maxlength="64" autocomplete="username"></label><label><?= e(t('password')) ?><input name="password" type="password" required maxlength="128" autocomplete="current-password"></label><label><?= e(t('totp')) ?><input name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label><?php if ($question): ?><label><?= e($question) ?><input name="captcha" inputmode="numeric" required autocomplete="off"></label><?php endif; ?><button type="submit"><?= e(t('enter')) ?></button></form></main>
<?php require ROOT . '/app/Views/footer.php'; ?>
```

## Файл: app/Views/admin.php

```php
<?php
$title = t($section) . ' · ' . t('admin');
require ROOT . '/app/Views/header.php';
$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$edit = $edit ?? [];
function adminField(string $name, string $label, string $value = '', string $type = 'text'): void
{
    echo '<label>' . e(t($label)) . '<input name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" autocomplete="off"></label>';
}
function adminSelect(string $name, array $choices, string $selected = ''): void
{
    echo '<select name="' . e($name) . '">';
    foreach ($choices as $key) {
        echo '<option value="' . e($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . e(t($key)) . '</option>';
    }
    echo '</select>';
}
?>
<main class="wrap admin-layout"><aside class="panel admin-nav"><h2><?= e(t('admin')) ?></h2><p class="muted"><?= e($admin['login']) ?> · <?= e(t($admin['role'])) ?></p><nav><?php foreach ($sections as $item): ?><?php if ($admin['role'] === 'super' || !in_array($item, $superOnly, true)): ?><a class="<?= $section === $item ? 'current' : '' ?>" href="<?= e(url('admin/' . $item)) ?>"><?= e(t($item)) ?></a><?php endif; ?><?php endforeach; ?></nav><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="logout"><button class="secondary wide"><?= e(t('logout')) ?></button></form></aside><section class="admin-content"><h1><?= e(t($section)) ?></h1><?php if ($error || $flash): ?><p class="notice <?= $error ? 'danger' : '' ?>" role="alert"><?= e($error ?: $flash) ?></p><?php endif; ?>
<?php if ($section === 'dashboard'): ?>
<div class="metric-grid"><?php foreach ($stats as $key => $number): ?><div class="panel metric"><span><?= e(t($key)) ?></span><strong><?= (int) $number ?></strong></div><?php endforeach; ?></div><p class="muted"><?= e(t('registrations_note')) ?></p><div class="panel"><h2><?= e(t('chart')) ?></h2><canvas id="chart" height="240" data-points="<?= e(json_encode($chart)) ?>" aria-label="<?= e(t('chart')) ?>" role="img"></canvas><div class="chart-data"><?php foreach ($chart as $point): ?><span><?= e($point['day']) ?>: <?= (int) $point['n'] ?></span><?php endforeach; ?></div></div><h2><?= e(t('distribution')) ?></h2><div class="metric-grid"><?php foreach (['cities' => $cities, 'gender' => $genders, 'age' => $ages] as $key => $items): ?><div class="panel"><h3><?= e(t($key)) ?></h3><?php foreach ($items as $item): ?><p class="spread"><span><?= e($key === 'gender' ? t($item['label']) : $item['label']) ?></span><b><?= (int) $item['n'] ?></b></p><?php endforeach; ?></div><?php endforeach; ?></div><?php if ($views): ?><div class="panel"><h2><?= e(t('views')) ?></h2><?php foreach ($views as $v): ?><p><?= e(substr($v['name'], 3)) ?>: <?= (int) $v['value'] ?></p><?php endforeach; ?></div><?php endif; ?>
<?php elseif ($section === 'users'): ?>
<form class="panel search-form" method="get"><label><?= e(t('search_label')) ?><input name="q" value="<?= e($query) ?>"></label><button><?= e(t('search')) ?></button></form>
<?php if (!empty($profile)): ?><div class="panel"><h2>#<?= (int) $profile['id'] ?> · <?= e($profile['nickname']) ?></h2><p><?= e(t($profile['gender'])) ?> · <?= (int) $profile['age'] ?> · <?= e($profile['name_' . lang()]) ?></p><p class="small">IP: <?= e($profile['ip_hash'] ? substr($profile['ip_hash'], 0, 16) . '…' : '—') ?><br>ID: <?= e($profile['fingerprint'] ? substr($profile['fingerprint'], 0, 16) . '…' : '—') ?></p><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="ban"><input type="hidden" name="id" value="<?= (int) $profile['id'] ?>"><div class="form-grid"><label><?= e(t('scope')) ?><?php adminSelect('scope', ['session', 'ip', 'fingerprint'], 'session'); ?></label><?php adminField('hours', 'hours', '24', 'number'); ?></div><?php adminField('reason', 'reason'); ?><button class="danger-button"><?= e(t('ban')) ?></button></form></div><?php endif; ?>
<?php elseif ($section === 'reports' && !empty($report)): ?>
<div class="panel"><h2>#<?= (int) $report['id'] ?> · <?= e(t($report['reason'])) ?></h2><p><?= e($report['detail']) ?></p><p><?= e(t($report['status'])) ?> · <?= e($report['created_at']) ?> UTC</p><h3><?= e(t('evidence')) ?></h3><?php foreach ($evidence['participants'] ?? [] as $person): ?><p>#<?= (int) $person['session'] ?> <?= e($person['nickname']) ?> · <?= e(t($person['gender'])) ?> · <?= (int) $person['age'] ?> · <?= e($person['city']) ?></p><?php endforeach; ?><div class="evidence"><?php foreach ($evidence['messages'] ?? [] as $message): ?><div class="evidence-line"><small>#<?= (int) $message['sender_id'] ?> · <?= e($message['created_at']) ?> UTC</small><p><?= e($message['body']) ?></p></div><?php endforeach; ?><?php if (empty($evidence['messages'])): ?><p><?= e(t('evidence_empty')) ?></p><?php endif; ?></div><?php if ($report['status'] === 'pending'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $report['id'] ?>"><?php adminField('hours', 'hours', '24', 'number'); ?><div class="button-row"><button name="action" value="dismiss" class="secondary"><?= e(t('dismiss')) ?></button><button name="action" value="warn"><?= e(t('warn')) ?></button><button name="action" value="ban" class="danger-button"><?= e(t('ban')) ?></button></div></form><?php endif; ?></div>
<?php elseif ($section === 'chats'): ?><div class="panel"><h2><?= e(t('active_chats')) ?>: <?= (int) $active ?></h2><p><?= e(t('archive_note')) ?></p></div>
<?php elseif ($section === 'cities'): ?><form method="post" class="panel"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><?php adminField('name_ru', 'name_ru', $edit['name_ru'] ?? ''); adminField('name_ky', 'name_ky', $edit['name_ky'] ?? ''); ?><label class="check"><input name="enabled" type="checkbox" <?= ($edit['enabled'] ?? 1) ? 'checked' : '' ?>><?= e(t('enabled')) ?></label><button><?= e(t('save')) ?></button></form>
<?php elseif ($section === 'stopwords'): ?><form method="post" class="panel"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><label><?= e(t('kind')) ?><?php adminSelect('kind', ['word', 'link', 'phone', 'messenger'], $edit['kind'] ?? 'word'); ?></label><?php adminField('pattern', 'pattern', $edit['pattern'] ?? ''); ?><label><?= e(t('action')) ?><?php adminSelect('rule_action', ['block', 'replace', 'report'], $edit['action'] ?? 'block'); ?></label><?php adminField('replacement', 'replacement', $edit['replacement'] ?? '***'); ?><label class="check"><input name="enabled" type="checkbox" <?= ($edit['enabled'] ?? 1) ? 'checked' : '' ?>><?= e(t('enabled')) ?></label><button><?= e(t('save')) ?></button></form>
<?php elseif ($section === 'settings'): ?><form method="post" class="panel settings-form"><?= csrfField() ?><input type="hidden" name="action" value="save"><?php foreach (\App\Models\Settings::defaults() as $key => $default): ?><?php if (in_array($key, ['chat_enabled', 'entry_enabled', 'log_messages'], true)): ?><label class="check"><input type="checkbox" name="<?= e($key) ?>" <?= setting($key) === '1' ? 'checked' : '' ?>><?= e(t($key)) ?></label><?php elseif (strpos($key, 'rules_') === 0): ?><label><?= e(t($key)) ?><textarea name="<?= e($key) ?>" rows="6"><?= e(setting($key)) ?></textarea></label><?php elseif ($key === 'language'): ?><label><?= e(t($key)) ?><select name="language"><option value="ru" <?= setting($key) === 'ru' ? 'selected' : '' ?>>Русский</option><option value="ky" <?= setting($key) === 'ky' ? 'selected' : '' ?>>Кыргызча</option></select></label><?php elseif ($key === 'transport'): ?><label><?= e(t($key)) ?><select name="transport"><option value="poll" <?= setting($key) === 'poll' ? 'selected' : '' ?>>Polling</option><option value="long" <?= setting($key) === 'long' ? 'selected' : '' ?>>Long-polling</option></select></label><?php else: ?><?php adminField($key, $key, setting($key), is_numeric($default) ? 'number' : ($key === 'support_email' ? 'email' : 'text')); ?><?php endif; ?><?php endforeach; ?><button><?= e(t('save')) ?></button></form>
<?php elseif ($section === 'admins'): ?><form method="post" class="panel"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>"><?php adminField('login', 'login', $edit['login'] ?? ''); adminField('email', 'email', $edit['email'] ?? '', 'email'); adminField('password', 'new_password', '', 'password'); ?><label><?= e(t('role')) ?><?php adminSelect('role', ['moderator', 'super'], $edit['role'] ?? 'moderator'); ?></label><label class="check"><input type="checkbox" name="enabled" <?= ($edit['enabled'] ?? 1) ? 'checked' : '' ?>><?= e(t('enabled')) ?></label><?php adminField('current_password', 'current_password', '', 'password'); ?><button><?= e(t('save')) ?></button></form>
<?php elseif ($section === 'security'): ?><div class="panel"><h2>2FA / TOTP</h2><p><?= e(t('2fa_note')) ?></p><?php if (!empty($_SESSION['pending_totp']) && !$admin['totp_secret']): ?><p><code><?= e($_SESSION['pending_totp']) ?></code></p><p class="muted">SHA1 · 6 digits · 30 seconds</p><?php endif; ?><form method="post"><?= csrfField() ?><?php adminField('current_password', 'current_password', '', 'password'); ?><?php if ($admin['totp_secret'] || !empty($_SESSION['pending_totp'])): ?><?php adminField('totp', 'totp'); ?><?php endif; ?><button name="action" value="<?= $admin['totp_secret'] ? 'totp_disable' : (!empty($_SESSION['pending_totp']) ? 'totp_enable' : 'totp_prepare') ?>"><?= e(t($admin['totp_secret'] ? 'totp_disable' : (!empty($_SESSION['pending_totp']) ? 'totp_enable' : 'totp_prepare'))) ?></button></form></div><form method="post" class="panel"><?= csrfField() ?><h2><?= e(t('change_password')) ?></h2><input type="hidden" name="action" value="password"><?php adminField('current_password', 'current_password', '', 'password'); adminField('password', 'new_password', '', 'password'); ?><button><?= e(t('save')) ?></button></form>
<?php elseif ($section === 'backup'): ?><div class="panel"><p><?= e(t('backup_note')) ?></p><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="export"><?php adminField('current_password', 'current_password', '', 'password'); ?><button><?= e(t('export')) ?></button></form><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="cleanup"><button class="secondary"><?= e(t('cleanup')) ?></button></form></div>
<?php endif; ?>
<?php if (!in_array($section, ['dashboard', 'settings', 'security', 'backup'], true)): ?><div class="panel table-wrap"><table><thead><tr><?php foreach (array_keys($rows[0] ?? ['id' => null]) as $column): ?><th><?= e(t($column)) ?></th><?php endforeach; ?><th><?= e(t('action')) ?></th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $column => $value): ?><td><?= e(in_array($column, ['reason', 'status', 'role', 'gender', 'kind', 'action'], true) ? t((string) $value) : ($column === 'value' && strlen((string) $value) > 20 ? substr($value, 0, 16) . '…' : $value)) ?></td><?php endforeach; ?><td><?php if (in_array($section, ['users', 'reports', 'cities', 'stopwords', 'admins'], true)): ?><a href="<?= e(url('admin/' . $section)) ?>?id=<?= (int) $row['id'] ?>"><?= e(t('view')) ?></a><?php endif; ?><?php if (($section === 'bans' && !$row['revoked_at']) || $section === 'stopwords'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="text-button" name="action" value="<?= $section === 'bans' ? 'unban' : 'delete' ?>"><?= e(t($section === 'bans' ? 'unban' : 'delete')) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="12"><?= e(t('empty')) ?></td></tr><?php endif; ?></tbody></table></div><nav class="pagination"><a href="?page=<?= max(1, $pageNumber - 1) ?>&amp;q=<?= e(rawurlencode($query ?? '')) ?>"><?= e(t('previous')) ?></a><span><?= e(t('page')) ?> <?= (int) $pageNumber ?></span><?php if (count($rows) === 50): ?><a href="?page=<?= $pageNumber + 1 ?>&amp;q=<?= e(rawurlencode($query ?? '')) ?>"><?= e(t('next')) ?></a><?php endif; ?></nav><?php endif; ?>
</section></main><script src="<?= e(url('assets/admin.js')) ?>" defer></script>
<?php require ROOT . '/app/Views/footer.php'; ?>
```

## Файл: app/Views/footer.php

```php
<footer class="footer wrap"><span>18+ · Кыргызстан</span><nav><a href="<?= e(url('rules')) ?>"><?= e(t('rules')) ?></a><a href="<?= e(url('privacy')) ?>"><?= e(t('privacy')) ?></a><a href="<?= e(url('contacts')) ?>"><?= e(t('contacts')) ?></a><a href="<?= e(url('18')) ?>">18+</a></nav></footer></body></html>
```

## Файл: app/Views/header.php

```php
<!doctype html>
<html lang="<?= e(lang()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0f1115"><meta name="description" content="<?= e(setting('seo_description')) ?>"><title><?= e($title ?? setting('site_name')) ?></title><link rel="icon" href="<?= e(url('assets/logo.svg')) ?>" type="image/svg+xml"><link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>"><script src="<?= e(url('assets/theme.js')) ?>" defer></script></head>
<body><header class="topbar wrap"><a class="brand" href="<?= e(url()) ?>"><img src="<?= e(url('assets/logo.svg')) ?>" alt="" width="38" height="38"><span><?= e(setting('site_name')) ?></span></a><nav class="topnav" aria-label="<?= e(t('settings')) ?>"><a href="?lang=ru" lang="ru" <?= lang() === 'ru' ? 'aria-current="page"' : '' ?>>RU</a><a href="?lang=ky" lang="ky" <?= lang() === 'ky' ? 'aria-current="page"' : '' ?>>KY</a><button type="button" class="icon-button" id="theme" aria-label="<?= e(t('theme')) ?>"><svg width="21" height="21" aria-hidden="true"><use href="<?= e(url('assets/icons.svg')) ?>#moon"></use></svg></button></nav></header>
```

## Файл: app/Views/home.php

```php
<?php
if (setting('analytics_id') !== '') {
    db()->run('INSERT INTO ' . db()->table('settings') . '(name,value) VALUES (?,1) ON DUPLICATE KEY UPDATE value=CAST(value AS UNSIGNED)+1', ['pv_' . gmdate('Y-m-d')]);
}
require ROOT . '/app/Views/header.php';
$client = ['base' => base(), 'csrf' => $_SESSION['csrf'], 'lang' => lang(), 'words' => $GLOBALS['dict'], 'transport' => setting('transport'), 'poll' => (int) setting('poll_seconds'), 'minAge' => max(18, (int) setting('min_age')), 'maxAge' => (int) setting('max_age'), 'maxLength' => (int) setting('message_length')];
?>
<main id="app" class="wrap" data-config="<?= e(json_encode($client, JSON_UNESCAPED_UNICODE)) ?>">
<div id="notice" class="notice" role="alert" hidden></div>
<section id="entry" class="entry"><div class="hero"><span class="pill">18+ · RU / KY</span><h1><?= e(t('tagline')) ?></h1><p><?= e(t('intro')) ?></p><div class="hero-art" aria-hidden="true"><span class="art-bubble">Салам 👋</span><span class="art-bubble second">Привет!</span></div><p class="safety"><?= e(t('safety')) ?></p></div><form id="entry-form" class="panel"><h2><?= e(t('profile')) ?></h2><label><?= e(t('nickname')) ?><input name="nickname" maxlength="40" placeholder="<?= e(t('anonymous')) ?>" autocomplete="off"></label><div class="form-grid"><label><?= e(t('gender')) ?><select name="gender" required><option value="">—</option><option value="m"><?= e(t('m')) ?></option><option value="f"><?= e(t('f')) ?></option></select></label><label><?= e(t('age')) ?><input name="age" type="number" min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>" step="1" required inputmode="numeric"></label></div><label><?= e(t('city')) ?><select name="city" required><option value="">—</option><?php foreach ($cities as $city): ?><option value="<?= (int) $city['id'] ?>"><?= e($city['name_' . lang()]) ?></option><?php endforeach; ?></select></label><label class="check"><input type="checkbox" name="adult" required><span><?= e(t('adult_check')) ?></span></label><label class="check"><input type="checkbox" name="rules" required><span><?= e(t('rules_check')) ?> · <a href="<?= e(url('rules')) ?>" target="_blank" rel="noopener"><?= e(t('rules')) ?></a> · <a href="<?= e(url('privacy')) ?>" target="_blank" rel="noopener"><?= e(t('privacy')) ?></a></span></label><button class="wide" type="submit"><?= e(t('enter')) ?></button><p class="muted small"><?= e(t('adult_text')) ?></p></form></section>
<section id="workspace" class="workspace" hidden><aside class="panel filters"><h2><?= e(t('filters')) ?></h2><p id="me" class="muted"></p><form id="filter-form"><label><?= e(t('peer_city')) ?><select name="city"><option value="0"><?= e(t('any')) ?></option><?php foreach ($cities as $city): ?><option value="<?= (int) $city['id'] ?>"><?= e($city['name_' . lang()]) ?></option><?php endforeach; ?></select></label><label><?= e(t('peer_gender')) ?><select name="gender"><option value=""><?= e(t('any')) ?></option><option value="m"><?= e(t('m')) ?></option><option value="f"><?= e(t('f')) ?></option></select></label><label><?= e(t('age_range')) ?><span class="form-grid"><input name="min_age" type="number" min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>" value="<?= $client['minAge'] ?>" required aria-label="<?= e(t('min_age')) ?>"><input name="max_age" type="number" min="<?= $client['minAge'] ?>" max="<?= $client['maxAge'] ?>" value="<?= $client['maxAge'] ?>" required aria-label="<?= e(t('max_age')) ?>"></span></label><button class="wide" id="search" type="submit"><?= e(t('search')) ?></button></form><button id="cancel" class="secondary wide" hidden><?= e(t('cancel')) ?></button><button id="expand" class="secondary wide" hidden><?= e(t('expand')) ?></button><div class="stats-line"><span><?= e(t('online')) ?> <b id="online">0</b></span><span><?= e(t('queued')) ?> <b id="queued">0</b></span></div><button id="forget" class="text-button"><?= e(t('forget')) ?></button></aside>
<div class="chat-panel panel"><div class="chat-header"><div><span id="state" class="pill"><?= e(t('idle')) ?></span><h2 id="peer"><?= e(t('search')) ?></h2></div><span class="live-dot" aria-hidden="true"></span></div><div id="messages" class="messages" role="log" aria-live="polite" aria-label="<?= e(t('chats')) ?>"><p class="empty-message"><?= e(t('safety')) ?></p></div><p id="typing" class="typing" aria-live="polite"></p><div id="emojis" class="emojis" hidden><?php foreach (['👋', '🙂', '😊', '😂', '❤️', '👍', '🙌', '🤔', '🔥', '🎉', '🇰🇬', '✨'] as $emoji): ?><button type="button" class="emoji-choice"><?= e($emoji) ?></button><?php endforeach; ?></div><form id="send-form" class="composer"><button id="emoji" type="button" class="icon-button" aria-label="<?= e(t('emoji')) ?>">☺</button><textarea id="message" rows="1" maxlength="<?= $client['maxLength'] ?>" placeholder="<?= e(t('message')) ?>" aria-label="<?= e(t('message')) ?>" disabled></textarea><button id="send" type="submit" aria-label="<?= e(t('send')) ?>" disabled><svg width="22" height="22" aria-hidden="true"><use href="<?= e(url('assets/icons.svg')) ?>#send"></use></svg></button></form><div class="chat-actions"><button id="next" disabled><?= e(t('next')) ?></button><button id="end" class="secondary" disabled><?= e(t('end')) ?></button><button id="report" class="text-button danger-text" disabled><?= e(t('report')) ?></button></div></div></section>
<dialog id="report-dialog"><form id="report-form"><h2><?= e(t('report')) ?></h2><label><?= e(t('reason')) ?><select name="reason"><?php foreach (['spam', 'abuse', 'advertising', 'explicit', 'minor', 'fraud', 'other'] as $reason): ?><option value="<?= e($reason) ?>"><?= e(t($reason)) ?></option><?php endforeach; ?></select></label><label><?= e(t('detail')) ?><textarea name="detail" maxlength="500" rows="3"></textarea></label><div class="button-row"><button type="submit"><?= e(t('send')) ?></button><button type="button" class="secondary" data-close="report-dialog"><?= e(t('cancel')) ?></button></div></form></dialog>
<dialog id="captcha-dialog"><form id="captcha-form"><h2><?= e(t('captcha')) ?></h2><label><span id="captcha-question"></span><input id="captcha-answer" inputmode="numeric" autocomplete="off" required></label><div class="button-row"><button type="submit"><?= e(t('send')) ?></button><button type="button" class="secondary" data-close="captcha-dialog"><?= e(t('cancel')) ?></button></div></form></dialog>
<noscript><p><?= e(t('invalid')) ?> JavaScript required.</p></noscript></main><script src="<?= e(url('assets/chat.js')) ?>" defer></script>
<?php require ROOT . '/app/Views/footer.php'; ?>
```

## Файл: app/Views/install.php

```php
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка Кезик</title><link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>"></head>
<body><main class="install panel"><img class="logo" src="<?= e(url('assets/logo.svg')) ?>" alt="Кезик"><h1>Установка • <?= (int) $step ?>/5</h1><p class="muted">PHP + MySQL. Без внешних сервисов.</p>
<?php if ($error): ?><p class="notice danger" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($step === 6): ?>
<h2>Готово!</h2><p>Обязательно удалите папку <b>install/</b> через файловый менеджер. Установка заблокирована файлом storage/installed.lock.</p><p>Перед публикацией заполните сведения об операторе и хостинге в админке.</p><a class="button" href="<?= e(url()) ?>">Открыть чат</a><a class="button secondary" href="<?= e(url('admin')) ?>">Админка</a>
<?php else: ?><form method="post"><?= csrfField() ?>
<?php if ($step === 1): ?>
<ul class="checks"><?php foreach ($checks as $name => $ok): ?><li><?= $ok ? '✓' : '✗' ?> <?= e($name) ?></li><?php endforeach; ?></ul><p>Запускайте мастер на закрытом от посторонних домене или временно защитите сайт паролем в панели хостинга.</p>
<?php elseif ($step === 2): ?>
<?php foreach (['host' => 'Хост БД', 'name' => 'Имя БД', 'user' => 'Пользователь', 'pass' => 'Пароль БД', 'prefix' => 'Префикс таблиц'] as $key => $label): ?><label><?= e($label) ?><input name="<?= e($key) ?>" type="<?= $key === 'pass' ? 'password' : 'text' ?>" value="<?= e($key === 'host' ? 'localhost' : ($key === 'prefix' ? 'kg_' : '')) ?>" <?= $key === 'pass' ? '' : 'required' ?> autocomplete="off"></label><?php endforeach; ?>
<?php elseif ($step === 3): ?>
<label>Логин администратора<input name="login" required minlength="3" maxlength="64" autocomplete="username"></label><label>Пароль (от 12 символов)<input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label><label>Email<input name="email" type="email" required></label>
<?php elseif ($step === 4): ?>
<label>Название сайта<input name="site_name" value="Кезик • Анонимный чат" required maxlength="120"></label><label>URL сайта, без /public<input name="url" type="url" placeholder="https://example.com" required></label><label>Язык<select name="language"><option value="ru">Русский</option><option value="ky">Кыргызча</option></select></label><label>Email поддержки<input name="support_email" type="email" required></label>
<?php elseif ($step === 5): ?><p>Соединение проверено. Создадим таблицы, справочник городов, администратора, настройки и конфигурацию.</p><p>Повторная установка не удаляет существующие таблицы. Для новой установки нужен свободный префикс.</p>
<?php endif; ?><button type="submit" <?= $step === 1 && in_array(false, $checks, true) ? 'disabled' : '' ?>><?= $step === 5 ? 'Установить' : 'Продолжить' ?></button><?php if ($step > 1): ?><button type="submit" name="back" value="1" class="secondary" formnovalidate>Назад</button><?php endif; ?></form><?php endif; ?>
</main></body></html>
```

## Файл: app/Views/page.php

```php
<?php $title = t($page) . ' · ' . setting('site_name'); require ROOT . '/app/Views/header.php'; ?>
<main class="wrap legal panel"><h1><?= e(t($page)) ?></h1>
<?php if ($page === 'rules'): ?><div class="prewrap"><?= e(setting('rules_' . lang())) ?></div>
<?php elseif ($page === 'privacy'): ?>
<h2><?= e(t('privacy_title')) ?></h2><p><b><?= e(t('operator')) ?>:</b> <?= e(setting('operator_name') ?: t('operator_missing')) ?><br><?= e(setting('operator_address')) ?></p><p><b><?= e(t('hosting')) ?>:</b> <?= e(setting('hosting_country') ?: t('operator_missing')) ?></p>
<?php foreach (['privacy_data', 'privacy_purpose', 'privacy_access', 'privacy_rights', 'privacy_cookies'] as $paragraph): ?><p><?= e(t($paragraph)) ?></p><?php endforeach; ?>
<h2><?= e(t('retention')) ?></h2><ul><?php foreach (['identity_days', 'session_days', 'report_days', 'audit_days'] as $key): ?><li><?= e(t($key)) ?>: <?= (int) setting($key) ?> <?= e(t('days')) ?></li><?php endforeach; ?><li><?= setting('log_messages') === '1' ? e(t('message_days')) . ': ' . (int) setting('message_days') . ' ' . e(t('days')) : e(t('ephemeral')) ?></li></ul><p><?= e(t('retention_cleanup')) ?></p><p><a href="https://dpa.gov.kg/" rel="noopener noreferrer">dpa.gov.kg</a> · <a href="https://cbd.minjust.gov.kg/3-48/edition/35412/ru" rel="noopener noreferrer">Цифровой кодекс КР №178</a></p>
<?php elseif ($page === 'contacts'): ?><p><?= e(t('operator')) ?>: <?= e(setting('operator_name') ?: t('operator_missing')) ?></p><p><?= e(setting('operator_address')) ?></p><p><?= e(t('support')) ?>: <a href="mailto:<?= e(setting('support_email')) ?>"><?= e(setting('support_email')) ?></a></p><p><?= e(t('my_id')) ?>: <?= (int) ($_SESSION['sid'] ?? 0) ?></p>
<?php elseif ($page === '18'): ?><p><?= e(t('adult_text')) ?></p>
<?php endif; ?><p><a class="button secondary" href="<?= e(url()) ?>"><?= e(t('home')) ?></a></p></main>
<?php require ROOT . '/app/Views/footer.php'; ?>
```

## Файл: app/bootstrap.php

```php
<?php

use App\Core\Db;
use App\Models\Settings;

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'App\\') === 0) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . '/storage/php-errors.log');
$GLOBALS['config'] = is_file(ROOT . '/config/config.php') ? require ROOT . '/config/config.php' : [];
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || !empty($GLOBALS['config']['https']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// Изолируем PHP-сессии от других сайтов на том же аккаунте хостинга.
$sessionDirectory = ROOT . '/storage/sessions';
if (!is_dir($sessionDirectory) && is_writable(ROOT . '/storage')) {
    @mkdir($sessionDirectory, 0700, true);
}
// До установки оставляем системное хранилище, если storage не writable:
// мастер сможет показать проверку прав вместо ошибки до первого шага.
if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    ini_set('session.save_path', $sessionDirectory);
}
ini_set('session.gc_maxlifetime', '1800');
session_name('kgchat');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(32));
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ru', 'ky'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}
$GLOBALS['lang'] = $_SESSION['lang'] ?? 'ru';
$GLOBALS['dict'] = require ROOT . '/app/Lang/' . $GLOBALS['lang'] . '.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}

function config(): array
{
    return $GLOBALS['config'];
}
function db(): Db
{
    static $db;
    if (!$db) {
        $db = new Db(config()['db']);
    }
    return $db;
}
function settings(): array
{
    if (!isset($GLOBALS['settings'])) {
        $GLOBALS['settings'] = Settings::load();
    }
    return $GLOBALS['settings'];
}
function setting(string $key): string
{
    return (string) (settings()[$key] ?? '');
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function t(string $key): string
{
    return $GLOBALS['dict'][$key] ?? $key;
}
function lang(): string
{
    return $GLOBALS['lang'];
}
function base(): string
{
    if (!empty(config()['url'])) {
        return rtrim((string) parse_url(config()['url'], PHP_URL_PATH), '/');
    }
    return rtrim(str_replace('/public', '', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/.');
}
function url(string $path = ''): string
{
    return base() . '/' . ltrim($path, '/');
}
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">';
}
```

## Файл: config/.htaccess

```apache
Require all denied
```

## Файл: config/config.example.php

```php
<?php
// Установщик создаёт config.php автоматически. Этот файл не подключается.
return [
    'db' => ['host' => 'localhost', 'name' => 'kg_chat', 'user' => 'kg_chat', 'pass' => '', 'prefix' => 'kg_'],
    'secret' => 'Сгенерировать 64 случайных шестнадцатеричных символа',
    'url' => 'https://example.com',
    // Включать только если TLS завершается на доверенном прокси хостинга.
    'https' => false,
];
```

## Файл: install/.htaccess

```apache
Require all denied
```

## Файл: install/Installer.php

```php
<?php

namespace App\Install;

use RuntimeException;
use PDOException;
use Throwable;
use App\Core\Db;
use App\Core\Http;
use App\Core\Security;
use App\Models\Settings;

class Installer
{
    public function checks(): array
    {
        $checks = ['PHP 7.4–8.3' => PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 80400];
        foreach (['pdo_mysql', 'mbstring', 'json', 'openssl'] as $extension) {
            $checks[$extension] = extension_loaded($extension);
        }
        foreach (['storage', 'config'] as $directory) {
            $checks[$directory . ' writable'] = is_writable(ROOT . '/' . $directory);
        }
        return $checks;
    }

    public function handle(): void
    {
        if (is_file(ROOT . '/storage/installed.lock')) {
            http_response_code(403);
            echo '<meta charset="utf-8">Установка заблокирована. Удалите папку install/.';
            return;
        }
        $step = (int) ($_SESSION['install_step'] ?? 1);
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Http::csrf();
            if (!empty($_POST['back']) && $step > 1 && $step <= 5) {
                $step--;
                $_SESSION['install_step'] = $step;
                Http::view('install', ['step' => $step, 'checks' => $this->checks(), 'error' => '']);
                return;
            }
            try {
                if ($step === 1) {
                    if (in_array(false, $this->checks(), true)) {
                        throw new RuntimeException('Окружение не соответствует требованиям.');
                    }
                } elseif ($step === 2) {
                    $db = [];
                    foreach (['host', 'name', 'user', 'pass', 'prefix'] as $field) {
                        $db[$field] = (string) ($_POST[$field] ?? '');
                    }
                    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/', $db['host']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $db['name']) || $db['user'] === '') {
                        throw new RuntimeException('Проверьте имя БД и хост.');
                    }
                    new Db($db);
                    $_SESSION['install_db'] = $db;
                } elseif ($step === 3) {
                    $login = trim((string) ($_POST['login'] ?? ''));
                    $password = (string) ($_POST['password'] ?? '');
                    $email = trim((string) ($_POST['email'] ?? ''));
                    if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $login) || strlen($password) < 12 || strlen($password) > 128 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Логин: 3–64 латинских символа. Пароль: 12–128 символов. Укажите правильный email.');
                    }
                    $_SESSION['install_admin'] = ['login' => $login, 'password' => Security::password($password), 'email' => $email];
                } elseif ($step === 4) {
                    $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
                    $name = trim((string) ($_POST['site_name'] ?? ''));
                    $support = trim((string) ($_POST['support_email'] ?? ''));
                    $parsed = parse_url($url);
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || isset($parsed['query']) || isset($parsed['fragment']) || isset($parsed['user']) || $name === '' || mb_strlen($name) > 120 || !filter_var($support, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Укажите название, URL сайта без /public и без параметров, email поддержки.');
                    }
                    $_SESSION['install_site'] = ['url' => $url, 'site_name' => $name, 'support_email' => $support, 'language' => ($_POST['language'] ?? '') === 'ky' ? 'ky' : 'ru'];
                } elseif ($step === 5) {
                    $this->finish();
                    $step = 6;
                }
                if ($step < 5) {
                    $step++;
                }
                $_SESSION['install_step'] = $step;
            } catch (Throwable $e) {
                // Ошибка подключения не раскрывает учётные данные.
                $error = $e instanceof PDOException ? 'Ошибка БД. Проверьте данные, права CREATE/ALTER/INDEX и журнал панели хостинга.' : $e->getMessage();
            }
        }
        Http::view('install', ['step' => $step, 'checks' => $this->checks(), 'error' => $error]);
    }

    private function finish(): void
    {
        $guard = fopen(ROOT . '/storage/install.guard', 'c');
        if (!$guard || !flock($guard, LOCK_EX)) {
            throw new RuntimeException('Невозможно заблокировать установку.');
        }
        $created = [];
        $db = null;
        try {
            if (is_file(ROOT . '/storage/installed.lock')) {
                throw new RuntimeException('Уже установлено.');
            }
            $cfg = ['db' => $_SESSION['install_db'], 'secret' => bin2hex(random_bytes(32)), 'url' => $_SESSION['install_site']['url'], 'https' => false];
            $db = new Db($cfg['db']);
            foreach (['cities', 'users', 'sessions', 'queue', 'chats', 'messages', 'reports', 'bans', 'admins', 'admin_logs', 'settings', 'stopwords', 'rate_limits'] as $table) {
                $exists = $db->one('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$cfg['db']['name'], $cfg['db']['prefix'] . $table]);
                if ($exists) {
                    throw new RuntimeException('Таблицы с этим префиксом уже существуют. Выберите другую БД/префикс. Установщик не удаляет существующие данные.');
                }
            }
            $sql = preg_replace('/^--.*$/m', '', file_get_contents(ROOT . '/install/install.sql'));
            $sql = str_replace('{{p}}', $cfg['db']['prefix'], $sql);
            foreach (explode(';', $sql) as $query) {
                if (trim($query) === '') {
                    continue;
                }
                $db->run($query);
                if (preg_match('/CREATE TABLE ([a-zA-Z0-9_]+)/', $query, $matches)) {
                    $created[] = $matches[1];
                }
            }
            $cities = [
                ['Бишкек', 'Бишкек'], ['Ош', 'Ош'], ['Джалал-Абад', 'Жалал-Абад'], ['Каракол', 'Каракол'],
                ['Токмок', 'Токмок'], ['Нарын', 'Нарын'], ['Талас', 'Талас'], ['Баткен', 'Баткен'],
                ['Балыкчы', 'Балыкчы'], ['Кара-Балта', 'Кара-Балта'], ['Кант', 'Кант'], ['Кемин', 'Кемин'],
                ['Чолпон-Ата', 'Чолпон-Ата'], ['Узген', 'Өзгөн'], ['Кок-Джангак', 'Көк-Жаңгак'],
                ['Майлуу-Суу', 'Майлуу-Суу'], ['Таш-Кумыр', 'Таш-Көмүр'], ['Кербен', 'Кербен'],
                ['Кызыл-Кия', 'Кызыл-Кыя'], ['Сулюкта', 'Сүлүктү'], ['Кара-Суу', 'Кара-Суу'],
                ['Кочкор', 'Кочкор'], ['Ат-Баши', 'Ат-Башы'], ['Токтогул', 'Токтогул'], ['Кадамжай', 'Кадамжай'],
                ['Ноокат', 'Ноокат'], ['Айдаркен', 'Айдаркен'], ['Кочкор-Ата', 'Кочкор-Ата'], ['Шопоков', 'Шопоков'],
            ];
            foreach ($cities as $city) {
                $db->run('INSERT INTO ' . $db->table('cities') . '(name_ru,name_ky) VALUES (?,?)', $city);
            }
            $admin = $_SESSION['install_admin'];
            $db->run('INSERT INTO ' . $db->table('admins') . "(login,password,email,role,created_at) VALUES (?,?,?,'super',UTC_TIMESTAMP())", [$admin['login'], $admin['password'], $admin['email']]);
            $defaults = array_merge(Settings::defaults(), $_SESSION['install_site']);
            unset($defaults['url']);
            foreach ($defaults as $key => $value) {
                $db->run('INSERT INTO ' . $db->table('settings') . '(name,value) VALUES (?,?)', [$key, $value]);
            }
            foreach (['link', 'phone', 'messenger'] as $kind) {
                $db->run('INSERT INTO ' . $db->table('stopwords') . "(kind,pattern,action) VALUES (?,?,'block')", [$kind, $kind]);
            }
            foreach (['бляд', 'хуй', 'пизд', 'fuck'] as $word) {
                $db->run('INSERT INTO ' . $db->table('stopwords') . "(kind,pattern,action) VALUES ('word',?,'replace')", [$word]);
            }
            $text = "<?php\n// Сгенерировано установщиком. Не публикуйте этот файл.\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents(ROOT . '/config/config.php.tmp', $text, LOCK_EX) === false || !rename(ROOT . '/config/config.php.tmp', ROOT . '/config/config.php')) {
                throw new RuntimeException('Не удалось записать конфигурацию.');
            }
            @chmod(ROOT . '/config/config.php', 0640);
            if (file_put_contents(ROOT . '/storage/installed.lock', gmdate('c'), LOCK_EX) === false) {
                throw new RuntimeException('Не удалось создать installed.lock.');
            }
            unset($_SESSION['install_db'], $_SESSION['install_admin'], $_SESSION['install_site']);
            session_regenerate_id(true);
        } catch (Throwable $e) {
            // DDL не откатывается транзакцией: удаляем только таблицы этой попытки.
            if ($db && $created) {
                foreach (array_reverse($created) as $table) {
                    try {
                        $db->run('DROP TABLE `' . $table . '`');
                    } catch (Throwable $ignored) {
                    }
                }
            }
            throw $e;
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }
}
```

## Файл: install/install.sql

```sql
-- {{p}} заменяется установщиком на проверенный префикс. InnoDB обязателен.
CREATE TABLE {{p}}cities (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name_ru VARCHAR(80) NOT NULL, name_ky VARCHAR(80) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1, INDEX(enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 nickname VARCHAR(40) NOT NULL, gender CHAR(1) NOT NULL, age TINYINT UNSIGNED NOT NULL,
 city_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(city_id) REFERENCES {{p}}cities(id), INDEX(created_at), INDEX(city_id,gender,age)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 ip_hash CHAR(64) NULL, fingerprint CHAR(64) NULL,
 last_seen DATETIME NOT NULL, identity_at DATETIME NOT NULL,
 typing_until DATETIME NULL, delivered_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 read_id BIGINT UNSIGNED NOT NULL DEFAULT 0, warning VARCHAR(500) NULL,
 FOREIGN KEY(user_id) REFERENCES {{p}}users(id) ON DELETE CASCADE,
 INDEX(last_seen), INDEX(ip_hash), INDEX(fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}chats (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 a BIGINT UNSIGNED NOT NULL, b BIGINT UNSIGNED NOT NULL,
 started_at DATETIME NOT NULL, ended_at DATETIME NULL, end_reason VARCHAR(40) NULL,
 last_activity DATETIME NOT NULL,
 FOREIGN KEY(a) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(b) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 INDEX(a,ended_at), INDEX(b,ended_at), INDEX(ended_at,started_at), INDEX(last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}queue (
 session_id BIGINT UNSIGNED PRIMARY KEY, city_id INT UNSIGNED NULL,
 gender CHAR(1) NULL, min_age TINYINT UNSIGNED NOT NULL, max_age TINYINT UNSIGNED NOT NULL,
 joined_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 FOREIGN KEY(session_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 FOREIGN KEY(city_id) REFERENCES {{p}}cities(id), INDEX(joined_at), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL, body TEXT NOT NULL, nonce VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY(chat_id) REFERENCES {{p}}chats(id) ON DELETE CASCADE,
 FOREIGN KEY(sender_id) REFERENCES {{p}}sessions(id) ON DELETE CASCADE,
 UNIQUE(chat_id,sender_id,nonce), INDEX(chat_id,id), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}admins (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, login VARCHAR(64) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL, email VARCHAR(190) NOT NULL, role VARCHAR(16) NOT NULL,
 totp_secret VARCHAR(64) NULL, totp_last BIGINT NOT NULL DEFAULT -1,
 enabled TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NULL,
 reporter_id BIGINT UNSIGNED NULL, target_id BIGINT UNSIGNED NULL,
 reason VARCHAR(40) NOT NULL, detail VARCHAR(500) NOT NULL DEFAULT '',
 priority TINYINT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 evidence MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL,
 resolved_at DATETIME NULL, admin_id INT UNSIGNED NULL,
 FOREIGN KEY(chat_id) REFERENCES {{p}}chats(id) ON DELETE SET NULL,
 FOREIGN KEY(reporter_id) REFERENCES {{p}}sessions(id) ON DELETE SET NULL,
 FOREIGN KEY(target_id) REFERENCES {{p}}sessions(id) ON DELETE SET NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL,
 INDEX(status,priority,created_at), INDEX(created_at), INDEX(chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}bans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, scope VARCHAR(16) NOT NULL,
 value VARCHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL,
 expires_at DATETIME NULL, created_at DATETIME NOT NULL,
 admin_id INT UNSIGNED NULL, revoked_at DATETIME NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL,
 INDEX(scope,value,revoked_at,expires_at), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}admin_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NULL,
 action VARCHAR(60) NOT NULL, detail VARCHAR(1000) NOT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(admin_id) REFERENCES {{p}}admins(id) ON DELETE SET NULL, INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}settings (
 name VARCHAR(64) PRIMARY KEY, value MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}stopwords (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL,
 pattern VARCHAR(190) NOT NULL, action VARCHAR(16) NOT NULL,
 replacement VARCHAR(190) NOT NULL DEFAULT '***', enabled TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE {{p}}rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO {{p}}settings(name,value) VALUES ('_match_lock','1'),('_cleanup_at','0');
```

## Файл: public/.htaccess

```apache
Options -Indexes
DirectoryIndex index.php
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [END,QSA]
```

## Файл: public/assets/admin.js

```javascript
'use strict';
(() => {
    const canvas = document.getElementById('chart');
    if (!canvas) return;
    const points = JSON.parse(canvas.dataset.points);
    const draw = () => {
        const width = Math.max(240, canvas.parentElement.clientWidth - 40);
        const dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = 240 * dpr;
        canvas.style.width = '100%';
        canvas.style.height = '240px';
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        const css = getComputedStyle(document.documentElement);
        ctx.fillStyle = css.getPropertyValue('--muted');
        ctx.font = '12px system-ui';
        if (!points.length) { ctx.fillText('0', 20, 120); return; }
        const max = Math.max(1, ...points.map(p => Number(p.n)));
        const slot = (width - 36) / points.length;
        points.forEach((p, index) => {
            const height = Number(p.n) / max * 160;
            ctx.fillStyle = css.getPropertyValue('--accent');
            ctx.fillRect(24 + index * slot, 190 - height, Math.max(4, slot - 10), height);
            ctx.fillStyle = css.getPropertyValue('--muted');
            ctx.fillText(p.n, 24 + index * slot, 180 - height);
            if (index % Math.max(1, Math.ceil(points.length / 6)) === 0) ctx.fillText(p.day.slice(5), 24 + index * slot, 215);
        });
    };
    window.addEventListener('resize', draw);
    document.addEventListener('themechange', draw);
    draw();
})();
```

## Файл: public/assets/chat.js

```javascript
'use strict';
(() => {
    const root = document.getElementById('app');
    if (!root) return;
    const cfg = JSON.parse(root.dataset.config);
    const word = key => cfg.words[key] || key;
    const $ = id => document.getElementById(id);
    let state = 'guest', chatId = 0, lastChat = 0, cursor = 0, seen = new Set();
    let polling = false, timer = null, transport = cfg.transport, failures = 0;
    let typingAt = 0, pending = null, readSent = 0, queueAt = 0;
    let currentWarning = '';

    const randomId = () => {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    };
    const device = () => {
        let id;
        try { id = localStorage.getItem('kg-device'); } catch (_) { }
        if (!id || !/^[a-f0-9]{32}$/.test(id)) {
            id = randomId();
            try { localStorage.setItem('kg-device', id); } catch (_) { }
        }
        return id;
    };
    const deviceId = device();
    const notice = (text, error = false) => {
        $('notice').textContent = text;
        $('notice').hidden = !text;
        $('notice').classList.toggle('danger', error);
    };
    const captcha = challenge => new Promise(resolve => {
        const dialog = $('captcha-dialog');
        $('captcha-question').textContent = challenge.question;
        $('captcha-answer').value = '';
        const close = () => { cleanup(); resolve(null); };
        const submit = event => {
            event.preventDefault();
            const answer = $('captcha-answer').value;
            cleanup(); dialog.close();
            resolve({captcha_token: challenge.token, captcha_answer: answer});
        };
        const cleanup = () => {
            dialog.removeEventListener('close', close);
            $('captcha-form').removeEventListener('submit', submit);
        };
        dialog.addEventListener('close', close, {once: true});
        $('captcha-form').addEventListener('submit', submit);
        dialog.showModal();
        $('captcha-answer').focus();
    });
    const api = async (route, data = null, retry = true) => {
        const controller = new AbortController();
        const limit = setTimeout(() => controller.abort(), route.startsWith('state') ? 27000 : 15000);
        try {
            const response = await fetch(`${cfg.base}/api/${route}`, {
                method: data === null ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'X-CSRF-Token': cfg.csrf, ...(data === null ? {} : {'Content-Type': 'application/json'})},
                body: data === null ? undefined : JSON.stringify(data)
            });
            const result = await response.json();
            if (!response.ok || result.error) {
                if (result.captcha && data !== null && retry) {
                    const solved = await captcha(result.captcha);
                    if (solved) return api(route, {...data, ...solved}, false);
                }
                throw new Error(result.error || word('server_error'));
            }
            return result;
        } catch (error) {
            if (error instanceof TypeError || error.name === 'AbortError') throw new Error(word('network'));
            throw error;
        } finally { clearTimeout(limit); }
    };
    const filters = () => {
        const form = $('filter-form');
        return {city: form.elements.city.value, gender: form.elements.gender.value, min_age: form.elements.min_age.value, max_age: form.elements.max_age.value};
    };
    const person = p => `${p.nickname} · ${word(p.gender)} · ${p.age} · ${p.city}`;
    const receipts = data => {
        document.querySelectorAll('.bubble.mine').forEach(element => {
            const id = Number(element.dataset.id);
            element.querySelector('.receipt').textContent = id <= data.peer_read ? word('read') : (id <= data.peer_delivered ? word('delivered') : word('sent'));
        });
    };
    const markRead = async () => {
        if (state !== 'chat' || document.hidden || cursor <= readSent) return;
        const id = cursor;
        try { await api('read', {chat_id: chatId, id}); readSent = id; } catch (_) { }
    };
    const render = data => {
        const previous = state;
        state = data.state;
        if (state === 'guest' || state === 'expired') {
            $('entry').hidden = false; $('workspace').hidden = true;
            chatId = 0;
            return;
        }
        $('entry').hidden = true; $('workspace').hidden = false;
        if (data.me) $('me').textContent = person(data.me);
        $('online').textContent = data.online ?? '—';
        $('queued').textContent = data.queued ?? '—';
        $('state').textContent = word(state);
        $('typing').textContent = data.typing ? word('typing') : '';
        $('search').disabled = ['chat', 'queue', 'banned', 'maintenance'].includes(state);
        $('cancel').hidden = state !== 'queue';
        $('next').disabled = ['banned', 'maintenance'].includes(state);
        $('end').disabled = state !== 'chat';
        $('message').disabled = state !== 'chat';
        $('send').disabled = state !== 'chat';
        if (state === 'queue') {
            queueAt = Date.parse(data.queue.joined_at.replace(' ', 'T') + 'Z');
            $('peer').textContent = word('queue');
        } else { queueAt = 0; $('expand').hidden = true; }
        if (state === 'chat') {
            if (chatId !== data.chat_id) {
                chatId = data.chat_id; lastChat = chatId;
                cursor = 0; readSent = 0; seen = new Set(); pending = null;
                $('message').value = ''; $('messages').replaceChildren();
            }
            $('peer').textContent = person(data.peer);
            for (const item of data.messages || []) {
                if (seen.has(item.id)) continue;
                seen.add(item.id); cursor = Math.max(cursor, item.id);
                const article = document.createElement('article');
                article.className = `bubble${item.mine ? ' mine' : ''}`;
                article.dataset.id = item.id;
                const text = document.createElement('p'); text.textContent = item.body;
                const meta = document.createElement('small');
                const time = document.createElement('time');
                const date = new Date(item.created_at.replace(' ', 'T') + 'Z');
                time.dateTime = date.toISOString(); time.textContent = date.toLocaleTimeString(cfg.lang === 'ky' ? 'ky-KG' : 'ru-RU', {hour: '2-digit', minute: '2-digit'});
                meta.append(time);
                if (item.mine) { const span = document.createElement('span'); span.className = 'receipt'; meta.append(span); }
                article.append(text, meta); $('messages').append(article);
            }
            // Ограничиваем DOM для долгого разговора, курсор сохраняется.
            while ($('messages').children.length > 300) $('messages').firstElementChild.remove();
            receipts(data);
            if ((data.messages || []).length) $('messages').scrollTop = $('messages').scrollHeight;
            markRead();
        } else {
            chatId = 0;
            if (state === 'idle') {
                $('peer').textContent = previous === 'queue' ? word('queue_expired') : (previous === 'chat' ? word('chat_ended') : word('idle'));
            }
        }
        $('report').disabled = !lastChat || ['banned', 'maintenance'].includes(state);
        if (state === 'banned' || state === 'maintenance') notice(data.reason || word(state), true);
        if (data.warning && data.warning !== currentWarning) {
            currentWarning = data.warning;
            notice(data.warning, true);
            api('warning', {}).catch(() => {});
        }
    };
    const poll = async () => {
        if (polling) return;
        polling = true;
        try {
            const data = await api(`state?cursor=${cursor}&transport=${transport === 'long' ? 'long' : 'poll'}`);
            failures = 0; render(data);
        } catch (error) {
            failures++; transport = 'poll'; notice(error.message, true);
        } finally {
            polling = false;
            clearTimeout(timer);
            const wait = transport === 'long' && ['chat', 'queue'].includes(state) && !failures ? 100 : Math.min(15000, cfg.poll * 1000 * Math.max(1, failures));
            timer = setTimeout(poll, wait);
        }
    };
    const refresh = () => { clearTimeout(timer); if (!polling) poll(); };
    const act = async (route, data = {}) => {
        try { const result = await api(route, data); notice(''); refresh(); return result; }
        catch (error) { notice(error.message, true); return null; }
    };
    $('entry-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget, submit = form.querySelector('button');
        submit.disabled = true;
        const data = Object.fromEntries(new FormData(form)); data.device = deviceId;
        await act('entry', data);
        submit.disabled = false;
    });
    $('filter-form').addEventListener('submit', event => { event.preventDefault(); act('join', filters()); });
    $('cancel').addEventListener('click', () => act('leave'));
    $('expand').addEventListener('click', () => {
        const form = $('filter-form');
        form.elements.city.value = '0'; form.elements.gender.value = '';
        form.elements.min_age.value = cfg.minAge; form.elements.max_age.value = cfg.maxAge;
        act('join', filters());
    });
    $('next').addEventListener('click', () => { if ($('filter-form').reportValidity()) act('next', filters()); });
    $('end').addEventListener('click', () => act('leave'));
    $('forget').addEventListener('click', async () => {
        if (confirm(word('forget_confirm'))) {
            const result = await act('forget');
            if (result) { lastChat = 0; cursor = 0; $('messages').replaceChildren(); render({state: 'guest'}); }
        }
    });
    $('send-form').addEventListener('submit', async event => {
        event.preventDefault();
        const body = $('message').value.trim();
        if (!body || state !== 'chat') return;
        if (!pending || pending.body !== body || pending.chat_id !== chatId) pending = {chat_id: chatId, body, nonce: randomId()};
        $('send').disabled = true;
        const result = await act('send', pending);
        if (result) { $('message').value = ''; pending = null; }
        $('send').disabled = state !== 'chat';
    });
    $('message').addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); $('send-form').requestSubmit(); }
    });
    $('message').addEventListener('input', () => {
        if (state === 'chat' && Date.now() - typingAt > 2000) { typingAt = Date.now(); api('typing', {chat_id: chatId, typing: $('message').value.length > 0}).catch(() => {}); }
    });
    $('emoji').addEventListener('click', () => { $('emojis').hidden = !$('emojis').hidden; });
    document.querySelectorAll('.emoji-choice').forEach(button => button.addEventListener('click', () => {
        if (state !== 'chat') return;
        if ($('message').value.length + button.textContent.length <= cfg.maxLength) $('message').setRangeText(button.textContent, $('message').selectionStart, $('message').selectionEnd, 'end');
        $('message').focus(); $('emojis').hidden = true;
    }));
    $('report').addEventListener('click', () => $('report-dialog').showModal());
    $('report-form').addEventListener('submit', async event => {
        event.preventDefault();
        const result = await act('report', {...Object.fromEntries(new FormData(event.currentTarget)), chat_id: lastChat});
        if (result) { $('report-dialog').close(); notice(word('reported')); }
    });
    document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => $(button.dataset.close).close()));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { markRead(); refresh(); } });
    setInterval(() => { $('expand').hidden = !(state === 'queue' && queueAt && Date.now() - queueAt > 45000); }, 1000);
    poll();
})();
```

## Файл: public/assets/icons.svg

```xml
<svg xmlns="http://www.w3.org/2000/svg"><symbol id="moon" viewBox="0 0 24 24"><path d="M20 15.1A8.3 8.3 0 0 1 8.9 4 8.5 8.5 0 1 0 20 15.1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></symbol><symbol id="send" viewBox="0 0 24 24"><path d="m3 3 18 9-18 9 4-9-4-9Zm4 9h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol></svg>
```

## Файл: public/assets/logo.svg

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="20" fill="#8787ff"/><path d="M15 16h34v26H32l-11 8v-8h-6z" fill="#11142b"/><circle cx="24" cy="29" r="3" fill="#e6e8ee"/><circle cx="33" cy="29" r="3" fill="#e6e8ee"/><circle cx="42" cy="29" r="3" fill="#e6e8ee"/></svg>
```

## Файл: public/assets/style.css

```css
/* Тема и базовые элементы */
:root {
    color-scheme: dark;
    --bg: #0f1115;
    --surface: #171a21;
    --raised: #20242f;
    --text: #e6e8ee;
    --muted: #a5afc2;
    --border: #303748;
    --accent: #8787ff;
    --accent-hover: #a2a1ff;
    --accent-ink: #0c0f1e;
    --bubble-own: #303362;
    --danger: #ff98a5;
    --danger-bg: #421e29;
    --shadow: 0 20px 65px #0003;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
:root[data-theme='light'] {
    color-scheme: light;
    --bg: #f3f5fa;
    --surface: #fff;
    --raised: #eef1f8;
    --text: #192031;
    --muted: #535f74;
    --border: #cbd2e1;
    --accent: #5047c9;
    --accent-hover: #6258dc;
    --accent-ink: #fff;
    --bubble-own: #e7e4ff;
    --danger: #b32144;
    --danger-bg: #ffedf0;
    --shadow: 0 20px 65px #1720360a;
}
* { box-sizing: border-box; }
[hidden] { display: none !important; }
body { margin: 0; background: var(--bg); color: var(--text); line-height: 1.55; transition: background .2s, color .2s; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
h1, h2, h3, p { margin: 0 0 16px; }
h1 { font-size: clamp(26px, 4vw, 42px); line-height: 1.15; letter-spacing: -.04em; }
h2 { font-size: 22px; line-height: 1.3; }
h3 { font-size: 17px; }
button, input, select, textarea { font: inherit; }
button, .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 10px 18px; border: 1px solid transparent; border-radius: 12px; background: var(--accent); color: var(--accent-ink); cursor: pointer; font-weight: 650; transition: background .15s, transform .15s; text-align: center; }
button:hover, .button:hover { background: var(--accent-hover); text-decoration: none; }
button:active { transform: translateY(1px); }
button:disabled { opacity: .5; cursor: default; }
button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
label { display: block; margin-bottom: 16px; color: var(--muted); font-size: 14px; }
input:not([type='checkbox']), select, textarea { width: 100%; min-height: 46px; margin-top: 7px; border: 1px solid var(--border); border-radius: 11px; background: var(--bg); color: var(--text); padding: 11px 12px; font-size: 16px; }
textarea { resize: vertical; }
.check { display: flex; align-items: flex-start; gap: 11px; }
.check input { width: 20px; height: 20px; flex-shrink: 0; margin-top: 1px; accent-color: var(--accent); }
.wrap { max-width: 1240px; width: calc(100% - 32px); margin-inline: auto; }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 22px; box-shadow: var(--shadow); }
.muted, .small { color: var(--muted); }
.small { font-size: 12px; }
.wide { width: 100%; }
.secondary { color: var(--text); background: var(--raised); border-color: var(--border); }
.secondary:hover { background: var(--border); }
.text-button { color: var(--muted); background: transparent; padding: 8px 3px; font-size: 13px; }
.text-button:hover { color: var(--text); background: transparent; }
.icon-button { width: 44px; padding: 8px; background: var(--raised); color: var(--text); font-size: 24px; }
.icon-button:hover { background: var(--border); }
.danger-text { color: var(--danger); }
.danger-button { background: var(--danger); color: var(--bg); }
.danger-button:hover { background: var(--danger); filter: brightness(1.1); }
.notice { padding: 15px 18px; margin: 0 0 20px; border-radius: 12px; border: 1px solid var(--accent); background: var(--raised); overflow-wrap: anywhere; }
.notice.danger { color: var(--danger); border-color: var(--danger); background: var(--danger-bg); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.button-row { display: flex; flex-wrap: wrap; gap: 10px; }
.prewrap { white-space: pre-wrap; }
.spread { display: flex; justify-content: space-between; gap: 12px; }
/* Шапка и стартовая анкета */
.topbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 20px 0 28px; }
.brand { display: flex; align-items: center; gap: 10px; color: var(--text); font-weight: 750; font-size: 14px; line-height: 1.2; }
.brand span { max-width: 170px; }
.topnav { display: flex; align-items: center; gap: 11px; font-size: 12px; }
.topnav a { color: var(--muted); }
.topnav a[aria-current] { color: var(--text); }
.entry { display: grid; gap: 26px; }
.hero { padding: 12px 0; }
.hero h1 { margin-top: 24px; max-width: 580px; font-size: clamp(36px, 5vw, 64px); }
.hero > p { color: var(--muted); max-width: 460px; font-size: 17px; }
.pill { display: inline-flex; padding: 7px 12px; border: 1px solid var(--border); border-radius: 100px; color: var(--accent); font-size: 12px; background: var(--raised); }
.hero-art { height: 145px; position: relative; max-width: 420px; }
.art-bubble { display: inline-block; position: absolute; left: 5%; top: 12px; padding: 15px 23px; border: 1px solid var(--border); background: var(--surface); border-radius: 22px 22px 22px 5px; font-size: 25px; transform: rotate(-5deg); }
.art-bubble.second { left: 40%; top: 70px; background: var(--bubble-own); transform: rotate(4deg); border-radius: 22px 22px 5px 22px; }
.hero > .safety { font-size: 13px; margin-top: 18px; }
/* Чат */
.workspace { display: grid; gap: 16px; }
.filters h2 { font-size: 19px; }
.filters .secondary { margin-top: 10px; }
.stats-line { display: flex; justify-content: space-between; padding: 17px 0 4px; font-size: 12px; color: var(--muted); }
.stats-line b { color: var(--text); }
.chat-panel { padding: 0; overflow: hidden; display: flex; flex-direction: column; min-width: 0; }
.chat-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px; border-bottom: 1px solid var(--border); }
.chat-header h2 { font-size: 15px; margin: 11px 0 0; overflow-wrap: anywhere; }
.chat-header .pill { font-size: 11px; }
.live-dot { width: 9px; height: 9px; flex-shrink: 0; background: #54d9ad; box-shadow: 0 0 0 6px #54d9ad15; border-radius: 50%; }
.messages { height: 44dvh; min-height: 250px; max-height: 620px; display: flex; flex-direction: column; gap: 12px; padding: 18px; overflow-y: auto; overscroll-behavior: contain; }
.empty-message { margin: auto; text-align: center; max-width: 280px; color: var(--muted); font-size: 13px; }
.bubble { align-self: flex-start; max-width: 85%; padding: 11px 15px; border-radius: 17px 17px 17px 4px; background: var(--raised); border: 1px solid var(--border); overflow-wrap: anywhere; flex-shrink: 0; }
.bubble.mine { align-self: flex-end; background: var(--bubble-own); border-radius: 17px 17px 4px 17px; }
.bubble p { margin: 0 0 6px; white-space: pre-wrap; }
.bubble small { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; color: var(--muted); font-size: 10px; }
.typing { min-height: 21px; margin: 0; padding: 0 18px; font-size: 11px; color: var(--muted); }
.composer { display: flex; align-items: flex-end; gap: 8px; padding: 12px; }
.composer textarea { margin: 0; min-height: 46px; height: 46px; max-height: 130px; resize: vertical; }
.composer > button { flex-shrink: 0; padding: 9px; width: 44px; }
.chat-actions { display: flex; gap: 8px; align-items: center; padding: 8px 12px 15px; border-top: 1px solid var(--border); }
.chat-actions button { min-height: 40px; font-size: 12px; padding: 8px 10px; }
.emojis { display: flex; flex-wrap: wrap; padding: 8px 12px; gap: 6px; }
.emoji-choice { background: var(--raised); min-height: 42px; padding: 6px 9px; font-size: 22px; }
dialog { width: min(440px, calc(100% - 32px)); color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 24px; box-shadow: var(--shadow); }
dialog::backdrop { background: #000a; backdrop-filter: blur(5px); }
/* Админка, документы и установщик */
.install { max-width: 540px; margin: 35px auto; width: calc(100% - 32px); }
.logo { width: 48px; height: 48px; margin-bottom: 16px; }
.checks { padding-left: 20px; }
.checks li { margin-bottom: 10px; }
.legal { max-width: 820px; margin-top: 18px; line-height: 1.8; overflow-wrap: anywhere; }
.legal h2 { margin-top: 28px; }
.admin-layout { display: grid; gap: 22px; }
.admin-nav nav { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.admin-nav nav a { display: block; color: var(--muted); padding: 8px 12px; font-size: 13px; border-radius: 10px; }
.admin-nav nav a.current { color: var(--text); background: var(--raised); }
.admin-content { min-width: 0; }
.admin-content > .panel, .admin-content > form { margin-bottom: 22px; }
.metric-grid { display: grid; gap: 14px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 20px; }
.metric { padding: 20px; }
.metric span { font-size: 12px; color: var(--muted); }
.metric strong { display: block; margin-top: 10px; font-size: 35px; line-height: 1.2; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 12px; }
th, td { text-align: left; padding: 12px 9px; border-bottom: 1px solid var(--border); vertical-align: top; max-width: 220px; overflow-wrap: anywhere; }
th { color: var(--muted); font-weight: 600; }
td form { margin: 0; }
.pagination { display: flex; gap: 18px; justify-content: center; margin: 20px 0; font-size: 13px; }
.evidence { max-height: 480px; overflow: auto; background: var(--bg); padding: 16px; border-radius: 12px; margin: 16px 0; }
.evidence-line { border-bottom: 1px solid var(--border); padding: 12px 0; }
.evidence-line p { margin: 7px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.evidence-line small { color: var(--muted); }
.chart-data { display: flex; flex-wrap: wrap; gap: 10px; color: var(--muted); font-size: 10px; }
.settings-form label { max-width: 680px; }
.footer { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; color: var(--muted); padding: 30px 0 max(24px, env(safe-area-inset-bottom)); font-size: 11px; }
.footer nav { display: flex; flex-wrap: wrap; gap: 15px; }
.footer a { color: var(--muted); }
@media (min-width: 760px) {
    .wrap { width: calc(100% - 64px); }
    .topbar { padding: 28px 0 40px; }
    .brand { font-size: 18px; }
    .brand span { max-width: none; }
    .entry { grid-template-columns: 1.2fr 1fr; gap: 60px; align-items: center; }
    .hero-art { height: 190px; margin-top: 28px; }
    .art-bubble { font-size: 34px; }
    .art-bubble.second { top: 90px; }
    .workspace { grid-template-columns: 290px minmax(0, 1fr); align-items: start; }
    .filters { position: sticky; top: 20px; }
    .messages { height: 51dvh; }
    .chat-actions button { font-size: 14px; padding-inline: 15px; }
    .panel { padding: 26px; }
    .chat-panel { padding: 0; }
    .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (min-width: 1000px) {
    .admin-layout { grid-template-columns: 230px minmax(0, 1fr); }
    .admin-nav { position: sticky; top: 20px; align-self: start; }
    .admin-nav nav { display: block; }
    .admin-nav nav a { margin-bottom: 3px; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; scroll-behavior: auto !important; } }
```

## Файл: public/assets/theme.js

```javascript
'use strict';
(() => {
    const storage = {
        get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
        set(key, value) { try { localStorage.setItem(key, value); } catch (_) { /* Хранилище может быть отключено. */ } }
    };
    document.documentElement.dataset.theme = storage.get('kg-theme') === 'light' ? 'light' : 'dark';
    document.getElementById('theme')?.addEventListener('click', () => {
        const theme = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
        document.documentElement.dataset.theme = theme;
        storage.set('kg-theme', theme);
        document.dispatchEvent(new Event('themechange'));
    });
})();
```

## Файл: public/index.php

```php
<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require ROOT . '/app/bootstrap.php';

use App\Core\Http;
use App\Controllers\ChatController;
use App\Controllers\AdminController;

try {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    $base = base();
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($path === '/install') {
        if (!is_file(ROOT . '/install/Installer.php')) {
            http_response_code(404);
            exit('Installer removed');
        }
        require ROOT . '/install/Installer.php';
        (new \App\Install\Installer())->handle();
        exit;
    }
    if (!is_file(ROOT . '/storage/installed.lock') || !config()) {
        Http::redirect('install');
    }
    if (!isset($_SESSION['lang'])) {
        $GLOBALS['lang'] = setting('language') === 'ky' ? 'ky' : 'ru';
        $GLOBALS['dict'] = require ROOT . '/app/Lang/' . lang() . '.php';
    }
    if (strpos($path, '/api/') === 0) {
        (new ChatController())->handle(substr($path, 5), $method);
    } elseif ($path === '/admin' || strpos($path, '/admin/') === 0) {
        (new AdminController())->handle($path, $method);
    } elseif ($method === 'GET' && $path === '/') {
        Http::view('home', ['cities' => db()->all('SELECT * FROM ' . db()->table('cities') . ' WHERE enabled=1 ORDER BY name_ru')]);
    } elseif ($method === 'GET' && in_array($path, ['/rules', '/privacy', '/contacts', '/18'], true)) {
        Http::view('page', ['page' => substr($path, 1)]);
    } else {
        http_response_code(404);
        Http::view('page', ['page' => '404']);
    }
} catch (Throwable $exception) {
    // Не записываем SQL/аргументы/пароли/сообщения в журнал ошибок.
    $reference = bin2hex(random_bytes(5));
    error_log('Incident ' . $reference . ': ' . get_class($exception) . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine());
    if (strpos($path ?? '', '/api/') === 0) {
        Http::json(['error' => t('server_error'), 'reference' => $reference], 500);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><p>' . e(t('server_error')) . ' #' . e($reference) . '</p>';
}
```

## Файл: storage/.htaccess

```apache
Require all denied
```

## Файл: tests/.htaccess

```apache
Require all denied
```

## Файл: tests/README.md

````markdown
# Проверки

Эта папка закрыта `.htaccess` и не нужна на хостинге. Можно удалить её после установки. На сервере не нужны Python, Node.js, Composer или CLI.

## Локально

Создайте отдельную пустую БД и пользователя с правами на неё. Из корня проекта:

```bash
KG_CHAT_TEST=1 TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=kg_chat_test TEST_DB_USER=kg_chat_test TEST_DB_PASS=your_test_password php tests/integration.php
```

Тест создаёт случайный префикс `test_*`, затем удаляет только созданные им таблицы. Не запускайте на рабочей БД. Потребуется `install/install.sql`, поэтому выполняйте до удаления `install/` или на локальной копии архива.

Проверяется: уникальное сопряжение, идемпотентная отправка, доставка, чтение, typing, скрытие хешей, изоляция по жалобе «несовершеннолетний», TTL без архива, снимок жалобы, вектор RFC 6238, rate limit.

Для локального браузерного запуска, исключительно при установленном PHP CLI:

```bash
php -S 127.0.0.1:8080 tests/router.php
```

На Windows этот встроенный сервер однопроцессный: long-poll блокирует остальные запросы. Для разработки оставьте polling. Установите приложение через `http://127.0.0.1:8080/install` на отдельной БД.

## Ручной приёмочный тест

1. Откройте обычное окно и инкогнито. Укажите возраст 17: вход должен быть запрещён. С возрастом 18 и согласиями вход разрешён.
2. Выберите несовместимые взаимные фильтры: пара не должна создаваться. Расширьте фильтры: ожидающий участник должен найти пару.
3. Отправьте кириллицу, кыргызские буквы, эмодзи и текст `<img src=x onerror=alert(1)>`: последний должен отображаться текстом.
4. Убедитесь в статусах доставки/чтения, typing, кнопках отмены поиска, следующего участника и завершения.
5. Закройте второе окно; через heartbeat timeout первый участник получает завершение. Поиск прекращается по queue timeout.
6. Пожалуйтесь «несовершеннолетний»: адресат временно изолирован. В админке жалоба имеет приоритет, снимок доступен, действия модератора записаны в журнал.
7. Войдите модератором: настройки, управление администраторами, экспорт и аудит недоступны. Через прямой URL также 403.
8. Включите TOTP с новым кодом; после выхода проверьте вход с правильным и неправильным кодом. Повторный код в том же временном окне не принимается.
9. Проверьте 360/390/430 px, горизонтальную ориентацию, экранную клавиатуру, тёмную/светлую тему и оба языка.
10. Экспортируйте SQL и восстановите его в отдельную БД. Проверьте блокировку `install/` и запрет доступа к `config/config.php`, `storage/php-errors.log`, `install/Installer.php`.

## Проверено при сборке

PHP 8.3.6 + MariaDB 10.11.14: lint всех PHP-файлов; PSR-12 без ошибок для классов и точки входа (предупреждения только о длине строк); синтаксис JavaScript; HTTP-мастер установки, lock, CSRF, возраст, капча, взаимные фильтры, одновременные сопряжение/next, сообщения/nonce, receipts/typing, приоритетная жалоба, баны, все страницы админки, разграничение ролей, TOTP, SQL-экспорт, long-poll без блокировки PHP-сессии, heartbeat, очистка без архива.

Совместимость с PHP 7.4 обеспечивается синтаксисом и используемыми API, но отдельный запуск на 7.4 не выполнялся. Браузерный автоматический тест в среде сборки не завершён: локальный Chromium аварийно завершался до открытия страницы. Визуальную приёмку и нагрузочный тест выполните на вашем хостинге.
````

## Файл: tests/integration.php

```php
<?php

/**
 * Локальные интеграционные тесты. Требуют PHP CLI и отдельную тестовую БД.
 * На рабочей БД не запускать. Префикс создаётся случайно и удаляется в finally.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('KG_CHAT_TEST') !== '1') {
    exit("Set KG_CHAT_TEST=1. Read tests/README.md first.\n");
}
define('ROOT', dirname(__DIR__));
require ROOT . '/app/bootstrap.php';

use App\Core\Security;
use App\Models\Settings;
use App\Services\ChatService;
use App\Services\ModerationService;
use App\Services\CleanupService;

$GLOBALS['config'] = [
    'db' => [
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'name' => getenv('TEST_DB_NAME') ?: 'kg_chat_test',
        'user' => getenv('TEST_DB_USER') ?: 'kg_chat_test',
        'pass' => getenv('TEST_DB_PASS') ?: '',
        'prefix' => 'test_' . bin2hex(random_bytes(4)) . '_',
    ],
    'secret' => bin2hex(random_bytes(32)),
    'url' => 'http://localhost',
];
$created = [];
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    echo 'PASS ' . $message . "\n";
}
try {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(ROOT . '/install/install.sql'));
    $sql = str_replace('{{p}}', config()['db']['prefix'], $sql);
    foreach (explode(';', $sql) as $query) {
        if (trim($query) === '') {
            continue;
        }
        db()->run($query);
        if (preg_match('/CREATE TABLE ([a-zA-Z0-9_]+)/', $query, $matches)) {
            $created[] = $matches[1];
        }
    }
    foreach (Settings::defaults() as $key => $value) {
        Settings::put($key, $value);
    }
    db()->run('INSERT INTO ' . db()->table('cities') . "(name_ru,name_ky) VALUES ('Бишкек','Бишкек')");
    $city = db()->id();
    $sessions = [];
    foreach (['m', 'f', 'm', 'f'] as $gender) {
        db()->run('INSERT INTO ' . db()->table('users') . '(nickname,gender,age,city_id,created_at) VALUES (?,?,25,?,UTC_TIMESTAMP())', ['Тест', $gender, $city]);
        $user = db()->id();
        db()->run('INSERT INTO ' . db()->table('sessions') . '(user_id,ip_hash,fingerprint,last_seen,identity_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$user, Security::hash('test-ip'), Security::hash((string) $user)]);
        $sessions[] = db()->id();
    }
    $service = new ChatService();
    $filter = [null, null, 18, 99];
    foreach ($sessions as $sid) {
        $service->join($sid, $filter);
    }
    $active = db()->all('SELECT a,b FROM ' . db()->table('chats') . ' WHERE ended_at IS NULL');
    $participants = [];
    foreach ($active as $row) {
        $participants[] = $row['a'];
        $participants[] = $row['b'];
    }
    verify(count($active) === 2 && count(array_unique($participants)) === 4, 'one active pair per session');
    $a = $sessions[0];
    $b = $sessions[1];
    $chat = $service->active($a);
    $data = ['chat_id' => $chat['id'], 'body' => 'Салам 👋', 'nonce' => bin2hex(random_bytes(16))];
    $id = $service->message($a, $data);
    verify($service->message($a, $data) === $id, 'message nonce is idempotent');
    $state = $service->snapshot($b);
    verify(count($state['messages']) === 1 && !isset($state['peer']['ip_hash']), 'delivery and private profile');
    $service->receipt($b, (int) $chat['id'], $id);
    verify($service->snapshot($a)['peer_read'] === $id, 'read receipts');
    $service->typing($b, (int) $chat['id'], true);
    verify($service->snapshot($a)['typing'] === true, 'typing indicator');
    (new ModerationService())->report($a, ['chat_id' => $chat['id'], 'reason' => 'minor']);
    verify($service->snapshot($b)['state'] === 'banned' && $service->active($a) === null, 'minor report isolates target');
    Settings::put('log_messages', '0');
    unset($GLOBALS['settings']);
    db()->run('UPDATE ' . db()->table('messages') . ' SET created_at=?', [gmdate('Y-m-d H:i:s', time() - 180)]);
    (new CleanupService())->run(true);
    verify((int) db()->one('SELECT COUNT(*) n FROM ' . db()->table('messages'))['n'] === 0, 'ephemeral messages are deleted');
    verify((int) db()->one('SELECT COUNT(*) n FROM ' . db()->table('reports'))['n'] === 1, 'report snapshot survives message cleanup');
    // RFC 6238: SHA1, time=59, восьмизначный код 94287082 -> шесть цифр 287082.
    verify(Security::totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082', 'RFC 6238 TOTP vector');
    verify(Security::hit('test-limit', 1, 60) && !Security::hit('test-limit', 1, 60), 'atomic rate limit');
    echo "ALL TESTS PASSED\n";
} finally {
    foreach (array_reverse($created) as $table) {
        db()->run('DROP TABLE `' . $table . '`');
    }
}
```

## Файл: tests/router.php

```php
<?php
// Только для локальной разработки: php -S 127.0.0.1:8080 tests/router.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/assets/[a-zA-Z0-9_.-]+$~', $path)) {
    $file = dirname(__DIR__) . '/public' . $path;
    if (is_file($file)) {
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml'];
        header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($file);
        return;
    }
}
if (preg_match('~^/(app|config|storage|tests|public)/~', $path) || (strpos($path, '/install/') === 0)) {
    http_response_code(403);
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__) . '/public/index.php';
```

## Файл: README.md

````markdown
# Кезик — анонимный чат Кыргызстана, 18+

Версия 1.0.0. PHP + PDO + MySQL/MariaDB, vanilla JavaScript и CSS. Все ресурсы находятся в архиве. Системный шрифт устройства, локальные SVG. Нет Composer, Node.js, сборки, обязательного cron или WebSocket-сервера.

## Требования

- PHP 7.4–8.3; рекомендуются поддерживаемые 8.2/8.3. Старые 7.4/8.0 не получают обновлений безопасности от upstream.
- Расширения `pdo_mysql`, `mbstring`, `json`, `openssl`; PHP sessions, password_hash, random_bytes и hash_hmac.
- MySQL 5.7+/8.0 или MariaDB 10.3+, InnoDB, utf8mb4. Проверено на MariaDB 10.11.
- Apache 2.4 с mod_rewrite и разрешённым `.htaccess`, либо nginx с document root на `public/` и маршрутизацией в index.php.
- Доступ PHP на запись в `storage/` и `config/`. HTTPS для публичного запуска.
- Секретный ключ и пароли не передаются внешним сервисам.

## Установка без SSH

1. В cPanel/ISPmanager создайте БД и отдельного пользователя, выдайте ему права на эту БД. На этапе установки нужны CREATE, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE; для обычной работы достаточно SELECT/INSERT/UPDATE/DELETE. Экспорт использует SHOW CREATE TABLE.
2. Загрузите ZIP через файловый менеджер, распакуйте. Внутри папки `kg-chat/` лежит весь проект; не теряйте скрытые `.htaccess`.
3. Предпочтительный вариант: папка проекта вне публичного каталога, document root домена указывает на `kg-chat/public/`. `app/`, `config/`, `storage/` и физическая `install/` вообще не попадают в web root.
4. Если document root менять нельзя, поместите **содержимое** `kg-chat/` в `public_html/` или подкаталог. Корневая `.htaccess` направляет запросы в `public/index.php`, ресурсы `/assets/*` — в `public/assets/*`, внутренние папки запрещает. Этот вариант требует Apache, обрабатывающий `.htaccess` для всех файлов. Если nginx отдаёт `.sql/.log` напрямую, используйте вариант с public/ либо настройте запреты через панель/поддержку хостинга.
5. Обычные права файлов 644, папок 755; запись в config/storage должна быть доступна вашему PHP-пользователю. При одном владельце достаточно 700 для storage и 755 для config. При работе через группу может понадобиться 770/775 по рекомендации провайдера. Не выставляйте 777. Установщик создаёт config.php с 640; если PHP работает от другого пользователя, настройте группу в панели.
6. Пока устанавливаете, ограничьте сайт паролем в панели хостинга. Откройте `https://ваш-домен/install` (без `/index.php` и без `/public`). Для подкаталога: `https://домен/chat/install`.
7. Пройдите пять шагов: окружение → БД → суперадмин → название, URL, язык и поддержка → создание таблиц. URL должен совпадать с фактическим адресом, содержать https:// и подкаталог, если он есть; без завершающего / и без /public.
8. После успешной установки существует `storage/installed.lock`. `/install` возвращает 403. **Удалите папку install/** через файловый менеджер. Папку tests/ также можно удалить с боевой копии. Сохраните исходный ZIP вне сайта.
9. Войдите в `/admin`. Заполните имя/организацию оператора, адрес, страну хостинга, email и тексты правил. Включите TOTP в разделе «Безопасность».
10. Проверьте две браузерные сессии (обычную и инкогнито), фильтры, сообщения, жалобу, язык и mobile. Чек-лист в tests/README.md.

Установщик не удаляет существующие таблицы. Для переустановки вручную удалите `storage/installed.lock`, верните install/ из архива и используйте отдельную БД или свободный префикс. Удаление config.php без удаления lock не разблокирует установщик. При неудаче откатываются только таблицы текущей попытки. DDL MySQL не обладает полноценным транзакционным rollback.

## URL и .htaccess

На Apache корневая `.htaccess` использует `[END]`, поэтому нужен Apache 2.4. `Options -Indexes` и `Require all denied` защищают папки. Если хостинг запрещает директиву Options, удалите только строку `Options -Indexes` после отключения листинга в панели. Не удаляйте запреты папок.

`/install` — виртуальный маршрут общей точки входа, подключающий закрытый файл `install/Installer.php`. Прямой `/install/Installer.php` запрещён. Такое устройство позволяет одновременно иметь браузерный установщик и закрытую физическую папку.

Для чистого nginx выбирайте только document root на public/. В конфигурации домена через панель используйте:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

PHP-обработчик и запрет скрытых файлов должны быть стандартными настройками вашего хостинга. При отсутствии доступа к конфигурации попросите поддержку включить маршрутизацию. Нельзя считать `.htaccess` защитой на чистом nginx.

Если TLS завершается на доверенном прокси, а PHP не видит HTTPS, в config/config.php задайте `'https' => true`; это включает Secure cookie и HSTS. Приложение не доверяет присланным клиентом X-Forwarded-For/Proto. Если провайдер скрывает реальные IP за прокси, настройте REMOTE_ADDR средствами доверенного прокси/хостинга; иначе IP-лимит будет общим для посетителей.

## Что реализовано

- Вход по анонимной анкете, без паролей и обязательной регистрации. В админке «Создание анкет» управляет допуском новых посетителей; отдельного пользовательского аккаунта с email/паролем нет.
- Жёсткий серверный минимум 18 лет, обязательные согласия. Максимум/минимум настраиваются, опустить минимум ниже 18 невозможно. Возраст является заявлением, а не проверкой документа.
- 29 городов с названиями RU/KY; CRUD и отключение справочника.
- Взаимные фильтры по полу, возрасту и городу. Очередь, отмена, таймаут, ручное расширение фильтров после 45 секунд; кнопка «Следующий».
- Сопряжение в транзакции InnoDB под `SELECT ... FOR UPDATE`: отдельная строка settings `_match_lock` сериализует операции очереди. Session ID является PK очереди. Активный чат проверяется до вставки, очередь обоих участников удаляется в одной транзакции. Join/next/leave/бан/очистка используют тот же mutex. При deadlock/lock timeout транзакция повторяется целиком до трёх раз.
- Текст и эмодзи; отправка с nonce, защита от повторной записи при сетевом повторе; доставка, прочтение, typing; heartbeat и таймаут без сообщений.
- Отдельные капчи на подозрительных входах и при превышении мягкого лимита сообщений. Жёсткие лимиты капча не отменяет.
- Жалобы с серверным снимком до 200 последних сообщений. Чужие сообщения клиент не может подменить. Жалоба доступна участнику текущего чата и до 10 минут после завершения. Дубликат жалобы на тот же чат блокируется.
- Жалоба «Несовершеннолетний» ставится в начало очереди и немедленно изолирует адресата на 1 час по сессии. Это превентивная мера, которую модератор может снять; итоговый постоянный бан решает модератор.
- Суперадмин/модератор, отдельный вход, CSRF, защита попыток входа, TOTP с проверкой повторного использования кода, управление ролями, аудит.
- Сессии, поиск по нику/ID, баны по сессии/IP-HMAC/браузерному ID, срок/причина, разбан, предупреждения.
- Метаданные активных/архивных чатов; текст в админке показывается только через жалобу. SQL-копия содержит сообщения: доступна только суперадмину после повторного ввода пароля и записывается в аудит.
- Дашборд с графиком canvas без библиотек, распределениями, новыми анкетами и жалобами. Числа считаются по UTC; время сообщений отображается в часовом поясе браузера.
- Настраиваемые блокировка/замена/авто-жалоба для слов/фрагментов, ссылок, телефонов, @ников/мессенджеров. Список по умолчанию — стартовый, его нужно адаптировать к вашему сообществу.
- Тёмная тема по умолчанию, светлая в localStorage, RU/KY, mobile-first, локальные SVG. PWA/Service Worker не добавлены: страницы и переписка не должны попадать в offline-кэш.
- Локальный счётчик просмотров: заполните «Имя локального счётчика» для включения. Считаются открытия главной страницы по UTC; нет cookies аналитики, уникальных посетителей, IP-журнала или внешнего JS. HTML/скрипты сторонних счётчиков не принимаются настройками.

## Почему polling по умолчанию

Shared-хостинг обычно ограничивает одновременные PHP-процессы. SSE и long-poll держат PHP worker занятым почти постоянно на каждого пользователя, даже если никто не пишет. Для SSE дополнительно нужны отключённая буферизация nginx/FastCGI и подходящие таймауты, которыми владелец shared-аккаунта часто не управляет.

Поэтому по умолчанию короткий запрос `/api/state` раз в 3 секунды. Можно выбрать 2 секунды. Нет висящего процесса между запросами. Запрос возвращает новые сообщения, состояние, typing и receipts; heartbeat обновляется этим же запросом.

Опциональный long-poll: до 20 секунд, с запасом 5 секунд относительно max_execution_time. Проверка изменения раз в секунду. Транзакция/блокировки не удерживаются во время ожидания. PHP file session закрывается перед ожиданием, поэтому send/leave выполняются параллельно. Браузер держит один state-запрос, при таймауте/ошибке автоматически переходит на короткий polling. Бесконечного SSE-стрима нет.

Сразу после входа/отправки инициируется ближайшее обновление; сетевые ошибки повторяются с увеличением интервала до 15 секунд. В фоне браузер может замедлять таймеры; после heartbeat timeout разговор завершится. На одиночном встроенном PHP-сервере long-poll использовать нельзя.

## Нагрузка: честные ориентиры

Без замеров вашего тарифа нельзя гарантировать число пользователей. Начальный ориентир для недорогого shared — **10–30 одновременно онлайн с polling 3 секунды**, затем проверяйте CPU, MySQL, PHP workers, задержку и 503 в панели. Это предположение для планирования, не результат нагрузочного теста. Некоторые тарифы выдержат больше, некоторые меньше.

При N=30 получается примерно 10 state-запросов/сек, при N=100 — 33/сек, плюс сообщения и кнопки. Если запрос в среднем занимает 100 мс, только state в среднем занимает N/3×0.1 PHP worker: около 1 для 30 и 3.3 для 100. Пики, SQL-лимиты и очередь блокировок повышают потребность. Snapshot выполняет несколько SQL-запросов и берёт общий mutex; это сознательный простой вариант для небольшой аудитории, а не архитектура на тысячи пользователей.

В режиме long-poll каждый ожидающий/чатящийся посетитель занимает около одного worker. При лимите 10 процессов и резерве 3 для админки/отправки получится примерно 7 ожидающих long-poll посетителей. Оставляйте polling, если провайдер не гарантирует существенно больше workers. Увеличивать таймаут не повышает вместимость shared.

Для роста: сначала измерения на staging, затем VPS/выделенная БД и другая архитектура realtime. В этом архиве таких зависимостей нет.

## Хранение, анонимность и очистка

Собеседник получает только ник, пол, возраст, город. Нет email пользователя, фотографий, файлов, голосовых сообщений или геолокации. IP преобразуется в HMAC-SHA256 с секретным ключом; сырой IP не записывается приложением. Браузерный «fingerprint» — случайный идентификатор в localStorage, также HMAC на сервере. Он не собирает canvas, шрифты или характеристики устройства и может быть сброшен очисткой хранилища. Сессионные/браузерные баны поэтому не заменяют профессиональную антибот-защиту.

По умолчанию:

| Данные | Срок |
| --- | --- |
| Сообщения в архиве | 7 дней |
| IP-HMAC и браузерный HMAC, в том числе значения hash-банов | 7 дней |
| Жалобы с независимым снимком | 30 дней |
| Неактивная анкета | 30 дней после last_seen |
| Аудит администраторов | 90 дней |
| PHP cookie-сессия без активности | 30 минут |

Сроки меняются в настройках. «Навсегда» для бана по сессии означает пока существует эта анонимная сессия. Для IP/браузерного хеша срок хранения приоритетнее бессрочного бана: хеш удаляется через identity_days, бан перестаёт действовать. Это сохраняет заданный предел хранения. Остатки завершённых чатов удаляются по сроку архива; жалобы сохраняют снимок независимо от удаления чата.

Если архив отключён, сообщения всё равно кратковременно записываются в БД для доставки (120 секунд); старше этого TTL не возвращаются state API и удаляются очередной очисткой. Уже сделанный снимок жалобы хранится report_days. Нельзя надёжно доставлять сообщения без какого-либо временного серверного хранения.

Очистка без cron запускается максимум раз в минуту на запросах API/админки, небольшими порциями под файловым lock. При отсутствии запросов физическое удаление откладывается до следующего визита; при накопленном backlog потребуется несколько проходов. Кнопка очистки запускает одну дополнительную порцию, а не удаляет свежие данные. Для большого сайта можно добавить необязательный запуск из cron после собственного расширения, но проект его не требует.

Кнопка смены анкеты удаляет её, завершает связанные чаты и каскадно удаляет её сообщения. Снимки ранее поданных жалоб остаются до своего срока. Журналы Apache/nginx/PHP-провайдера, резервные копии и логи CDN находятся вне контроля приложения. Не объявляйте посетителям абсолютную анонимность или физическое уничтожение «ровно в указанную секунду».

## Политика для Кыргызстана

Встроенная политика описывает состав, цели, сроки, права, cookies и работу с жалобами. Учитывает Цифровой кодекс КР от 31.07.2025 №178 и закон о введении №179. Официальные источники:

- https://cbd.minjust.gov.kg/3-48/edition/35412/ru
- https://reestr.dpa.gov.kg/ru/npa/60
- https://dpa.gov.kg/

Это настраиваемая политика приложения, не заключение о юридическом соответствии конкретного оператора. До публичного запуска оператор должен указать свои действительные данные и место хостинга, проверить основания обработки/трансграничной передачи и порядок ответа на обращения. Заявление 18+ не доказывает фактический возраст. Подозрения рассматриваются в приоритетном порядке; автоматическую изоляцию можно обжаловать через поддержку.

## База данных и связи

Полная схема находится в `install/install.sql`. `{{p}}` — проверенный префикс, заменяемый установщиком; не импортируйте файл без замены. Справочник/настройки/суперадмин заполняются кодом мастера.

| Таблица | Назначение и связи |
| --- | --- |
| users | Анонимная анкета; city_id → cities |
| sessions | 1:1 с users через UNIQUE(user_id), heartbeat, HMAC, watermark доставки/чтения |
| cities | Справочник RU/KY, enabled |
| queue | Одна строка на session_id; FK → sessions/cities, взаимные фильтры, joined/expires |
| chats | a/b → sessions, started/ended, last_activity; атомарность активных пар обеспечивает сервис с mutex |
| messages | FK → chats/sessions; UNIQUE(chat_id,sender_id,nonce), индекс(chat_id,id) |
| reports | FK → chat/reporter/target/admin с SET NULL, независимый evidence, priority/status |
| bans | scope/value, срок/причина, revoked_at, FK → admins; scope=session/ip/fingerprint |
| admins | Логин, password_hash, роль, TOTP secret и последнее принятое окно |
| admin_logs | FK → admins, действие и метаданные без паролей/текста сообщений |
| settings | Настройки, mutex, время очистки, суточные агрегаты просмотров |
| stopwords | literal-слово либо встроенный тип шаблона; block/replace/report |
| rate_limits | HMAC bucket, atomic hits, expires_at |

Удаление анкеты каскадно удаляет её sessions/chats/messages/queue; ссылки reports получают NULL, а evidence сохраняется. Админы отключаются, не удаляются через UI. Города отключаются без нарушения FK. Индексы созданы на выборках состояния, времени, очереди, жалобах и банах.

## Обновление и резервные копии

1. Включите режим обслуживания, снимите SQL-копию в `/admin/backup` или phpMyAdmin и отдельную защищённую копию config/config.php через файловый менеджер. Секрет необходим для сопоставления уже имеющихся HMAC; SQL-копия его не содержит.
2. Для версии с совместимой схемой замените app/, public/, корневую .htaccess. Не перезаписывайте config/config.php, storage/installed.lock и storage/sessions/. Не возвращайте install/ на публичный сайт.
3. При изменении схемы новой версией следуйте её конкретной миграции через phpMyAdmin. В 1.0.0 нет автоматического обновляющего мастера и непредоставленных миграций.
4. Проверьте сценарии, выключите обслуживание. При откате возвращайте согласованные файлы и БД, а не только часть.

SQL-экспорт использует согласованный снимок InnoDB и hex-литералы, работает без записи SQL-файла в web root. На очень большой БД может превысить время запроса; применяйте экспорт phpMyAdmin/панели. Скачанный SQL содержит чувствительные сведения и ключи TOTP, должен храниться вне сайта; удаления по TTL не распространяются автоматически на отдельные копии.

Восстановление: создайте отдельную БД, импортируйте SQL через phpMyAdmin, настройте config/config.php с её учётными данными и **тем же secret**. Проверьте, затем переключайте рабочий домен. Не импортируйте непроверенную копию поверх единственной рабочей БД.

Если потерян TOTP, владелец хостинга может через phpMyAdmin выполнить для своей учётной записи, заменив kg_ на свой префикс:

```sql
UPDATE kg_admins SET totp_secret=NULL,totp_last=-1 WHERE login='ваш_логин';
```

После входа задайте новый пароль и повторно включите 2FA. Это аварийное восстановление требует контроля над БД.

## Где редактировать код

- public/assets/style.css — темы, mobile/desktop, пузыри и админка.
- public/assets/chat.js — UI, запросы API, emoji, polling, dialogs.
- app/Lang/ru.php / ky.php — переводы интерфейса.
- app/Views/ — HTML-шаблоны; экранируйте новые данные через e().
- app/Controllers/ — маршруты пользовательского API и админки.
- app/Services/ChatService.php — очередь, match, чат, receipts.
- app/Services/ModerationService.php — фильтры, жалобы, баны.
- app/Services/CleanupService.php — ограниченная фоновая очистка.
- app/Core/ — PDO, HTTP, CSRF, rate limits, TOTP.
- app/Models/Settings.php — начальные настройки и доступ к ним.
- public/index.php — единая точка входа и роутер.
- install/Installer.php / install.sql — мастер и схема.

В архиве есть SOURCE.md — полный код всех файлов с заголовками «Файл: путь», README идёт последним. Исходники являются рабочими файлами, а SOURCE.md предназначен для просмотра и копирования. Он закрыт от web-доступа.

## Известные ограничения

Нет загрузок/видео, push-уведомлений, платных функций, аккаунтов пользователей, документальной проверки возраста, гарантированной уникальности физического человека или промышленной защиты от ботов. Арифметическая капча может решаться ботом; основные меры здесь — жёсткие лимиты и модерация. Баны по общей NAT-IP могут затронуть нескольких людей; предпочитайте бан по сессии и проверяйте жалобы.

Вторая вкладка того же браузера использует одну сессию и тот же разговор. Не используйте вкладки как отдельных участников. «Прочитано» означает, что страница была видима и подтвердила получение; это не доказательство, что человек прочёл текст. «Доставлено» означает ответ на state-запрос, но при обрыве сети на пути к браузеру такое подтверждение не даёт абсолютной гарантии отображения.

Фильтры регулярных выражений не распознают все способы маскировки ссылок/контактов/мата. Дашборд отражает сохранённые строки: после очистки исторические числа уменьшаются, это не бессрочная аналитическая система. Счётчик онлайн основан на heartbeat и может включать недавно ушедших/заблокированных до таймаута. На большом архиве экспорт и статистика потребуют оптимизации.

Точный отчёт проверок и ручной приёмочный список — tests/README.md. Запуск на каждом PHP/MySQL из диапазона и измерения на вашем shared-тарифе не выполнялись.
````

