<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-2 (ajuste V3 puntos 5/6): arranca la capa de Canonical Concepts SIN crawling nuevo -
 * "bootstrap barato" en el sentido de que solo usa datos ya importados.
 *
 * Paso 1: agrupa los 113 términos `external_verified` por `LOWER(canonical_term)` - confirmado
 * contra datos reales que esto es correcto, no solo una simplificación: ej. "artificial lift" tiene
 * 11 filas distintas (`artificial lift services/equipment/tools/systems/...`) que genuinamente son
 * variantes comerciales del MISMO concepto, no duplicados. Crea 1 concepto por grupo distinto (76
 * grupos sobre 113 términos) y vincula todas las filas del grupo.
 *
 * Paso 2: pasada conservadora de auto-link sobre el resto de los términos (`curated`/`pending`, los
 * que hoy generarían la sensación de "1.776 pendientes") usando similitud `pg_trgm` alta (≥0.6, ver
 * `App\Support\Taxonomy\TaxonomyRankingParameters` para el resto de umbrales del proyecto) contra
 * los nombres de concepto ya creados en el paso 1. Esto conecta casos con solapamiento léxico real
 * (ej. "gas natural" ↔ concepto "natural gas") pero NO jerga regional sin ninguna palabra en común
 * (mechurrio/arbolito/guaya fina) - esos quedan disponibles para vincular a mano desde
 * `TaxonomyCanonicalConceptResource` (no es una tarea pendiente, es una mejora opcional), o para que
 * la Fase B (Full Source Discovery, fuera de esta fase) los resuelva si encuentra una fuente real
 * que documente el regionalismo.
 *
 * Idempotente: un término ya vinculado a cualquier concepto no se vuelve a tocar en una segunda
 * corrida.
 */
class BootstrapTaxonomyCanonicalConcepts extends Command
{
    protected $signature = 'taxonomy:bootstrap-canonical-concepts {--similarity=0.6 : Umbral pg_trgm para el auto-link conservador del paso 2}';

    protected $description = 'TAXV3-2: crea 1 concepto por grupo de términos external_verified y enlaza conservadoramente el resto por similitud léxica.';

    public function handle(): int
    {
        $conceptsCreated = 0;
        $verifiedLinked = 0;

        $verifiedTerms = TaxonomyTerm::query()
            ->where('origin_type', TaxonomyTerm::ORIGIN_EXTERNAL_VERIFIED)
            ->get(['id', 'canonical_term', 'language']);

        $groups = $verifiedTerms->groupBy(fn (TaxonomyTerm $t) => mb_strtolower(trim($t->canonical_term)));

        foreach ($groups as $key => $termsInGroup) {
            if ($key === '') {
                continue;
            }

            $alreadyLinked = DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->whereIn('term_id', $termsInGroup->pluck('id'))
                ->pluck('term_id')
                ->all();

            $pending = $termsInGroup->reject(fn (TaxonomyTerm $t) => in_array($t->id, $alreadyLinked, true));
            if ($pending->isEmpty()) {
                continue;
            }

            $enName = $termsInGroup->firstWhere('language', 'en')?->canonical_term;
            $esName = $termsInGroup->firstWhere('language', 'es')?->canonical_term;

            $concept = TaxonomyCanonicalConcept::query()
                ->where('canonical_name_en', $enName)
                ->where('canonical_name_es', $esName)
                ->first();

            if (! $concept) {
                $concept = TaxonomyCanonicalConcept::query()->create([
                    'canonical_name_en' => $enName,
                    'canonical_name_es' => $esName,
                    'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
                ]);
                $conceptsCreated++;
            }

            foreach ($pending as $term) {
                DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
                    'term_id' => $term->id,
                    'concept_id' => $concept->id,
                    'created_at' => now(),
                ]);
                $verifiedLinked++;
            }
        }

        $this->info("Paso 1: {$conceptsCreated} concepto(s) nuevo(s), {$verifiedLinked} término(s) verificado(s) vinculado(s).");

        $similarity = (float) $this->option('similarity');
        $autoLinked = $this->autoLinkBySimilarity($similarity);

        $this->info("Paso 2: {$autoLinked} término(s) adicionales auto-vinculados por similitud léxica (umbral {$similarity}).");

        $totalConcepts = TaxonomyCanonicalConcept::query()->count();
        $totalLinked = DB::connection('pgsql')->table('taxonomy_term_concepts')->distinct('term_id')->count('term_id');
        $this->info("Totales: {$totalConcepts} conceptos, {$totalLinked} término(s) vinculado(s) a algún concepto.");

        return self::SUCCESS;
    }

    private function autoLinkBySimilarity(float $threshold): int
    {
        $linked = 0;

        $unlinkedTerms = DB::connection('pgsql')->select(<<<'SQL'
            SELECT t.id, t.canonical_term
            FROM taxonomy_terms t
            WHERE t.canonical_term IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM taxonomy_term_concepts tc WHERE tc.term_id = t.id)
        SQL);

        foreach ($unlinkedTerms as $term) {
            $match = DB::connection('pgsql')->selectOne(<<<'SQL'
                SELECT id,
                    GREATEST(
                        COALESCE(similarity(canonical_name_en, ?), 0),
                        COALESCE(similarity(canonical_name_es, ?), 0)
                    ) AS sim
                FROM taxonomy_canonical_concepts
                WHERE (canonical_name_en IS NOT NULL AND canonical_name_en % ?)
                   OR (canonical_name_es IS NOT NULL AND canonical_name_es % ?)
                ORDER BY sim DESC
                LIMIT 1
            SQL, [$term->canonical_term, $term->canonical_term, $term->canonical_term, $term->canonical_term]);

            if (! $match || (float) $match->sim < $threshold) {
                continue;
            }

            DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
                'term_id' => $term->id,
                'concept_id' => $match->id,
                'created_at' => now(),
            ]);
            $linked++;
        }

        return $linked;
    }
}
