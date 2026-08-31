<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 del plan de migracion: compara fila por fila una vista entre 'mysql' (original) y
 * 'pgsql' (reescrita), ordenando por la columna indicada. Nunca imprime valores de columnas -
 * mismo criterio de seguridad que db:sync-to-pgsql, porque varias de estas vistas exponen datos
 * de contacto/experiencia que podrian ser sensibles. Solo reporta IDs y nombres de columna.
 */
class DiffView extends Command
{
    protected $signature = 'db:diff-view {view} {--id-column=id}';

    protected $description = 'Compara fila por fila una vista entre mysql y pgsql (sin imprimir valores) - Fase 2';

    public function handle(): int
    {
        $view = $this->argument('view');
        $idColumn = $this->option('id-column');

        try {
            $mysqlRows = DB::connection('mysql')->table($view)->orderBy($idColumn)->get()
                ->map(fn ($r) => (array) $r)->keyBy($idColumn);
            $pgsqlRows = DB::connection('pgsql')->table($view)->orderBy($idColumn)->get()
                ->map(fn ($r) => (array) $r)->keyBy($idColumn);
        } catch (\Throwable $e) {
            $this->error('Error al consultar la vista (' . get_class($e) . ', SQLSTATE ' . ($e->getCode() ?: '?') . '). Sin datos impresos.');
            return 1;
        }

        $this->line("MySQL: {$mysqlRows->count()} filas | Postgres: {$pgsqlRows->count()} filas");

        $onlyMysql = $mysqlRows->keys()->diff($pgsqlRows->keys());
        $onlyPgsql = $pgsqlRows->keys()->diff($mysqlRows->keys());

        if ($onlyMysql->isNotEmpty()) {
            $this->error('IDs solo en MySQL: ' . $onlyMysql->implode(','));
        }
        if ($onlyPgsql->isNotEmpty()) {
            $this->error('IDs solo en Postgres: ' . $onlyPgsql->implode(','));
        }

        $mismatches = 0;
        $columnsWithDiffs = [];

        foreach ($mysqlRows as $id => $mysqlRow) {
            $pgsqlRow = $pgsqlRows->get($id);
            if ($pgsqlRow === null) {
                continue; // ya reportado arriba
            }

            $commonColumns = array_intersect(array_keys($mysqlRow), array_keys($pgsqlRow));
            $rowHasDiff = false;

            foreach ($commonColumns as $col) {
                if ($this->normalize($mysqlRow[$col]) !== $this->normalize($pgsqlRow[$col])) {
                    $columnsWithDiffs[$col] = ($columnsWithDiffs[$col] ?? 0) + 1;
                    $rowHasDiff = true;
                }
            }

            if ($rowHasDiff) {
                $mismatches++;
            }
        }

        if ($mismatches === 0 && $onlyMysql->isEmpty() && $onlyPgsql->isEmpty()) {
            $this->info("{$view}: identico, 0 diferencias.");
            return 0;
        }

        $this->error("{$view}: {$mismatches} filas con al menos 1 columna distinta.");
        foreach ($columnsWithDiffs as $col => $count) {
            $this->line("  columna \"{$col}\": difiere en {$count} filas");
        }

        return 1;
    }

    /**
     * Normaliza diferencias de representacion esperables entre motores (no son bugs reales):
     * null/"" tratados igual, booleanos 1/0 vs true/false/t/f, espacios al final.
     */
    private function normalize($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $s = trim((string) $value);
        if (in_array(strtolower($s), ['t', 'true'], true)) {
            return '1';
        }
        if (in_array(strtolower($s), ['f', 'false'], true)) {
            return '0';
        }
        return $s;
    }
}
