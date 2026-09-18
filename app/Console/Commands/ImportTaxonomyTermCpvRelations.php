<?php

namespace App\Console\Commands;

use App\Models\TaxonomyTermCpvRelation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-2: importa `records[].taxonomy_relations[]` del JSON V2 a `taxonomy_term_cpv_relations`.
 * Requiere que `taxonomy:import-term-dictionary` (TAXV2-1) ya haya cargado `taxonomy_terms` -
 * resuelve `term_id` por `external_id` (el `id` del JSON, ej. "og_00001").
 *
 * `cpv_code -> category_id`: resuelto contra `taxonomy_categories.code`. De los ~903 códigos
 * distintos del JSON, 2 no existen hoy (`CPV-48.01.13G`/`CPV-48.01.24G`, mismo problema de tipeo
 * del Grupo 48 ya documentado en acuerdos_pendientes_con_lorenzo.md) - la fila se guarda igual con
 * `category_id = NULL` y se reporta al final, nunca se descarta.
 *
 * Auto-triage por confianza (decisión del plan de esta fase, no pedido literal del documento V2):
 * usa los propios umbrales de `admin_defaults.scoring.relation_thresholds` del JSON
 * (`automatic_accept`/`minimum_relation_weight`) en vez de hardcodear 0.9/0.45 - si el JSON cambia
 * de calibración en una versión futura, el importador se ajusta solo.
 *
 * Upsert masivo por lote (`DB::table()->upsert()`, no fila por fila) - a diferencia del diccionario
 * base (TAXV2-1), acá no hay columnas array de Postgres que necesiten un bind tipado (`matched_on`
 * es texto plano), así que el patrón rápido de `taxonomy:import` (agrupar en memoria, upsert por
 * chunk) aplica directo.
 */
class ImportTaxonomyTermCpvRelations extends Command
{
    protected $signature = 'taxonomy:import-term-cpv-relations
        {file=docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json : Ruta del JSON V2, relativa a la raiz del proyecto}
        {--chunk=500 : Filas por lote en el upsert}';

    protected $description = 'Importa records[].taxonomy_relations[] del JSON V2 a taxonomy_term_cpv_relations, con auto-triage por confianza (TAXV2-2)';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $this->info('Leyendo JSON...');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $records = $data['records'] ?? [];
        $thresholds = $data['admin_defaults']['scoring']['relation_thresholds'] ?? [];

        $automaticAccept = (float) ($thresholds['automatic_accept'] ?? 0.9);
        $minimumWeight = (float) ($thresholds['minimum_relation_weight'] ?? 0.45);

        $this->info("Umbrales del JSON: automatic_accept={$automaticAccept}, minimum_relation_weight={$minimumWeight}");

        if (empty($records)) {
            $this->error('El JSON no trae records[].');

            return self::FAILURE;
        }

        $termIdByExternalId = DB::connection('pgsql')->table('taxonomy_terms')->pluck('id', 'external_id');

        if ($termIdByExternalId->isEmpty()) {
            $this->error('taxonomy_terms está vacío - correr primero taxonomy:import-term-dictionary.');

            return self::FAILURE;
        }

        $categoryIdByCode = DB::connection('pgsql')->table('taxonomy_categories')->pluck('id', 'code');

        $now = now();
        $rows = [];
        $orphanCodes = [];
        $counts = ['approved' => 0, 'needs_review' => 0, 'deprecated' => 0];
        $skippedNoTerm = 0;

        foreach ($records as $record) {
            $externalId = $record['id'] ?? null;
            $termId = $externalId ? $termIdByExternalId->get($externalId) : null;

            if (! $termId) {
                $skippedNoTerm++;

                continue;
            }

            foreach ($record['taxonomy_relations'] ?? [] as $relation) {
                $cpvCode = $relation['cpv_code'] ?? null;

                if (! $cpvCode) {
                    continue;
                }

                $categoryId = $categoryIdByCode->get($cpvCode);

                if (! $categoryId) {
                    $orphanCodes[$cpvCode] = true;
                }

                $weight = (float) ($relation['weight'] ?? 0);
                $confidence = (float) ($relation['confidence'] ?? 0);

                $status = match (true) {
                    $confidence >= $automaticAccept => TaxonomyTermCpvRelation::STATUS_APPROVED,
                    $weight >= $minimumWeight => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
                    default => TaxonomyTermCpvRelation::STATUS_DEPRECATED,
                };
                $counts[$status]++;

                $rows[] = [
                    'term_id' => $termId,
                    'cpv_code' => $cpvCode,
                    'category_id' => $categoryId,
                    'level' => $relation['level'] ?? TaxonomyTermCpvRelation::LEVEL_CATEGORY,
                    'relation_type' => $relation['relation_type'] ?? 'manual',
                    'weight' => $weight,
                    'confidence' => $confidence,
                    'matched_on' => $relation['matched_on'] ?? null,
                    'source' => $relation['source'] ?? 'manual',
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->info(count($rows).' relación(es) a importar...');

        $chunkSize = (int) $this->option('chunk');
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->upsert(
                $chunk,
                ['term_id', 'cpv_code', 'relation_type', 'source'],
                ['category_id', 'level', 'weight', 'confidence', 'matched_on', 'status', 'updated_at']
            );
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);

        if ($skippedNoTerm > 0) {
            $this->warn("{$skippedNoTerm} record(s) sin taxonomy_terms correspondiente, omitidos.");
        }

        if (! empty($orphanCodes)) {
            $this->warn(count($orphanCodes).' código(s) CPV sin categoría existente (guardados con category_id=NULL):');
            foreach (array_keys($orphanCodes) as $code) {
                $this->line("  - {$code}");
            }
        }

        $total = DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->count();
        $this->info("Listo. Total en taxonomy_term_cpv_relations: {$total}.");
        $this->info("Por status -> approved: {$counts['approved']}, needs_review: {$counts['needs_review']}, deprecated: {$counts['deprecated']}.");

        return self::SUCCESS;
    }
}
