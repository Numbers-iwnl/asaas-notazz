<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function init(array $cfg): void
    {
        if (self::$pdo !== null) {
            return;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int)$cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );
        try {
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00', NAMES utf8mb4",
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Falha de conexão MySQL: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database não inicializado. Chame Bootstrap::init().');
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $bindings = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    public static function one(string $sql, array $bindings = []): ?array
    {
        $row = self::run($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $bindings = []): array
    {
        return self::run($sql, $bindings)->fetchAll();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = implode(', ', array_map(static fn($c) => "`{$c}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$cols}) VALUES ({$placeholders})";
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $set = implode(', ', array_map(static fn($c) => "`{$c}` = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";
        return self::run($sql, array_merge(array_values($data), $whereBindings))->rowCount();
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
