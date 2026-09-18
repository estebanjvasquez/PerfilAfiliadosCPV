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
 * **Corrección TAXV3-7** (encontrada al preparar la integración con el buscador real de CIRA):
 * `pg_trgm` puntúa igual de alto un hiperónimo genérico de una sola palabra contra un concepto de
 * dos palabras que comparte esa palabra (ej. "drilling" vs "drilling mud", similarity 0.6923) que un
 * subtipo legítimo del mismo objeto (ej. "annular preventer" vs "annular blowout preventer", misma
 * similarity) - el número solo no distingue "es un tipo de" de "aparece en el nombre de". Esto había
 * fusionado 22 términos genéricos de perforación ("drilling equipment/tools/services", "servicios/
 * equipos/ingeniería de perforación", etc. - todos con su propia relación CPV `approved` directa,
 * CPV-28.06.01S) dentro del concepto "drilling mud", con el riesgo real de que el término genuino
 * "drilling mud"/"lodo de perforación" (que NO tiene relación propia aprobada) heredara por el
 * concepto el código de "perforación general" en vez de quedar sin señal. Corregido con el guard de
 * `hasOwnApprovedRelation()` abajo: un término que YA tiene su propia relación `approved` no necesita
 * la capa de conceptos (esa capa es para los que no tienen ninguna verificación propia todavía), así
 * que nunca se auto-vincula por similitud - elimina la fuente del falso positivo sin bajar el umbral
 * ni perder los casos legítimos (kelly/hex kelly, drill string + variantes, annular/blowout
 * preventer, oil based mud) que sí quedaron intactos porque ninguno tenía relación propia previa.
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

        // Excluye términos que YA tienen su propia relación CPV `approved`: la capa de conceptos
        // existe para que un término SIN verificación propia herede la de un hermano - uno que ya
        // tiene la suya no gana nada uniéndose, y sí puede generar un falso positivo si un hiperónimo
        // genérico (ej. "drilling") queda agrupado por trigram con un concepto más específico que
        // comparte la palabra (ej. "drilling mud") - ver docblock de la clase, corrección TAXV3-7.
        $unlinkedTerms = DB::connection('pgsql')->select(<<<'SQL'
            SELECT t.id, t.canonical_term
            FROM taxonomy_terms t
            WHERE t.canonical_term IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM taxonomy_term_concepts tc WHERE tc.term_id = t.id)
              AND NOT EXISTS (
                  SELECT 1 FROM taxonomy_term_cpv_relations r
                  WHERE r.term_id = t.id AND r.status = 'approved'
              )
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
