<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * PostgreSQL BIGINT[] <-> list<int> (TASK-0027). Same idea as PostgresTextArrayCast, for integers: only digits reach the
 * literal (anything else is dropped), so the cast cannot carry text or SQL into the array.
 */
class PgBigintArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return static::fromLiteral($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return static::toLiteral(is_array($value) ? $value : []);
    }

    public static function fromLiteral(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('intval', array_filter($value, 'is_numeric')));
        }
        $inner = trim((string) ($value ?? ''), '{}');
        if ($inner === '') {
            return [];
        }

        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $inner)), fn ($v) => preg_match('/^-?\d+$/', $v) === 1)));
    }

    public static function toLiteral(array $values): string
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($values, fn ($v) => is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1)))));

        return '{'.implode(',', $ids).'}';
    }
}
