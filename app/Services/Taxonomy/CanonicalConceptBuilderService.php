<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelationType;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermEmbedding;
use App\Support\Taxonomy\TaxonomyRankingParameters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3: Canonical Concept Builder. EXTIENDE la capa TAXV3-2 existente (79 conceptos, 142 enlaces
 * de identidad léxica/traducción/regionalismo/paráfrasis) - no la reemplaza.
 *
 * Tres responsabilidades separadas a propósito (sección 9 del pedido: "Separar CANDIDATE RETRIEVAL
 * de CANDIDATE DECISION"):
 *  1. `bootstrapFromExternalVerified()`/`autoLinkBySimilarity()` - el bootstrap TAXV3-2 original
 *     (movido tal cual desde `BootstrapTaxonomyCanonicalConcepts`, mismo comportamiento, mismos
 *     resultados - el command ahora es un wrapper delgado de este servicio).
 *  2. `auditExisting()` - AUDIT_EXISTING (sección 14): solo lectura, clasifica los conceptos que YA
 *     existen, sin modificarlos. Carga los miembros de cada concepto DINÁMICAMENTE en cada corrida
 *     (nunca asume un conteo fijo - corrección del re-audit independiente que encontró 22 miembros
 *     reales en el concepto #10 donde un informe previo había registrado 21).
 *  3. `dryRun()` - Builder multi-signal (secciones 8-13): candidate retrieval acotado (nunca
 *     all-pairs) + scoring compuesto + clasificación por tiers, 100% en memoria, CERO escritura.
 *
 * Regla de seguridad (sección 12): ninguna señal semántica individual (pg_trgm, embedding, CPV
 * compartido) puede producir AUTO_ACCEPT por sí sola - `classifyTier()` exige un mínimo de señales
 * corroborantes independientes, salvo la identidad canónica exacta (mismo canonical_term
 * normalizado), que reproduce la semántica ya seguraya probada del bootstrap TAXV3-2 original.
 */
class CanonicalConceptBuilderService
{
    // =====================================================================================
    // INSTRUMENTACIÓN (Phase 3.1, sección 2 del pedido) - atribuye cada query SQL real
    // (capturada vía DB::listen, la misma fuente de verdad que enableQueryLog()) a la etapa
    // lógica que la disparó. Estático porque el listener se registra una sola vez por proceso
    // (un comando artisan es un proceso corto, no hay riesgo de fuga entre invocaciones) y varias
    // instancias del servicio dentro del mismo proceso (ej. tests) deben acumular en el mismo
    // contador si se pide explícitamente. `resetInstrumentation()` limpia los contadores para
    // medir una corrida aislada.
    // =====================================================================================
    private static ?string $currentStage = null;

    private static array $stageQueryCount = [];

    private static array $stageTimeMs = [];

    private static bool $listenerRegistered = false;

    public static function resetInstrumentation(): void
    {
        self::$stageQueryCount = [];
        self::$stageTimeMs = [];
        self::$currentStage = null;

        if (! self::$listenerRegistered) {
            DB::connection('pgsql')->listen(function () {
                $stage = self::$currentStage ?? 'other';
                self::$stageQueryCount[$stage] = (self::$stageQueryCount[$stage] ?? 0) + 1;
            });
            self::$listenerRegistered = true;
        }
    }

    /** @return array{query_count_by_stage: array<string,int>, time_ms_by_stage: array<string,float>} */
    public static function instrumentationReport(): array
    {
        return [
            'query_count_by_stage' => self::$stageQueryCount,
            'time_ms_by_stage' => array_map(fn ($ms) => round($ms, 1), self::$stageTimeMs),
        ];
    }

    private function stage(string $name, callable $fn)
    {
        $previous = self::$currentStage;
        self::$currentStage = $name;
        $start = microtime(true);

        try {
            return $fn();
        } finally {
            self::$stageTimeMs[$name] = (self::$stageTimeMs[$name] ?? 0) + (microtime(true) - $start) * 1000;
            self::$currentStage = $previous;
        }
    }

    public function __construct(private readonly IntentContaminationDetector $intentDetector) {}

    // =====================================================================================
    // 1. BOOTSTRAP LEGACY (TAXV3-2) - movido de BootstrapTaxonomyCanonicalConcepts sin cambios
    //    de comportamiento. El command es ahora un wrapper de estos dos métodos.
    // =====================================================================================

    /** @return array{concepts_created:int, terms_linked:int} */
    public function bootstrapFromExternalVerified(): array
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

        return ['concepts_created' => $conceptsCreated, 'terms_linked' => $verifiedLinked];
    }

    public function autoLinkBySimilarity(float $threshold): int
    {
        $linked = 0;

        // Excluye términos que YA tienen su propia relación CPV `approved` - ver docblock original
        // de BootstrapTaxonomyCanonicalConcepts (corrección TAXV3-7).
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

    // =====================================================================================
    // 2. AUDIT_EXISTING (sección 14) - SOLO LECTURA
    // =====================================================================================

    public function auditExisting(): array
    {
        $settings = $this->settings();

        // Carga dinámica, sin asumir ningún conteo fijo - corrección del re-audit independiente
        // (concepto #10 encontrado con 22 miembros reales, no 21).
        $concepts = TaxonomyCanonicalConcept::query()->with('terms')->orderBy('id')->get();

        // Phase 3.1 (sección 7 del pedido): batch ÚNICO para el sub-desglose de ORPHAN_CONCEPT -
        // evidencia de fuente por término, sobre TODOS los conceptos a la vez (no una query por
        // concepto orphan) para no reintroducir el patrón N+1 que esta misma fase existe para
        // eliminar.
        $allMemberTermIds = $concepts->flatMap(fn (TaxonomyCanonicalConcept $c) => $c->terms->pluck('id'))->unique()->values()->all();
        $verifiedSourceCountByTerm = [];
        if (! empty($allMemberTermIds)) {
            $sourceRows = DB::connection('pgsql')->select(
                "SELECT term_id, count(*) AS c FROM taxonomy_term_source_bindings
                 WHERE term_id IN (".implode(',', array_fill(0, count($allMemberTermIds), '?')).")
                   AND verification_status = 'verified'
                 GROUP BY term_id",
                $allMemberTermIds
            );
            foreach ($sourceRows as $row) {
                $verifiedSourceCountByTerm[$row->term_id] = (int) $row->c;
            }
        }

        $flagCounts = [];
        $results = [];
        $flagCombinationCounts = [];

        foreach ($concepts as $concept) {
            $memberTerms = $concept->terms;
            $termStrings = $memberTerms->pluck('term')->all();

            $flags = [];
            $signals = [];
            $reasonCodes = [];

            if (count($termStrings) <= 1) {
                $flags[] = 'ORPHAN_CONCEPT';
                $reasonCodes[] = 'TERM_COUNT_LTE_1';
            }

            $contamination = $this->intentDetector->detectClusterContamination($termStrings);
            if ($contamination !== []) {
                $flags[] = 'POSSIBLE_INTENT_CONCEPT_COLLAPSE';
                $signals['intent_contamination_pairs'] = $contamination;
                $reasonCodes[] = 'INTENT_MARKER_STRUCTURAL_MATCH';
            }

            $memberIds = $memberTerms->pluck('id')->all();
            $cpvRows = empty($memberIds) ? [] : DB::connection('pgsql')->select(
                'SELECT r.term_id, r.status, cat.path, cat.tipo_oferta
                 FROM taxonomy_term_cpv_relations r
                 JOIN taxonomy_categories cat ON cat.id = r.category_id
                 WHERE r.term_id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
                $memberIds
            );

            if (empty($cpvRows) && $concept->concept_type === null) {
                $flags[] = 'LEXICAL_ONLY_CLUSTER';
                $reasonCodes[] = 'NO_CONCEPT_TYPE_AND_NO_CPV_GROUNDING';
            }

            // Phase 3.1 (sección 7 del pedido): sub-clasificación de POR QUÉ un concepto cae en
            // ORPHAN_CONCEPT - NO cambia el flag en sí (sigue siendo term_count<=1, sección 7:
            // "no corregir ninguno"), solo documenta la causa estructural para el breakdown pedido.
            if (in_array('ORPHAN_CONCEPT', $flags, true)) {
                $hasApprovedCpv = ! empty(array_filter($cpvRows, fn ($r) => $r->status === 'approved'));
                $hasAnyCpv = ! empty($cpvRows);
                $hasVerifiedSource = collect($memberIds)->contains(fn ($id) => ($verifiedSourceCountByTerm[$id] ?? 0) > 0);
                $hasTypedRelations = false; // taxonomy_concept_relations tiene 0 filas en todo el sistema hoy (ver Sección R)

                $signals['orphan_reason'] = match (true) {
                    count($termStrings) === 0 => 'NO_TERM_MEMBERS',
                    $hasApprovedCpv => 'SINGLE_TERM_HAS_APPROVED_CPV_BUT_NO_SIBLING',
                    $hasAnyCpv => 'SINGLE_TERM_ONLY_UNAPPROVED_CPV',
                    $hasVerifiedSource => 'SINGLE_TERM_SOURCE_EVIDENCE_ONLY_NO_CPV',
                    $hasTypedRelations => 'SINGLE_TERM_TYPED_RELATION_ONLY',
                    default => 'SINGLE_TERM_NO_EVIDENCE_AT_ALL',
                };
            }

            $approvedRows = array_values(array_filter($cpvRows, fn ($r) => $r->status === 'approved'));
            if (! empty($approvedRows)) {
                $groupPrefixes = collect($approvedRows)->map(fn ($r) => explode('/', $r->path)[0] ?? $r->path)->unique();
                $familyPrefixes = collect($approvedRows)->map(function ($r) {
                    $parts = explode('/', $r->path);

                    return implode('/', array_slice($parts, 0, 2));
                })->unique();

                if ($groupPrefixes->count() > 1) {
                    $flags[] = 'CPV_CONFLICT';
                    $signals['conflicting_cpv_groups'] = $groupPrefixes->values()->all();
                    $reasonCodes[] = 'MULTIPLE_APPROVED_CPV_GROUPS';
                } elseif ($familyPrefixes->count() > 1) {
                    $flags[] = 'POSSIBLE_MULTI_ENTITY_CLUSTER';
                    $signals['distinct_cpv_families'] = $familyPrefixes->values()->all();
                    $reasonCodes[] = 'MULTIPLE_APPROVED_CPV_FAMILIES_SAME_GROUP';
                }

                $tiposOferta = collect($approvedRows)->pluck('tipo_oferta')->filter()->unique();
                if ($tiposOferta->count() > 1) {
                    $flags[] = 'DOMAIN_CONFLICT';
                    $signals['conflicting_tipo_oferta'] = $tiposOferta->values()->all();
                    $reasonCodes[] = 'APPROVED_CPV_MIXES_BIEN_Y_SERVICIO';
                }
            }

            if (count($termStrings) >= 2) {
                $avgSimilarity = (float) (DB::connection('pgsql')->selectOne(
                    'SELECT AVG(similarity(a.canonical_term, b.canonical_term)) AS avg_sim
                     FROM taxonomy_terms a
                     JOIN taxonomy_terms b ON b.id > a.id
                     WHERE a.id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')
                       AND b.id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
                    array_merge($memberIds, $memberIds)
                )->avg_sim ?? 0);

                $signals['avg_pairwise_similarity'] = round($avgSimilarity, 4);
                if ($avgSimilarity < $settings['concept_builder.low_coherence_avg_similarity_threshold']) {
                    $flags[] = 'LOW_COHERENCE_CLUSTER';
                    $reasonCodes[] = 'AVG_SIMILARITY_BELOW_THRESHOLD';
                }
            }

            if ($flags === []) {
                $flags[] = 'VALID_IDENTITY_CLUSTER';
            }

            foreach ($flags as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }

            // Phase 3.1 (sección 7 del pedido): "los flags pueden solaparse... reportar
            // explícitamente combinaciones y counts por combinación" - ORDENADO para que
            // "A+B" y "B+A" no cuenten como combinaciones distintas.
            $comboKey = implode('+', collect($flags)->sort()->values()->all());
            $flagCombinationCounts[$comboKey] = ($flagCombinationCounts[$comboKey] ?? 0) + 1;

            $results[] = [
                'concept_id' => $concept->id,
                'concept_name' => $concept->display_name,
                'concept_type' => $concept->concept_type,
                'domain' => $concept->domain,
                'term_count' => count($termStrings),
                'terms' => $termStrings,
                'flags' => $flags,
                'signals' => $signals,
                'reason_codes' => $reasonCodes,
            ];
        }

        $orphanReasonSummary = [];
        foreach ($results as $r) {
            if (isset($r['signals']['orphan_reason'])) {
                $reason = $r['signals']['orphan_reason'];
                $orphanReasonSummary[$reason] = ($orphanReasonSummary[$reason] ?? 0) + 1;
            }
        }

        return [
            'audited_at' => now()->toIso8601String(),
            'concept_count' => $concepts->count(),
            'flag_summary' => $flagCounts,
            'flag_combination_summary' => $flagCombinationCounts,
            'orphan_reason_summary' => $orphanReasonSummary,
            'concepts' => $results,
        ];
    }

    // =====================================================================================
    // 3. DRY RUN BUILDER (secciones 8-13, 15) - CERO ESCRITURA
    // =====================================================================================

    public function dryRun(?int $termLimit = null): array
    {
        self::resetInstrumentation();
        $settings = $this->settings();
        $startedAt = microtime(true);
        $startedMemory = memory_get_usage();

        DB::connection('pgsql')->enableQueryLog();

        $query = TaxonomyTerm::query()
            ->whereDoesntHave('concepts')
            ->whereDoesntHave('cpvRelations', fn ($q) => $q->where('status', 'approved'))
            ->with('aliases')
            // Phase 3.1: orden determinístico - sin esto Postgres no garantiza el mismo conjunto
            // de N términos entre corridas (necesario para el result-equivalence test antes/después
            // del refactor de batching, sección 4 del pedido). No cambia qué términos calificarían
            // como candidatos, solo fija CUÁLES entran cuando se usa --limit.
            ->orderBy('id');

        if ($termLimit !== null) {
            $query->limit($termLimit);
        }

        $unlinkedTerms = $this->stage('load_terms', fn () => $query->get());

        // Phase 3.1 (sección 3 del pedido, batch step 8): un solo chequeo "¿existe AL MENOS UN
        // embedding persistido en todo el sistema?" en vez de una consulta por término (retrieval)
        // + otra por candidato (scoring) que, con la tabla vacía, siempre iban a devolver null -
        // 152 queries eliminadas en la medición BEFORE de 40 términos, sin cambiar el resultado:
        // si la tabla sigue vacía, embedding_similarity sigue siendo 0.0 exactamente igual que
        // antes; si algún día se puebla, esta bandera vuelve a `true` y el código per-término/
        // per-candidato de siempre se ejecuta sin cambios.
        $embeddingsAvailable = $this->stage('embeddings_knn', fn () => DB::connection('pgsql')->table('taxonomy_term_embeddings')->exists());

        // Phase 3.1 (sección 3 del pedido, batch steps 4-7/9): cache de evidencia por CONCEPTO,
        // vive durante toda la corrida de dryRun() - un concepto candidato de múltiples términos
        // (frecuente: 79 conceptos totales, docenas de términos evaluados) solo se hidrata una vez.
        $conceptEvidenceCache = [];

        // Phase B (B2): impacto predicho, memoizado por concepto - mismo motivo que el cache de
        // arriba, nunca una query de empresas por PAR término×concepto.
        $conceptImpactCache = [];
        $proposeNewConceptDetails = [];

        $tierCounts = [
            TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT => 0,
            TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT_CONSERVATIVE => 0,
            TaxonomyCandidateConceptLink::TIER_REVIEW => 0,
            TaxonomyCandidateConceptLink::TIER_REJECT => 0,
        ];

        $candidatePairsGenerated = 0;
        $pairsScored = 0;
        $vectorCandidateTermCount = 0;
        $proposeNewConceptCount = 0;
        $sampleResults = [];
        $allResults = [];
        $signalKeysForCounting = [
            'exact_canonical_signal', 'lexical_similarity', 'alias_overlap', 'shared_cpv',
            'cpv_specificity', 'cpv_mapping_quality', 'legacy_service_overlap',
            'embedding_similarity', 'language_equivalence', 'source_evidence',
            'intent_contamination_penalty',
        ];
        $candidateCountBySignal = array_fill_keys($signalKeysForCounting, 0);

        foreach ($unlinkedTerms as $term) {
            [$candidateConcepts, $usedVector] = $this->retrieveCandidateConcepts($term, $settings, $embeddingsAvailable);
            $candidatePairsGenerated += $candidateConcepts->count();
            if ($usedVector) {
                $vectorCandidateTermCount++;
            }

            // Phase 3.1 (sección 3, batch steps 4-7): evidencia del TÉRMINO (mine) es la MISMA para
            // todos sus candidatos - se calcula UNA vez por término, no una vez por par.
            $termEvidence = $this->precomputeTermEvidence($term);

            $bestTier = TaxonomyCandidateConceptLink::TIER_REJECT;

            $possibleExistingForThisTerm = [];

            foreach ($candidateConcepts as $concept) {
                if (! isset($conceptEvidenceCache[$concept->id])) {
                    $conceptEvidenceCache[$concept->id] = $this->precomputeConceptEvidence($concept);
                }
                // Phase B (B2): impacto predicho por concepto, memoizado - una sola query de
                // empresas por concepto distinto visto en toda la corrida, nunca por par.
                if (! isset($conceptImpactCache[$concept->id])) {
                    $conceptImpactCache[$concept->id] = $this->predictedImpactForConcept($concept);
                }

                $scored = $this->scoreCandidate($term, $concept, $settings, $termEvidence, $conceptEvidenceCache[$concept->id], $embeddingsAvailable);
                // Phase B (B2): se adjunta DESPUÉS de scoreCandidate() (que sigue siendo puro/
                // testeable en aislamiento, sin este campo) - solo dryRun() lo agrega, desde el cache.
                $scored['predicted_impact'] = $conceptImpactCache[$concept->id];
                $pairsScored++;
                $tierCounts[$scored['tier']]++;

                foreach ($signalKeysForCounting as $signalKey) {
                    if (($scored['signals'][$signalKey] ?? 0) > 0) {
                        $candidateCountBySignal[$signalKey]++;
                    }
                }

                if ($this->tierRank($scored['tier']) > $this->tierRank($bestTier)) {
                    $bestTier = $scored['tier'];
                }

                if ($scored['tier'] !== TaxonomyCandidateConceptLink::TIER_REJECT) {
                    $possibleExistingForThisTerm[] = [
                        'concept_id' => $concept->id,
                        'concept_name' => $concept->display_name,
                        'score' => $scored['score'],
                        'tier' => $scored['tier'],
                    ];
                }

                if (count($sampleResults) < 50) {
                    $sampleResults[] = $scored;
                }
                // Fingerprint/equivalencia (sección 4 del pedido): TODOS los resultados, no solo
                // la muestra de 50 - necesario para comparar candidate set / score / tier
                // exhaustivamente entre la corrida antes y después del refactor de batching.
                $allResults[] = $scored;
            }

            if ($candidateConcepts->isEmpty() || $bestTier === TaxonomyCandidateConceptLink::TIER_REJECT) {
                $proposeNewConceptCount++;

                // Phase B (B3): detalle estructurado en vez de solo un contador - "posibles
                // duplicados" son los mismos candidatos que este término YA evaluó y quedaron por
                // debajo de REJECT (nunca None) - reusa el mismo pipeline de retrieval/scoring, no
                // inventa un segundo algoritmo de detección de duplicados.
                $proposeNewConceptDetails[] = [
                    'term_id' => $term->id,
                    'term' => $term->term,
                    'language' => $term->language,
                    'region' => $term->region,
                    'term_type' => $term->term_type,
                    'suggested_canonical_name' => $term->canonical_term ?: $term->term,
                    'possible_existing_concepts' => $possibleExistingForThisTerm,
                    'automation_decision' => 'REVIEW_REQUIRED', // nunca AUTO_ELIGIBLE - crear concepto siempre requiere revisión humana (sección 19 del pedido)
                    'reason' => $possibleExistingForThisTerm === []
                        ? 'Ningún concepto existente corrobora lo suficiente - candidato genuino a concepto nuevo.'
                        : 'Hay conceptos existentes con evidencia parcial (por debajo de REJECT) - el revisor debe elegir MAP_TO_EXISTING, CREATE_NEW o REJECT.',
                ];
            }
        }

        // Phase B (B1): propuestas de relación concepto<->concepto, acotadas a los conceptos que
        // efectivamente aparecieron como candidatos en ESTA corrida (nunca all-pairs de los 79
        // sin motivo) - mismo espíritu de "candidate retrieval acotado" que el resto del Builder.
        // `proposeConceptRelations(null)` (todos los conceptos activos) sigue disponible como
        // llamada independiente para un análisis exhaustivo fuera del dry-run de términos.
        $conceptIdsEncountered = array_keys($conceptEvidenceCache);
        $conceptRelationProposals = empty($conceptIdsEncountered)
            ? ['proposals' => [], 'pairs_evaluated' => 0, 'concepts_considered' => 0]
            : $this->proposeConceptRelations($conceptIdsEncountered);

        $queryCount = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        $instrumentation = self::instrumentationReport();
        $termsProcessed = $unlinkedTerms->count();

        return [
            'mode' => 'dry_run',
            'write_barrier_respected' => true,
            'write_barrier_note' => 'CERO INSERT/UPDATE/DELETE ejecutado en esta corrida - ver Sección R (BEFORE/AFTER) del informe.',
            'terms_processed' => $termsProcessed,
            'candidate_pairs_generated' => $candidatePairsGenerated,
            'vector_candidate_terms' => $vectorCandidateTermCount,
            'pairs_scored' => $pairsScored,
            'candidates_by_tier' => $tierCounts,
            'candidate_count_by_signal' => $candidateCountBySignal,
            'propose_new_concept_candidates' => $proposeNewConceptCount,
            // Phase B (B3): detalle estructurado (no solo el contador de arriba, que se conserva
            // por compatibilidad con nada lo consumía todavía, pero no rompe nada quitarlo).
            'propose_new_concept_details' => $proposeNewConceptDetails,
            // Phase B (B1): dry-run 8/8 - relaciones concepto<->concepto propuestas.
            'concept_relation_proposals' => $conceptRelationProposals,
            // Phase B.1 (sección 20 del pedido): estado del grafo en el momento de esta corrida -
            // parte del contrato mínimo que Phase C (--apply) debe consumir para no persistir un
            // candidato ya obsoleto. Ver CanonicalConceptBuilderService::conceptGraphFingerprint().
            'concept_graph_fingerprint' => self::conceptGraphFingerprint(),
            'embedding_calls_made' => 0,
            'db_queries' => $queryCount,
            'queries_per_term' => $termsProcessed > 0 ? round($queryCount / $termsProcessed, 2) : 0.0,
            'queries_per_candidate' => $pairsScored > 0 ? round($queryCount / $pairsScored, 2) : 0.0,
            'query_count_by_stage' => $instrumentation['query_count_by_stage'],
            'time_ms_by_stage' => $instrumentation['time_ms_by_stage'],
            'cache' => 'not_implemented',
            'runtime_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'peak_memory_bytes' => memory_get_peak_usage() - $startedMemory,
            'sample_results' => $sampleResults,
            'all_results' => $allResults,
        ];
    }

    /**
     * Phase B (soporte de B3): mismo pipeline de retrieval+scoring que usa `dryRun()` internamente,
     * expuesto para UN término aislado - la "detección de duplicados" al aprobar un candidato de
     * concepto nuevo (`CandidateConceptApprovalService::findPossibleDuplicateConcepts()`) reutiliza
     * esto en vez de un segundo algoritmo de similitud paralelo (sección 17 del pedido).
     *
     * @return array<int, array{term_id:int,term:string,concept_id:int,concept_name:string,signals:array,score:float,corroborating_signal_count:int,tier:string}>
     */
    public function scoredCandidatesForTerm(TaxonomyTerm $term): array
    {
        $settings = $this->settings();
        $embeddingsAvailable = DB::connection('pgsql')->table('taxonomy_term_embeddings')->exists();
        [$candidateConcepts] = $this->retrieveCandidateConcepts($term, $settings, $embeddingsAvailable);
        $termEvidence = $this->precomputeTermEvidence($term);

        $results = [];
        foreach ($candidateConcepts as $concept) {
            $scored = $this->scoreCandidate($term, $concept, $settings, $termEvidence, $this->precomputeConceptEvidence($concept), $embeddingsAvailable);
            if ($scored['tier'] !== TaxonomyCandidateConceptLink::TIER_REJECT) {
                $results[] = $scored;
            }
        }

        return $results;
    }

    /** @return array{0: Collection<int, TaxonomyCanonicalConcept>, 1: bool} */
    private function retrieveCandidateConcepts(TaxonomyTerm $term, array $settings, bool $embeddingsAvailable = true): array
    {
        $vectorTopK = (int) $settings['concept_builder.vector_top_k'];
        $maxConcepts = (int) $settings['concept_builder.max_candidate_concepts_per_term'];
        $needle = trim($term->canonical_term ?: $term->term);
        $conceptIds = collect();

        $lexical = $this->stage('trigram_candidates', fn () => DB::connection('pgsql')->select(<<<'SQL'
            SELECT id FROM taxonomy_canonical_concepts
            WHERE (canonical_name_en IS NOT NULL AND canonical_name_en % ?)
               OR (canonical_name_es IS NOT NULL AND canonical_name_es % ?)
            ORDER BY GREATEST(COALESCE(similarity(canonical_name_en, ?), 0), COALESCE(similarity(canonical_name_es, ?), 0)) DESC
            LIMIT ?
        SQL, [$needle, $needle, $needle, $needle, $vectorTopK]));
        $conceptIds = $conceptIds->merge(collect($lexical)->pluck('id'));

        // CPV compartido (cualquier status - solo para ACOTAR el universo; la calidad real se
        // recalcula en scoreCandidate()/cpvSignals()). Boundary '/%' evita falsos positivos de
        // prefijo (ej. "CPV-05" no debe matchear "CPV-052").
        $sharedCpv = $this->stage('shared_cpv', fn () => DB::connection('pgsql')->select(<<<'SQL'
            SELECT DISTINCT tc.concept_id
            FROM taxonomy_term_cpv_relations mine
            JOIN taxonomy_categories myCat ON myCat.id = mine.category_id
            JOIN taxonomy_categories otherCat
                ON otherCat.path = myCat.path
                OR otherCat.path LIKE myCat.path || '/%'
                OR myCat.path LIKE otherCat.path || '/%'
            JOIN taxonomy_term_cpv_relations other ON other.category_id = otherCat.id
            JOIN taxonomy_term_concepts tc ON tc.term_id = other.term_id
            WHERE mine.term_id = ?
            LIMIT ?
        SQL, [$term->id, $vectorTopK]));
        $conceptIds = $conceptIds->merge(collect($sharedCpv)->pluck('concept_id'));

        $aliasOverlap = $this->stage('aliases', fn () => DB::connection('pgsql')->select(<<<'SQL'
            SELECT DISTINCT tc.concept_id
            FROM taxonomy_terms t2
            JOIN taxonomy_term_concepts tc ON tc.term_id = t2.id
            LEFT JOIN taxonomy_term_aliases a ON a.term_id = t2.id
            WHERE lower(t2.canonical_term) = lower(?) OR lower(t2.term) = lower(?) OR lower(a.alias) = lower(?)
            LIMIT ?
        SQL, [$needle, $needle, $needle, $vectorTopK]));
        $conceptIds = $conceptIds->merge(collect($aliasOverlap)->pluck('concept_id'));

        $serviceOverlap = $this->stage('legacy_services', fn () => DB::connection('pgsql')->select(<<<'SQL'
            SELECT DISTINCT tc.concept_id
            FROM taxonomy_term_service_relations mine
            JOIN taxonomy_term_service_relations other
                ON other.legacy_service_id = mine.legacy_service_id AND other.term_id != mine.term_id
            JOIN taxonomy_term_concepts tc ON tc.term_id = other.term_id
            WHERE mine.term_id = ?
            LIMIT ?
        SQL, [$term->id, $vectorTopK]));
        $conceptIds = $conceptIds->merge(collect($serviceOverlap)->pluck('concept_id'));

        // Vecino más cercano SOLO si ya existe un embedding persistido para este término - nunca se
        // llama al modelo de embeddings dentro del Builder (sección 9 del pedido). Phase 3.1: si
        // `dryRun()` ya determinó con UN chequeo global que la tabla está vacía, ni siquiera se
        // intenta - mismo resultado (0 candidatos por esta vía) sin el round trip por término.
        $usedVector = false;
        if ($embeddingsAvailable) {
            $termEmbedding = $this->stage('embeddings_knn', fn () => TaxonomyTermEmbedding::query()->where('term_id', $term->id)->value('embedding'));
            if ($termEmbedding !== null) {
                $usedVector = true;
                $vectorNeighbors = $this->stage('embeddings_knn', fn () => DB::connection('pgsql')->select(<<<'SQL'
                    SELECT tc.concept_id, MIN(te.embedding <=> ?::vector) AS dist
                    FROM taxonomy_term_embeddings te
                    JOIN taxonomy_term_concepts tc ON tc.term_id = te.term_id
                    WHERE te.term_id != ?
                    GROUP BY tc.concept_id
                    ORDER BY dist ASC
                    LIMIT ?
                SQL, [$termEmbedding, $term->id, $vectorTopK]));
                $conceptIds = $conceptIds->merge(collect($vectorNeighbors)->pluck('concept_id'));
            }
        }

        $uniqueIds = $conceptIds->filter()->unique()->take($maxConcepts)->values();

        $concepts = $uniqueIds->isEmpty()
            ? collect()
            : $this->stage('concept_members', fn () => TaxonomyCanonicalConcept::query()->whereIn('id', $uniqueIds)->with('terms')->get());

        return [$concepts, $usedVector];
    }

    // =====================================================================================
    // PRECÓMPUTO DE EVIDENCIA (Phase 3.1, sección 3 del pedido) - separa la obtención de datos
    // "propios del término" y "propios del concepto" de su USO en el scoring, para que dryRun()
    // pueda calcularlos UNA vez por término / UNA vez por concepto (memoizado) en vez de una vez
    // por PAR término×concepto. `scoreCandidate()` sigue aceptando ambos como null y calculándolos
    // por sí solo (mismas queries de siempre) para no romper ningún caller existente (tests, uso
    // directo de un solo par) - la optimización es exclusivamente de CÓMO se invoca desde dryRun(),
    // nunca de qué calcula cada señal.
    // =====================================================================================

    /** @return array{cpv_rows: array, service_ids: \Illuminate\Support\Collection, verified_source_ids: \Illuminate\Support\Collection} */
    private function precomputeTermEvidence(TaxonomyTerm $term): array
    {
        return [
            'cpv_rows' => $this->stage('cpv_quality', fn () => DB::connection('pgsql')->select(
                'SELECT r.relation_type, r.confidence, r.status, cat.path
                 FROM taxonomy_term_cpv_relations r
                 JOIN taxonomy_categories cat ON cat.id = r.category_id
                 WHERE r.term_id = ?',
                [$term->id]
            )),
            'service_ids' => $this->stage('legacy_services', fn () => DB::connection('pgsql')->table('taxonomy_term_service_relations')->where('term_id', $term->id)->pluck('legacy_service_id')),
            'verified_source_ids' => $this->stage('source_evidence', fn () => DB::connection('pgsql')->table('taxonomy_term_source_bindings')
                ->where('term_id', $term->id)->where('verification_status', 'verified')->pluck('source_id')),
        ];
    }

    /** @return array{member_ids: array, member_strings: array, cpv_rows: array, service_ids: \Illuminate\Support\Collection, verified_source_ids: \Illuminate\Support\Collection, alias_forms: \Illuminate\Support\Collection} */
    private function precomputeConceptEvidence(TaxonomyCanonicalConcept $concept): array
    {
        $memberTerms = $concept->relationLoaded('terms') ? $concept->terms : $concept->terms()->get();
        $memberIds = $memberTerms->pluck('id')->all();

        if (empty($memberIds)) {
            return [
                'member_ids' => [], 'member_strings' => [], 'cpv_rows' => [],
                'service_ids' => collect(), 'verified_source_ids' => collect(), 'alias_forms' => collect(),
            ];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

        return [
            'member_ids' => $memberIds,
            'member_strings' => $memberTerms->pluck('term')->all(),
            'cpv_rows' => $this->stage('cpv_quality', fn () => DB::connection('pgsql')->select(
                "SELECT r.relation_type, r.confidence, r.status, cat.path
                 FROM taxonomy_term_cpv_relations r
                 JOIN taxonomy_categories cat ON cat.id = r.category_id
                 WHERE r.term_id IN ({$placeholders})",
                $memberIds
            )),
            'service_ids' => $this->stage('legacy_services', fn () => DB::connection('pgsql')->table('taxonomy_term_service_relations')->whereIn('term_id', $memberIds)->pluck('legacy_service_id')),
            'verified_source_ids' => $this->stage('source_evidence', fn () => DB::connection('pgsql')->table('taxonomy_term_source_bindings')
                ->whereIn('term_id', $memberIds)->where('verification_status', 'verified')->pluck('source_id')),
            'alias_forms' => $this->stage('aliases', fn () => collect(DB::connection('pgsql')->table('taxonomy_terms as t')
                ->leftJoin('taxonomy_term_aliases as a', 'a.term_id', '=', 't.id')
                ->whereIn('t.id', $memberIds)
                ->select('t.canonical_term', 't.term', 'a.alias')
                ->get())
                ->flatMap(fn ($row) => [$row->canonical_term, $row->term, $row->alias])
                ->filter()->map(fn ($s) => mb_strtolower(trim($s)))->unique()),
        ];
    }

    // =====================================================================================
    // SCORING COMPUESTO (sección 10-12) - público para poder testearlo de forma aislada
    // =====================================================================================

    public function scoreCandidate(
        TaxonomyTerm $term,
        TaxonomyCanonicalConcept $concept,
        ?array $settings = null,
        ?array $termEvidence = null,
        ?array $conceptEvidence = null,
        ?bool $embeddingsAvailable = null,
    ): array {
        $settings ??= $this->settings();
        $termEvidence ??= $this->precomputeTermEvidence($term);
        $conceptEvidence ??= $this->precomputeConceptEvidence($concept);

        $memberIds = $conceptEvidence['member_ids'];
        $memberStrings = $conceptEvidence['member_strings'];
        $memberTerms = $concept->relationLoaded('terms') ? $concept->terms : $concept->terms()->get();
        $needle = trim($term->canonical_term ?: $term->term);

        $signals = [];

        $normalizedNeedle = mb_strtolower(trim($needle));
        $signals['exact_canonical_signal'] = $memberTerms->contains(
            fn (TaxonomyTerm $m) => mb_strtolower(trim((string) $m->canonical_term)) === $normalizedNeedle
        ) ? 1.0 : 0.0;

        $signals['lexical_similarity'] = empty($memberIds) ? 0.0 : (float) ($this->stage('scoring_support', fn () => DB::connection('pgsql')->selectOne(
            'SELECT COALESCE(MAX(similarity(?, t.canonical_term)), 0) AS s
             FROM taxonomy_terms t WHERE t.id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
            array_merge([$needle], $memberIds)
        ))->s ?? 0);

        $myForms = collect([$term->canonical_term, $term->term])
            ->merge($term->relationLoaded('aliases') ? $term->aliases->pluck('alias') : $this->stage('aliases', fn () => $term->aliases()->pluck('alias')))
            ->filter()->map(fn ($s) => mb_strtolower(trim($s)))->unique();

        $theirForms = $conceptEvidence['alias_forms'];

        $signals['alias_overlap'] = $myForms->intersect($theirForms)->isNotEmpty() ? 1.0 : 0.0;

        [$sharedCpv, $cpvSpecificity, $cpvMappingQuality] = $this->cpvSignals($termEvidence['cpv_rows'], $conceptEvidence['cpv_rows'], $settings);
        $signals['shared_cpv'] = $sharedCpv;
        $signals['cpv_specificity'] = $cpvSpecificity;
        $signals['cpv_mapping_quality'] = $cpvMappingQuality;

        $myServices = $termEvidence['service_ids'];
        $signals['legacy_service_overlap'] = ($myServices->isEmpty() || empty($memberIds)) ? 0.0 : (
            $myServices->intersect($conceptEvidence['service_ids'])->isNotEmpty() ? 1.0 : 0.0
        );

        $signals['embedding_similarity'] = $this->embeddingSimilaritySignal($term, $memberIds, $embeddingsAvailable ?? true);

        $signals['language_equivalence'] = (
            (($term->language === 'es' && $concept->canonical_name_en) || ($term->language === 'en' && $concept->canonical_name_es))
            && $term->term_type === TaxonomyTerm::TERM_TYPE_TRANSLATION_ALIAS
        ) ? 1.0 : 0.0;

        $myVerifiedSources = $termEvidence['verified_source_ids'];
        $signals['source_evidence'] = ($myVerifiedSources->isEmpty() || empty($memberIds)) ? 0.0 : (
            $myVerifiedSources->intersect($conceptEvidence['verified_source_ids'])->isNotEmpty() ? 1.0 : 0.0
        );

        $signals['intent_contamination_penalty'] = $this->intentDetector->contaminationPenaltySignal($term->term, $memberStrings);

        $weightKeys = [
            'exact_canonical_signal', 'lexical_similarity', 'alias_overlap', 'shared_cpv',
            'cpv_specificity', 'cpv_mapping_quality', 'legacy_service_overlap',
            'embedding_similarity', 'language_equivalence', 'source_evidence',
        ];

        $weightedSum = 0.0;
        foreach ($weightKeys as $key) {
            $weightedSum += $signals[$key] * ($settings["concept_builder.weight_{$key}"] ?? 0.0);
        }

        // Tratamiento especial de identidad canónica exacta (sección 12 del pedido): reproduce la
        // semántica ya segura y probada del bootstrap TAXV3-2 (Paso 1, agrupación exacta por
        // canonical_term - confianza plena, sin necesitar corroboración de otras señales débiles).
        // Sin este override el score máximo posible de UNA sola señal sería su propio peso (<=0.20),
        // muy por debajo de cualquier umbral de auto-aceptación - cubierto por tests dedicados, ver
        // CanonicalConceptBuilderServiceTest. La penalización por contaminación de intención SIGUE
        // aplicándose después del override (una coincidencia "exacta" contaminada no debe auto-
        // aceptarse ciegamente).
        $rawScore = $signals['exact_canonical_signal'] >= 1.0 ? 1.0 : $weightedSum;

        $score = max(0.0, min(1.0, $rawScore - $signals['intent_contamination_penalty'] * $settings['concept_builder.intent_contamination_penalty_weight']));

        $corroboratingThreshold = $settings['concept_builder.corroborating_signal_threshold'];
        $corroboratingCount = 0;
        foreach ($weightKeys as $key) {
            if ($signals[$key] >= $corroboratingThreshold) {
                $corroboratingCount++;
            }
        }

        if ($signals['exact_canonical_signal'] >= 1.0) {
            $corroboratingCount = max($corroboratingCount, (int) $settings['concept_builder.min_corroborating_signals_for_auto_accept']);
        }

        $tier = $this->classifyTier($score, $corroboratingCount, $settings);

        return [
            'term_id' => $term->id,
            'term' => $term->term,
            'concept_id' => $concept->id,
            'concept_name' => $concept->display_name,
            'signals' => $signals,
            'score' => round($score, 4),
            'corroborating_signal_count' => $corroboratingCount,
            'tier' => $tier,
        ];
    }

    public function classifyTier(float $score, int $corroboratingSignalCount, array $settings): string
    {
        $minCorroboration = (int) $settings['concept_builder.min_corroborating_signals_for_auto_accept'];

        if ($score >= $settings['concept_builder.auto_accept_threshold']) {
            return $corroboratingSignalCount >= $minCorroboration
                ? TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT
                : TaxonomyCandidateConceptLink::TIER_REVIEW;
        }

        if ($score >= $settings['concept_builder.auto_accept_conservative_threshold']) {
            return $corroboratingSignalCount >= $minCorroboration
                ? TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT_CONSERVATIVE
                : TaxonomyCandidateConceptLink::TIER_REVIEW;
        }

        if ($score >= $settings['concept_builder.review_threshold']) {
            return TaxonomyCandidateConceptLink::TIER_REVIEW;
        }

        return TaxonomyCandidateConceptLink::TIER_REJECT;
    }

    /**
     * Phase 3.1: pura sobre datos YA obtenidos (`$mine`/`$theirs`, filas de
     * `taxonomy_term_cpv_relations` join `taxonomy_categories`) - cero acceso a BD acá. El caller
     * (`scoreCandidate()`) decide si esos datos vienen de un fetch fresco o de la evidencia
     * precalculada por término/concepto - el cálculo en sí (CATEGORY > FAMILY > GROUP, calidad de
     * mapping) es idéntico a como era cuando esta función hacía sus propias queries.
     *
     * @return array{0:float,1:float,2:float} [shared_cpv, cpv_specificity, cpv_mapping_quality]
     */
    private function cpvSignals(array $mine, array $theirs, array $settings): array
    {
        if (empty($mine) || empty($theirs)) {
            return [0.0, 0.0, 0.0];
        }

        $qualityMap = [
            'exact' => (float) $settings['concept_builder.mapping_quality_exact'],
            'explicit_synonym' => (float) $settings['concept_builder.mapping_quality_explicit_synonym'],
            'strong_lexical' => (float) $settings['concept_builder.mapping_quality_strong_lexical'],
            'lexical' => (float) $settings['concept_builder.mapping_quality_lexical'],
            'contextual' => (float) $settings['concept_builder.mapping_quality_contextual'],
        ];
        $statusFactor = fn (string $status) => match ($status) {
            'approved' => 1.0,
            'deprecated', 'rejected' => 0.0,
            default => 0.5,
        };

        $bestShared = 0.0;
        $bestSpecificity = 0.0;
        $bestQuality = 0.0;

        foreach ($mine as $m) {
            $myParts = explode('/', $m->path);
            foreach ($theirs as $t) {
                $theirParts = explode('/', $t->path);
                $commonDepth = 0;
                while (isset($myParts[$commonDepth], $theirParts[$commonDepth]) && $myParts[$commonDepth] === $theirParts[$commonDepth]) {
                    $commonDepth++;
                }

                if ($commonDepth === 0) {
                    continue;
                }

                $exactMatch = $m->path === $t->path;
                $shared = $exactMatch ? 1.0 : ($commonDepth >= 2 ? 0.6 : 0.3);
                $specificity = $exactMatch ? 1.0 : ($commonDepth >= 2 ? 0.5 : 0.0);

                $myQuality = ($qualityMap[$m->relation_type] ?? 0.5) * $statusFactor($m->status) * (float) $m->confidence;
                $theirQuality = ($qualityMap[$t->relation_type] ?? 0.5) * $statusFactor($t->status) * (float) $t->confidence;
                $quality = ($myQuality + $theirQuality) / 2;

                if ($shared > $bestShared || ($shared === $bestShared && $quality > $bestQuality)) {
                    $bestShared = $shared;
                    $bestSpecificity = $specificity;
                    $bestQuality = $quality;
                }
            }
        }

        return [$bestShared, $bestSpecificity, round($bestQuality, 4)];
    }

    private function embeddingSimilaritySignal(TaxonomyTerm $term, array $memberIds, bool $embeddingsAvailable = true): float
    {
        // Phase 3.1: si dryRun() ya confirmó con un único chequeo global que no hay NINGÚN
        // embedding persistido en todo el sistema, no tiene sentido preguntar por este término en
        // particular - misma respuesta (0.0) sin el round trip.
        if (! $embeddingsAvailable || empty($memberIds)) {
            return 0.0;
        }

        $termEmbedding = $this->stage('embeddings_knn', fn () => TaxonomyTermEmbedding::query()->where('term_id', $term->id)->value('embedding'));
        if ($termEmbedding === null) {
            return 0.0;
        }

        $row = $this->stage('embeddings_knn', fn () => DB::connection('pgsql')->selectOne(
            'SELECT MIN(embedding <=> ?::vector) AS dist FROM taxonomy_term_embeddings
             WHERE term_id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
            array_merge([$termEmbedding], $memberIds)
        ));

        return ($row && $row->dist !== null) ? max(0.0, 1 - (float) $row->dist) : 0.0;
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT => 3,
            TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT_CONSERVATIVE => 2,
            TaxonomyCandidateConceptLink::TIER_REVIEW => 1,
            default => 0,
        };
    }

    /**
     * Phase 3.1 (sección 11 del pedido): "mismo COUNT no demuestra por sí solo ausencia de UPDATE" -
     * fingerprint determinístico de una tabla completa (todas las columnas relevantes, ordenado por
     * `id`), para las 4 tablas críticas y pequeñas. Un UPDATE que no cambia el conteo de filas SÍ
     * cambia este hash.
     */
    public static function tableFingerprint(string $table, array $columns = ['*']): string
    {
        $rows = DB::connection('pgsql')->table($table)->orderBy('id')->get($columns);

        return hash('sha256', $rows->toJson());
    }

    /**
     * Phase B.1 (sección 9 del pedido): fingerprint del ESTADO del grafo de conceptos
     * (`taxonomy_canonical_concepts` + `taxonomy_term_concepts` - las dos tablas cuyo cambio puede
     * volver obsoleta una propuesta de `taxonomy_candidate_concept_links`: un concepto nuevo, uno
     * fusionado/mergeado, o un término re-vinculado). Reusa `tableFingerprint()` dos veces en vez de
     * inventar un segundo mecanismo de hashing. Contrato de Phase C (sección 20): el futuro
     * `--apply` debe estampar este valor en `taxonomy_candidate_concept_links.taxonomy_state_fingerprint`
     * al generar cada candidato; la UI de revisión (Phase B.1) compara contra el valor actual en el
     * momento de la revisión.
     */
    public static function conceptGraphFingerprint(): string
    {
        return hash('sha256',
            self::tableFingerprint('taxonomy_canonical_concepts').
            self::tableFingerprint('taxonomy_term_concepts')
        );
    }

    /** @return array<string, float> Solo las claves `concept_builder.*`, valor real de taxonomy_settings o default. */
    public function settings(): array
    {
        return $this->settingsWithPrefix('concept_builder.');
    }

    /** @return array<string, float> Solo las claves `concept_relations.*` (Phase B), valor real de taxonomy_settings o default. */
    public function conceptRelationSettings(): array
    {
        return $this->settingsWithPrefix('concept_relations.');
    }

    private function settingsWithPrefix(string $prefix): array
    {
        $defaults = TaxonomyRankingParameters::defaults();
        $stored = $this->stage('settings', fn () => DB::connection('pgsql')->table('taxonomy_settings')->pluck('value', 'key'));

        $merged = [];
        foreach ($defaults as $key => $default) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $merged[$key] = isset($stored[$key]) ? (float) $stored[$key] : (float) $default;
        }

        return $merged;
    }

    // =====================================================================================
    // PHASE B - B2: IMPACTO PREDICHO (empresas afectadas) - CERO ESCRITURA, solo lectura de
    // `empresa_taxonomy_category` (el vínculo real empresa<->CPV, TAXV2/Fase 4) y
    // `company_term_matches` (evidencia de crawler, TAXV2-13 - hoy vacía, el crawler es Fase E).
    // No se reimplementa la semántica de alcanzabilidad del Worker (hybrid-search.ts) en PHP -
    // se consulta la MISMA tabla de origen (`empresa_taxonomy_category`) que esa capa expone como
    // evidencia `canonical_cpv`/`declared_profile`, así que ambos lados leen la misma fuente de
    // verdad en vez de tener dos definiciones divergentes de "empresa alcanzable".
    // =====================================================================================

    /** @return array<int,int> IDs de taxonomy_categories con relación `approved` para estos términos. */
    public function conceptApprovedCategoryIds(array $memberTermIds): array
    {
        if (empty($memberTermIds)) {
            return [];
        }

        return $this->stage('predicted_impact', fn () => DB::connection('pgsql')->table('taxonomy_term_cpv_relations')
            ->whereIn('term_id', $memberTermIds)
            ->where('status', 'approved')
            ->pluck('category_id')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * B2 del pedido de Phase B. Dado un conjunto de IDs de `taxonomy_categories`, calcula el
     * impacto de empresas de la MISMA forma en que lo expondría un dry-run: predicción, nunca
     * escritura. Deduplicado por `empresa_id` (sección 12/13 del pedido: rutas de evidencia
     * múltiples no deben inflar el conteo, pero SÍ se preservan todas).
     *
     * `expanded_company_count` queda en 0 a propósito: no hay ningún consumidor de
     * `taxonomy_concept_relations`/traversal todavía (confirmado en `audit/phase3_completion_audit.md`),
     * así que no hay ninguna expansión real que calcular - existe el campo para cuando eso exista
     * (Fase G del plan), en vez de inventar un número.
     *
     * @return array{direct_company_count:int, evidence_company_count:int, expanded_company_count:int, total_unique_company_count:int, companies:array, data_gap_flags:array}
     */
    public function predictAffectedCompanies(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_filter($categoryIds)));

        if (empty($categoryIds)) {
            return [
                'direct_company_count' => 0,
                'evidence_company_count' => 0,
                'expanded_company_count' => 0,
                'total_unique_company_count' => 0,
                'companies' => [],
                'data_gap_flags' => ['CONCEPT_RESOLUTION_FAILURE'],
            ];
        }

        $directRows = $this->stage('predicted_impact', fn () => DB::connection('pgsql')->table('empresa_taxonomy_category')
            ->whereIn('category_id', $categoryIds)
            ->select('empresa_id', 'category_id', 'origen', 'es_principal', 'approved_at')
            ->get());

        // Evidencia de crawler (TAXV2-13) - tabla real, hoy vacía (el crawler es Fase E). Se
        // consulta igual (no se asume que está vacía) para que este método siga siendo correcto
        // el día que el crawler exista, sin tocarlo de nuevo.
        $cpvCodes = $this->stage('predicted_impact', fn () => DB::connection('pgsql')->table('taxonomy_categories')
            ->whereIn('id', $categoryIds)->pluck('code')->all());

        $evidenceRows = $this->stage('predicted_impact', fn () => DB::connection('pgsql')->table('company_term_matches')
            ->whereIn('cpv_code', $cpvCodes)
            ->select('empresa_id', 'cpv_code', 'evidence_score', 'match_type')
            ->get());

        $companies = [];
        foreach ($directRows as $row) {
            $companies[$row->empresa_id]['direct'][] = [
                'category_id' => $row->category_id,
                'origen' => $row->origen,
                'es_principal' => (bool) $row->es_principal,
                'confirmed' => $row->approved_at !== null,
            ];
        }
        foreach ($evidenceRows as $row) {
            $companies[$row->empresa_id]['crawler_evidence'][] = [
                'cpv_code' => $row->cpv_code,
                'match_type' => $row->match_type,
                'evidence_score' => (float) $row->evidence_score,
            ];
        }

        $companyList = [];
        foreach ($companies as $empresaId => $paths) {
            $reasons = [];
            if (! empty($paths['direct'])) {
                $reasons[] = 'declared_taxonomy_category';
            }
            if (! empty($paths['crawler_evidence'])) {
                $reasons[] = 'crawler_evidence';
            }

            $companyList[] = [
                'company_id' => $empresaId,
                'evidence_paths' => $paths,
                'reason' => implode('+', $reasons),
            ];
        }

        $dataGapFlags = [];
        if (empty($companyList)) {
            $dataGapFlags[] = 'CPV_WITHOUT_COMPANIES';
        }
        if ($evidenceRows->isEmpty()) {
            $dataGapFlags[] = 'NO_CRAWLER_EVIDENCE';
        }

        return [
            'direct_company_count' => $directRows->pluck('empresa_id')->unique()->count(),
            'evidence_company_count' => $evidenceRows->pluck('empresa_id')->unique()->count(),
            'expanded_company_count' => 0,
            'total_unique_company_count' => count($companyList),
            'companies' => $companyList,
            'data_gap_flags' => $dataGapFlags,
        ];
    }

    /** Atajo: impacto predicho de un concepto completo (unión de las categorías CPV aprobadas de todos sus miembros). */
    public function predictedImpactForConcept(TaxonomyCanonicalConcept $concept): array
    {
        $memberIds = $concept->relationLoaded('terms') ? $concept->terms->pluck('id')->all() : $concept->terms()->pluck('taxonomy_terms.id')->all();
        $categoryIds = $this->conceptApprovedCategoryIds($memberIds);

        return $this->predictAffectedCompanies($categoryIds);
    }

    // =====================================================================================
    // PHASE B - B1: PROPUESTA DE RELACIONES CONCEPTO<->CONCEPTO (`taxonomy_concept_relations`).
    // CERO ESCRITURA. Usa el catálogo de gobernanza YA seedeado (5 tipos reales: RELATED_TO,
    // PART_OF/HAS_PART, SUPERSEDES/SUPERSEDED_BY - ver `taxonomy_concept_relation_types`), nunca
    // un vocabulario inventado.
    //
    // Decisión de diseño (documentada también en audit/phase3_phase_b.md): solo se AUTO-PROPONE
    // `RELATED_TO` (no direccional) a partir de señales reales (nombre, CPV compartido, alias/
    // términos compartidos). Los 4 tipos direccionales/jerárquicos (PART_OF/HAS_PART/SUPERSEDES/
    // SUPERSEDED_BY) NO se infieren automáticamente acá - inferir "parte de" desde el anidamiento
    // de paths de `taxonomy_categories` confundiría la jerarquía CPV (una dimensión) con relación
    // semántica entre conceptos (otra dimensión), justo lo que el proyecto ya decidió evitar para
    // `relation_type` de CPV (ver audit/phase3_completion_audit.md). Esos 4 tipos solo se validan
    // (`validateConceptRelationProposal()`) cuando un humano los propone a mano - el Builder no
    // adivina jerarquía.
    // =====================================================================================

    // Phase B.1 (sección 16 del pedido): umbral de advertencia SOLO documental/de instrumentación -
    // all-pairs sobre 79 conceptos activos es 3.081 pares (medido, ver
    // audit/phase3_phase_b1_review_workflow.md sección 12), barato en la práctica. Complejidad real:
    // O(n²) en `concepts_considered` - si el catálogo de conceptos crece a un orden de magnitud
    // más (ej. cientos/miles), este método deja de ser el approach correcto y necesitaría
    // generación de candidatos acotada/indexada (mismo espíritu que `retrieveCandidateConcepts()`
    // ya usa para término->concepto) en vez de comparar todos los pares. NO se reemplaza el
    // algoritmo acá sin evidencia real de que haga falta (pedido explícito de la sección 16).
    public const CONCEPT_RELATION_PAIRS_WARNING_THRESHOLD = 20000;

    /** @return array{proposals:array, pairs_evaluated:int, concepts_considered:int, runtime_ms:float, complexity_warning:?string} */
    public function proposeConceptRelations(?array $conceptIds = null): array
    {
        $startedAt = microtime(true);
        $settings = $this->conceptRelationSettings();
        $cpvQualitySettings = $this->settings(); // concept_builder.mapping_quality_* - una sola vez, no por par

        $concepts = $conceptIds === null
            ? TaxonomyCanonicalConcept::query()->where('status', TaxonomyCanonicalConcept::STATUS_ACTIVE)->with('terms')->orderBy('id')->get()
            : TaxonomyCanonicalConcept::query()->whereIn('id', $conceptIds)->with('terms')->orderBy('id')->get();

        $evidenceCache = [];
        foreach ($concepts as $concept) {
            $evidenceCache[$concept->id] = $this->precomputeConceptEvidence($concept);
        }

        $existingPairs = $this->stage('concept_relations', fn () => DB::connection('pgsql')->table('taxonomy_concept_relations')
            ->select('source_concept_id', 'target_concept_id', 'relation_type')
            ->get()
            ->map(fn ($r) => "{$r->source_concept_id}:{$r->target_concept_id}:{$r->relation_type}")
            ->flip());

        $proposals = [];
        $pairsEvaluated = 0;
        $conceptList = $concepts->values();

        for ($i = 0; $i < $conceptList->count(); $i++) {
            for ($j = $i + 1; $j < $conceptList->count(); $j++) {
                $a = $conceptList[$i];
                $b = $conceptList[$j];
                $pairsEvaluated++;

                if (isset($existingPairs["{$a->id}:{$b->id}:RELATED_TO"]) || isset($existingPairs["{$b->id}:{$a->id}:RELATED_TO"])) {
                    continue; // ya existe, no proponer de nuevo
                }

                $proposal = $this->scoreConceptPair($a, $b, $evidenceCache[$a->id], $evidenceCache[$b->id], $settings, $cpvQualitySettings);
                if ($proposal !== null) {
                    $proposals[] = $proposal;
                }
            }
        }

        return [
            'proposals' => $proposals,
            'pairs_evaluated' => $pairsEvaluated,
            'concepts_considered' => $conceptList->count(),
            'runtime_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'complexity_warning' => $pairsEvaluated > self::CONCEPT_RELATION_PAIRS_WARNING_THRESHOLD
                ? "all-pairs generó {$pairsEvaluated} pares (> ".self::CONCEPT_RELATION_PAIRS_WARNING_THRESHOLD.") - considerar generación de candidatos acotada en vez de all-pairs si esto se vuelve recurrente."
                : null,
        ];
    }

    /** @return array|null null si ninguna señal corrobora lo suficiente para siquiera proponer (por debajo de review_threshold). */
    private function scoreConceptPair(
        TaxonomyCanonicalConcept $a,
        TaxonomyCanonicalConcept $b,
        array $evidenceA,
        array $evidenceB,
        array $settings,
        array $cpvQualitySettings,
    ): ?array {
        $nameSim = (float) ($this->stage('concept_relations', fn () => DB::connection('pgsql')->selectOne(
            'SELECT GREATEST(
                COALESCE(similarity(?, ?), 0),
                COALESCE(similarity(?, ?), 0),
                COALESCE(similarity(?, ?), 0),
                COALESCE(similarity(?, ?), 0)
            ) AS s',
            [
                (string) $a->canonical_name_es, (string) $b->canonical_name_es,
                (string) $a->canonical_name_en, (string) $b->canonical_name_en,
                (string) $a->canonical_name_es, (string) $b->canonical_name_en,
                (string) $a->canonical_name_en, (string) $b->canonical_name_es,
            ]
        ))->s ?? 0);

        [$sharedCpv] = $this->cpvSignals($evidenceA['cpv_rows'], $evidenceB['cpv_rows'], $cpvQualitySettings);

        $termsA = collect($evidenceA['member_strings'] ?? [])->merge($evidenceA['alias_forms'] ?? [])->map(fn ($s) => mb_strtolower(trim($s)))->filter()->unique();
        $termsB = collect($evidenceB['member_strings'] ?? [])->merge($evidenceB['alias_forms'] ?? [])->map(fn ($s) => mb_strtolower(trim($s)))->filter()->unique();
        $termOverlap = $termsA->intersect($termsB)->isNotEmpty() ? 1.0 : 0.0;

        $signals = [
            'name_similarity' => round($nameSim, 4),
            'shared_cpv' => $sharedCpv,
            'term_or_alias_overlap' => $termOverlap,
        ];

        $corroborating = 0;
        if ($signals['name_similarity'] >= $settings['concept_relations.min_lexical_similarity']) {
            $corroborating++;
        }
        if ($signals['shared_cpv'] > 0) {
            $corroborating++;
        }
        if ($signals['term_or_alias_overlap'] > 0) {
            $corroborating++;
        }

        $confidence = round(($signals['name_similarity'] + $signals['shared_cpv'] + $signals['term_or_alias_overlap']) / 3, 4);

        if ($confidence < $settings['concept_relations.review_threshold']) {
            return null;
        }

        $minCorroborating = (int) $settings['concept_relations.min_corroborating_signals'];
        $automationDecision = ($confidence >= $settings['concept_relations.auto_eligible_threshold'] && $corroborating >= $minCorroborating)
            ? 'AUTO_ELIGIBLE'
            : 'REVIEW_REQUIRED';

        $reasons = [];
        if ($signals['name_similarity'] >= $settings['concept_relations.min_lexical_similarity']) {
            $reasons[] = 'nombres de concepto lexicamente similares';
        }
        if ($signals['shared_cpv'] > 0) {
            $reasons[] = 'CPV compartido entre miembros de ambos conceptos';
        }
        if ($signals['term_or_alias_overlap'] > 0) {
            $reasons[] = 'termino o alias compartido entre miembros';
        }
        if ($corroborating < $minCorroborating) {
            $reasons[] = "solo {$corroborating} de {$minCorroborating} señales requeridas para AUTO_ELIGIBLE - queda en revisión";
        }

        return [
            'source_concept_id' => $a->id,
            'source_concept' => $a->display_name,
            'target_concept_id' => $b->id,
            'target_concept' => $b->display_name,
            'relation_type' => 'RELATED_TO',
            'direction' => 'symmetric',
            'confidence' => $confidence,
            'evidence' => $signals,
            'provenance' => ['generated_by' => 'CanonicalConceptBuilderService::proposeConceptRelations', 'generated_at' => now()->toIso8601String()],
            'context' => [],
            'automation_decision' => $automationDecision,
            'reasons' => $reasons,
        ];
    }

    // =====================================================================================
    // PHASE B - B1 (secciones 6/7 del pedido): validación de seguridad para CUALQUIER propuesta
    // de relación concepto<->concepto (tanto las auto-generadas arriba como una que un admin
    // proponga a mano para un tipo direccional/jerárquico) - direccionalidad, duplicados y
    // ciclos, ANTES de que exista ningún modo de escritura real (Phase C) que pudiera persistirla.
    // =====================================================================================

    /** @return array{valid:bool, reason:?string, possible_duplicate_of:?array} */
    public function validateConceptRelationProposal(int $sourceConceptId, int $targetConceptId, string $relationType): array
    {
        if ($sourceConceptId === $targetConceptId) {
            return ['valid' => false, 'reason' => 'SELF_RELATION_NOT_ALLOWED', 'possible_duplicate_of' => null];
        }

        $type = TaxonomyConceptRelationType::query()->where('code', $relationType)->where('active', true)->first();
        if (! $type) {
            return ['valid' => false, 'reason' => 'UNKNOWN_OR_INACTIVE_RELATION_TYPE', 'possible_duplicate_of' => null];
        }

        // Duplicado exacto (mismo sentido).
        $exact = DB::connection('pgsql')->table('taxonomy_concept_relations')
            ->where('source_concept_id', $sourceConceptId)
            ->where('target_concept_id', $targetConceptId)
            ->where('relation_type', $relationType)
            ->first();
        if ($exact) {
            return ['valid' => false, 'reason' => 'DUPLICATE', 'possible_duplicate_of' => (array) $exact];
        }

        if (! $type->directional) {
            // Simétrico (ej. RELATED_TO): (B,A) es la MISMA relación que (A,B) - no proponer dos veces.
            $reverse = DB::connection('pgsql')->table('taxonomy_concept_relations')
                ->where('source_concept_id', $targetConceptId)
                ->where('target_concept_id', $sourceConceptId)
                ->where('relation_type', $relationType)
                ->first();
            if ($reverse) {
                return ['valid' => false, 'reason' => 'DUPLICATE_VIA_SYMMETRY', 'possible_duplicate_of' => (array) $reverse];
            }
        } elseif ($type->inverse_relation_code) {
            // Direccional con inverso (ej. PART_OF/HAS_PART): (B,A,INVERSO) expresa la MISMA
            // relación semántica - proponer (A,B,TIPO) además sería una duplicación real, no un
            // hecho distinto.
            $viaInverse = DB::connection('pgsql')->table('taxonomy_concept_relations')
                ->where('source_concept_id', $targetConceptId)
                ->where('target_concept_id', $sourceConceptId)
                ->where('relation_type', $type->inverse_relation_code)
                ->first();
            if ($viaInverse) {
                return ['valid' => false, 'reason' => 'DUPLICATE_VIA_INVERSE', 'possible_duplicate_of' => (array) $viaInverse];
            }
        }

        // Ciclos: solo tiene sentido para tipos direccionales que representan una jerarquía real
        // (PART_OF/HAS_PART - "SUPERSEDES" es una cadena de reemplazo, no un contenedor, un ciclo
        // ahí también sería absurdo semánticamente pero no rompe un traversal de contención; se
        // protege igual por seguridad, mismo mecanismo, sin distinguir casos hoy).
        if ($type->directional) {
            $cycle = $this->wouldCreateCycle($sourceConceptId, $targetConceptId, $relationType, $type->inverse_relation_code);
            if ($cycle) {
                return ['valid' => false, 'reason' => 'CYCLE_DETECTED', 'possible_duplicate_of' => null];
            }
        }

        return ['valid' => true, 'reason' => null, 'possible_duplicate_of' => null];
    }

    /**
     * BFS acotado: ¿ya existe un camino target -> ... -> source usando el mismo tipo de relación
     * (o su inverso, recorrido en sentido contrario)? Si sí, agregar source->target cerraría un
     * ciclo. Acotado por `concept_relations.max_cycle_check_depth` (seguridad de costo, no de
     * negocio - el `max_depth` del catálogo de gobernanza es un concepto aparte).
     */
    private function wouldCreateCycle(int $sourceConceptId, int $targetConceptId, string $relationType, ?string $inverseType): bool
    {
        $maxDepth = (int) ($this->conceptRelationSettings()['concept_relations.max_cycle_check_depth'] ?? 20);
        $types = array_filter([$relationType, $inverseType]);

        $visited = [$targetConceptId => true];
        $frontier = [$targetConceptId];
        $depth = 0;

        while (! empty($frontier) && $depth < $maxDepth) {
            $next = DB::connection('pgsql')->table('taxonomy_concept_relations')
                ->whereIn('source_concept_id', $frontier)
                ->whereIn('relation_type', $types)
                ->pluck('target_concept_id')
                ->unique()
                ->all();

            foreach ($next as $id) {
                if ($id === $sourceConceptId) {
                    return true;
                }
            }

            $frontier = array_values(array_diff($next, array_keys($visited)));
            foreach ($frontier as $id) {
                $visited[$id] = true;
            }
            $depth++;
        }

        return false;
    }
}
