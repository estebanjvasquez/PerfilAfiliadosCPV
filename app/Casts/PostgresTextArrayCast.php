<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Cast para columnas `text[]` nativas de Postgres (TAXV2-1: `taxonomy_terms.region`/
 * `negative_context`/`positive_context`) - el cast `array` por defecto de Eloquent serializa a
 * JSON, que Postgres no acepta para una columna `text[]` (tipos distintos). PDO recibe/envía el
 * array como su representación literal de texto (`{"a","b"}`), que Postgres parsea/genera nativo
 * para columnas de tipo array - no hace falta una extensión ni un paquete aparte para esto.
 */
class PostgresTextArrayCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return static::fromLiteral($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return static::toLiteral(is_array($value) ? $value : []);
    }

    /** Reusado por los comandos de importación que escriben con DB::statement crudo (sin pasar por Eloquent). */
    public static function fromLiteral(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $trimmed = trim($value, '{}');

        if ($trimmed === '') {
            return [];
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $trimmed, $matches, PREG_SET_ORDER);

        return array_map(function ($match) {
            $raw = $match[1] !== '' ? $match[1] : $match[2];

            return stripslashes($raw);
        }, $matches);
    }

    public static function toLiteral(array $values): string
    {
        $escaped = array_map(
            fn ($item) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $item).'"',
            $values
        );

        return '{'.implode(',', $escaped).'}';
    }
}
