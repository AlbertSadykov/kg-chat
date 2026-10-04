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
