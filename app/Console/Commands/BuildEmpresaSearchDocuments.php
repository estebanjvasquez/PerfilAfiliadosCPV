<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-7 (Fase A, ver docs/taxonomia/plan_mcp_cira.md): arma el documento unificado de
 * búsqueda por empresa (`empresa_search_documents`) - concatena TODO lo que hoy vive repartido en
 * 6 niveles de `search_empresas` (nombre, sector, servicios, taxonomía CPV, certificaciones,
 * sostenibilidad, experiencias) en un solo texto por empresa, y calcula su `tsvector` (full-text
 * nativo de Postgres, stemming real en español vía `to_tsvector('spanish', ...)`) para la nueva
 * lista de fusión RRF. Solo LEE de pgsql (todo el dato fuente ya vive ahí desde Fase MCP-5) y solo
 * ESCRIBE en pgsql - no toca mysql.
 *
 * Deliberadamente NO incremental: recalcula todo en cada corrida vía upsert (mismo criterio que
 * `empresas:sync-experiencias` - volumen chico, sin panel propio que dispare cambios frecuentes, no
 * se justifica un diff idempotente más complejo).
 *
 * NO incluye ciudad/estado/país ni RIF en el documento - "ciudad" sigue siendo un filtro
 * estructurado aparte en `search_empresas` (no texto libre a buscar), y el RIF ya lo resuelve
 * `get_empresa` directamente.
 *
 * **TAXV3-7**: hasta esta fase, el bloque de taxonomía de este documento solo sumaba
 * `taxonomy_category_synonyms` (3 filas en toda la base) - el diccionario de 1.889 términos
 * (`taxonomy_terms`, con alias y jerga regional venezolana) nunca llegaba al texto que indexa el
 * buscador híbrido de empresas, el mismo hueco que tenía `search_taxonomy` en el Worker MCP
 * (corregido ahí en esta misma fase). Se agrega `dictionaryTermsPorCategoria`: por cada categoría
 * CPV que la empresa ya tiene asignada, suma los términos/alias con relación `approved` a esa
 * categoría, más los heredados por concepto canónico (mismo criterio y mismo query que
 * `taxonomy-tools.ts::search_taxonomy` - ver ese docblock para el razonamiento completo de la
 * herencia). Nunca usa el backlog `needs_review` ni candidatos de baja confianza del Auto Mapper.
 */
class BuildEmpresaSearchDocuments extends Command
{
    protected $signature = 'empresas:build-search-documents';

    protected $description = 'Arma el documento unificado por empresa (nombre+sector+servicios+taxonomía+certificaciones+sostenibilidad+experiencias) para el buscador híbrido de Fase MCP-7';

    private const CERTIFICATION_LABELS = [
        'iso9001' => 'ISO 9001',
        'iso14001' => 'ISO 14001',
        'iso45001' => 'ISO 45001',
        'iso27001' => 'ISO 27001',
        'iso50001' => 'ISO 50001',
        'iso17025' => 'ISO 17025',
        'iso37001' => 'ISO 37001',
        'dun' => 'DUN',
        'ovid' => 'OVID',
        'pmi' => 'PMI',
    ];

    // Mismas familias CPV "atractoras" excluidas del nivel semántico de taxonomía en el Worker
    // (ver GENERIC_ATTRACTOR_FAMILY_CODES en empresa-tools.ts, Fase MCP-4.x/4.6) - se excluyen acá
    // también para no propagar el mismo ruido genérico al documento unificado.
    private const GENERIC_ATTRACTOR_FAMILY_CODES = ['CPV-29.02', 'CPV-12.04', 'CPV-48.02'];

    public function handle(): int
    {
        $this->info('Leyendo datos base de pgsql...');

        $empresas = DB::connection('pgsql')->table('empresas')->where('status_id', 1)->get(['id', 'name']);

        $sectoresPorEmpresa = DB::connection('pgsql')->table('empresa_sector_service as ess')
            ->join('services as sv', 'sv.id', '=', 'ess.service_id')
            ->join('sectors as s', 's.id', '=', 'sv.sectors_id')
            ->select('ess.empresa_id', 's.name')
            ->distinct()
            ->get()
            ->groupBy('empresa_id');

        $serviciosPorEmpresa = DB::connection('pgsql')->table('empresa_sector_service as ess')
            ->join('services as sv', 'sv.id', '=', 'ess.service_id')
            ->select('ess.empresa_id', 'sv.name')
            ->get()
            ->groupBy('empresa_id');

        $certificacionesPorEmpresa = DB::connection('pgsql')->table('empresa_certifications')->get()->keyBy('empresa_id');

        $sostenibilidadPorEmpresa = DB::connection('pgsql')->table('empresa_sustainability_areas as esa')
            ->join('sustainability_areas as sa', 'sa.id', '=', 'esa.area_id')
            ->select('esa.empresa_id', 'sa.name', 'sa.synonyms')
            ->get()
            ->groupBy('empresa_id');

        $taxonomiaPorEmpresa = DB::connection('pgsql')->table('empresa_taxonomy_category as etc')
            ->join('taxonomy_categories as tc', 'tc.id', '=', 'etc.category_id')
            ->leftJoin('taxonomy_category_translations as tt', function ($join) {
                $join->on('tt.category_id', '=', 'tc.id')->where('tt.locale', '=', 'es');
            })
            ->leftJoin('taxonomy_category_translations as tt_en', function ($join) {
                $join->on('tt_en.category_id', '=', 'tc.id')->where('tt_en.locale', '=', 'en');
            })
            ->leftJoin('taxonomy_category_synonyms as syn', 'syn.category_id', '=', 'tc.id')
            ->whereNotIn('tc.code', self::GENERIC_ATTRACTOR_FAMILY_CODES)
            ->selectRaw('etc.empresa_id, tc.code, coalesce(tt.name, tc.code) as name_es, tt_en.name as name_en, syn.term')
            ->get()
            ->groupBy('empresa_id');

        $dictionaryTermsPorCategoria = collect(DB::connection('pgsql')->select(<<<'SQL'
            select distinct tc.code, dm.matched_term
            from (
                select r.category_id, t.term as matched_term
                from taxonomy_term_cpv_relations r
                join taxonomy_terms t on t.id = r.term_id
                where r.status = 'approved'
                union
                select r.category_id, a.alias as matched_term
                from taxonomy_term_cpv_relations r
                join taxonomy_term_aliases a on a.term_id = r.term_id
                where r.status = 'approved'
                union
                select r.category_id, t.term as matched_term
                from taxonomy_terms t
                join taxonomy_term_concepts link on link.term_id = t.id
                join taxonomy_term_concepts sib_link on sib_link.concept_id = link.concept_id and sib_link.term_id != t.id
                join taxonomy_term_cpv_relations r on r.term_id = sib_link.term_id and r.status = 'approved'
                where not exists (
                    select 1 from taxonomy_term_cpv_relations r2
                    where r2.term_id = t.id and r2.status = 'approved'
                )
            ) dm
            join taxonomy_categories tc on tc.id = dm.category_id
        SQL))
            ->groupBy('code')
            ->map(fn ($rows) => $rows->pluck('matched_term')->unique()->implode(', '));

        $experienciasPorEmpresa = DB::connection('pgsql')->table('empresa_experiencias')
            ->select('empresa_id', 'descripcion')
            ->get()
            ->groupBy('empresa_id');

        $this->info("Armando documentos para {$empresas->count()} empresas activas...");

        $now = now();
        $bar = $this->output->createProgressBar($empresas->count());
        $bar->start();

        foreach ($empresas as $empresa) {
            $parts = [$empresa->name];

            if ($sectoresPorEmpresa->has($empresa->id)) {
                $parts[] = $sectoresPorEmpresa[$empresa->id]->pluck('name')->unique()->implode(', ');
            }

            if ($serviciosPorEmpresa->has($empresa->id)) {
                $parts[] = $serviciosPorEmpresa[$empresa->id]->pluck('name')->unique()->implode(', ');
            }

            if ($cert = $certificacionesPorEmpresa->get($empresa->id)) {
                $labels = [];
                foreach (self::CERTIFICATION_LABELS as $column => $label) {
                    if ($cert->$column) {
                        $labels[] = $label;
                    }
                }
                if ($labels) {
                    $parts[] = implode(', ', $labels);
                }
                if (! empty($cert->otras_certificaciones)) {
                    $parts[] = $cert->otras_certificaciones;
                }
            }

            if ($sostenibilidadPorEmpresa->has($empresa->id)) {
                foreach ($sostenibilidadPorEmpresa[$empresa->id] as $area) {
                    $parts[] = $area->name;
                    if (! empty($area->synonyms)) {
                        $parts[] = $area->synonyms;
                    }
                }
            }

            if ($taxonomiaPorEmpresa->has($empresa->id)) {
                foreach ($taxonomiaPorEmpresa[$empresa->id] as $cat) {
                    $parts[] = $cat->name_es;
                    if (! empty($cat->name_en)) {
                        $parts[] = $cat->name_en;
                    }
                    if (! empty($cat->term)) {
                        $parts[] = $cat->term;
                    }
                    if ($dictionaryTermsPorCategoria->has($cat->code)) {
                        $parts[] = $dictionaryTermsPorCategoria[$cat->code];
                    }
                }
            }

            if ($experienciasPorEmpresa->has($empresa->id)) {
                foreach ($experienciasPorEmpresa[$empresa->id] as $exp) {
                    $parts[] = $exp->descripcion;
                }
            }

            $document = implode(' ', array_filter($parts, fn ($p) => trim((string) $p) !== ''));

            DB::connection('pgsql')->statement(
                <<<'SQL'
                    INSERT INTO empresa_search_documents (empresa_id, document, search_vector, updated_at)
                    VALUES (?, ?, to_tsvector('spanish', unaccent(?)), ?)
                    ON CONFLICT (empresa_id) DO UPDATE SET
                        document = excluded.document,
                        search_vector = excluded.search_vector,
                        updated_at = excluded.updated_at
                SQL,
                [$empresa->id, $document, $document, $now]
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $total = DB::connection('pgsql')->table('empresa_search_documents')->count();
        $this->info("Listo. Total de documentos en empresa_search_documents: {$total}.");

        return self::SUCCESS;
    }
}
