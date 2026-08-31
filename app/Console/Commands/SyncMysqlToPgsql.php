<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 del plan de migracion a Postgres/Supabase: copia las tablas de negocio de 'mysql'
 * (produccion, solo lectura aca) hacia 'pgsql' (Supabase), o verifica que ya coincidan.
 *
 * Idempotente: se puede correr las veces que haga falta durante el desarrollo. Nunca escribe en
 * 'mysql', solo lee de ahi.
 */
class SyncMysqlToPgsql extends Command
{
    protected $signature = 'db:sync-to-pgsql
                            {--truncate : Vaciar las tablas destino en pgsql antes de copiar}
                            {--verify : Solo verificar conteos/checksums, no copia nada}
                            {--only= : Lista de tablas separadas por coma, para correr solo esas}';

    protected $description = 'Copia (o verifica) las tablas de negocio de mysql hacia pgsql (Supabase) - Fase 1';

    /**
     * Tablas de infraestructura de la app (no son datos de negocio a migrar), mas 2 tablas
     * huerfanas encontradas en mysql de produccion sin migracion ni modelo/referencia en el
     * codigo (`api_keys`, 4 filas; `category_entity`, 0 filas) - se excluyen porque no hay
     * esquema de destino para ellas en pgsql y la app no las usa. Documentado en docs/task.md
     * para decidir mas adelante si se investigan/eliminan de produccion (fuera de alcance aca).
     */
    private const EXCLUDED_TABLES = [
        'migrations', 'jobs', 'failed_jobs', 'personal_access_tokens', 'password_resets',
        'api_keys', 'category_entity',
    ];

    /** Tablas de negocio criticas sobre las que ademas se compara un checksum, no solo el conteo. */
    private const CHECKSUM_TABLES = [
        'empresas', 'assets', 'management', 'sustainabilities',
        'services', 'sectors', 'empresa_sector_service', 'empresa_module_status',
    ];

    /**
     * Diferencias de conteo ya explicadas y aceptadas (no bloquean --verify): 'contacts' tiene 5
     * filas en produccion con la columna 'name' vacia (dato invalido preexistente, sin migracion
     * ni codigo que las use) - se omiten a proposito al copiar, ver requiredColumnsWithoutDefault().
     */
    private const ACCEPTED_COUNT_DIFF = ['contacts' => 5];

    public function handle(): int
    {
        $tables = $this->resolveTables();

        if (empty($tables)) {
            $this->error('Ninguna tabla para procesar (revisar --only o la conexion mysql).');
            return 1;
        }

        try {
            if ($this->option('verify')) {
                return $this->verify($tables) ? 0 : 1;
            }

            return $this->sync($tables);
        } catch (\Throwable $e) {
            // Red de seguridad: nunca $e->getMessage() aca. En Postgres, los errores de
            // constraint (NOT NULL, unique) incluyen en el propio mensaje el contenido de la
            // fila que los violo (DETAIL: Failing row contains (...)) - podria ser informacion
            // personal real de un contacto/empresa. Solo se informa el tipo de error.
            $this->newLine();
            $this->error('Fallo inesperado (' . get_class($e) . ', SQLSTATE ' . ($e->getCode() ?: '?') . '). Mensaje omitido a proposito por si contiene datos reales - revisar en Tinker con cuidado si hace falta mas detalle.');
            return 1;
        }
    }

    private function resolveTables(): array
    {
        $rows = DB::connection('mysql')->select(
            "select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
            [DB::connection('mysql')->getDatabaseName()]
        );

        $names = array_map(fn ($r) => $r->table_name, $rows);
        $names = array_values(array_diff($names, self::EXCLUDED_TABLES));

        if ($only = $this->option('only')) {
            $onlyList = array_map('trim', explode(',', $only));
            $names = array_values(array_intersect($names, $onlyList));
        }

        sort($names);

        return $names;
    }

    private function sync(array $tables): int
    {
        if ($this->option('truncate')) {
            $quoted = implode(', ', array_map(fn ($t) => "\"{$t}\"", $tables));
            DB::connection('pgsql')->statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
            $this->info('Tablas destino truncadas: ' . implode(', ', $tables));
        }

        // Copiamos sin importar el orden de dependencias FK entre tablas: se desactivan los
        // triggers/constraints de sesion (tecnica estandar para carga masiva), y se restauran
        // siempre al final via el finally, incluso si algo falla a mitad de camino.
        DB::connection('pgsql')->statement("SET session_replication_role = 'replica'");

        try {
            foreach ($tables as $table) {
                $this->copyTable($table);
            }
        } finally {
            DB::connection('pgsql')->statement("SET session_replication_role = 'origin'");
        }

        $this->newLine();
        $this->info('Copia completa. Corre con --verify para confirmar conteos/checksums antes de dar la Fase 1 por cerrada.');

        return 0;
    }

    private function copyTable(string $table): void
    {
        $rows = DB::connection('mysql')->table($table)->get();
        $total = $rows->count();

        if ($total === 0) {
            $this->line("  {$table}: 0 filas, nada que copiar.");
            return;
        }

        $data = $rows->map(fn ($row) => (array) $row)->all();

        // Se filtran ANTES de insertar las filas que violarian una columna NOT NULL del esquema
        // pgsql (datos reales existentes en produccion, no un problema de la migracion) - nunca
        // se deja llegar una fila asi al INSERT, porque si Postgres la rechaza, su mensaje de
        // error incluye el contenido completo de la fila (DETAIL: Failing row contains (...)),
        // lo que expondria datos personales reales (nombre/telefono/email) si ese texto se
        // imprimiera en algun lado. Solo se reportan los IDs omitidos (nunca el contenido).
        $requiredColumns = $this->requiredColumnsWithoutDefault($table);
        $skippedIds = [];

        if (! empty($requiredColumns)) {
            $data = array_values(array_filter($data, function ($row) use ($requiredColumns, &$skippedIds) {
                foreach ($requiredColumns as $col) {
                    if (! array_key_exists($col, $row) || $row[$col] === null) {
                        $skippedIds[] = $row['id'] ?? '(sin id)';
                        return false;
                    }
                }
                return true;
            }));
        }

        $copied = 0;
        $failedIds = [];

        foreach (array_chunk($data, 500) as $chunk) {
            try {
                DB::connection('pgsql')->table($table)->insertOrIgnore($chunk);
                $copied += count($chunk);
            } catch (\Throwable $e) {
                // El lote completo fallo - se reintenta fila por fila para no perder el resto
                // del lote y para poder identificar (por ID, nunca por contenido) cuales filas
                // puntuales fallan. Nunca $e->getMessage() aca: en Postgres, un error de
                // constraint (NOT NULL, unique, largo de columna, etc.) incluye el contenido
                // completo de la fila que lo violo en el DETAIL.
                foreach ($chunk as $row) {
                    try {
                        DB::connection('pgsql')->table($table)->insertOrIgnore([$row]);
                        $copied++;
                    } catch (\Throwable $rowError) {
                        $failedIds[] = $row['id'] ?? '(sin id)';
                    }
                }
            }
        }

        $this->resetSequence($table);

        $notes = [];
        if (! empty($skippedIds)) {
            $notes[] = 'omitidas ' . count($skippedIds) . ' por columna obligatoria vacia en origen, ids: ' . implode(',', $skippedIds);
        }
        if (! empty($failedIds)) {
            $notes[] = 'fallaron ' . count($failedIds) . ' al insertar (revisar esquema/tipos), ids: ' . implode(',', $failedIds);
        }
        $note = empty($notes) ? '' : ' (' . implode('; ', $notes) . ')';

        $method = empty($failedIds) ? 'info' : 'error';
        $this->{$method}("  {$table}: {$copied}/{$total} filas copiadas.{$note}");
    }

    /**
     * Columnas NOT NULL sin default en el esquema pgsql (excluyendo la PK autoincremental y los
     * timestamps, que Laravel/Eloquent completa aparte). Copiar una fila de mysql con NULL en
     * alguna de estas violaria el esquema en Postgres.
     */
    private function requiredColumnsWithoutDefault(string $table): array
    {
        $rows = DB::connection('pgsql')->select(
            "select column_name from information_schema.columns
                where table_name = ? and is_nullable = 'NO' and column_default is null
                and column_name not in ('id', 'created_at', 'updated_at')",
            [$table]
        );

        return array_map(fn ($r) => $r->column_name, $rows);
    }

    /**
     * Tras insertar filas con IDs explicitos, la secuencia autoincremental de Postgres se queda
     * atras (sigue en 1) - el proximo insert sin ID explicito chocaria con un ID ya usado. Se
     * reacomoda al maximo real de la tabla. No falla si la tabla no tiene columna 'id' (pivotes).
     */
    private function resetSequence(string $table): void
    {
        if (! DB::connection('pgsql')->getSchemaBuilder()->hasColumn($table, 'id')) {
            return;
        }

        DB::connection('pgsql')->statement(
            "select setval(pg_get_serial_sequence(?, 'id'), coalesce((select max(id) from \"{$table}\"), 1), (select max(id) from \"{$table}\") is not null)",
            [$table]
        );
    }

    private function verify(array $tables): bool
    {
        $allOk = true;

        $rows = collect($tables)->map(function ($table) use (&$allOk) {
            $mysqlCount = DB::connection('mysql')->table($table)->count();
            $pgsqlCount = DB::connection('pgsql')->table($table)->count();
            $diff = $mysqlCount - $pgsqlCount;

            if ($diff === 0) {
                $estado = 'OK';
            } elseif (($accepted = self::ACCEPTED_COUNT_DIFF[$table] ?? null) === $diff) {
                $estado = 'OK (diferencia esperada, ver comentario en el codigo)';
            } else {
                $estado = 'DIFERENTE';
                $allOk = false;
            }

            return [$table, $mysqlCount, $pgsqlCount, $estado];
        })->all();

        $this->table(['Tabla', 'MySQL', 'Postgres', 'Estado'], $rows);

        $this->newLine();
        $this->line('Checksums (tablas de negocio criticas, booleanos normalizados entre motores):');

        foreach (self::CHECKSUM_TABLES as $table) {
            if (! in_array($table, $tables)) {
                continue;
            }

            $checksumOk = $this->compareChecksum($table);
            $this->line("  {$table}: " . ($checksumOk ? 'OK' : 'DIFERENTE'));

            if (! $checksumOk) {
                $allOk = false;
            }
        }

        if ($allOk) {
            $this->info('Todo coincide.');
        } else {
            $this->error('Hay diferencias - revisar arriba antes de dar la Fase 1 por cerrada.');
        }

        return $allOk;
    }

    private function compareChecksum(string $table): bool
    {
        $columns = DB::connection('mysql')->getSchemaBuilder()->getColumnListing($table);
        $columns = array_values(array_diff($columns, ['created_at', 'updated_at']));

        if (empty($columns) || ! in_array('id', $columns)) {
            return true; // sin PK 'id' no hay forma fiable de ordenar para comparar
        }

        // Columnas boolean en pgsql se normalizan a '1'/'0' de los dos lados (Postgres las
        // stringifica como 't'/'f' por defecto, MySQL como '1'/'0') para que el checksum no de
        // una diferencia falsa que no es un problema real de datos.
        $boolColumns = collect(DB::connection('pgsql')->select(
            "select column_name from information_schema.columns where table_name = ? and data_type = 'boolean'",
            [$table]
        ))->pluck('column_name')->all();

        $mysqlColsSql = implode(', ', array_map(
            fn ($c) => in_array($c, $boolColumns) ? "IF(`{$c}`, '1', '0')" : "`{$c}`",
            $columns
        ));
        $mysqlHash = DB::connection('mysql')->selectOne(
            "select md5(group_concat(concat_ws('|', {$mysqlColsSql}) order by id separator '||')) as hash from `{$table}`"
        )->hash ?? null;

        $pgsqlColsSql = implode(', ', array_map(
            fn ($c) => in_array($c, $boolColumns) ? "(CASE WHEN \"{$c}\" THEN '1' ELSE '0' END)" : "\"{$c}\"",
            $columns
        ));
        $pgsqlHash = DB::connection('pgsql')->selectOne(
            "select md5(string_agg(concat_ws('|', {$pgsqlColsSql}), '||' order by id)) as hash from \"{$table}\""
        )->hash ?? null;

        return $mysqlHash === $pgsqlHash;
    }
}
