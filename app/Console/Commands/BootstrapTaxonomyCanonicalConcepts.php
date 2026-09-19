<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCanonicalConcept;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
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
 *
 * Phase 3: la lógica de ambos pasos vive ahora en `CanonicalConceptBuilderService` (reutilizable
 * desde `taxonomy:build-canonical-concepts`) - este command es un wrapper delgado, mismo signature,
 * mismo comportamiento, mismos mensajes de salida.
 */
class BootstrapTaxonomyCanonicalConcepts extends Command
{
    protected $signature = 'taxonomy:bootstrap-canonical-concepts {--similarity=0.6 : Umbral pg_trgm para el auto-link conservador del paso 2}';

    protected $description = 'TAXV3-2: crea 1 concepto por grupo de términos external_verified y enlaza conservadoramente el resto por similitud léxica.';

    public function handle(CanonicalConceptBuilderService $builder): int
    {
        $step1 = $builder->bootstrapFromExternalVerified();
        $this->info("Paso 1: {$step1['concepts_created']} concepto(s) nuevo(s), {$step1['terms_linked']} término(s) verificado(s) vinculado(s).");

        $similarity = (float) $this->option('similarity');
        $autoLinked = $builder->autoLinkBySimilarity($similarity);
        $this->info("Paso 2: {$autoLinked} término(s) adicionales auto-vinculados por similitud léxica (umbral {$similarity}).");

        $totalConcepts = TaxonomyCanonicalConcept::query()->count();
        $totalLinked = DB::connection('pgsql')->table('taxonomy_term_concepts')->distinct('term_id')->count('term_id');
        $this->info("Totales: {$totalConcepts} conceptos, {$totalLinked} término(s) vinculado(s) a algún concepto.");

        return self::SUCCESS;
    }
}
