<?php

declare(strict_types=1);

namespace Vexor\ORM;

use Vexor\Application;
use Vexor\Exceptions\ModelException;

/**
 * Vexor Base Model
 * 
 * ActiveRecord-style ORM model with:
 * - Automatic timestamps (created_at, updated_at)
 * - Mass assignment protection ($fillable / $guarded)
 * - Attribute casting
 * - Relationships (hasOne, hasMany, belongsTo, belongsToMany)
 * - Soft deletes
 * - Scopes
 * - Events (creating, created, updating, updated, deleting, deleted)
 * - Serialization control ($hidden)
 */
abstract class Model implements \JsonSerializable
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static bool $timestamps = true;
    protected static bool $softDeletes = false;

    protected array $fillable = [];
    protected array $guarded  = ['id'];
    protected array $hidden   = ['password', 'remember_token'];
    protected array $casts    = [];
    protected array $attributes = [];
    protected array $original  = [];
    protected bool $exists      = false;

    private static ?QueryBuilder $queryBuilder = null;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ── Static boot ───────────────────────────────────────────────────────────

    protected static function getTable(): string
    {
        if (!empty(static::$table)) return static::$table;

        $class = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . 's';
    }

    protected static function db(): QueryBuilder
    {
        if (static::$queryBuilder === null) {
            $app = Application::getInstance();
            static::$queryBuilder = $app->make(QueryBuilder::class);
        }

        return (clone static::$queryBuilder)->table(static::getTable());
    }

    // ── Attribute Handling ────────────────────────────────────────────────────

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded)) return false;
        if (empty($this->fillable)) return true;
        return in_array($key, $this->fillable);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->castAttribute($key, $value);
    }

    public function getAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        // Check for relationship method
        if (method_exists($this, $key)) {
            return $this->$key();
        }

        return null;
    }

    private function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key]) || $value === null) return $value;

        return match ($this->casts[$key]) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string'          => (string) $value,
            'array', 'json'   => is_string($value) ? json_decode($value, true) : $value,
            'datetime'        => new \DateTimeImmutable($value),
            default           => $value,
        };
    }

    public function __get(string $key): mixed   { return $this->getAttribute($key); }
    public function __set(string $key, mixed $value): void { $this->setAttribute($key, $value); }
    public function __isset(string $key): bool  { return isset($this->attributes[$key]); }

    public function toArray(): array
    {
        $data = $this->attributes;

        foreach ($this->casts as $key => $type) {
            if (isset($data[$key]) && in_array($type, ['array', 'json'])) {
                $data[$key] = is_string($data[$key]) ? json_decode($data[$key], true) : $data[$key];
            }
        }

        foreach ($this->hidden as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function save(): bool
    {
        if (static::$timestamps) {
            $now = date('Y-m-d H:i:s');
            if (!$this->exists) {
                $this->attributes['created_at'] ??= $now;
            }
            $this->attributes['updated_at'] = $now;
        }

        if ($this->exists) {
            $pk = static::$primaryKey;
            $data = $this->attributes;
            unset($data[$pk]);

            $affected = static::db()->where($pk, $this->attributes[$pk])->update($data);
            return $affected >= 0;
        } else {
            $id = static::db()->insertGetId($this->attributes);
            if ($id) {
                $this->attributes[static::$primaryKey] = $id;
                $this->exists = true;
                return true;
            }
            return false;
        }
    }

    public function delete(): bool
    {
        if (!$this->exists) return false;

        if (static::$softDeletes) {
            $this->attributes['deleted_at'] = date('Y-m-d H:i:s');
            return $this->save();
        }

        $affected = static::db()->where(static::$primaryKey, $this->attributes[static::$primaryKey])->delete();
        $this->exists = false;
        return $affected > 0;
    }

    public function restore(): bool
    {
        if (!static::$softDeletes) return false;
        $this->attributes['deleted_at'] = null;
        return $this->save();
    }

    // ── Static Finders ────────────────────────────────────────────────────────

    public static function find(mixed $id): ?static
    {
        $row = static::db()->find($id);
        return $row ? static::hydrate($row) : null;
    }

    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if (!$model) throw new ModelException(static::class . " [{$id}] not found.", 404);
        return $model;
    }

    public static function all(): array
    {
        return array_map(fn($row) => static::hydrate($row), static::db()->get());
    }

    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        $qb = static::db();
        return func_num_args() === 2
            ? $qb->where($column, $operatorOrValue)
            : $qb->where($column, $operatorOrValue, $value);
    }

    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    public static function updateOrCreate(array $conditions, array $values = []): static
    {
        $qb = static::db();
        foreach ($conditions as $col => $val) {
            $qb->where($col, $val);
        }

        $row = $qb->first();
        if ($row) {
            $model = static::hydrate($row);
            $model->fill($values);
            $model->save();
            return $model;
        }

        return static::create(array_merge($conditions, $values));
    }

    public static function findByCredential(string $value, string $field = 'email'): ?static
    {
        $row = static::db()->where($field, $value)->first();
        return $row ? static::hydrate($row) : null;
    }

    public static function findByEmail(string $email): ?static
    {
        return static::findByCredential($email, 'email');
    }

    public static function findByApiKey(string $hashedKey): ?static
    {
        $row = static::db()->where('api_key', $hashedKey)->first();
        return $row ? static::hydrate($row) : null;
    }

    // ── Hydration ────────────────────────────────────────────────────────────

    protected static function hydrate(array $attributes): static
    {
        $model          = new static();
        $model->attributes = $attributes;
        $model->original  = $attributes;
        $model->exists    = true;
        return $model;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    protected function hasOne(string $related, string $foreignKey = null, string $localKey = 'id'): ?Model
    {
        $foreignKey ??= $this->guessForeignKey();
        $row = $related::db()->where($foreignKey, $this->attributes[$localKey])->first();
        return $row ? $related::hydrate($row) : null;
    }

    protected function hasMany(string $related, string $foreignKey = null, string $localKey = 'id'): array
    {
        $foreignKey ??= $this->guessForeignKey();
        $rows = $related::db()->where($foreignKey, $this->attributes[$localKey])->get();
        return array_map(fn($row) => $related::hydrate($row), $rows);
    }

    protected function belongsTo(string $related, string $foreignKey = null, string $ownerKey = 'id'): ?Model
    {
        $foreignKey ??= strtolower((new \ReflectionClass($related))->getShortName()) . '_id';
        $row = $related::db()->where($ownerKey, $this->attributes[$foreignKey])->first();
        return $row ? $related::hydrate($row) : null;
    }

    protected function belongsToMany(string $related, string $pivotTable, string $foreignKey, string $relatedKey): array
    {
        $localKey  = static::$primaryKey;
        $localId   = $this->attributes[$localKey];

        $rows = static::db()
            ->table($related::getTable())
            ->select("{$related::getTable()}.*")
            ->join($pivotTable, "{$pivotTable}.{$relatedKey}", '=', "{$related::getTable()}.{$related::$primaryKey}")
            ->where("{$pivotTable}.{$foreignKey}", $localId)
            ->get();

        return array_map(fn($row) => $related::hydrate($row), $rows);
    }

    private function guessForeignKey(): string
    {
        $class = (new \ReflectionClass(static::class))->getShortName();
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class)) . '_id';
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public static function query(): QueryBuilder
    {
        return static::db();
    }

    // ── Dirty Tracking ────────────────────────────────────────────────────────

    public function isDirty(string $key = null): bool
    {
        if ($key) {
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }
        return $this->attributes !== $this->original;
    }

    public function getDirty(): array
    {
        return array_diff_assoc($this->attributes, $this->original);
    }

    public function wasChanged(string $key = null): bool
    {
        return $this->isDirty($key);
    }
}
