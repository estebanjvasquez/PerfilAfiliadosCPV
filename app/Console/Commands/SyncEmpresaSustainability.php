<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-5.2 (ver docs/taxonomia/plan_mcp_cira.md): sincroniza `sustainabilities` (MySQL
 * producción, módulo "Sostenibilidad" del perfil de afiliado, pivote empresa<->área) contra
 * `empresa_sustainability_areas` (pgsql/Supabase) - `sustainability_areas` (el catálogo de 8 áreas)
 * ya se sembró una sola vez en su propia migración porque es un catálogo cerrado que no cambia.
 * Solo LEE de mysql, solo ESCRIBE en pgsql.
 */
class SyncEmpresaSustainability extends Command
{
    protected $signature = 'empresas:sync-sustainability';

    protected $description = 'Sincroniza el módulo Sostenibilidad (sustainabilities, MySQL) hacia empresa_sustainability_areas (pgsql)';

    public function handle(): int
    {
        $this->info('Leyendo sustainabilities de MySQL...');

        $rows = DB::connection('mysql')->table('sustainabilities')
            ->select('empresa_id', 'areas_id')
            ->whereNotNull('areas_id')
            ->distinct()
            ->get();

        $this->info("Total de pares empresa/área: {$rows->count()}");

        $now = now();
        $upserts = $rows->map(fn ($row) => [
            'empresa_id' => $row->empresa_id,
            'area_id' => $row->areas_id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::connection('pgsql')->table('empresa_sustainability_areas')->upsert(
            $upserts,
            ['empresa_id', 'area_id'],
            ['updated_at']
        );

        $total = DB::connection('pgsql')->table('empresa_sustainability_areas')->count();
        $this->info("Listo. Total en empresa_sustainability_areas: {$total}.");

        return self::SUCCESS;
    }
}
