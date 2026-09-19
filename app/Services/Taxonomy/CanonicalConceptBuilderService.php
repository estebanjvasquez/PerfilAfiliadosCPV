<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
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

            foreach ($candidateConcepts as $concept) {
                if (! isset($conceptEvidenceCache[$concept->id])) {
                    $conceptEvidenceCache[$concept->id] = $this->precomputeConceptEvidence($concept);
                }

                $scored = $this->scoreCandidate($term, $concept, $settings, $termEvidence, $conceptEvidenceCache[$concept->id], $embeddingsAvailable);
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
            }
        }

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

    /** @return array<string, float> Solo las claves `concept_builder.*`, valor real de taxonomy_settings o default. */
    public function settings(): array
    {
        $defaults = TaxonomyRankingParameters::defaults();
        $stored = $this->stage('settings', fn () => DB::connection('pgsql')->table('taxonomy_settings')->pluck('value', 'key'));

        $merged = [];
        foreach ($defaults as $key => $default) {
            if (! str_starts_with($key, 'concept_builder.')) {
                continue;
            }
            $merged[$key] = isset($stored[$key]) ? (float) $stored[$key] : (float) $default;
        }

        return $merged;
    }
}
