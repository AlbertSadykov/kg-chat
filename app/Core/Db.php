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
