<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1 y PARTE 6: cobertura de
 * `ReviewedProposalService::preflight()`.
 *
 * Dos cosas distintas que hay que probar, y la primera es la que justifica toda la tarea:
 *
 * 1. AUSENCIA DE EFECTOS. No basta con revisar el código a ojo: cada test de bloqueo mide los
 *    statements SQL reales con `DB::listen()` y exige CERO escrituras, y además compara la fila de la
 *    propuesta COLUMNA POR COLUMNA antes y después. Si una refactorización futura metiera un efecto
 *    por descuido, estos tests fallan. Esto importa porque el preflight existe precisamente para no
 *    tener que usar `apply()` como sonda: `apply()` registra obsolescencia/tamper/drift con `abort()`,
 *    que es TERMINAL, y re-congelar está bloqueado por el índice único parcial.
 *
 * 2. PARIDAD CON `apply()`, SIN LLAMAR A `apply()`. El orquestador pidió explícitamente que la
 *    paridad se demuestre sin ejecutar un apply real. Acá se demuestra de forma estructural, que es
 *    más fuerte que comparar dos corridas: se verifica (a) que cada bloqueo del preflight tiene
 *    declarado su `ABORT_*` correspondiente para los dos tipos de origen, (b) que un bloqueo sin
 *    mapear hace lanzar en vez de abortar con un motivo inventado, y (c) que los métodos de ESCRITURA
 *    de `apply()` no contienen ni una sola llamada a `abort()` - o sea que `apply()` no puede abortar
 *    por ningún camino que no sea la cadena compartida `evaluateApplicability()`. La semántica de
 *    `apply()` en sí ya está cubierta por `ReviewedProposalServiceTest`/
 *    `ReviewedProposalConfirmationTest`, que no se duplican acá.
 *
 * `DatabaseTransactions` sobre `pgsql`: nada persiste. Ninguna propuesta/candidato/relación REAL se
 * toca - todo se crea de cero por test.
 */
class ReviewedProposalPreflightTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'update_taxonomy::candidate::concept::link',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function term(string $language = 'es'): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'c2-preflight-'.uniqid('', true),
            'term' => 'zzz_preflight_'.uniqid('', true),
            'language' => $language,
            'canonical_term' => 'zzz_preflight_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function concept(string $name): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $name,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    private function mapCandidate(): array
    {
        $term = $this->term();
        $concept = $this->concept('zzz_preflight_target_'.uniqid('', true));
        $candidate = TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);

        return [$candidate, $concept];
    }

    private function newConceptCandidate(string $language = 'es'): TaxonomyCandidateConceptLink
    {
        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $this->term($language)->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_preflight_new_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function candidateRelation(): array
    {
        $a = $this->concept('zzz_preflight_rel_a_'.uniqid());
        $b = $this->concept('zzz_preflight_rel_b_'.uniqid());
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        return [$relation, $a, $b];
    }

    private function frozenMapProposal(): array
    {
        [$candidate, $concept] = $this->mapCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => $concept->id],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return [$frozen['proposal'], $candidate, $concept];
    }

    /**
     * Corre `$callback` midiendo TODOS los statements SQL de escritura que emita, y devuelve el
     * resultado. La lista de verbos cubre DML y DDL: lo que se quiere probar es que el preflight no
     * escribe NADA, no solo que no escribe en las tablas que uno recuerda.
     */
    private function captureWrites(callable $callback, ?array &$writes = null): mixed
    {
        $observed = [];
        DB::listen(function ($query) use (&$observed) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $observed[] = $query->sql;
            }
        });

        $result = $callback();
        $writes = $observed;

        return $result;
    }

    /** Snapshot crudo de la fila, sin casts de Eloquent - para comparar columna por columna. */
    private function rawProposalRow(int $id): array
    {
        return (array) DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $id)->first();
    }

    private function auditRowCount(int $proposalId): int
    {
        return DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyReviewedProposal::class)
            ->where('entity_id', $proposalId)
            ->count();
    }

    // =========================================================================================
    // AUSENCIA DE EFECTOS - el punto central de la PARTE 1
    // =========================================================================================

    #[Test]
    public function preflight_emits_zero_write_statements_and_leaves_the_proposal_byte_identical(): void
    {
        [$proposal] = $this->frozenMapProposal();

        $before = $this->rawProposalRow($proposal->id);
        $auditBefore = $this->auditRowCount($proposal->id);
        $counts = fn () => [
            'candidates' => DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->count(),
            'concepts' => DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'term_concepts' => DB::connection('pgsql')->table('taxonomy_term_concepts')->count(),
            'relations' => DB::connection('pgsql')->table('taxonomy_concept_relations')->count(),
            'proposals' => DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->count(),
            'audit' => DB::connection('pgsql')->table('taxonomy_audit_log')->count(),
        ];
        $countsBefore = $counts();

        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight($proposal->id), $writes);

        $this->assertSame([], $writes, 'El preflight emitió statements de ESCRITURA: '.implode(' | ', $writes));
        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        $this->assertSame($before, $this->rawProposalRow($proposal->id), 'El preflight modificó alguna columna de la propuesta.');
        $this->assertSame($auditBefore, $this->auditRowCount($proposal->id), 'El preflight insertó filas de auditoría.');
        $this->assertSame($countsBefore, $counts(), 'El preflight cambió el conteo de alguna tabla de taxonomía.');
    }

    #[Test]
    public function preflight_of_a_stale_proposal_reports_it_without_aborting_it(): void
    {
        // Esta es LA razón de ser del preflight: con `apply()`, descubrir la obsolescencia la habría
        // dejado en ABORTED de forma terminal e irrecuperable (re-congelar está bloqueado por el
        // índice único parcial).
        [$proposal] = $this->frozenMapProposal();
        $this->concept('zzz_preflight_stale_trigger_'.uniqid()); // cambia dryRunInputFingerprint()

        $before = $this->rawProposalRow($proposal->id);
        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight($proposal->id), $writes);

        $this->assertSame([], $writes);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_STALE_TAXONOMY_STATE, $report['blocker']);
        $this->assertSame(ReviewedProposalService::CATEGORY_NEEDS_REVALIDATION, $report['governance_category']);
        $this->assertTrue($report['stale']);
        $this->assertNotSame($report['taxonomy_state_fingerprint_frozen'], $report['taxonomy_state_fingerprint_current']);

        // La propuesta sigue viva y entera.
        $this->assertSame($before, $this->rawProposalRow($proposal->id));
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->application_result);

        // Y el informe advierte explícitamente lo que pasaría si alguien usara apply() como sonda.
        $this->assertSame(ReviewedProposalService::ABORT_STALE_TAXONOMY_STATE, $report['would_apply_abort_with']);
        $this->assertSame(2, $report['expected_write_count']);
        $this->assertStringContainsString('quemaría', $report['expected_write_set_note']);
    }

    #[Test]
    public function preflight_never_takes_the_group_advisory_lock(): void
    {
        // Si el preflight tomara el advisory lock del grupo podría DEMORAR un apply() real, y eso ya
        // sería un efecto observable - no solo una escritura.
        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$this->newConceptCandidate('es')->id, $this->newConceptCandidate('en')->id],
            'zzz_preflight_grupo_es_'.uniqid(),
            'zzz_preflight_group_en_'.uniqid(),
            $this->authorizedUser(),
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        (new ReviewedProposalService())->preflight($frozen['proposals'][0]->id);

        $this->assertFalse(
            ReviewedProposalService::holdsGroupAdvisoryLock($frozen['group_id']),
            'El preflight tomó el advisory lock del grupo - eso puede bloquear un apply() real.',
        );
    }

    // =========================================================================================
    // VOCABULARIO DE BLOQUEO - un test por bloqueo que el orquestador pidió distinguir
    // =========================================================================================

    #[Test]
    public function preflight_reports_ready_to_apply_with_the_exact_write_set_apply_would_perform(): void
    {
        [$proposal, $candidate, $concept] = $this->frozenMapProposal();

        $report = (new ReviewedProposalService())->preflight($proposal->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        $this->assertSame(ReviewedProposalService::CATEGORY_READY_TO_APPLY, $report['governance_category']);
        $this->assertNull($report['would_apply_abort_with']);
        $this->assertTrue($report['payload_fingerprint_valid']);
        $this->assertFalse($report['stale']);
        $this->assertTrue($report['source_entity_exists']);
        $this->assertTrue($report['source_state_compatible']);
        $this->assertFalse($report['source_snapshot_drift']);
        $this->assertTrue($report['human_confirmation_satisfied']);

        // MAP_TO_EXISTING: link término→concepto + candidato a published + contabilidad (propuesta y
        // auditoría) = 4 filas. Ni un concepto nuevo.
        $tables = array_column($report['expected_write_set'], 'table');
        $this->assertSame([
            'taxonomy_term_concepts',
            'taxonomy_candidate_concept_links',
            'taxonomy_reviewed_proposals',
            'taxonomy_audit_log',
        ], $tables);
        $this->assertSame(4, $report['expected_write_count']);
        $this->assertNotContains('taxonomy_canonical_concepts', $tables);

        // Y nada de eso se escribió.
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $concept->id)->count());
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function preflight_detects_a_payload_edited_directly_in_the_database(): void
    {
        [$proposal] = $this->frozenMapProposal();

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['decision' => TaxonomyReviewedProposal::DECISION_REJECT]);

        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight($proposal->id), $writes);

        $this->assertSame([], $writes);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED, $report['blocker']);
        $this->assertFalse($report['payload_fingerprint_valid']);
        // Tamper NO es "hay que revalidar": es un hallazgo de seguridad.
        $this->assertSame(ReviewedProposalService::CATEGORY_BLOCKED_FOR_OTHER_REASON, $report['governance_category']);
        $this->assertStringContainsString('INVESTIGAR', $report['action_required']);

        // Las compuertas posteriores NO se evalúan, y el informe lo dice con null en vez de inventar
        // que pasaron.
        $this->assertNull($report['taxonomy_state_fingerprint_current']);
        $this->assertNull($report['stale']);
        $this->assertNull($report['source_entity_exists']);
    }

    #[Test]
    public function preflight_reports_a_pending_human_confirmation_as_a_non_terminal_blocker(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => $concept->id],
            preparedByActorType: TaxonomyReviewedProposal::ACTOR_AGENT,
        );

        $report = (new ReviewedProposalService())->preflight($frozen['proposal']->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED, $report['blocker']);
        $this->assertFalse($report['human_confirmation_satisfied']);
        $this->assertTrue($report['confirmation']['required']);
        $this->assertNull($report['confirmation']['confirmed_at']);
        $this->assertSame([$frozen['proposal']->id], $report['detail']['unconfirmed_proposal_ids']);

        // Lo que distingue esta compuerta de un abort: apply() NO la quema, así que el write-set
        // esperado es CERO, no las dos escrituras del aborto.
        $this->assertNull($report['would_apply_abort_with']);
        $this->assertSame([], $report['expected_write_set']);
        $this->assertSame(0, $report['expected_write_count']);
        $this->assertStringContainsString('NO es un abort', $report['expected_write_set_note']);
    }

    #[Test]
    public function preflight_detects_source_drift_of_the_frozen_snapshot(): void
    {
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $this->authorizedUser(),
            ['new_concept_name' => 'Nombre elegido por el revisor '.uniqid()],
        );

        // Deriva la fila FUENTE sin tocar la taxonomía publicada (si no, ganaría la obsolescencia).
        DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
            ->where('id', $candidate->id)
            ->update(['suggested_new_concept_name' => 'editado después de freeze '.uniqid()]);

        $report = (new ReviewedProposalService())->preflight($frozen['proposal']->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_SOURCE_DRIFT, $report['blocker']);
        $this->assertSame(ReviewedProposalService::CATEGORY_NEEDS_REVALIDATION, $report['governance_category']);
        $this->assertTrue($report['source_snapshot_drift']);
        $this->assertFalse($report['stale']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $report['would_apply_abort_with']);
    }

    #[Test]
    public function preflight_detects_a_source_candidate_already_resolved_by_another_path(): void
    {
        [$proposal, $candidate] = $this->frozenMapProposal();

        DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
            ->where('id', $candidate->id)
            ->update(['status' => TaxonomyCandidateConceptLink::STATUS_REJECTED]);

        $report = (new ReviewedProposalService())->preflight($proposal->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_SOURCE_ALREADY_RESOLVED, $report['blocker']);
        $this->assertTrue($report['source_entity_exists']);
        $this->assertFalse($report['source_state_compatible']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $report['source_state']);
        // El preflight lo reporta como UN hallazgo; apply() conserva la constante histórica por tipo.
        $this->assertSame(ReviewedProposalService::ABORT_CANDIDATE_ALREADY_RESOLVED, $report['would_apply_abort_with']);
    }

    #[Test]
    public function preflight_detects_a_missing_target_concept(): void
    {
        // El concepto DESTINO de la decisión es distinto del concepto SUGERIDO del candidato: si se
        // borrara el sugerido, la FK en cascada se llevaría el candidato y con él la propuesta, y el
        // test probaría otra cosa (ENTITY_MISSING del candidato, no del destino).
        [$candidate] = $this->mapCandidate();
        $redirectTarget = $this->concept('zzz_preflight_redirect_'.uniqid('', true));
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => $redirectTarget->id],
        );
        $proposal = $frozen['proposal'];

        // Borrar el concepto cambia el grafo publicado, así que la obsolescencia ganaría primero.
        // Se neutraliza alineando el fingerprint congelado con el actual DESPUÉS del borrado: así el
        // test aísla la compuerta de existencia, que es lo que quiere probar. El payload_fingerprint
        // se recomputa para que la fila siga siendo coherente y no dispare tamper.
        DB::connection('pgsql')->table('taxonomy_canonical_concepts')->where('id', $redirectTarget->id)->delete();
        $this->realignFingerprints($proposal->id);
        $this->assertNotNull($proposal->fresh(), 'La propuesta tiene que sobrevivir al borrado del concepto destino.');

        $report = (new ReviewedProposalService())->preflight($proposal->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_ENTITY_MISSING, $report['blocker']);
        $this->assertTrue($report['payload_fingerprint_valid']);
        $this->assertFalse($report['stale']);
        $this->assertSame(ReviewedProposalService::ABORT_ENTITY_MISSING, $report['would_apply_abort_with']);
    }

    #[Test]
    public function preflight_runs_the_real_server_side_relation_validation(): void
    {
        [$relation, $a, $b] = $this->candidateRelation();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            $this->authorizedUser(),
        );

        $ready = (new ReviewedProposalService())->preflight($frozen['proposal']->id);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $ready['blocker']);
        $this->assertTrue($ready['relation_validation']['valid']);

        // Aparece una relación equivalente YA aprobada mientras esta esperaba: la validación
        // server-side (duplicado/simétrica/inversa/ciclo) tiene que correr de verdad, no estimarse.
        //
        // Se siembra la relación INVERSA (B->A), no un duplicado exacto, y la razón es concreta: la
        // tabla tiene UNIQUE(source_concept_id, target_concept_id, relation_type), así que un
        // duplicado exacto no se puede insertar por NINGÚN camino - ni con el query builder. `RELATED_TO`
        // es no-direccional, así que (B,A) ES la misma relación semántica y la validación la rechaza
        // con `DUPLICATE_VIA_SYMMETRY`. Eso hace al test más fuerte: una reimplementación ingenua del
        // preflight que sólo buscara duplicados exactos NO detectaría esto.
        //
        // Se inserta con el query builder y no con el modelo porque el guard de
        // `TaxonomyConceptRelation::booted()` rechaza -correctamente- crear una relación aprobada que
        // conflictúa con una existente, así que por el modelo este estado no se puede sembrar. Ese
        // guard tiene su propia cobertura en `TaxonomyConceptRelationValidationTest`; acá sólo hace
        // falta el ESTADO, para probar que el preflight lo detecta.
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->realignFingerprints($frozen['proposal']->id);

        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight($frozen['proposal']->id), $writes);

        $this->assertSame([], $writes);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_RELATION_VALIDATION_FAILED, $report['blocker']);
        $this->assertSame(ReviewedProposalService::CATEGORY_NEEDS_REVALIDATION, $report['governance_category']);
        $this->assertFalse($report['relation_validation']['valid']);
        $this->assertSame('DUPLICATE_VIA_SYMMETRY', $report['relation_validation']['reason'], 'El preflight tiene que correr la validación REAL, incluida la regla de simetría - no sólo buscar duplicados exactos.');
        $this->assertSame(ReviewedProposalService::ABORT_RELATION_INVALID_AT_APPLY_TIME, $report['would_apply_abort_with']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);
    }

    #[Test]
    public function preflight_of_a_relation_reject_marks_source_drift_as_not_applicable(): void
    {
        [$relation] = $this->candidateRelation();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            TaxonomyReviewedProposal::DECISION_REJECT,
            $this->authorizedUser(),
        );

        $report = (new ReviewedProposalService())->preflight($frozen['proposal']->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        // REJECT es la única decisión que deliberadamente no depende de ningún campo fuente
        // congelado: el drift no es "ok", es "no aplica", y el informe no finge lo contrario.
        $this->assertFalse($report['source_snapshot_drift_applicable']);
        $this->assertNull($report['source_snapshot_drift']);
        $this->assertNull($report['relation_validation'], 'REJECT no publica el grafo, así que no corre la validación server-side.');

        // REJECT de una relación: una fila de la relación + contabilidad = 3.
        $this->assertSame(['taxonomy_concept_relations', 'taxonomy_reviewed_proposals', 'taxonomy_audit_log'], array_column($report['expected_write_set'], 'table'));
        $this->assertSame(3, $report['expected_write_count']);
        $this->assertStringContainsString('NO publica', $report['expected_write_set'][0]['description']);
    }

    #[Test]
    public function preflight_of_a_bilingual_group_describes_one_concept_for_all_members(): void
    {
        $es = $this->newConceptCandidate('es');
        $en = $this->newConceptCandidate('en');
        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$es->id, $en->id],
            'zzz_preflight_grupo_es_'.uniqid(),
            'zzz_preflight_group_en_'.uniqid(),
            $this->authorizedUser(),
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        $report = (new ReviewedProposalService())->preflight($frozen['proposals'][0]->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        $this->assertSame($frozen['group_id'], $report['group']['proposal_group_id']);
        $this->assertCount(2, $report['group']['member_ids']);
        $this->assertCount(2, $report['group']['members_pending_apply']);
        $this->assertTrue($report['group']['consistent']);
        $this->assertNull($report['group']['reuses_concept_id']);

        // El invariante de la sección D de TASK-0006B, dicho en el write-set: UN solo concepto para
        // los dos candidatos, nunca uno por candidato. Y cero relaciones TÉRMINO→CPV.
        $tables = array_column($report['expected_write_set'], 'table');
        $this->assertSame(1, count(array_filter($tables, fn ($t) => $t === 'taxonomy_canonical_concepts')));
        $this->assertSame(2, count(array_filter($tables, fn ($t) => $t === 'taxonomy_term_concepts')));
        $this->assertSame(2, count(array_filter($tables, fn ($t) => $t === 'taxonomy_candidate_concept_links')));
        $this->assertNotContains('taxonomy_term_cpv_relations', $tables);
    }

    #[Test]
    public function preflight_of_a_group_whose_sibling_is_no_longer_applicable_reports_group_inconsistency(): void
    {
        [$entry, $sibling] = $this->frozenBilingualGroup();

        // El hermano deja de ser aplicable sin tocar la taxonomía publicada y SIN romper su
        // fingerprint: se mueve su `status`, que NO es uno de los 9 campos cubiertos por el
        // `payload_fingerprint`. Esto importa desde el re-audit `5952211890`: antes este test mutaba
        // `decision`, que SÍ está cubierto, así que ahora produciría -correctamente- TAMPER_DETECTED y
        // dejaría sin probar la compuerta de coherencia de grupo, que es lo que quiere verificar.
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $sibling->id)
            ->update(['status' => TaxonomyReviewedProposal::STATUS_ABORTED]);

        $report = (new ReviewedProposalService())->preflight($entry->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT, $report['blocker']);
        $this->assertFalse($report['group']['consistent']);
        // El payload del hermano sigue intacto: el problema es su ciclo de vida, no una manipulación.
        $this->assertTrue($report['group']['all_member_payloads_valid']);
        $this->assertSame([], $report['group']['tampered_member_ids']);
        $this->assertSame(ReviewedProposalService::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE, $report['would_apply_abort_with']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $entry->fresh()->status);
    }

    // =========================================================================================
    // TASK-0006D re-audit (Issue #2 comentario `5952211890`): integridad de payload POR MIEMBRO del
    // grupo bilingüe, y semántica terminal de grupo.
    // =========================================================================================

    /**
     * Congela un grupo bilingüe de 2 miembros y devuelve [propuestaEs, propuestaEn, groupId].
     *
     * @return array{0:TaxonomyReviewedProposal,1:TaxonomyReviewedProposal,2:string}
     */
    private function frozenBilingualGroup(): array
    {
        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$this->newConceptCandidate('es')->id, $this->newConceptCandidate('en')->id],
            'zzz_preflight_grupo_es_'.uniqid(),
            'zzz_preflight_group_en_'.uniqid(),
            $this->authorizedUser(),
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return [$frozen['proposals'][0], $frozen['proposals'][1], $frozen['group_id']];
    }

    /**
     * Manipula un campo de decisión de una propuesta YA congelada, por fuera del servicio, de forma
     * que su `payload_fingerprint` deje de coincidir. Es exactamente el ataque que la tamper-detection
     * existe para detectar.
     */
    private function tamperWithPayload(TaxonomyReviewedProposal $proposal): void
    {
        $payload = $proposal->decision_payload;
        $payload['canonical_name_es'] = 'nombre inyectado por fuera del servicio '.uniqid();

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['decision_payload' => json_encode($payload)]);
    }

    #[Test]
    public function preflight_detects_a_tampered_sibling_when_entering_through_the_healthy_one(): void
    {
        // EL DEFECTO QUE ESTO CUBRE (re-audit `5952211890`): el grupo validaba el payload sólo de la
        // propuesta de ENTRADA, mientras el apply publicaba y marcaba APPLIED a TODOS los hermanos
        // pendientes. Entrar por el hermano sano tiene que detectar al hermano manipulado.
        [$entry, $sibling] = $this->frozenBilingualGroup();
        $this->tamperWithPayload($sibling);

        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight($entry->id), $writes);

        $this->assertSame([], $writes);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED, $report['blocker']);
        $this->assertSame($sibling->id, $report['detail']['tampered_proposal_id'], 'El diagnóstico tiene que nombrar la propuesta ofensora.');
        $this->assertSame($entry->id, $report['detail']['entry_proposal_id']);

        // El payload de la fila de ENTRADA es válido: el problema está en el hermano. Reportar `false`
        // acá sería acusar a una fila intacta.
        $this->assertTrue($report['payload_fingerprint_valid']);

        // Y la evidencia de grupo lo dice con precisión, miembro por miembro.
        $this->assertFalse($report['group']['all_member_payloads_valid']);
        $this->assertSame([$sibling->id], $report['group']['tampered_member_ids']);
        $this->assertFalse($report['group']['member_payload_fingerprint_valid'][(string) $sibling->id]);

        $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $report['would_apply_abort_with']);
    }

    #[Test]
    public function the_tampered_group_gives_the_same_safety_result_through_either_sibling(): void
    {
        // Requisito explícito del re-audit: «Entry by #629 or #630 must produce the same safety result
        // for the same damaged group.»
        [$first, $second] = $this->frozenBilingualGroup();
        $this->tamperWithPayload($second);

        $service = new ReviewedProposalService();
        $throughHealthy = $service->preflight($first->id);
        $throughTampered = $service->preflight($second->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED, $throughHealthy['blocker']);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED, $throughTampered['blocker']);
        $this->assertSame($second->id, $throughHealthy['detail']['tampered_proposal_id']);
        $this->assertSame($second->id, $throughTampered['detail']['tampered_proposal_id']);
        $this->assertSame($throughHealthy['governance_category'], $throughTampered['governance_category']);

        // La diferencia legítima entre los dos informes: entrando por la manipulada, su PROPIO payload
        // es inválido y la cadena corta en la compuerta de entrada, antes de mirar el grupo.
        $this->assertTrue($throughHealthy['payload_fingerprint_valid']);
        $this->assertFalse($throughTampered['payload_fingerprint_valid']);
    }

    #[Test]
    public function a_healthy_group_reports_every_member_payload_as_valid(): void
    {
        [$entry, $sibling] = $this->frozenBilingualGroup();

        $report = (new ReviewedProposalService())->preflight($entry->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        $this->assertTrue($report['group']['all_member_payloads_valid']);
        $this->assertSame([], $report['group']['tampered_member_ids']);
        $this->assertSame(
            [true, true],
            [$report['group']['member_payload_fingerprint_valid'][(string) $entry->id], $report['group']['member_payload_fingerprint_valid'][(string) $sibling->id]],
        );
    }

    #[Test]
    public function apply_publishes_nothing_when_any_group_sibling_payload_is_tampered(): void
    {
        // Fixtures desechables dentro de `DatabaseTransactions`: ninguna propuesta real participa y
        // nada persiste. Es la contraparte de ejecución del test de preflight de arriba - lo que el
        // re-audit pide probar es que el camino de ESCRITURA tampoco se deja engañar.
        [$entry, $sibling, $groupId] = $this->frozenBilingualGroup();
        $this->tamperWithPayload($sibling);

        $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $termConceptsBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        $outcome = (new ReviewedProposalService())->apply($entry->id, 'TASK-0006D re-audit test-suite 5952211890');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $outcome['abort_reason']);
        $this->assertSame($sibling->id, $outcome['application_result']['tampered_proposal_id']);

        // CERO publicación de taxonomía: ni un concepto, ni un mapeo término->concepto, ni un
        // candidato publicado.
        $this->assertSame($conceptsBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $this->assertSame($termConceptsBefore, DB::connection('pgsql')->table('taxonomy_term_concepts')->count());
        foreach ([$entry, $sibling] as $member) {
            $this->assertSame(
                TaxonomyCandidateConceptLink::STATUS_PENDING,
                TaxonomyCandidateConceptLink::query()->findOrFail($member->candidate_link_id)->status,
                'Ningún candidato del grupo puede quedar publicado tras un aborto por tamper.',
            );
        }
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('proposal_group_id', $groupId)->where('status', TaxonomyReviewedProposal::STATUS_APPLIED)->count());
    }

    #[Test]
    public function a_terminal_group_blocker_aborts_every_still_pending_member_and_strands_none(): void
    {
        // Punto B del re-audit: antes, un bloqueo terminal detectado en un hermano abortaba SÓLO la
        // propuesta de entrada y dejaba al hermano PENDING_APPLY dentro de un grupo ya fallido -
        // aparentemente aplicable, cuando aplicarlo solo crearía el concepto con un único término.
        [$entry, $sibling, $groupId] = $this->frozenBilingualGroup();
        $this->tamperWithPayload($sibling);

        (new ReviewedProposalService())->apply($entry->id, 'TASK-0006D re-audit test-suite 5952211890');

        $members = TaxonomyReviewedProposal::query()->where('proposal_group_id', $groupId)->orderBy('id')->get();

        $this->assertCount(2, $members);
        $this->assertSame(0, $members->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count(), 'No puede quedar ningún hermano varado en PENDING_APPLY.');
        foreach ($members as $member) {
            $this->assertSame(TaxonomyReviewedProposal::STATUS_ABORTED, $member->status);
            $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $member->application_result['abort_reason']);
            $this->assertTrue($member->application_result['group_terminal_failure']);
            $this->assertSame($sibling->id, $member->application_result['detected_on_proposal_id'], 'Cada fila tiene que decir DÓNDE se detectó el problema, para que un hermano sin defecto propio sea explicable.');
            $this->assertEqualsCanonicalizing([$entry->id, $sibling->id], $member->application_result['group_aborted_proposal_ids']);
        }

        // Una fila de auditoría de ejecución POR MIEMBRO, no una sola para el grupo.
        foreach ($members as $member) {
            $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_audit_log')
                ->where('entity_type', TaxonomyReviewedProposal::class)
                ->where('entity_id', $member->id)
                ->where('new_value', TaxonomyReviewedProposal::STATUS_ABORTED)
                ->count(), "Falta la fila de auditoría del aborto del miembro #{$member->id}.");
        }
    }

    #[Test]
    public function a_pending_human_confirmation_is_not_terminal_and_never_aborts_the_group(): void
    {
        // Requisito explícito: «HUMAN_CONFIRMATION_REQUIRED remains non-terminal and must not abort
        // the group.» Es lo que impide que un apply() prematuro queme un grupo entero sólo porque
        // todavía nadie lo confirmó.
        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$this->newConceptCandidate('es')->id, $this->newConceptCandidate('en')->id],
            'zzz_preflight_grupo_es_'.uniqid(),
            'zzz_preflight_group_en_'.uniqid(),
            $this->authorizedUser(),
            preparedByActorType: TaxonomyReviewedProposal::ACTOR_AGENT,
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);
        $groupId = $frozen['group_id'];

        $outcome = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006D re-audit test-suite 5952211890');

        $this->assertSame(ReviewedProposalService::RESULT_HUMAN_CONFIRMATION_REQUIRED, $outcome['result']);
        $this->assertNull($outcome['abort_reason']);

        $members = TaxonomyReviewedProposal::query()->where('proposal_group_id', $groupId)->get();
        $this->assertCount(2, $members);
        foreach ($members as $member) {
            $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $member->status, 'La compuerta de confirmación no puede quemar el grupo.');
            $this->assertNull($member->application_result);
        }
    }

    #[Test]
    public function preflight_of_a_nonexistent_proposal_reports_not_found_without_writing(): void
    {
        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflight(999999999), $writes);

        $this->assertSame([], $writes);
        $this->assertSame(ReviewedProposalService::PREFLIGHT_NOT_FOUND, $report['blocker']);
        $this->assertSame(0, $report['expected_write_count']);
    }

    #[Test]
    public function preflight_all_evaluates_every_requested_proposal_in_id_order(): void
    {
        [$first] = $this->frozenMapProposal();
        [$second] = $this->frozenMapProposal();

        $writes = [];
        $report = $this->captureWrites(fn () => (new ReviewedProposalService())->preflightAll([$second->id, $first->id]), $writes);

        $this->assertSame([], $writes);
        $this->assertSame([$first->id, $second->id], array_column($report, 'proposal_id'));
    }

    // =========================================================================================
    // PARIDAD CON apply(), SIN LLAMAR A apply() - PARTE 6
    // =========================================================================================

    #[Test]
    public function every_preflight_blocker_declares_the_apply_abort_reason_it_predicts(): void
    {
        $expected = [
            ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED => ReviewedProposalService::ABORT_TAMPER_DETECTED,
            ReviewedProposalService::PREFLIGHT_STALE_TAXONOMY_STATE => ReviewedProposalService::ABORT_STALE_TAXONOMY_STATE,
            ReviewedProposalService::PREFLIGHT_ENTITY_MISSING => ReviewedProposalService::ABORT_ENTITY_MISSING,
            ReviewedProposalService::PREFLIGHT_SOURCE_DRIFT => ReviewedProposalService::ABORT_SOURCE_DRIFT,
            ReviewedProposalService::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT => ReviewedProposalService::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE,
            ReviewedProposalService::PREFLIGHT_RELATION_VALIDATION_FAILED => ReviewedProposalService::ABORT_RELATION_INVALID_AT_APPLY_TIME,
        ];

        foreach ($expected as $blocker => $abortReason) {
            foreach ([TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION] as $type) {
                $this->assertSame(
                    $abortReason,
                    ReviewedProposalService::applyAbortReasonForPreflightBlocker($blocker, $type),
                    "El bloqueo {$blocker} no predice el abort real de apply() para {$type}.",
                );
            }
        }

        // El único bloqueo que depende del tipo de origen: el preflight lo reporta como un solo
        // hallazgo, apply() conserva las dos constantes históricas.
        $this->assertSame(
            ReviewedProposalService::ABORT_CANDIDATE_ALREADY_RESOLVED,
            ReviewedProposalService::applyAbortReasonForPreflightBlocker(ReviewedProposalService::PREFLIGHT_SOURCE_ALREADY_RESOLVED, TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK),
        );
        $this->assertSame(
            ReviewedProposalService::ABORT_RELATION_ALREADY_RESOLVED,
            ReviewedProposalService::applyAbortReasonForPreflightBlocker(ReviewedProposalService::PREFLIGHT_SOURCE_ALREADY_RESOLVED, TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION),
        );

        // Y los que NO abortan: `null` es parte de la paridad, no una omisión - un apply() prematuro
        // sobre algo sin confirmar o ya resuelto no quema nada.
        foreach ([
            ReviewedProposalService::PREFLIGHT_READY_TO_APPLY,
            ReviewedProposalService::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED,
            ReviewedProposalService::PREFLIGHT_ALREADY_APPLIED,
            ReviewedProposalService::PREFLIGHT_ALREADY_ABORTED,
            ReviewedProposalService::PREFLIGHT_NOT_FOUND,
        ] as $blocker) {
            $this->assertNull(ReviewedProposalService::applyAbortReasonForPreflightBlocker($blocker, TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK));
        }
    }

    #[Test]
    public function an_unmapped_blocker_throws_instead_of_aborting_with_an_invented_reason(): void
    {
        $this->expectException(\LogicException::class);

        ReviewedProposalService::applyAbortReasonForPreflightBlocker('BLOQUEO_INVENTADO', TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK);
    }

    #[Test]
    public function the_write_methods_of_apply_contain_no_abort_call_so_only_the_shared_chain_can_abort(): void
    {
        // Paridad ESTRUCTURAL, sin ejecutar ningún apply: si los métodos de escritura no pueden
        // abortar, entonces todo aborto de apply() sale de `evaluateApplicability()`, que es
        // exactamente la misma función que corre el preflight. Una divergencia futura (alguien vuelve
        // a meter una validación dentro de una rama de escritura) rompe este test.
        $reflection = new \ReflectionClass(ReviewedProposalService::class);
        $source = file($reflection->getFileName());

        foreach (['writeCandidateLinkDecision', 'writeBilingualGroupCreateNew', 'writeConceptRelationDecision'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            $this->assertStringNotContainsString('$this->abort(', $body, "{$methodName}() aborta por su cuenta: la validación volvió a divergir de la cadena compartida con el preflight.");
        }

        // Y la contraparte: la cadena compartida es la ÚNICA que decide bloqueos, y `apply()` la
        // llama con locks mientras `preflight()` la llama sin locks.
        $applyBody = implode('', array_slice($source, $reflection->getMethod('apply')->getStartLine() - 1, $reflection->getMethod('apply')->getEndLine() - $reflection->getMethod('apply')->getStartLine() + 1));
        $this->assertStringContainsString('evaluateApplicability($proposal, lockRows: true)', $applyBody);

        $preflightBody = implode('', array_slice($source, $reflection->getMethod('preflightProposal')->getStartLine() - 1, $reflection->getMethod('preflightProposal')->getEndLine() - $reflection->getMethod('preflightProposal')->getStartLine() + 1));
        $this->assertStringContainsString('evaluateApplicability($proposal, lockRows: false)', $preflightBody);
        $this->assertStringNotContainsString('->apply(', $preflightBody, 'El preflight no puede llamar a apply() por ningún camino.');
    }

    /**
     * Re-alinea el `taxonomy_state_fingerprint` de una propuesta con el estado ACTUAL y recomputa su
     * `payload_fingerprint` para que la fila siga siendo internamente coherente.
     *
     * Existe solo para AISLAR compuertas en los tests: varias de ellas (entidad faltante, relación
     * inválida) requieren cambiar el grafo publicado, y ese cambio dispararía la obsolescencia, que
     * corre antes. Sin esto no se podría probar que las compuertas posteriores existen de verdad.
     * No es un camino de producción: `freeze()` es el único que escribe estos dos campos, y el
     * trigger/los tests de inmutabilidad lo cubren aparte.
     */
    private function realignFingerprints(int $proposalId): void
    {
        $proposal = TaxonomyReviewedProposal::query()->findOrFail($proposalId);
        $current = \App\Services\Taxonomy\CanonicalConceptBuilderService::dryRunInputFingerprint();

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposalId)->update([
            'taxonomy_state_fingerprint' => $current,
            'payload_fingerprint' => ReviewedProposalService::computePayloadFingerprint([
                'proposal_type' => $proposal->proposal_type,
                'candidate_link_id' => $proposal->candidate_link_id,
                'concept_relation_id' => $proposal->concept_relation_id,
                'decision' => $proposal->decision,
                'decision_payload' => $proposal->decision_payload ?? [],
                'payload_version' => $proposal->payload_version,
                'taxonomy_state_fingerprint' => $current,
                'reviewer_id' => $proposal->reviewer_id,
                'reviewed_at' => $proposal->reviewed_at->format('Y-m-d H:i:s'),
            ]),
        ]);
    }
}
