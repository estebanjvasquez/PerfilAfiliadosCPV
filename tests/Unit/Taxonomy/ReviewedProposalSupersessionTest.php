<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0006E (Issue #2 comentario `5955148859`): cobertura del ciclo de vida **SUPERSEDED** no
 * destructivo - la transición que retira de la cola una revisión obsoleta sin destruirla, para que su
 * candidato vuelva a la revisión humana normal contra el estado ACTUAL.
 *
 * Lo que estos tests tienen que demostrar, en orden de importancia:
 *
 * 1. **NO DESTRUCTIVA.** La fila predecesora queda ÍNTEGRA: decisión, payload, los DOS fingerprints,
 *    revisor, `reviewed_at` y la fila fuente sin un solo cambio. Se compara campo por campo, porque
 *    «no destructiva» es la propiedad entera de este diseño y no alcanza con confiar en que el UPDATE
 *    liste pocas columnas.
 * 2. **LIBERA EL SLOT.** El índice único parcial filtra por `status = 'PENDING_APPLY'`, así que salir
 *    de ese estado devuelve el candidato a la cola - y entonces se puede congelar una propuesta NUEVA
 *    mientras la vieja sigue `SUPERSEDED`. Eso es lo que convierte un callejón sin salida en un
 *    camino de recuperación.
 * 3. **SOLO OBSOLETAS.** Una propuesta vigente no se puede retirar por este camino.
 * 4. **TERMINAL.** `apply()`/`preflight()` tratan `SUPERSEDED` como estado histórico y no lo pisan; el
 *    trigger de base de datos además prohíbe salir de él y reescribir el rastro.
 * 5. **CERO PUBLICACIÓN.** Ningún concepto, mapeo, candidato publicado ni relación aprobada.
 *
 * `DatabaseTransactions` sobre `pgsql`: nada persiste. **Ninguna propuesta/candidato/relación REAL se
 * toca** - todo se crea de cero por test. Y ningún test llama a `apply()` esperando que publique.
 */
class ReviewedProposalSupersessionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const REFERENCE = 'Issue #2 — TASK-0006E test-suite 5955148859';

    private const REASON = 'Prueba automatizada: el estado de la taxonomía cambió desde la revisión.';

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
            'external_id' => 'c2-supersede-'.uniqid('', true),
            'term' => 'zzz_supersede_'.uniqid('', true),
            'language' => $language,
            'canonical_term' => 'zzz_supersede_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function newConceptCandidate(string $language = 'es'): TaxonomyCandidateConceptLink
    {
        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $this->term($language)->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_supersede_new_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    /** Cambia el grafo publicado, que es lo que mueve `dryRunInputFingerprint()`. */
    private function changeTaxonomyState(): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_supersede_state_change_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    /**
     * Congela una revisión CONTEXT_REQUIRED y después deja el estado obsoleto, igual que la historia
     * real de #420/#421/#422: se revisó, y luego aparecieron conceptos nuevos.
     *
     * @return array{0:TaxonomyReviewedProposal,1:TaxonomyCandidateConceptLink}
     */
    private function staleFrozenProposal(): array
    {
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $this->authorizedUser(),
            ['context_reason' => 'Demasiado genérico para un mapeo directo (fixture).'],
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        $this->changeTaxonomyState();

        return [$frozen['proposal'], $candidate];
    }

    private function rawRow(int $id): array
    {
        return (array) DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $id)->first();
    }

    private function publishedCounts(): array
    {
        $c = DB::connection('pgsql');

        return [
            'concepts' => $c->table('taxonomy_canonical_concepts')->count(),
            'term_concepts' => $c->table('taxonomy_term_concepts')->count(),
            'published_candidates' => $c->table('taxonomy_candidate_concept_links')->where('status', TaxonomyCandidateConceptLink::STATUS_PUBLISHED)->count(),
            'approved_relations' => $c->table('taxonomy_concept_relations')->where('status', 'approved')->count(),
            'cpv' => $c->table('taxonomy_term_cpv_relations')->count(),
        ];
    }

    // =========================================================================================
    // NO DESTRUCTIVA - la propiedad central
    // =========================================================================================

    #[Test]
    public function supersession_preserves_every_immutable_field_of_the_predecessor(): void
    {
        [$proposal, $candidate] = $this->staleFrozenProposal();
        $before = $this->rawRow($proposal->id);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $outcome['result']);
        $after = $this->rawRow($proposal->id);

        // Campo por campo: «no destructiva» es la propiedad entera del diseño, así que no se confía en
        // que el UPDATE liste pocas columnas - se verifica que NINGUNA de estas cambió.
        foreach ([
            'proposal_type', 'candidate_link_id', 'concept_relation_id', 'decision', 'decision_payload',
            'payload_version', 'taxonomy_state_fingerprint', 'payload_fingerprint', 'reviewer_id',
            'reviewed_at', 'applied_at', 'authorization_reference', 'target_environment',
            'application_result', 'requires_human_confirmation', 'prepared_by_actor_type', 'prepared_via',
            'confirmed_by_id', 'confirmed_at', 'confirmation_reference', 'confirmation_channel',
            'confirmation_note', 'proposal_group_id', 'confirmation_invalidated_at',
            'invalidated_confirmation_snapshot', 'created_at',
        ] as $column) {
            $this->assertSame($before[$column], $after[$column],
                "supersedeStaleProposal() modificó `{$column}`, que tiene que quedar intacto.");
        }

        // Lo único que cambia: el status y el rastro.
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $before['status']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_SUPERSEDED, $after['status']);
        $this->assertNotNull($after['superseded_at']);
        $this->assertSame(self::REFERENCE, $after['supersession_reference']);
        $this->assertSame(self::REASON, $after['supersession_reason']);
        $this->assertSame(TaxonomyReviewedProposal::ACTOR_AGENT, $after['supersession_actor_type']);
        $this->assertNull($after['supersession_by_id'], 'Si lo ejecuta el agente, no se atribuye a ninguna persona - lección de TASK-0006C.');

        // Y la fila FUENTE tampoco se toca: sigue pending, sin revisor y sin publicar.
        $freshCandidate = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $freshCandidate->status);
        $this->assertNull($freshCandidate->reviewed_at);
        $this->assertNull($freshCandidate->published_term_concept_id);
    }

    #[Test]
    public function supersession_publishes_absolutely_nothing(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        $before = $this->publishedCounts();

        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->assertSame($before, $this->publishedCounts(), 'Supersedir no puede publicar nada.');
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)->whereNotNull('applied_at')->count());
    }

    #[Test]
    public function supersession_without_successor_is_explicit_not_an_omission(): void
    {
        [$proposal, $candidate] = $this->staleFrozenProposal();
        $proposalsBefore = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->count();

        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $fresh = $proposal->fresh();
        $this->assertNull($fresh->superseded_by_proposal_id);
        $this->assertTrue($fresh->wasSupersededWithoutSuccessor());

        // Prueba de que NO se creó ningún sucesor: ni una fila nueva en la tabla, ni ninguna propuesta
        // para ese candidato más allá de la original.
        $this->assertSame($proposalsBefore, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->count());
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('candidate_link_id', $candidate->id)->count());
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('supersedes_proposal_id', $proposal->id)->count());
    }

    // =========================================================================================
    // LIBERA EL SLOT - el camino de recuperación
    // =========================================================================================

    #[Test]
    public function the_candidate_can_be_reviewed_again_while_the_predecessor_stays_superseded(): void
    {
        // El corazón de TASK-0006E: antes esto era imposible. El índice único parcial bloqueaba una
        // segunda propuesta PENDING_APPLY, y la única salida era ABORTED (terminal).
        [$proposal, $candidate] = $this->staleFrozenProposal();
        $user = $this->authorizedUser();

        $this->assertSame(
            ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL,
            (new ReviewedProposalService())->freeze(
                TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                $candidate->id,
                TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                $user,
                ['context_reason' => 'intento antes de supersedir'],
            )['result'],
            'Antes de supersedir, el slot está ocupado - eso es el callejón sin salida que esta tarea resuelve.',
        );

        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        // Ahora sí: una revisión NUEVA, contra el estado actual.
        $second = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'revisión nueva desde la evidencia actual'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $second['result']);
        $this->assertNotSame($proposal->id, $second['proposal']->id);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $second['proposal']->status);

        // El predecesor sigue SUPERSEDED y entero: coexisten, no se reemplazan.
        $this->assertSame(TaxonomyReviewedProposal::STATUS_SUPERSEDED, $proposal->fresh()->status);

        // Y la propuesta nueva se congeló contra el fingerprint ACTUAL, no el viejo.
        $this->assertSame(CanonicalConceptBuilderService::dryRunInputFingerprint(), $second['proposal']->taxonomy_state_fingerprint);
        $this->assertNotSame($proposal->taxonomy_state_fingerprint, $second['proposal']->taxonomy_state_fingerprint);
    }

    #[Test]
    public function the_unique_pending_apply_invariant_still_holds_after_supersession(): void
    {
        [$proposal, $candidate] = $this->staleFrozenProposal();
        $user = $this->authorizedUser();

        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);
        (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user, ['context_reason' => 'primera nueva'],
        );

        // Un TERCER congelamiento tiene que seguir rechazado: liberar el slot una vez no lo deja
        // abierto para siempre. El índice único parcial no se debilitó.
        $third = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_REJECT, $user, ['notes' => 'tercero'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $third['result']);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('candidate_link_id', $candidate->id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count());
    }

    // =========================================================================================
    // COMPUERTAS DE REVALIDACIÓN
    // =========================================================================================

    #[Test]
    public function a_proposal_that_is_not_stale_cannot_be_superseded_through_the_stale_only_flow(): void
    {
        // Requisito explícito: retirar de la cola una revisión que SIGUE siendo válida es otra decisión
        // de gobernanza, con su propia autorización. No se cuela por este camino.
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $this->authorizedUser(),
            ['context_reason' => 'vigente'],
        );
        $before = $this->rawRow($frozen['proposal']->id);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($frozen['proposal']->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_NOT_STALE, $outcome['result']);
        $this->assertSame($before, $this->rawRow($frozen['proposal']->id), 'Cero escrituras cuando la propuesta no está obsoleta.');
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $frozen['proposal']->fresh()->status);
    }

    #[Test]
    public function a_tampered_proposal_is_not_superseded_because_that_would_bury_the_finding(): void
    {
        [$proposal] = $this->staleFrozenProposal();

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['decision' => TaxonomyReviewedProposal::DECISION_REJECT]);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_TAMPERED_PAYLOAD, $outcome['result']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->superseded_at);
    }

    #[Test]
    public function a_source_candidate_already_resolved_blocks_supersession(): void
    {
        // Liberar el slot no serviría de nada: el candidato no volvería a la cola de revisión.
        [$proposal, $candidate] = $this->staleFrozenProposal();

        DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
            ->where('id', $candidate->id)
            ->update(['status' => TaxonomyCandidateConceptLink::STATUS_REJECTED]);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_SOURCE_NOT_REVIEWABLE, $outcome['result']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->fresh()->status);
    }

    #[Test]
    public function an_applied_or_aborted_proposal_is_out_of_scope(): void
    {
        [$proposal] = $this->staleFrozenProposal();

        // Se fuerza ABORTED por SQL crudo para aislar la compuerta de estado (pasar por apply() de
        // verdad también serviría, pero haría el test dependiente de esa cadena).
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['status' => TaxonomyReviewedProposal::STATUS_ABORTED]);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_PROCESSED, $outcome['result']);
        $this->assertNull($proposal->fresh()->superseded_at);
    }

    #[Test]
    public function supersession_is_idempotent_on_replay(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        $service = new ReviewedProposalService();

        $first = $service->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);
        $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $first['result']);

        $afterFirst = $this->rawRow($proposal->id);
        $auditAfterFirst = $this->supersessionAuditCount($proposal->id);

        $second = $service->supersedeStaleProposal($proposal->id, 'Issue #2 — segundo intento 9999', 'otro motivo');

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_SUPERSEDED, $second['result']);
        $this->assertSame($afterFirst, $this->rawRow($proposal->id), 'El replay no puede reescribir el rastro.');
        $this->assertSame($auditAfterFirst, $this->supersessionAuditCount($proposal->id), 'El replay no inserta una segunda fila de auditoría.');
    }

    #[Test]
    public function the_reference_and_reason_must_be_real_references(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        $service = new ReviewedProposalService();

        foreach ([['', self::REASON], ['sin digitos', self::REASON], [self::REFERENCE, '']] as [$reference, $reason]) {
            try {
                $service->supersedeStaleProposal($proposal->id, $reference, $reason);
                $this->fail("Debió rechazar reference='{$reference}' reason='{$reason}'.");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->fresh()->status);
    }

    // =========================================================================================
    // EVIDENCIA DURABLE - el delta de estado
    // =========================================================================================

    #[Test]
    public function the_state_delta_records_why_the_review_became_stale(): void
    {
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $this->authorizedUser(),
            ['context_reason' => 'demasiado genérico'],
        );
        $proposal = $frozen['proposal'];

        // El cambio concreto que invalida la revisión - el equivalente de `oleoducto`/`gasoducto`.
        $newConcept = $this->changeTaxonomyState();

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);
        $delta = $outcome['state_delta'];

        $this->assertTrue($delta['stale']);
        $this->assertSame($proposal->taxonomy_state_fingerprint, $delta['frozen_taxonomy_state_fingerprint']);
        $this->assertSame(CanonicalConceptBuilderService::dryRunInputFingerprint(), $delta['current_taxonomy_state_fingerprint']);
        $this->assertNotSame($delta['frozen_taxonomy_state_fingerprint'], $delta['current_taxonomy_state_fingerprint']);

        // La evidencia decision-relevante: el concepto que apareció DESPUÉS de la revisión está
        // nombrado, no sólo contado. Es lo que le permite al humano entender qué volver a mirar.
        $createdIds = array_column($delta['concepts_created_since_review'], 'id');
        $this->assertContains($newConcept->id, $createdIds);
        $this->assertGreaterThanOrEqual(1, $delta['concepts_created_since_review_count']);

        // Y el límite queda DECLARADO en el propio delta, en vez de presentar la derivación por
        // timestamps como si fuera una reconstrucción exacta del estado viejo.
        $this->assertStringContainsString('HASH', $delta['limitation']);

        // Persistido, no sólo devuelto: si no se guardara, la evidencia se perdería para siempre.
        $this->assertSame($delta, $proposal->fresh()->supersession_state_delta);
    }

    #[Test]
    public function supersession_writes_its_own_distinguishable_audit_event(): void
    {
        [$proposal] = $this->staleFrozenProposal();

        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $row = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyReviewedProposal::class)
            ->where('entity_id', $proposal->id)
            ->where('field', 'superseded_at')
            ->first();

        $this->assertNotNull($row, 'Supersedir tiene que dejar su propio evento, distinguible de freeze/confirm/apply.');
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $row->old_value);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_SUPERSEDED, $row->new_value);
        $this->assertStringContainsString('SUPERSEDED', $row->reason);
        $this->assertStringContainsString(self::REFERENCE, $row->reason);

        // Supersedir NO es ejecutar: esos dos campos están reservados para el APPLY real, y es la
        // distinción sobre la que se apoya todo el contrato C2.
        $this->assertNull($row->authorization_reference);
        $this->assertNull($row->target_environment);
    }

    // =========================================================================================
    // TERMINAL - apply/preflight y la base de datos
    // =========================================================================================

    #[Test]
    public function preflight_treats_superseded_as_terminal_history_not_ready_to_apply(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $report = (new ReviewedProposalService())->preflight($proposal->id);

        $this->assertSame(ReviewedProposalService::PREFLIGHT_ALREADY_SUPERSEDED, $report['blocker']);
        $this->assertNotSame(ReviewedProposalService::PREFLIGHT_READY_TO_APPLY, $report['blocker']);
        $this->assertSame(ReviewedProposalService::CATEGORY_BLOCKED_FOR_OTHER_REASON, $report['governance_category']);
        $this->assertNull($report['would_apply_abort_with'], 'Un apply() sobre una propuesta supersedida no la quema.');
        $this->assertSame(0, $report['expected_write_count']);
        $this->assertTrue($report['superseded']);
        $this->assertSame(self::REFERENCE, $report['supersession']['reference']);
        $this->assertTrue($report['supersession']['has_state_delta']);

        // Las compuertas posteriores no se evalúan, y el informe lo dice con null.
        $this->assertNull($report['stale']);
        $this->assertNull($report['payload_fingerprint_valid']);
    }

    #[Test]
    public function apply_on_a_superseded_proposal_writes_nothing_and_does_not_overwrite_the_trail(): void
    {
        // Sin esta compuerta el defecto sería real: SUPERSEDED no es APPLIED ni ABORTED, así que la
        // propuesta habría caído por la cadena de validación y apply() la habría ABORTADO, pisando el
        // rastro que la supersesión acaba de grabar.
        [$proposal] = $this->staleFrozenProposal();
        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $before = $this->rawRow($proposal->id);
        $publishedBefore = $this->publishedCounts();

        $outcome = (new ReviewedProposalService())->apply($proposal->id, 'TASK-0006E test-suite 5955148859');

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_SUPERSEDED, $outcome['result']);
        $this->assertNull($outcome['abort_reason']);
        $this->assertSame($before, $this->rawRow($proposal->id), 'apply() no puede tocar una propuesta supersedida.');
        $this->assertSame($publishedBefore, $this->publishedCounts());
    }

    #[Test]
    public function the_database_refuses_to_un_supersede_a_proposal(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['status' => TaxonomyReviewedProposal::STATUS_PENDING_APPLY]);
    }

    #[Test]
    public function the_database_refuses_to_rewrite_a_recorded_supersession_trail(): void
    {
        [$proposal] = $this->staleFrozenProposal();
        (new ReviewedProposalService())->supersedeStaleProposal($proposal->id, self::REFERENCE, self::REASON);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['supersession_reference' => 'referencia reescrita 123']);
    }

    #[Test]
    public function a_successor_cannot_be_recorded_without_a_persisted_state_delta(): void
    {
        // La regla de integridad que impide que una confirmación de sucesor sea ceremonial, impuesta
        // por la base de datos y no sólo por la aplicación.
        [$proposal] = $this->staleFrozenProposal();
        [$other] = $this->staleFrozenProposal();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['superseded_by_proposal_id' => $other->id]);
    }

    // =========================================================================================
    // GRUPOS BILINGÜES - nunca medio supersedidos
    // =========================================================================================

    #[Test]
    public function a_bilingual_group_is_superseded_whole_or_not_at_all(): void
    {
        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$this->newConceptCandidate('es')->id, $this->newConceptCandidate('en')->id],
            'zzz_supersede_grupo_es_'.uniqid(),
            'zzz_supersede_group_en_'.uniqid(),
            $this->authorizedUser(),
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);
        $groupId = $frozen['group_id'];

        $this->changeTaxonomyState();

        // Se entra por UN hermano; tiene que supersederse el grupo COMPLETO. Medio grupo supersedido
        // dejaría al otro aplicable por su cuenta, creando el concepto con un único término adjunto -
        // el duplicado que la sección D de TASK-0006B prohíbe.
        $outcome = (new ReviewedProposalService())->supersedeStaleProposal($frozen['proposals'][0]->id, self::REFERENCE, self::REASON);

        $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $outcome['result']);
        $this->assertCount(2, $outcome['proposals']);

        $members = TaxonomyReviewedProposal::query()->where('proposal_group_id', $groupId)->get();
        $this->assertCount(2, $members);
        $this->assertSame(0, $members->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count(), 'No puede quedar ningún hermano en la cola.');
        foreach ($members as $member) {
            $this->assertSame(TaxonomyReviewedProposal::STATUS_SUPERSEDED, $member->status);
            $this->assertNotNull($member->superseded_at);
            $this->assertNotNull($member->supersession_state_delta);
            $this->assertNull($member->superseded_by_proposal_id);
        }

        // Una fila de auditoría POR MIEMBRO, no una sola para el grupo.
        foreach ($members as $member) {
            $this->assertSame(1, $this->supersessionAuditCount($member->id));
        }
    }

    #[Test]
    public function entering_through_either_sibling_supersedes_the_same_whole_group(): void
    {
        foreach ([0, 1] as $entryIndex) {
            $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
                [$this->newConceptCandidate('es')->id, $this->newConceptCandidate('en')->id],
                'zzz_supersede_grupo_es_'.uniqid(),
                'zzz_supersede_group_en_'.uniqid(),
                $this->authorizedUser(),
            );
            $this->changeTaxonomyState();

            $outcome = (new ReviewedProposalService())->supersedeStaleProposal($frozen['proposals'][$entryIndex]->id, self::REFERENCE, self::REASON);

            $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $outcome['result'], "Entrando por el hermano {$entryIndex}.");
            $this->assertSame(2, TaxonomyReviewedProposal::query()
                ->where('proposal_group_id', $frozen['group_id'])
                ->where('status', TaxonomyReviewedProposal::STATUS_SUPERSEDED)->count());
        }
    }

    private function supersessionAuditCount(int $proposalId): int
    {
        return DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyReviewedProposal::class)
            ->where('entity_id', $proposalId)
            ->where('field', 'superseded_at')
            ->count();
    }
}
