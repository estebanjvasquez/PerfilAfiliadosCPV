<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-5.1 (ver docs/taxonomia/plan_mcp_cira.md): sincroniza `management` (MySQL producción,
 * módulo "Gestión" del perfil de afiliado) contra `empresa_certifications` (pgsql/Supabase), para
 * que `search_empresas` pueda responder preguntas tipo "empresas con ISO 9001" - hoy imposible
 * porque esta tabla nunca se replicó fuera de MySQL. Solo LEE de mysql, solo ESCRIBE en pgsql -
 * respeta la regla del proyecto de nunca escribir en la conexión mysql de producción.
 *
 * `otras_certificaciones` concatena las 6 columnas "_otros_name" (dentro de los JSON
 * quality_data/environment_data/credibility_data/security_data/pmi_data/info_data) en una sola
 * cadena separada por " | " - texto libre real (ej. "SISTEMA DE GESTIÓN AMBIENTAL PROPIO"), útil
 * para un ILIKE aunque no sea una ISO reconocida.
 */
class SyncEmpresaCertifications extends Command
{
    protected $signature = 'empresas:sync-certifications';

    protected $description = 'Sincroniza el módulo Gestión (management, MySQL) hacia empresa_certifications (pgsql)';

    private const OTROS_FIELDS = [
        'quality_data' => 'quality_otros_name',
        'environment_data' => 'environment_otros_name',
        'credibility_data' => 'credibility_otros_name',
        'security_data' => 'security_otros_name',
        'pmi_data' => 'pmi_otros_name',
        'info_data' => 'info_otros_name',
    ];

    public function handle(): int
    {
        $this->info('Leyendo management de MySQL...');

        $rows = DB::connection('mysql')->table('management')->get();

        $this->info("Total de filas: {$rows->count()}");

        $now = now();
        $upserts = $rows->map(function ($row) use ($now) {
            return [
                'empresa_id' => $row->empresa_id,
                'iso9001' => (bool) $row->iso9001,
                'iso14001' => (bool) $row->iso14001,
                'iso45001' => (bool) $row->iso45001,
                'iso27001' => (bool) $row->iso27001,
                'iso50001' => (bool) $row->iso50001,
                'iso17025' => (bool) $row->iso17025,
                'iso37001' => (bool) $row->iso37001,
                'dun' => (bool) $row->dun,
                'ovid' => (bool) $row->ovid,
                'pmi' => (bool) $row->pmi,
                'otras_certificaciones' => $this->buildOtrasCertificaciones($row) ?: null,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        })->all();

        DB::connection('pgsql')->table('empresa_certifications')->upsert(
            $upserts,
            ['empresa_id'],
            ['iso9001', 'iso14001', 'iso45001', 'iso27001', 'iso50001', 'iso17025', 'iso37001', 'dun', 'ovid', 'pmi', 'otras_certificaciones', 'updated_at']
        );

        $total = DB::connection('pgsql')->table('empresa_certifications')->count();
        $this->info("Listo. Total en empresa_certifications: {$total}.");

        return self::SUCCESS;
    }

    private function buildOtrasCertificaciones(object $row): string
    {
        $names = [];

        foreach (self::OTROS_FIELDS as $jsonColumn => $nameKey) {
            $decoded = json_decode($row->{$jsonColumn} ?? '', true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $entry) {
                $name = trim((string) ($entry[$nameKey] ?? ''));

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return implode(' | ', array_unique($names));
    }
}
