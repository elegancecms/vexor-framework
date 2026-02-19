<?php

declare(strict_types=1);

namespace Vexor\ORM;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Vexor Database Connection Manager
 * 
 * Manages database connections with:
 * - Multiple connection support
 * - Connection pooling simulation
 * - Read/write splitting
 * - Query logging
 */
class DatabaseManager
{
    private array $connections = [];
    private array $config;
    private array $queryLog = [];
    private bool $logging = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connection(string $name = null): PDO
    {
        $name ??= $this->config['default'] ?? 'mysql';

        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    private function createConnection(string $name): PDO
    {
        $config = $this->config['connections'][$name] ?? null;

        if (!$config) {
            throw new RuntimeException("Database connection [{$name}] not configured.");
        }

        try {
            $pdo = match ($config['driver']) {
                'mysql'  => $this->createMysql($config),
                'pgsql'  => $this->createPgsql($config),
                'sqlite' => $this->createSqlite($config),
                default  => throw new RuntimeException("Unsupported driver [{$config['driver']}]"),
            };

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to connect to database [{$name}]: " . $e->getMessage(), 0, $e);
        }
    }

    private function createMysql(array $config): PDO
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$config['charset']}' COLLATE '{$config['collation']}'",
            PDO::ATTR_PERSISTENT         => $config['persistent'] ?? false,
        ]);
    }

    private function createPgsql(array $config): PDO
    {
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        return new PDO($dsn, $config['username'], $config['password']);
    }

    private function createSqlite(array $config): PDO
    {
        $dsn = "sqlite:{$config['database']}";
        return new PDO($dsn);
    }

    public function builder(string $connection = null): QueryBuilder
    {
        return new QueryBuilder($this->connection($connection));
    }

    public function enableQueryLog(): void    { $this->logging = true; }
    public function disableQueryLog(): void   { $this->logging = false; }
    public function getQueryLog(): array      { return $this->queryLog; }

    public function select(string $query, array $bindings = [], string $connection = null): array
    {
        $pdo  = $this->connection($connection);
        $stmt = $pdo->prepare($query);
        $stmt->execute($bindings);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function statement(string $query, array $bindings = [], string $connection = null): bool
    {
        $pdo  = $this->connection($connection);
        $stmt = $pdo->prepare($query);
        return $stmt->execute($bindings);
    }

    public function transaction(callable $callback, string $connection = null): mixed
    {
        $pdo = $this->connection($connection);
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function disconnect(string $name = null): void
    {
        $name ??= $this->config['default'] ?? 'mysql';
        unset($this->connections[$name]);
    }

    public function reconnect(string $name = null): PDO
    {
        $this->disconnect($name);
        return $this->connection($name);
    }
}
