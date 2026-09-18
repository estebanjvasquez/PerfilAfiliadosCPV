<?php

namespace App\Console\Commands;

use App\Models\LegacyService;
use App\Models\LegacyServiceCpvRelation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-3 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md secciones 4.7/4.8 y
 * el plan de esta fase): puebla `legacy_services` + `legacy_service_cpv_relations` +
 * `taxonomy_term_service_relations`, en ese orden (los 2 siguientes dependen del primero).
 *
 * `legacy_services` se llena del JOIN VIVO `services`+`sectors` de mysql (fuente de verdad real),
 * NO de `legacy_services[]` del JSON (que coincide 1:1 en id/nombre pero es una foto vieja) - el
 * `status` administrable nunca se pisa en una re-corrida.
 *
 * `legacy_service_cpv_relations` viene de `service_to_cpv_relations[].taxonomy_relations[]`, con el
 * mismo auto-triage por confianza que `taxonomy:import-term-cpv-relations` (TAXV2-2) - reusa los
 * mismos umbrales de `admin_defaults.scoring.relation_thresholds` del JSON.
 *
 * `taxonomy_term_service_relations` viene de `records[].legacy_service_relations[]` de cada
 * término - requiere que `taxonomy:import-term-dictionary` (TAXV2-1) ya haya corrido. `status` acá
 * se importa tal cual trae el JSON (`legacy_active`/etc.), sin auto-triage (ver docblock de la
 * migración: no es un workflow de revisión, es un snapshot del estado operativo del servicio).
 *
 * 100% aditivo sobre el catálogo legacy real: solo LEE `services`/`sectors` de mysql, nunca
 * escribe ahí - mismo criterio ya usado en `taxonomy:homologate-empresas`.
 */
class ImportLegacyServiceRelations extends Command
{
    protected $signature = 'taxonomy:import-legacy-service-relations
        {file=docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json : Ruta del JSON V2, relativa a la raiz del proyecto}
        {--chunk=500 : Filas por lote en los upserts}';

    protected $description = 'Puebla legacy_services + legacy_service_cpv_relations + taxonomy_term_service_relations desde el catálogo mysql real y el JSON V2 (TAXV2-3)';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $this->info('Leyendo JSON...');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $thresholds = $data['admin_defaults']['scoring']['relation_thresholds'] ?? [];
        $automaticAccept = (float) ($thresholds['automatic_accept'] ?? 0.9);
        $minimumWeight = (float) ($thresholds['minimum_relation_weight'] ?? 0.45);
        $chunkSize = (int) $this->option('chunk');
        $now = now();

        // --- Paso A: legacy_services desde el JOIN vivo services+sectors (mysql) ---
        $this->info('Paso A: legacy_services desde services+sectors (mysql)...');

        $services = DB::connection('mysql')->table('services')->select('id', 'sectors_id', 'name')->get();

        $serviceRows = $services->map(fn ($s) => [
            'id' => $s->id,
            'sectors_id' => $s->sectors_id,
            'name' => $s->name,
            'status' => LegacyService::STATUS_LEGACY_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($serviceRows, $chunkSize) as $chunk) {
            // No se actualiza `status` en el upsert - es la única columna administrable desde el
            // panel (TAXV2-7) y una re-corrida de este comando no debe pisar un cambio manual.
            DB::connection('pgsql')->table('legacy_services')->upsert(
                $chunk,
                ['id'],
                ['sectors_id', 'name', 'updated_at']
            );
        }

        $totalServices = DB::connection('pgsql')->table('legacy_services')->count();
        $this->info("  legacy_services: {$totalServices}.");

        // --- Paso B: legacy_service_cpv_relations desde service_to_cpv_relations[] ---
        $this->info('Paso B: legacy_service_cpv_relations desde service_to_cpv_relations[]...');

        $validServiceIds = DB::connection('pgsql')->table('legacy_services')->pluck('id')->flip();
        $categoryIdByCode = DB::connection('pgsql')->table('taxonomy_categories')->pluck('id', 'code');

        $relationRows = [];
        $orphanCodes = [];
        $countsB = ['approved' => 0, 'needs_review' => 0, 'deprecated' => 0];

        foreach ($data['service_to_cpv_relations'] ?? [] as $serviceBlock) {
            $serviceId = $serviceBlock['service_id'] ?? null;

            if (! $serviceId || ! $validServiceIds->has($serviceId)) {
                continue;
            }

            foreach ($serviceBlock['taxonomy_relations'] ?? [] as $relation) {
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
                    $confidence >= $automaticAccept => LegacyServiceCpvRelation::STATUS_APPROVED,
                    $weight >= $minimumWeight => LegacyServiceCpvRelation::STATUS_NEEDS_REVIEW,
                    default => LegacyServiceCpvRelation::STATUS_DEPRECATED,
                };
                $countsB[$status]++;

                $relationRows[] = [
                    'legacy_service_id' => $serviceId,
                    'cpv_code' => $cpvCode,
                    'category_id' => $categoryId,
                    'level' => $relation['level'] ?? 'category',
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

        foreach (array_chunk($relationRows, $chunkSize) as $chunk) {
            DB::connection('pgsql')->table('legacy_service_cpv_relations')->upsert(
                $chunk,
                ['legacy_service_id', 'cpv_code', 'relation_type', 'source'],
                ['category_id', 'level', 'weight', 'confidence', 'matched_on', 'status', 'updated_at']
            );
        }

        $totalRelationsB = DB::connection('pgsql')->table('legacy_service_cpv_relations')->count();
        $this->info("  legacy_service_cpv_relations: {$totalRelationsB} (approved: {$countsB['approved']}, needs_review: {$countsB['needs_review']}, deprecated: {$countsB['deprecated']}).");

        if (! empty($orphanCodes)) {
            $this->warn('  Códigos CPV sin categoría existente (category_id=NULL): '.implode(', ', array_keys($orphanCodes)));
        }

        // --- Paso C: taxonomy_term_service_relations desde records[].legacy_service_relations[] ---
        $this->info('Paso C: taxonomy_term_service_relations desde records[].legacy_service_relations[]...');

        $termIdByExternalId = DB::connection('pgsql')->table('taxonomy_terms')->pluck('id', 'external_id');

        if ($termIdByExternalId->isEmpty()) {
            $this->warn('  taxonomy_terms está vacío - correr primero taxonomy:import-term-dictionary. Se omite el Paso C.');

            return self::SUCCESS;
        }

        $termServiceRows = [];
        $skippedNoTerm = 0;

        foreach ($data['records'] ?? [] as $record) {
            $externalId = $record['id'] ?? null;
            $termId = $externalId ? $termIdByExternalId->get($externalId) : null;

            if (! $termId) {
                $skippedNoTerm++;

                continue;
            }

            foreach ($record['legacy_service_relations'] ?? [] as $relation) {
                $serviceId = $relation['service_id'] ?? null;

                if (! $serviceId || ! $validServiceIds->has($serviceId)) {
                    continue;
                }

                $termServiceRows[] = [
                    'term_id' => $termId,
                    'legacy_service_id' => $serviceId,
                    'relation_type' => $relation['relation_type'] ?? 'manual',
                    'weight' => (float) ($relation['weight'] ?? 0),
                    'confidence' => (float) ($relation['confidence'] ?? 0),
                    'status' => $relation['status'] ?? LegacyService::STATUS_LEGACY_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($termServiceRows, $chunkSize) as $chunk) {
            DB::connection('pgsql')->table('taxonomy_term_service_relations')->upsert(
                $chunk,
                ['term_id', 'legacy_service_id', 'relation_type'],
                ['weight', 'confidence', 'status', 'updated_at']
            );
        }

        $totalTermService = DB::connection('pgsql')->table('taxonomy_term_service_relations')->count();
        $this->info("  taxonomy_term_service_relations: {$totalTermService}.");

        if ($skippedNoTerm > 0) {
            $this->warn("  {$skippedNoTerm} record(s) sin taxonomy_terms correspondiente, omitidos en el Paso C.");
        }

        return self::SUCCESS;
    }
}
