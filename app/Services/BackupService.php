<?php

namespace App\Services;

use App\Models\Blog;
use App\Models\Settings;
use Throwable;

class BackupService
{
    private const RETENTION_DAYS = 7;

    public function runDailyIfDue(): void
    {
        $lock = null;
        try {
            $directory = $this->directory();
            try {
                $this->prune();
            } catch (Throwable $exception) {
                error_log('Automatic backup cleanup failed: ' . get_class($exception));
                Settings::put('_backup_error', 'Backup cleanup failed: ' . get_class($exception));
            }
            $day = gmdate('Y-m-d');
            $marker = $directory . '/.last-attempt';
            if (is_file($marker) && trim((string) file_get_contents($marker)) === $day) {
                return;
            }

            $lock = fopen($directory . '/.backup.lock', 'c');
            if (!$lock) {
                throw new \RuntimeException('Could not open backup lock');
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }
            if (is_file($marker) && trim((string) file_get_contents($marker)) === $day) {
                return;
            }
            if (file_put_contents($marker, $day, LOCK_EX) === false) {
                throw new \RuntimeException('Could not write backup schedule marker');
            }
            Settings::put('_backup_last_attempt_day', $day);
            try {
                $filename = $this->create();
                try {
                    db()->run(
                        'INSERT INTO ' . db()->table('admin_logs') . '(admin_id,action,detail,created_at) VALUES (NULL,?,?,UTC_TIMESTAMP())',
                        ['system_backup', $filename]
                    );
                } catch (Throwable $exception) {
                    error_log('Automatic backup audit write failed: ' . get_class($exception));
                }
            } catch (Throwable $exception) {
                $errorDetail = $exception instanceof \PDOException
                    ? ' SQLSTATE ' . (string) ($exception->errorInfo[0] ?? $exception->getCode()) . ', driver code ' . (string) ($exception->errorInfo[1] ?? '') . ': ' . $exception->getMessage()
                    : ': ' . $exception->getMessage();
                error_log('Automatic backup failed (' . get_class($exception) . ')' . $errorDetail);
                try {
                    Settings::put('_backup_error', 'Backup failed: ' . get_class($exception));
                } catch (Throwable $statusException) {
                    error_log('Could not store automatic backup error status: ' . get_class($statusException));
                }
            }
        } catch (Throwable $exception) {
            error_log('Automatic backup scheduler failed: ' . get_class($exception));
            try {
                Settings::put('_backup_error', 'Backup scheduler failed: ' . get_class($exception));
            } catch (Throwable $statusException) {
                error_log('Could not store backup scheduler error status: ' . get_class($statusException));
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function create(): string
    {
        if (!class_exists(\PharData::class)) {
            throw new \RuntimeException('PharData is unavailable');
        }
        $directory = $this->directory();
        $filename = 'kg-chat-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.tar.gz';
        $archivePath = $directory . '/' . $filename;
        $tarPath = substr($archivePath, 0, -3);
        $sqlPath = $directory . '/.database-' . bin2hex(random_bytes(8)) . '.sql';
        $stream = fopen($sqlPath, 'wb');
        if (!$stream) {
            throw new \RuntimeException('Could not create database dump');
        }

        try {
            $this->writeDatabaseDump($stream);
            fclose($stream);
            $stream = null;

            $archive = new \PharData($tarPath);
            $this->addProjectFiles($archive);
            $archive->addFile($sqlPath, 'database.sql');
            unset($archive);

            $compressed = (new \PharData($tarPath))->compress(\Phar::GZ);
            unset($compressed);
            if (!is_file($archivePath) || !unlink($tarPath)) {
                throw new \RuntimeException('Could not finalize backup archive');
            }
            if (DIRECTORY_SEPARATOR !== '\\' && !chmod($archivePath, 0600)) {
                throw new \RuntimeException('Could not secure backup archive permissions');
            }
            if (file_put_contents($directory . '/.last-attempt', gmdate('Y-m-d'), LOCK_EX) === false) {
                throw new \RuntimeException('Could not update backup schedule marker');
            }
            Settings::put('_backup_last_attempt_day', gmdate('Y-m-d'));
            Settings::put('_backup_last_success', gmdate('Y-m-d H:i:s'));
            Settings::put('_backup_error', '');
            try {
                $this->prune();
            } catch (Throwable $exception) {
                error_log('Backup cleanup failed: ' . get_class($exception));
                Settings::put('_backup_error', 'Backup cleanup failed: ' . get_class($exception));
            }
            return $filename;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($tarPath)) {
                unlink($tarPath);
            }
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
            throw $exception;
        } finally {
            if (is_file($sqlPath)) {
                unlink($sqlPath);
            }
        }
    }

    public function writeDatabaseDump($stream): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('A writable stream is required');
        }
        $this->write($stream, "-- KG Chat backup. Contains sensitive data. UTC.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        db()->run('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        db()->transaction(function () use ($stream): void {
            $tables = [
                'cities', 'users', 'sessions', 'chats', 'queue', 'messages',
                'message_likes', 'message_replies', 'admins', 'reports', 'bans',
                'admin_logs', 'settings', 'stopwords', 'rate_limits',
            ];
            if (Blog::ready()) {
                $tables = array_merge($tables, ['blog_posts', 'blog_media', 'blog_post_media']);
            }
            if (PushService::ready()) {
                $tables[] = 'push_subscriptions';
            }

            foreach ($tables as $name) {
                $table = db()->table($name);
                $ddl = db()->one("SHOW CREATE TABLE $table");
                if (!$ddl) {
                    continue;
                }
                $this->write($stream, "DROP TABLE IF EXISTS $table;\n" . $ddl['Create Table'] . ";\n");
                $offset = 0;
                $batch = in_array($name, ['reports', 'blog_posts'], true) ? 10 : 250;
                do {
                    $orderColumns = [
                        'settings' => ['name'],
                        'rate_limits' => ['bucket'],
                        'queue' => ['session_id'],
                        'message_likes' => ['message_id', 'session_id'],
                        'message_replies' => ['message_id'],
                        'blog_post_media' => ['post_id', 'media_id'],
                    ];
                    $orderSql = implode(',', array_map(
                        static function (string $column): string {
                            return '`' . $column . '`';
                        },
                        $orderColumns[$name] ?? ['id']
                    ));
                    $rows = db()->all("SELECT * FROM $table ORDER BY $orderSql LIMIT " . $batch . ' OFFSET ' . $offset);
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) {
                            $values[] = $value === null ? 'NULL' : "X'" . bin2hex((string) $value) . "'";
                        }
                        $this->write($stream, "INSERT INTO $table VALUES (" . implode(',', $values) . ");\n");
                    }
                    $offset += $batch;
                } while (count($rows) === $batch);
            }
        });
        $this->write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    public function status(): array
    {
        $settings = Settings::load();
        return [
            'lastSuccess' => $settings['_backup_last_success'] ?? '',
            'lastAttemptDay' => $settings['_backup_last_attempt_day'] ?? '',
            'error' => $settings['_backup_error'] ?? '',
            'files' => $this->listFiles(),
        ];
    }

    public function pathForDownload(string $filename): ?string
    {
        if (!preg_match('/^kg-chat-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.tar\.gz$/D', $filename)) {
            return null;
        }
        $path = $this->directory() . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    private function directory(): string
    {
        $directory = ROOT . '/storage/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create private backup directory');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && !chmod($directory, 0700)) {
            throw new \RuntimeException('Could not secure backup directory permissions');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Backup directory is not writable');
        }
        return $directory;
    }

    private function addProjectFiles(\PharData $archive): void
    {
        $root = realpath(ROOT);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(ROOT, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen(ROOT) + 1);
            $normalized = str_replace('\\', '/', $relative);
            if ($normalized === 'storage/backups' || strpos($normalized, 'storage/backups/') === 0) {
                continue;
            }
            if ($normalized === 'storage/sessions' || strpos($normalized, 'storage/sessions/') === 0) {
                continue;
            }
            if ($file->isFile()) {
                $realPath = realpath($path);
                if ($realPath === false || strpos($realPath, $root . DIRECTORY_SEPARATOR) !== 0) {
                    continue;
                }
                $archive->addFile($path, 'site/' . $normalized);
            }
        }
    }

    private function prune(): void
    {
        $cutoff = time() - self::RETENTION_DAYS * 86400;
        foreach ($this->listFiles() as $file) {
            if ($file['modifiedAt'] < $cutoff && !unlink($this->directory() . '/' . $file['name'])) {
                throw new \RuntimeException('Could not remove an expired backup');
            }
        }
    }

    private function listFiles(): array
    {
        $files = [];
        foreach (new \DirectoryIterator($this->directory()) as $file) {
            if ($file->isFile() && preg_match('/^kg-chat-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.tar\.gz$/D', $file->getFilename())) {
                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified' => gmdate('Y-m-d H:i:s', $file->getMTime()),
                    'modifiedAt' => $file->getMTime(),
                ];
            }
        }
        usort($files, static function (array $a, array $b): int {
            return strcmp($b['name'], $a['name']);
        });
        return $files;
    }

    private function write($stream, string $content): void
    {
        $length = strlen($content);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($stream, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Could not write database dump');
            }
            $offset += $written;
        }
    }
}
