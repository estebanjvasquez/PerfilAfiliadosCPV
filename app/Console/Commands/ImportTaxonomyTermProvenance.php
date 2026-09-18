<?php

namespace App\Console\Commands;

use App\Casts\PostgresTextArrayCast;
use App\Models\TaxonomySource;
use App\Models\TaxonomyTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * V2->V3 (ver docs/taxonomia/MIGRACION_TAXONOMIA_CPV_V2_A_V3.md y
 * docs/taxonomia/oil_gas_master_taxonomy_v3_source_driven.json): corrige el bug real reportado por
 * el usuario - los 1.837 términos importados en TAXV2-1 mostraban "Curado manualmente" sin
 * excepción, porque `ImportTaxonomyTermDictionary::primarySourceId()` tomaba el primer elemento de
 * `sources[]` que ya existiera en `taxonomy_sources`, y ese array del JSON V2 listaba `CURATED`
 * primero casi siempre (incluso para términos con una fuente real como SLB/OSHA/IADC).
 *
 * El JSON V3 separa correctamente `provenance.source_bindings[]` (verificados, 117 en total sobre
 * 113 términos) de `provenance.candidate_sources[]` (sugerencias SIN verificar heredadas de esa
 * misma generación V2 - nunca se muestran como si fueran la fuente real, regla explícita del
 * documento de migración).
 *
 * Import DELIBERADAMENTE ACOTADO (ver plan de esta fase - no es el rediseño completo de V3):
 * - Para los 1.837 términos YA existentes (`external_id` coincide): actualiza SOLO las 4 columnas
 *   de procedencia (`origin_type`/`display_source`/`primary_source_id`/`candidate_sources`) y sus
 *   `source_bindings` - nunca toca `term`/`canonical_term`/`relevance_weight`/etc. para no perder
 *   ediciones manuales hechas desde el panel después de TAXV2-1 (mismo criterio ya usado en
 *   `ImportLegacyServiceRelations` para no pisar `status`). Verificado contra el JSON real: ninguno
 *   de los 1.837 términos originales cambió su `taxonomy_relations` en V3 - no hace falta reimportar
 *   relaciones CPV en esta fase.
 * - Para los 52 términos NUEVOS de V3 (`src_osha_*` etc., sin `taxonomy_relations` todavía - quedan
 *   `source_verified_needs_cpv_mapping`): inserta la fila completa (mismo shape que
 *   `ImportTaxonomyTermDictionary`) más su procedencia y bindings. No importa relaciones CPV para
 *   estos porque el JSON no trae ninguna todavía (awaiting mapping, como su propio
 *   `mapping_review_status` indica).
 *
 * Fuera de alcance a propósito (ver plan): full discovery del catálogo SLB/OSHA/IADC completo,
 * canonical concepts, dedupe difuso/embeddings, cola de excepciones dedicada - quedan para una fase
 * aparte, más grande.
 */
class ImportTaxonomyTermProvenance extends Command
{
    protected $signature = 'taxonomy:import-term-provenance
        {file=docs/taxonomia/oil_gas_master_taxonomy_v3_source_driven.json : Ruta del JSON V3, relativa a la raiz del proyecto}';

    protected $description = 'Corrige la procedencia de los términos (origin_type/display_source/bindings) desde el JSON V3 - no toca relaciones CPV existentes.';

    private const UPDATE_PROVENANCE_SQL = <<<'SQL'
        UPDATE taxonomy_terms SET
            origin_type = ?,
            display_source = ?,
            primary_source_id = ?,
            candidate_sources = ?::jsonb,
            updated_at = ?
        WHERE external_id = ?
        SQL;

    private const INSERT_NEW_TERM_SQL = <<<'SQL'
        INSERT INTO taxonomy_terms (
            external_id, term, language, canonical_term, term_category, term_subcategory,
            region, term_type, relevance_weight, oil_gas_exclusivity, context_required,
            minimum_supporting_terms, context_window_words, ambiguity_penalty,
            negative_context, positive_context, source_id, mapping_review_status,
            origin_type, display_source, primary_source_id, candidate_sources,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?, ?, ?, ?, ?, ?::text[], ?::text[], ?, ?,
            ?, ?, ?, ?::jsonb, ?, ?
        )
        ON CONFLICT (external_id) DO NOTHING
        RETURNING id
        SQL;

    private const UPSERT_BINDING_SQL = <<<'SQL'
        INSERT INTO taxonomy_term_source_bindings (
            term_id, source_id, source_term, source_url, language, binding_type,
            verification_status, sync_enabled, first_seen_at, last_seen_at, last_verified_at,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON CONFLICT (term_id, source_id) DO UPDATE SET
            source_term = excluded.source_term,
            source_url = excluded.source_url,
            language = excluded.language,
            binding_type = excluded.binding_type,
            verification_status = excluded.verification_status,
            sync_enabled = excluded.sync_enabled,
            first_seen_at = COALESCE(taxonomy_term_source_bindings.first_seen_at, excluded.first_seen_at),
            last_seen_at = excluded.last_seen_at,
            last_verified_at = excluded.last_verified_at,
            updated_at = excluded.updated_at
        SQL;

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $this->info('Leyendo JSON V3...');
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $records = $data['records'] ?? [];

        if (empty($records)) {
            $this->error('El JSON no trae records[].');

            return self::FAILURE;
        }

        $validSourceIds = TaxonomySource::query()->pluck('source_id')->all();
        if (empty($validSourceIds)) {
            $this->error('taxonomy_sources está vacío - correr primero taxonomy:seed-sources.');

            return self::FAILURE;
        }

        $existingIds = DB::connection('pgsql')->table('taxonomy_terms')->pluck('id', 'external_id');

        $now = now();
        $updated = 0;
        $created = 0;
        $bindingsWritten = 0;
        $skippedBindings = 0;

        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        foreach ($records as $record) {
            $externalId = $record['id'] ?? null;
            if (! $externalId) {
                $bar->advance();

                continue;
            }

            $provenance = $record['provenance'] ?? [];
            $candidateSourcesJson = json_encode($provenance['candidate_sources'] ?? []);
            $primarySourceId = $provenance['primary_source_id'] ?? null;
            if ($primarySourceId && ! in_array($primarySourceId, $validSourceIds, true)) {
                $primarySourceId = null;
            }

            $termId = $existingIds[$externalId] ?? null;

            if ($termId) {
                DB::connection('pgsql')->update(self::UPDATE_PROVENANCE_SQL, [
                    $provenance['origin_type'] ?? null,
                    $provenance['display_source'] ?? null,
                    $primarySourceId,
                    $candidateSourcesJson,
                    $now,
                    $externalId,
                ]);
                $updated++;
            } else {
                $properties = $record['term_properties'] ?? [];
                $sourceId = $primarySourceId ?? (in_array(TaxonomySource::CURATED, $validSourceIds, true) ? TaxonomySource::CURATED : null);

                $result = DB::connection('pgsql')->selectOne(self::INSERT_NEW_TERM_SQL, [
                    $externalId,
                    $record['term'] ?? '',
                    $record['language'] ?? 'en',
                    $record['canonical_term'] ?? ($record['term'] ?? ''),
                    $record['category'] ?? null,
                    $record['subcategory'] ?? null,
                    PostgresTextArrayCast::toLiteral($record['region'] ?? []),
                    $record['term_type'] ?? 'technical_term',
                    $properties['relevance_weight'] ?? $record['relevance_weight'] ?? 0,
                    $properties['oil_gas_exclusivity'] ?? $record['oil_gas_exclusivity'] ?? 0,
                    (bool) ($properties['context_required'] ?? false),
                    $properties['minimum_supporting_terms'] ?? 0,
                    $properties['context_window_words'] ?? 80,
                    $properties['ambiguity_penalty'] ?? 0,
                    PostgresTextArrayCast::toLiteral($record['negative_context'] ?? []),
                    PostgresTextArrayCast::toLiteral([]),
                    $sourceId,
                    $this->normalizeMappingReviewStatus($record['mapping_review_status'] ?? null),
                    $provenance['origin_type'] ?? null,
                    $provenance['display_source'] ?? null,
                    $primarySourceId,
                    $candidateSourcesJson,
                    $now,
                    $now,
                ]);

                if ($result) {
                    $termId = $result->id;
                    $created++;
                    $this->syncAliases((int) $termId, $record['aliases'] ?? []);
                }
            }

            if ($termId) {
                foreach (($provenance['source_bindings'] ?? []) as $binding) {
                    $bindingSourceId = $binding['source_id'] ?? null;
                    if (! $bindingSourceId || ! in_array($bindingSourceId, $validSourceIds, true)) {
                        $skippedBindings++;

                        continue;
                    }

                    $lastVerified = $binding['last_verified'] ?? null;

                    DB::connection('pgsql')->update(self::UPSERT_BINDING_SQL, [
                        $termId,
                        $bindingSourceId,
                        $binding['source_term'] ?? null,
                        $binding['source_url'] ?? null,
                        $binding['language'] ?? $record['language'] ?? null,
                        $binding['binding_type'] ?? null,
                        $binding['verification_status'] ?? 'pending_verification',
                        (bool) ($binding['sync_enabled'] ?? false),
                        $now,
                        $now,
                        $lastVerified ? \Carbon\Carbon::parse($lastVerified) : null,
                        $now,
                        $now,
                    ]);
                    $bindingsWritten++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($skippedBindings > 0) {
            $this->warn("{$skippedBindings} binding(s) omitido(s) por source_id desconocido.");
        }

        $totalTerms = DB::connection('pgsql')->table('taxonomy_terms')->count();
        $totalBindings = DB::connection('pgsql')->table('taxonomy_term_source_bindings')->count();

        $this->info("Listo. Términos actualizados (procedencia): {$updated}. Términos nuevos creados: {$created}. Bindings escritos: {$bindingsWritten}.");
        $this->info("Totales: taxonomy_terms={$totalTerms}, taxonomy_term_source_bindings={$totalBindings}.");

        return self::SUCCESS;
    }

    /**
     * TAXV3-1: el JSON V3 trae `source_verified_needs_cpv_mapping` para los términos nuevos sin
     * ninguna relación CPV todavía - se normaliza al eje limpio de 4 valores desde el import mismo
     * (no solo vía la migración de limpieza, que fue un fix de una sola vez para los datos ya
     * cargados).
     */
    private function normalizeMappingReviewStatus(?string $rawStatus): string
    {
        return match ($rawStatus) {
            'source_verified_needs_cpv_mapping' => TaxonomyTerm::MAPPING_NEEDS_REVIEW,
            TaxonomyTerm::MAPPING_AUTO_MAPPED, TaxonomyTerm::MAPPING_NEEDS_REVIEW => $rawStatus,
            default => TaxonomyTerm::MAPPING_UNMAPPED,
        };
    }

    private function syncAliases(int $termId, array $aliases): void
    {
        $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases))));
        if (empty($aliases)) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($alias) => [
            'term_id' => $termId,
            'alias' => $alias,
            'created_at' => $now,
            'updated_at' => $now,
        ], $aliases);

        DB::connection('pgsql')->table('taxonomy_term_aliases')->insert($rows);
    }
}
