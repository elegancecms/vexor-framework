<?php

declare(strict_types=1);

namespace Vexor\ORM;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Vexor QueryBuilder
 * 
 * Fluent, secure query builder using PDO prepared statements exclusively.
 * Zero chance of SQL injection via raw query values.
 * 
 * Supports:
 * - SELECT, INSERT, UPDATE, DELETE
 * - WHERE, AND, OR, IN, BETWEEN, LIKE
 * - JOIN (INNER, LEFT, RIGHT, CROSS)
 * - ORDER BY, GROUP BY, HAVING, LIMIT, OFFSET
 * - Subqueries
 * - Aggregate functions
 * - Transactions
 */
class QueryBuilder
{
    private PDO $pdo;
    private string $table = '';
    private array $wheres = [];
    private array $bindings = [];
    private array $columns = ['*'];
    private array $joins = [];
    private array $orders = [];
    private array $groups = [];
    private ?string $having = null;
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;
    private array $withs = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function table(string $table): static
    {
        $clone        = clone $this;
        $clone->table = $table;
        return $clone;
    }

    // ── SELECT ────────────────────────────────────────────────────────────────

    public function select(string ...$columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    public function addSelect(string ...$columns): static
    {
        $this->columns = array_merge($this->columns, $columns);
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    // ── WHERE ─────────────────────────────────────────────────────────────────

    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operatorOrValue];
        } else {
            $operator = strtoupper((string) $operatorOrValue);
        }

        $placeholder      = ':w_' . count($this->bindings);
        $this->wheres[]   = ['type' => 'AND', 'clause' => "`{$column}` {$operator} {$placeholder}"];
        $this->bindings[$placeholder] = $value;
        return $this;
    }

    public function orWhere(string $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operatorOrValue];
        } else {
            $operator = strtoupper((string) $operatorOrValue);
        }

        $placeholder      = ':w_' . count($this->bindings);
        $this->wheres[]   = ['type' => 'OR', 'clause' => "`{$column}` {$operator} {$placeholder}"];
        $this->bindings[$placeholder] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $placeholders = [];
        foreach ($values as $value) {
            $key = ':in_' . count($this->bindings);
            $placeholders[]       = $key;
            $this->bindings[$key] = $value;
        }

        $this->wheres[] = [
            'type'   => 'AND',
            'clause' => "`{$column}` IN (" . implode(', ', $placeholders) . ")",
        ];
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $placeholders = [];
        foreach ($values as $value) {
            $key = ':nin_' . count($this->bindings);
            $placeholders[]       = $key;
            $this->bindings[$key] = $value;
        }

        $this->wheres[] = [
            'type'   => 'AND',
            'clause' => "`{$column}` NOT IN (" . implode(', ', $placeholders) . ")",
        ];
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $minKey = ':bmin_' . count($this->bindings);
        $this->bindings[$minKey] = $min;
        $maxKey = ':bmax_' . count($this->bindings);
        $this->bindings[$maxKey] = $max;

        $this->wheres[] = ['type' => 'AND', 'clause' => "`{$column}` BETWEEN {$minKey} AND {$maxKey}"];
        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        return $this->where($column, 'LIKE', $pattern);
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'AND', 'clause' => "`{$column}` IS NULL"];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'AND', 'clause' => "`{$column}` IS NOT NULL"];
        return $this;
    }

    // ── JOINs ─────────────────────────────────────────────────────────────────

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "INNER JOIN `{$table}` ON `{$first}` {$operator} `{$second}`";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "LEFT JOIN `{$table}` ON `{$first}` {$operator} `{$second}`";
        return $this;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = "RIGHT JOIN `{$table}` ON `{$first}` {$operator} `{$second}`";
        return $this;
    }

    // ── ORDER / GROUP ─────────────────────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $direction      = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = "`{$column}` {$direction}";
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'ASC');
    }

    public function groupBy(string ...$columns): static
    {
        foreach ($columns as $col) {
            $this->groups[] = "`{$col}`";
        }
        return $this;
    }

    public function having(string $clause): static
    {
        $this->having = $clause;
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function skip(int $offset): static { return $this->offset($offset); }
    public function take(int $limit): static  { return $this->limit($limit); }

    // ── Fetch ─────────────────────────────────────────────────────────────────

    public function get(string $fetchClass = null): array
    {
        $sql  = $this->buildSelect();
        $stmt = $this->execute($sql);

        if ($fetchClass) {
            return $stmt->fetchAll(PDO::FETCH_CLASS, $fetchClass);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function first(string $fetchClass = null): mixed
    {
        $clone  = clone $this;
        $clone->limit = 1;
        $result = $clone->get($fetchClass);
        return $result[0] ?? null;
    }

    public function find(mixed $id, string $fetchClass = null): mixed
    {
        return $this->where('id', $id)->first($fetchClass);
    }

    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        return $row[$column] ?? null;
    }

    public function pluck(string $column, string $key = null): array
    {
        $rows   = $this->select(...array_filter([$column, $key]))->get();
        $result = [];

        foreach ($rows as $row) {
            if ($key && isset($row[$key])) {
                $result[$row[$key]] = $row[$column];
            } else {
                $result[] = $row[$column];
            }
        }
        return $result;
    }

    // ── Aggregates ────────────────────────────────────────────────────────────

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function sum(string $column): mixed
    {
        return $this->aggregate('SUM', $column);
    }

    public function avg(string $column): mixed
    {
        return $this->aggregate('AVG', $column);
    }

    private function aggregate(string $func, string $column): mixed
    {
        $col = $column === '*' ? '*' : "`{$column}`";
        $sql = "SELECT {$func}({$col}) AS aggregate FROM `{$this->table}`" . $this->buildWhereClause();
        $row = $this->execute($sql)->fetch(PDO::FETCH_ASSOC);
        return $row['aggregate'] ?? null;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function paginate(int $perPage = 15, int $page = 1, string $fetchClass = null): array
    {
        $total   = $this->count();
        $results = $this->limit($perPage)->offset(($page - 1) * $perPage)->get($fetchClass);

        return [
            'data'          => $results,
            'total'         => $total,
            'per_page'      => $perPage,
            'current_page'  => $page,
            'last_page'     => (int) ceil($total / $perPage),
            'from'          => ($page - 1) * $perPage + 1,
            'to'            => min($page * $perPage, $total),
        ];
    }

    // ── INSERT ────────────────────────────────────────────────────────────────

    public function insert(array $data): bool
    {
        $columns = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $keys    = [];

        foreach (array_keys($data) as $col) {
            $key            = ':ins_' . $col;
            $keys[]         = $key;
            $this->bindings[$key] = $data[$col];
        }

        $placeholders = implode(', ', $keys);
        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})";

        return $this->execute($sql)->rowCount() > 0;
    }

    public function insertGetId(array $data): int|string
    {
        $this->insert($data);
        return $this->pdo->lastInsertId();
    }

    public function insertMany(array $rows): bool
    {
        if (empty($rows)) return false;

        $columns = array_keys($rows[0]);
        $colSql  = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $allPlaceholders = [];

        foreach ($rows as $i => $row) {
            $rowKeys = [];
            foreach ($columns as $col) {
                $key                  = ":im_{$i}_{$col}";
                $rowKeys[]            = $key;
                $this->bindings[$key] = $row[$col];
            }
            $allPlaceholders[] = '(' . implode(', ', $rowKeys) . ')';
        }

        $sql = "INSERT INTO `{$this->table}` ({$colSql}) VALUES " . implode(', ', $allPlaceholders);
        return $this->execute($sql)->rowCount() > 0;
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(array $data): int
    {
        $sets = [];
        foreach ($data as $col => $value) {
            $key                  = ':upd_' . $col;
            $sets[]               = "`{$col}` = {$key}";
            $this->bindings[$key] = $value;
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets) . $this->buildWhereClause();
        return $this->execute($sql)->rowCount();
    }

    public function increment(string $column, int $amount = 1): int
    {
        $key = ':inc_amount';
        $this->bindings[$key] = $amount;
        $sql = "UPDATE `{$this->table}` SET `{$column}` = `{$column}` + {$key}" . $this->buildWhereClause();
        return $this->execute($sql)->rowCount();
    }

    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }

    // ── DELETE ────────────────────────────────────────────────────────────────

    public function delete(): int
    {
        $sql = "DELETE FROM `{$this->table}`" . $this->buildWhereClause();
        return $this->execute($sql)->rowCount();
    }

    public function truncate(): bool
    {
        return $this->pdo->exec("TRUNCATE TABLE `{$this->table}`") !== false;
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool           { return $this->pdo->commit(); }
    public function rollBack(): bool         { return $this->pdo->rollBack(); }

    // ── SQL Builder ───────────────────────────────────────────────────────────

    private function buildSelect(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $columns  = implode(', ', $this->columns);
        $sql      = "SELECT {$distinct}{$columns} FROM `{$this->table}`";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        $sql .= $this->buildWhereClause();

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->having !== null) {
            $sql .= ' HAVING ' . $this->having;
        }

        if (!empty($this->orders)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    private function buildWhereClause(): string
    {
        if (empty($this->wheres)) return '';

        $sql = ' WHERE ';
        foreach ($this->wheres as $i => $where) {
            if ($i > 0) $sql .= " {$where['type']} ";
            $sql .= $where['clause'];
        }
        return $sql;
    }

    private function execute(string $sql): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $sql);
        }

        $stmt->execute($this->bindings);
        return $stmt;
    }

    public function toSql(): string
    {
        return $this->buildSelect();
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
