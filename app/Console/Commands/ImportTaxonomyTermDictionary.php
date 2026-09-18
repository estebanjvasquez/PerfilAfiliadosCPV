<?php

namespace App\Console\Commands;

use App\Casts\PostgresTextArrayCast;
use App\Models\TaxonomySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-1: importa `records[]` del diccionario de términos V2
 * (docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json) a `taxonomy_terms` + `taxonomy_term_aliases`.
 * Solo el diccionario base en esta fase - las relaciones término->CPV (`taxonomy_relations[]`) y
 * término->servicio (`legacy_service_relations[]`) de cada record se importan en TAXV2-2/TAXV2-3
 * (tablas que todavía no existen en esta fase).
 *
 * Upsert por `external_id` (`og_00001`, etc.) vía `INSERT ... ON CONFLICT ... RETURNING id` - mismo
 * criterio de idempotencia que `taxonomy:import` (correr 2 veces con el mismo JSON no duplica, solo
 * actualiza). Un `INSERT` por término (no upsert masivo por lote como `taxonomy:import`): acá cada
 * fila necesita bindings tipados (`::text[]`) para los arrays nativos de Postgres, que el helper
 * `DB::table()->upsert()` no arma - 1.837 round-trips es aceptable a esta escala (mismo criterio ya
 * usado en `taxonomy:homologate-services`, que hace 112 queries en loop).
 *
 * La fuente principal de un término (`source_id`) es la primera de `sources[]` que ya exista en
 * `taxonomy_sources` (correr primero `taxonomy:seed-sources`) - un término puede traer varias
 * fuentes en el JSON (a veces repetidas), acá solo se guarda 1 para trazabilidad simple; no se
 * modela como tabla pivote (no está en la lista de tablas recomendadas de la sección 11).
 */
class ImportTaxonomyTermDictionary extends Command
{
    protected $signature = 'taxonomy:import-term-dictionary
        {file=docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json : Ruta del JSON V2, relativa a la raiz del proyecto}';

    protected $description = 'Importa records[] del diccionario de términos V2 a taxonomy_terms + taxonomy_term_aliases (TAXV2-1)';

    private const UPSERT_SQL = <<<'SQL'
        INSERT INTO taxonomy_terms (
            external_id, term, language, canonical_term, term_category, term_subcategory,
            region, term_type, relevance_weight, oil_gas_exclusivity, context_required,
            minimum_supporting_terms, context_window_words, ambiguity_penalty,
            negative_context, positive_context, source_id, mapping_review_status,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?, ?, ?, ?, ?, ?::text[], ?::text[], ?, ?, ?, ?
        )
        ON CONFLICT (external_id) DO UPDATE SET
            term = excluded.term,
            language = excluded.language,
            canonical_term = excluded.canonical_term,
            term_category = excluded.term_category,
            term_subcategory = excluded.term_subcategory,
            region = excluded.region,
            term_type = excluded.term_type,
            relevance_weight = excluded.relevance_weight,
            oil_gas_exclusivity = excluded.oil_gas_exclusivity,
            context_required = excluded.context_required,
            minimum_supporting_terms = excluded.minimum_supporting_terms,
            context_window_words = excluded.context_window_words,
            ambiguity_penalty = excluded.ambiguity_penalty,
            negative_context = excluded.negative_context,
            positive_context = excluded.positive_context,
            source_id = excluded.source_id,
            mapping_review_status = excluded.mapping_review_status,
            updated_at = excluded.updated_at
        RETURNING id
        SQL;

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

        if (empty($records)) {
            $this->error('El JSON no trae records[].');

            return self::FAILURE;
        }

        $validSourceIds = TaxonomySource::query()->pluck('source_id')->all();

        if (empty($validSourceIds)) {
            $this->error('taxonomy_sources está vacío - correr primero taxonomy:seed-sources.');

            return self::FAILURE;
        }

        $this->info('Importando '.count($records).' términos...');

        $now = now();
        $bar = $this->output->createProgressBar(count($records));
        $bar->start();
        $skipped = 0;

        foreach ($records as $record) {
            $externalId = $record['id'] ?? null;

            if (! $externalId) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $properties = $record['term_properties'] ?? [];
            $sourceId = $this->primarySourceId($record['sources'] ?? [], $validSourceIds);

            $result = DB::connection('pgsql')->selectOne(self::UPSERT_SQL, [
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
                PostgresTextArrayCast::toLiteral($record['positive_context'] ?? []),
                $sourceId,
                $record['mapping_review_status'] ?? 'unmapped',
                $now,
                $now,
            ]);

            $this->syncAliases((int) $result->id, $record['aliases'] ?? []);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($skipped > 0) {
            $this->warn("{$skipped} registro(s) sin 'id', omitidos.");
        }

        $totalTerms = DB::connection('pgsql')->table('taxonomy_terms')->count();
        $unmapped = DB::connection('pgsql')->table('taxonomy_terms')->where('mapping_review_status', 'unmapped')->count();
        $totalAliases = DB::connection('pgsql')->table('taxonomy_term_aliases')->count();

        $this->info("Listo. taxonomy_terms: {$totalTerms} (unmapped: {$unmapped}). taxonomy_term_aliases: {$totalAliases}.");

        return self::SUCCESS;
    }

    private function primarySourceId(array $sources, array $validSourceIds): ?string
    {
        foreach ($sources as $source) {
            $sourceId = $source['source_id'] ?? null;

            if ($sourceId && in_array($sourceId, $validSourceIds, true)) {
                return $sourceId;
            }
        }

        return in_array(TaxonomySource::CURATED, $validSourceIds, true) ? TaxonomySource::CURATED : null;
    }

    private function syncAliases(int $termId, array $aliases): void
    {
        $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases))));

        DB::connection('pgsql')->table('taxonomy_term_aliases')->where('term_id', $termId)->delete();

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
