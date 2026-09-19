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

        $flagCounts = [];
        $results = [];

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

        return [
            'audited_at' => now()->toIso8601String(),
            'concept_count' => $concepts->count(),
            'flag_summary' => $flagCounts,
            'concepts' => $results,
        ];
    }

    // =====================================================================================
    // 3. DRY RUN BUILDER (secciones 8-13, 15) - CERO ESCRITURA
    // =====================================================================================

    public function dryRun(?int $termLimit = null): array
    {
        $settings = $this->settings();
        $startedAt = microtime(true);
        $startedMemory = memory_get_usage();

        DB::connection('pgsql')->enableQueryLog();

        $query = TaxonomyTerm::query()
            ->whereDoesntHave('concepts')
            ->whereDoesntHave('cpvRelations', fn ($q) => $q->where('status', 'approved'))
            ->with('aliases');

        if ($termLimit !== null) {
            $query->limit($termLimit);
        }

        $unlinkedTerms = $query->get();

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

        foreach ($unlinkedTerms as $term) {
            [$candidateConcepts, $usedVector] = $this->retrieveCandidateConcepts($term, $settings);
            $candidatePairsGenerated += $candidateConcepts->count();
            if ($usedVector) {
                $vectorCandidateTermCount++;
            }

            $bestTier = TaxonomyCandidateConceptLink::TIER_REJECT;

            foreach ($candidateConcepts as $concept) {
                $scored = $this->scoreCandidate($term, $concept, $settings);
                $pairsScored++;
                $tierCounts[$scored['tier']]++;

                if ($this->tierRank($scored['tier']) > $this->tierRank($bestTier)) {
                    $bestTier = $scored['tier'];
                }

                if (count($sampleResults) < 50) {
                    $sampleResults[] = $scored;
                }
            }

            if ($candidateConcepts->isEmpty() || $bestTier === TaxonomyCandidateConceptLink::TIER_REJECT) {
                $proposeNewConceptCount++;
            }
        }

        $queryCount = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        return [
            'mode' => 'dry_run',
            'write_barrier_respected' => true,
            'write_barrier_note' => 'CERO INSERT/UPDATE/DELETE ejecutado en esta corrida - ver Sección R (BEFORE/AFTER) del informe.',
            'terms_processed' => $unlinkedTerms->count(),
            'candidate_pairs_generated' => $candidatePairsGenerated,
            'vector_candidate_terms' => $vectorCandidateTermCount,
            'pairs_scored' => $pairsScored,
            'candidates_by_tier' => $tierCounts,
            'propose_new_concept_candidates' => $proposeNewConceptCount,
            'embedding_calls_made' => 0,
            'db_queries' => $queryCount,
            'cache' => 'not_implemented',
            'runtime_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'peak_memory_bytes' => memory_get_peak_usage() - $startedMemory,
            'sample_results' => $sampleResults,
        ];
    }

    /** @return array{0: Collection<int, TaxonomyCanonicalConcept>, 1: bool} */
    private function retrieveCandidateConcepts(TaxonomyTerm $term, array $settings): array
    {
        $vectorTopK = (int) $settings['concept_builder.vector_top_k'];
        $maxConcepts = (int) $settings['concept_builder.max_candidate_concepts_per_term'];
        $needle = trim($term->canonical_term ?: $term->term);
        $conceptIds = collect();

        $lexical = DB::connection('pgsql')->select(<<<'SQL'
            SELECT id FROM taxonomy_canonical_concepts
            WHERE (canonical_name_en IS NOT NULL AND canonical_name_en % ?)
               OR (canonical_name_es IS NOT NULL AND canonical_name_es % ?)
            ORDER BY GREATEST(COALESCE(similarity(canonical_name_en, ?), 0), COALESCE(similarity(canonical_name_es, ?), 0)) DESC
            LIMIT ?
        SQL, [$needle, $needle, $needle, $needle, $vectorTopK]);
        $conceptIds = $conceptIds->merge(collect($lexical)->pluck('id'));

        // CPV compartido (cualquier status - solo para ACOTAR el universo; la calidad real se
        // recalcula en scoreCandidate()/cpvSignals()). Boundary '/%' evita falsos positivos de
        // prefijo (ej. "CPV-05" no debe matchear "CPV-052").
        $sharedCpv = DB::connection('pgsql')->select(<<<'SQL'
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
        SQL, [$term->id, $vectorTopK]);
        $conceptIds = $conceptIds->merge(collect($sharedCpv)->pluck('concept_id'));

        $aliasOverlap = DB::connection('pgsql')->select(<<<'SQL'
            SELECT DISTINCT tc.concept_id
            FROM taxonomy_terms t2
            JOIN taxonomy_term_concepts tc ON tc.term_id = t2.id
            LEFT JOIN taxonomy_term_aliases a ON a.term_id = t2.id
            WHERE lower(t2.canonical_term) = lower(?) OR lower(t2.term) = lower(?) OR lower(a.alias) = lower(?)
            LIMIT ?
        SQL, [$needle, $needle, $needle, $vectorTopK]);
        $conceptIds = $conceptIds->merge(collect($aliasOverlap)->pluck('concept_id'));

        $serviceOverlap = DB::connection('pgsql')->select(<<<'SQL'
            SELECT DISTINCT tc.concept_id
            FROM taxonomy_term_service_relations mine
            JOIN taxonomy_term_service_relations other
                ON other.legacy_service_id = mine.legacy_service_id AND other.term_id != mine.term_id
            JOIN taxonomy_term_concepts tc ON tc.term_id = other.term_id
            WHERE mine.term_id = ?
            LIMIT ?
        SQL, [$term->id, $vectorTopK]);
        $conceptIds = $conceptIds->merge(collect($serviceOverlap)->pluck('concept_id'));

        // Vecino más cercano SOLO si ya existe un embedding persistido para este término - nunca se
        // llama al modelo de embeddings dentro del Builder (sección 9 del pedido).
        $usedVector = false;
        $termEmbedding = TaxonomyTermEmbedding::query()->where('term_id', $term->id)->value('embedding');
        if ($termEmbedding !== null) {
            $usedVector = true;
            $vectorNeighbors = DB::connection('pgsql')->select(<<<'SQL'
                SELECT tc.concept_id, MIN(te.embedding <=> ?::vector) AS dist
                FROM taxonomy_term_embeddings te
                JOIN taxonomy_term_concepts tc ON tc.term_id = te.term_id
                WHERE te.term_id != ?
                GROUP BY tc.concept_id
                ORDER BY dist ASC
                LIMIT ?
            SQL, [$termEmbedding, $term->id, $vectorTopK]);
            $conceptIds = $conceptIds->merge(collect($vectorNeighbors)->pluck('concept_id'));
        }

        $uniqueIds = $conceptIds->filter()->unique()->take($maxConcepts)->values();

        $concepts = $uniqueIds->isEmpty()
            ? collect()
            : TaxonomyCanonicalConcept::query()->whereIn('id', $uniqueIds)->with('terms')->get();

        return [$concepts, $usedVector];
    }

    // =====================================================================================
    // SCORING COMPUESTO (sección 10-12) - público para poder testearlo de forma aislada
    // =====================================================================================

    public function scoreCandidate(TaxonomyTerm $term, TaxonomyCanonicalConcept $concept, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $memberTerms = $concept->relationLoaded('terms') ? $concept->terms : $concept->terms()->get();
        $memberStrings = $memberTerms->pluck('term')->all();
        $memberIds = $memberTerms->pluck('id')->all();
        $needle = trim($term->canonical_term ?: $term->term);

        $signals = [];

        $normalizedNeedle = mb_strtolower(trim($needle));
        $signals['exact_canonical_signal'] = $memberTerms->contains(
            fn (TaxonomyTerm $m) => mb_strtolower(trim((string) $m->canonical_term)) === $normalizedNeedle
        ) ? 1.0 : 0.0;

        $signals['lexical_similarity'] = empty($memberIds) ? 0.0 : (float) (DB::connection('pgsql')->selectOne(
            'SELECT COALESCE(MAX(similarity(?, t.canonical_term)), 0) AS s
             FROM taxonomy_terms t WHERE t.id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
            array_merge([$needle], $memberIds)
        )->s ?? 0);

        $myForms = collect([$term->canonical_term, $term->term])
            ->merge($term->relationLoaded('aliases') ? $term->aliases->pluck('alias') : $term->aliases()->pluck('alias'))
            ->filter()->map(fn ($s) => mb_strtolower(trim($s)))->unique();

        $theirForms = empty($memberIds) ? collect() : collect(DB::connection('pgsql')->table('taxonomy_terms as t')
            ->leftJoin('taxonomy_term_aliases as a', 'a.term_id', '=', 't.id')
            ->whereIn('t.id', $memberIds)
            ->select('t.canonical_term', 't.term', 'a.alias')
            ->get())
            ->flatMap(fn ($row) => [$row->canonical_term, $row->term, $row->alias])
            ->filter()->map(fn ($s) => mb_strtolower(trim($s)))->unique();

        $signals['alias_overlap'] = $myForms->intersect($theirForms)->isNotEmpty() ? 1.0 : 0.0;

        [$sharedCpv, $cpvSpecificity, $cpvMappingQuality] = $this->cpvSignals($term, $memberIds, $settings);
        $signals['shared_cpv'] = $sharedCpv;
        $signals['cpv_specificity'] = $cpvSpecificity;
        $signals['cpv_mapping_quality'] = $cpvMappingQuality;

        $myServices = DB::connection('pgsql')->table('taxonomy_term_service_relations')->where('term_id', $term->id)->pluck('legacy_service_id');
        $signals['legacy_service_overlap'] = ($myServices->isEmpty() || empty($memberIds)) ? 0.0 : (
            $myServices->intersect(
                DB::connection('pgsql')->table('taxonomy_term_service_relations')->whereIn('term_id', $memberIds)->pluck('legacy_service_id')
            )->isNotEmpty() ? 1.0 : 0.0
        );

        $signals['embedding_similarity'] = $this->embeddingSimilaritySignal($term, $memberIds);

        $signals['language_equivalence'] = (
            (($term->language === 'es' && $concept->canonical_name_en) || ($term->language === 'en' && $concept->canonical_name_es))
            && $term->term_type === TaxonomyTerm::TERM_TYPE_TRANSLATION_ALIAS
        ) ? 1.0 : 0.0;

        $myVerifiedSources = DB::connection('pgsql')->table('taxonomy_term_source_bindings')
            ->where('term_id', $term->id)->where('verification_status', 'verified')->pluck('source_id');
        $signals['source_evidence'] = ($myVerifiedSources->isEmpty() || empty($memberIds)) ? 0.0 : (
            $myVerifiedSources->intersect(
                DB::connection('pgsql')->table('taxonomy_term_source_bindings')
                    ->whereIn('term_id', $memberIds)->where('verification_status', 'verified')->pluck('source_id')
            )->isNotEmpty() ? 1.0 : 0.0
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

    /** @return array{0:float,1:float,2:float} [shared_cpv, cpv_specificity, cpv_mapping_quality] */
    private function cpvSignals(TaxonomyTerm $term, array $memberIds, array $settings): array
    {
        if (empty($memberIds)) {
            return [0.0, 0.0, 0.0];
        }

        $mine = DB::connection('pgsql')->select(
            'SELECT r.relation_type, r.confidence, r.status, cat.path
             FROM taxonomy_term_cpv_relations r
             JOIN taxonomy_categories cat ON cat.id = r.category_id
             WHERE r.term_id = ?',
            [$term->id]
        );

        if (empty($mine)) {
            return [0.0, 0.0, 0.0];
        }

        $theirs = DB::connection('pgsql')->select(
            'SELECT r.relation_type, r.confidence, r.status, cat.path
             FROM taxonomy_term_cpv_relations r
             JOIN taxonomy_categories cat ON cat.id = r.category_id
             WHERE r.term_id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
            $memberIds
        );

        if (empty($theirs)) {
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

    private function embeddingSimilaritySignal(TaxonomyTerm $term, array $memberIds): float
    {
        if (empty($memberIds)) {
            return 0.0;
        }

        $termEmbedding = TaxonomyTermEmbedding::query()->where('term_id', $term->id)->value('embedding');
        if ($termEmbedding === null) {
            return 0.0;
        }

        $row = DB::connection('pgsql')->selectOne(
            'SELECT MIN(embedding <=> ?::vector) AS dist FROM taxonomy_term_embeddings
             WHERE term_id IN ('.implode(',', array_fill(0, count($memberIds), '?')).')',
            array_merge([$termEmbedding], $memberIds)
        );

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

    /** @return array<string, float> Solo las claves `concept_builder.*`, valor real de taxonomy_settings o default. */
    public function settings(): array
    {
        $defaults = TaxonomyRankingParameters::defaults();
        $stored = DB::connection('pgsql')->table('taxonomy_settings')->pluck('value', 'key');

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
