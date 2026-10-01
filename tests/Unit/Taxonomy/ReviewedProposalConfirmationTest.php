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
 * TASK-0006B (Issue #2 comentario `5936206843`): cobertura de las tres extensiones de C2 que las
 * decisiones humanas del dueño de la taxonomía requirieron.
 *
 * - Sección A: capa de CONFIRMACIÓN HUMANA exigible sobre una propuesta ya congelada, sin borrarla
 *   ni re-congelarla, y sin tocar su decisión ni sus fingerprints.
 * - Sección C: `CREATE_NEW` con identidad BILINGÜE explícita (ES + EN), sin traducción ni fallback
 *   implícito, con compatibilidad hacia atrás explícita para los payloads monolingües históricos.
 * - Sección D: convergencia gobernada de VARIOS candidatos bilingües en UN SOLO concepto.
 *
 * Ninguna fila real de la cola (candidatos 263-272, relaciones 61/62, propuestas #420-#495) se usa
 * acá: todo se crea de cero por test y `DatabaseTransactions` lo revierte, mismo criterio que el
 * resto de la suite de taxonomía.
 */
class ReviewedProposalConfirmationTest extends TestCase
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

    private function term(string $language = 'es', ?string $canonical = null): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'c2b-test-'.uniqid('', true),
            'term' => 'zzz_task0006b_'.$language.'_'.uniqid('', true),
            'language' => $language,
            'canonical_term' => $canonical ?? ('zzz_task0006b_canon_'.uniqid('', true)),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function newConceptCandidate(string $language = 'es', ?string $canonical = null): TaxonomyCandidateConceptLink
    {
        $term = $this->term($language, $canonical);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_task0006b_suggested_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function candidateRelation(): TaxonomyConceptRelation
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006b_rel_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006b_rel_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        return TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
    }

    /** Congela una decisión declarada como PREPARADA POR EL AGENTE (la que exige confirmación). */
    private function freezeAgentPrepared(User $reviewer): TaxonomyReviewedProposal
    {
        $candidate = $this->newConceptCandidate();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $reviewer,
            ['context_reason' => 'zzz_task0006b motivo preparado por agente'],
            TaxonomyReviewedProposal::ACTOR_AGENT,
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return $frozen['proposal'];
    }

    // =========================================================================================
    // SECCIÓN A - la confirmación es ADITIVA: no toca la decisión ni sus fingerprints
    // =========================================================================================

    #[Test]
    public function freezing_as_agent_prepared_marks_the_proposal_as_requiring_human_confirmation(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->assertTrue($proposal->requires_human_confirmation);
        $this->assertSame(TaxonomyReviewedProposal::ACTOR_AGENT, $proposal->prepared_by_actor_type);
        $this->assertTrue($proposal->awaitsHumanConfirmation());
        $this->assertFalse($proposal->isHumanConfirmed());
        // El canal se auto-captura y NO lo provee quien llama (mismo criterio que
        // `target_environment` en apply()). La suite corre por consola.
        $this->assertSame(TaxonomyReviewedProposal::CHANNEL_CONSOLE, $proposal->prepared_via);
    }

    #[Test]
    public function freezing_normally_does_not_require_confirmation_so_the_existing_ui_path_is_unchanged(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_task0006b decidido por el propio revisor'],
        );

        $this->assertFalse($frozen['proposal']->requires_human_confirmation);
        $this->assertSame(TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER, $frozen['proposal']->prepared_by_actor_type);
        $this->assertFalse($frozen['proposal']->awaitsHumanConfirmation());
    }

    #[Test]
    public function confirming_writes_only_confirmation_fields_and_leaves_the_frozen_decision_and_its_fingerprint_intact(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $before = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first();

        $this->actingAs($user);
        $outcome = (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843', 'nota de prueba');

        $this->assertSame(ReviewedProposalService::RESULT_CONFIRMED, $outcome['result']);

        $after = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first();

        // INVARIANTE CENTRAL de la sección A: confirmar NO puede alterar ningún campo de decisión.
        foreach ([
            'proposal_type', 'candidate_link_id', 'concept_relation_id', 'decision', 'decision_payload',
            'payload_version', 'taxonomy_state_fingerprint', 'payload_fingerprint', 'reviewer_id',
            'reviewed_at', 'status', 'applied_at', 'authorization_reference', 'target_environment',
            'requires_human_confirmation', 'prepared_by_actor_type', 'prepared_via',
        ] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, "confirm() modificó `{$column}`, que debe ser inmutable.");
        }

        $this->assertSame($user->id, (int) $after->confirmed_by_id);
        $this->assertNotNull($after->confirmed_at);
        $this->assertSame('Issue #2 comentario 5936206843', $after->confirmation_reference);
        $this->assertSame('nota de prueba', $after->confirmation_note);
        $this->assertSame(TaxonomyReviewedProposal::CHANNEL_CONSOLE, $after->confirmation_channel);
    }

    #[Test]
    public function a_confirmed_proposal_still_passes_its_own_tamper_detection(): void
    {
        // Corolario imprescindible: si confirmar rompiera `payload_fingerprint`, toda propuesta
        // confirmada abortaría después como si hubiera sido manipulada. Se prueba recomputando el
        // fingerprint exactamente como lo hace apply().
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->actingAs($user);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        $fresh = $proposal->fresh();
        $recomputed = ReviewedProposalService::computePayloadFingerprint([
            'proposal_type' => $fresh->proposal_type,
            'candidate_link_id' => $fresh->candidate_link_id,
            'concept_relation_id' => $fresh->concept_relation_id,
            'decision' => $fresh->decision,
            'decision_payload' => $fresh->decision_payload ?? [],
            'payload_version' => $fresh->payload_version,
            'taxonomy_state_fingerprint' => $fresh->taxonomy_state_fingerprint,
            'reviewer_id' => $fresh->reviewer_id,
            'reviewed_at' => $fresh->reviewed_at->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame($fresh->payload_fingerprint, $recomputed);
    }

    #[Test]
    public function confirmation_is_recorded_as_its_own_distinguishable_audit_event(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->actingAs($user);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        // `field = confirmed_at` distingue HUMAN_CONFIRMED de freeze/apply (que usan `status`), así
        // que la bitácora sola permite reconstruir PREPARED/FROZEN -> HUMAN_CONFIRMED -> APPLIED.
        $audit = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_id', $proposal->id)
            ->where('field', 'confirmed_at')
            ->get();

        $this->assertCount(1, $audit);
        $this->assertStringContainsString('HUMAN_CONFIRMED', $audit->first()->reason);
        $this->assertSame('user', $audit->first()->actor_type);
        // Confirmar NO es ejecutar: nunca lleva referencia de autorización ni entorno de ejecución.
        $this->assertNull($audit->first()->authorization_reference);
        $this->assertNull($audit->first()->target_environment);
    }

    // =========================================================================================
    // SECCIÓN A - autorización: solo un humano autenticado y con permiso puede confirmar
    // =========================================================================================

    #[Test]
    public function confirmation_is_refused_for_a_user_without_the_source_type_permission(): void
    {
        $reviewer = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($reviewer);
        $outsider = User::factory()->create();

        $this->actingAs($outsider);
        $outcome = (new ReviewedProposalService())->confirm($proposal->id, $outsider, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertNull($proposal->fresh()->confirmed_at);
    }

    #[Test]
    public function confirmation_is_refused_when_nobody_is_authenticated(): void
    {
        // Esto es lo que impide que un actor no-humano (script/servicio sin sesión) registre una
        // confirmación: no hay identidad autenticada que la respalde.
        $reviewer = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($reviewer);

        $outcome = (new ReviewedProposalService())->confirm($proposal->id, $reviewer, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertNull($proposal->fresh()->confirmed_at);
    }

    #[Test]
    public function confirmation_cannot_be_recorded_on_behalf_of_another_account(): void
    {
        // Requisito explícito "do not allow an agent/service actor to masquerade as a human
        // confirmer": el confirmador tiene que SER el usuario autenticado. Pasar el `User` de otra
        // persona mientras está logueada otra no atribuye nada a nadie.
        $reviewer = $this->authorizedUser();
        $otherHuman = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($reviewer);

        $this->actingAs($reviewer);
        $outcome = (new ReviewedProposalService())->confirm($proposal->id, $otherHuman, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertNull($proposal->fresh()->confirmed_at);
    }

    #[Test]
    public function confirmation_requires_a_verifiable_governance_reference(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);
        $this->actingAs($user);

        $this->expectException(\InvalidArgumentException::class);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'porque lo dije yo');
    }

    #[Test]
    public function confirmation_is_refused_for_a_proposal_that_does_not_require_it(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_task0006b decidido por el revisor'],
        );

        $this->actingAs($user);
        $outcome = (new ReviewedProposalService())->confirm($frozen['proposal']->id, $user, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_NOT_AWAITING_CONFIRMATION, $outcome['result']);
    }

    // =========================================================================================
    // SECCIÓN A - idempotencia y concurrencia
    // =========================================================================================

    #[Test]
    public function confirming_twice_is_idempotent_and_writes_nothing_the_second_time(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);
        $this->actingAs($user);

        $service = new ReviewedProposalService();
        $first = $service->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');
        $snapshot = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first();
        $auditCount = DB::connection('pgsql')->table('taxonomy_audit_log')->where('entity_id', $proposal->id)->count();

        $second = $service->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_CONFIRMED, $first['result']);
        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_CONFIRMED, $second['result']);
        $this->assertEquals($snapshot, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first());
        $this->assertSame($auditCount, DB::connection('pgsql')->table('taxonomy_audit_log')->where('entity_id', $proposal->id)->count());
    }

    #[Test]
    public function a_second_human_cannot_take_over_an_existing_confirmation(): void
    {
        $user = $this->authorizedUser();
        $otherHuman = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->actingAs($user);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        $this->actingAs($otherHuman);
        $outcome = (new ReviewedProposalService())->confirm($proposal->id, $otherHuman, 'Issue #2 comentario 5936206843');

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_CONFIRMED, $outcome['result']);
        $this->assertSame($user->id, (int) $proposal->fresh()->confirmed_by_id, 'La primera confirmación gana y es inmutable.');
    }

    #[Test]
    public function the_database_itself_rejects_overwriting_a_recorded_confirmation(): void
    {
        // Salvaguarda final, independiente del servicio: el trigger de la migración. Es lo que la
        // sección A pidió al exigir proteger el marcador "from casual mutation".
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);
        $this->actingAs($user);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['confirmed_by_id' => 99999]);
    }

    #[Test]
    public function the_database_itself_rejects_clearing_the_confirmation_requirement(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $proposal->id)
            ->update(['requires_human_confirmation' => false]);
    }

    // =========================================================================================
    // SECCIÓN A - la compuerta en apply(), y la regla de compatibilidad con lo histórico
    // =========================================================================================

    #[Test]
    public function apply_refuses_an_agent_prepared_proposal_that_nobody_confirmed_and_writes_nothing(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);
        $candidateId = $proposal->candidate_link_id;

        $outcome = (new ReviewedProposalService())->apply($proposal->id, 'TASK-0006B test-suite 1');

        $this->assertSame(ReviewedProposalService::RESULT_HUMAN_CONFIRMATION_REQUIRED, $outcome['result']);
        // Deliberadamente NO terminal: la propuesta sigue aplicable después de confirmarse. Si esto
        // abortara, un apply() prematuro dejaría la decisión humana irrecuperable (re-congelar está
        // bloqueado por el índice único parcial).
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->fresh()->status);
        $this->assertNull($proposal->fresh()->applied_at);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, TaxonomyCandidateConceptLink::find($candidateId)->status);
    }

    #[Test]
    public function apply_proceeds_once_the_human_confirmation_is_recorded(): void
    {
        // Esto cierra la compuerta en los dos sentidos: sin confirmación rechaza (test anterior),
        // con confirmación deja de ser el obstáculo. Sobre fixtures desechables, nunca sobre la cola
        // real - en la cola real NO se ejecuta ningún APPLY en esta tarea.
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        $this->actingAs($user);
        (new ReviewedProposalService())->confirm($proposal->id, $user, 'Issue #2 comentario 5936206843');

        $outcome = (new ReviewedProposalService())->apply($proposal->id, 'TASK-0006B test-suite 2');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertNotSame(ReviewedProposalService::RESULT_HUMAN_CONFIRMATION_REQUIRED, $outcome['result']);
        // CONTEXT_REQUIRED nunca publica un mapeo ni crea un concepto, confirmado o no.
        $this->assertSame('CONTEXT_REQUIRED', $outcome['application_result']['outcome']);
    }

    #[Test]
    public function a_proposal_frozen_without_the_agent_flag_applies_without_any_confirmation(): void
    {
        // REGLA DE COMPATIBILIDAD (requisito explícito de la sección A): toda propuesta cuya
        // procedencia de revisión original ya satisface el requisito de revisión humana - es decir
        // todo lo congelado antes de TASK-0006B, que quedó en `requires_human_confirmation = FALSE`
        // por el DEFAULT de la migración - sigue siendo aplicable exactamente como antes.
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_task0006b histórico-equivalente'],
        );

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B test-suite 3');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
    }

    #[Test]
    public function tamper_detection_still_wins_over_the_confirmation_gate(): void
    {
        // Orden de precedencia deliberado: un hallazgo de seguridad (payload manipulado) tiene que
        // ganarle a un simple "falta confirmar", y quedar registrado como ABORTED.
        $user = $this->authorizedUser();
        $proposal = $this->freezeAgentPrepared($user);

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)
            ->update(['decision_payload' => json_encode(['term_id' => 1, 'context_reason' => 'manipulado'])]);

        $outcome = (new ReviewedProposalService())->apply($proposal->id, 'TASK-0006B test-suite 4');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $outcome['abort_reason']);
    }

    // =========================================================================================
    // SECCIÓN C - CREATE_NEW bilingüe: ES + EN explícitas, sin fallback, compatible hacia atrás
    // =========================================================================================

    #[Test]
    public function freeze_records_both_canonical_names_exactly_as_the_reviewer_wrote_them(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate('en');

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $user,
            ['canonical_name_es' => 'refinería', 'canonical_name_en' => 'refinery'],
        );

        $payload = $frozen['proposal']->decision_payload;
        $this->assertSame('refinería', $payload['canonical_name_es']);
        $this->assertSame('refinery', $payload['canonical_name_en']);
        // No queda ningún nombre monolingüe residual que `apply()` pudiera preferir por error.
        $this->assertArrayNotHasKey('new_concept_name', $payload);
    }

    #[Test]
    public function the_builder_suggestion_stays_evidence_only_and_never_becomes_the_published_identity(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate('en');

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $user,
            ['canonical_name_es' => 'refinería', 'canonical_name_en' => 'refinery'],
        );

        $payload = $frozen['proposal']->decision_payload;
        // La sugerencia del Builder se conserva, pero SOLO como snapshot para detectar drift.
        $this->assertSame($candidate->suggested_new_concept_name, $payload['source_suggested_new_concept_name']);
        $this->assertNotSame($payload['source_suggested_new_concept_name'], $payload['canonical_name_es']);
        $this->assertNotSame($payload['source_suggested_new_concept_name'], $payload['canonical_name_en']);
    }

    #[Test]
    public function freeze_refuses_a_partial_bilingual_identity_instead_of_inventing_the_missing_name(): void
    {
        $user = $this->authorizedUser();

        foreach ([
            ['canonical_name_es' => 'refinería'],
            ['canonical_name_en' => 'refinery'],
            ['canonical_name_es' => 'refinería', 'canonical_name_en' => '   '],
            ['canonical_name_es' => '', 'canonical_name_en' => 'refinery'],
        ] as $payload) {
            $candidate = $this->newConceptCandidate('en');
            $outcome = (new ReviewedProposalService())->freeze(
                TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                $candidate->id,
                TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                $user,
                $payload,
            );

            $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result'],
                'Una identidad bilingüe parcial no puede completarse sola: '.json_encode($payload));
            $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
                ->where('candidate_link_id', $candidate->id)->count());
        }
    }

    #[Test]
    public function apply_publishes_each_bilingual_name_in_its_own_language_column(): void
    {
        // Este es el defecto que el BLOQUEO 2 del re-audit `5934324928` señaló: con una sola columna
        // de nombre, un término `es` terminaba con la palabra INGLESA en `canonical_name_es`.
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate('es');

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $user,
            ['canonical_name_es' => 'zzz_task0006b_es_name', 'canonical_name_en' => 'zzz_task0006b_en_name'],
        );

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B test-suite 5');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $concept = TaxonomyCanonicalConcept::find($outcome['application_result']['concept_id']);
        $this->assertSame('zzz_task0006b_es_name', $concept->canonical_name_es);
        $this->assertSame('zzz_task0006b_en_name', $concept->canonical_name_en);
    }

    #[Test]
    public function a_historical_monolingual_create_new_payload_still_applies_exactly_as_before(): void
    {
        // Compatibilidad hacia atrás EXPLÍCITA (requisito de la sección C). Un payload congelado
        // antes de TASK-0006B no trae las claves ES/EN: tiene que seguir publicándose igual, con el
        // nombre en el campo del idioma del término y el otro en NULL.
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate('en');

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $user,
            ['new_concept_name' => 'zzz_task0006b_monolingual'],
        );

        $this->assertArrayNotHasKey('canonical_name_es', $frozen['proposal']->decision_payload);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B test-suite 6');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $concept = TaxonomyCanonicalConcept::find($outcome['application_result']['concept_id']);
        $this->assertSame('zzz_task0006b_monolingual', $concept->canonical_name_en);
        $this->assertNull($concept->canonical_name_es);
        $this->assertSame('CREATED_NEW_CONCEPT', $outcome['application_result']['outcome']);
    }

    #[Test]
    public function tampering_with_either_canonical_name_is_detected(): void
    {
        $user = $this->authorizedUser();

        foreach (['canonical_name_es', 'canonical_name_en'] as $field) {
            $candidate = $this->newConceptCandidate('en');
            $frozen = (new ReviewedProposalService())->freeze(
                TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                $candidate->id,
                TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                $user,
                ['canonical_name_es' => 'zzz_es_original', 'canonical_name_en' => 'zzz_en_original'],
            );

            $payload = $frozen['proposal']->decision_payload;
            $payload[$field] = 'zzz_manipulado';
            DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $frozen['proposal']->id)
                ->update(['decision_payload' => json_encode($payload)]);

            $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
            $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B test-suite 7');

            $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $outcome['abort_reason'],
                "Manipular `{$field}` tiene que detectarse - el fingerprint cubre las DOS nombres.");
            $this->assertSame($conceptsBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        }
    }

    // =========================================================================================
    // SECCIÓN D - convergencia bilingüe: UN concepto para VARIOS candidatos
    // =========================================================================================

    /** @return array{0:TaxonomyCandidateConceptLink,1:TaxonomyCandidateConceptLink} */
    private function bilingualPair(): array
    {
        $canonical = 'zzz_task0006b_pair_'.uniqid();

        return [
            $this->newConceptCandidate('en', $canonical),
            $this->newConceptCandidate('es', $canonical),
        ];
    }

    #[Test]
    public function freezing_a_bilingual_group_creates_one_linked_proposal_per_candidate(): void
    {
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();

        $outcome = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id], 'refinería', 'refinery', $user,
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $outcome['result']);
        $this->assertCount(2, $outcome['proposals']);
        $this->assertNotNull($outcome['group_id']);

        foreach ($outcome['proposals'] as $proposal) {
            $this->assertSame($outcome['group_id'], $proposal->proposal_group_id);
            $this->assertSame(TaxonomyReviewedProposal::DECISION_CREATE_NEW, $proposal->decision);
            $this->assertSame('refinería', $proposal->decision_payload['canonical_name_es']);
            $this->assertSame('refinery', $proposal->decision_payload['canonical_name_en']);
            // Cada miembro identifica el GRUPO COMPLETO, así que cualquiera por sí solo describe la
            // convergencia entera (requisito explícito de la sección D).
            $this->assertEqualsCanonicalizing([$en->id, $es->id], $proposal->decision_payload['grouped_candidate_link_ids']);
            $this->assertCount(2, $proposal->decision_payload['grouped_term_ids']);
        }

        // Mismo fingerprint de estado para todo el grupo: si no, apply() abortaría por obsolescencia
        // aunque nada hubiera cambiado.
        $this->assertSame(
            $outcome['proposals'][0]->taxonomy_state_fingerprint,
            $outcome['proposals'][1]->taxonomy_state_fingerprint,
        );
    }

    #[Test]
    public function a_bilingual_group_must_actually_span_both_languages(): void
    {
        $user = $this->authorizedUser();
        $canonical = 'zzz_task0006b_same_lang_'.uniqid();
        $a = $this->newConceptCandidate('en', $canonical);
        $b = $this->newConceptCandidate('en', $canonical);

        $outcome = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$a->id, $b->id], 'refinería', 'refinery', $user,
        );

        $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->whereIn('candidate_link_id', [$a->id, $b->id])->count());
    }

    #[Test]
    public function a_bilingual_group_rolls_back_completely_when_one_member_already_has_a_pending_proposal(): void
    {
        // Medio grupo congelado sería peor que ninguno: el hermano sin propuesta podría recibir
        // después un CREATE_NEW independiente y crear el concepto duplicado.
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();

        (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $es->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_task0006b ya revisado antes'],
        );

        $outcome = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id], 'refinería', 'refinery', $user,
        );

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('candidate_link_id', $en->id)->count(), 'El grupo tiene que revertirse COMPLETO.');
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->whereNotNull('proposal_group_id')->where('candidate_link_id', $es->id)->count());
    }

    #[Test]
    public function the_existing_partial_unique_index_protects_every_member_of_the_group(): void
    {
        // Esta es la razón de modelar el grupo como varias filas y no como una lista en el payload:
        // con una sola fila, el segundo candidato quedaría FUERA del índice único parcial y nada
        // impediría congelarle otra propuesta en paralelo (la carrera de concepto duplicado).
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();

        (new ReviewedProposalService())->freezeBilingualConceptGroup([$en->id, $es->id], 'refinería', 'refinery', $user);

        foreach ([$en, $es] as $member) {
            $outcome = (new ReviewedProposalService())->freeze(
                TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                $member->id,
                TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                $user,
                ['new_concept_name' => 'zzz_task0006b_intento_paralelo'],
            );

            $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $outcome['result']);
        }
    }

    #[Test]
    public function applying_a_bilingual_group_creates_exactly_one_concept_and_attaches_both_terms(): void
    {
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();
        $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $linksBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id], 'zzz_task0006b_es_unico', 'zzz_task0006b_en_unico', $user,
        );

        $outcome = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B test-suite 8');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame('CREATED_NEW_BILINGUAL_CONCEPT_FOR_GROUP', $outcome['application_result']['outcome']);

        // UN concepto, no dos.
        $this->assertSame($conceptsBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $concept = TaxonomyCanonicalConcept::find($outcome['application_result']['concept_id']);
        $this->assertSame('zzz_task0006b_es_unico', $concept->canonical_name_es);
        $this->assertSame('zzz_task0006b_en_unico', $concept->canonical_name_en);

        // Los DOS términos adjuntos al MISMO concepto.
        $this->assertSame($linksBefore + 2, DB::connection('pgsql')->table('taxonomy_term_concepts')->count());
        foreach ([$en, $es] as $member) {
            $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->where('term_id', $member->suggested_term_id)->where('concept_id', $concept->id)->count());
            $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $member->fresh()->status);
        }

        // Todo el grupo queda APPLIED en la MISMA transacción.
        foreach ($frozen['proposals'] as $proposal) {
            $this->assertSame(TaxonomyReviewedProposal::STATUS_APPLIED, $proposal->fresh()->status);
            $this->assertSame($concept->id, $proposal->fresh()->application_result['concept_id']);
        }
    }

    #[Test]
    public function applying_the_sibling_afterwards_reuses_the_same_concept_instead_of_creating_a_second_one(): void
    {
        // Idempotencia real del diseño de un-solo-concepto (requisito explícito de la sección D).
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();

        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id], 'zzz_task0006b_es_idem', 'zzz_task0006b_en_idem', $user,
        );

        $first = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B test-suite 9');
        $conceptsAfterFirst = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $second = (new ReviewedProposalService())->apply($frozen['proposals'][1]->id, 'TASK-0006B test-suite 10');

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_APPLIED, $second['result']);
        $this->assertSame($conceptsAfterFirst, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'Aplicar el hermano no puede crear un segundo concepto.');
        $this->assertSame(
            $first['application_result']['concept_id'],
            $frozen['proposals'][1]->fresh()->application_result['concept_id'],
        );
    }

    #[Test]
    public function source_drift_on_either_member_aborts_the_whole_group_with_zero_writes(): void
    {
        $user = $this->authorizedUser();

        foreach ([0, 1] as $driftIndex) {
            [$en, $es] = $this->bilingualPair();
            $members = [$en, $es];
            // El término alternativo se crea ANTES de congelar, para que el fingerprint de estado
            // de taxonomía no cambie después y este test pruebe DRIFT DE FUENTE, no obsolescencia.
            $otherTerm = $this->term('en');

            $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
                [$en->id, $es->id], 'zzz_task0006b_es_drift', 'zzz_task0006b_en_drift', $user,
            );

            $members[$driftIndex]->update(['suggested_term_id' => $otherTerm->id]);

            $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
            $linksBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

            $outcome = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B test-suite 11');

            $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result'],
                "El drift en el miembro {$driftIndex} tiene que abortar el grupo entero.");
            $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
            $this->assertSame($conceptsBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
            $this->assertSame($linksBefore, DB::connection('pgsql')->table('taxonomy_term_concepts')->count());
            foreach ([$en, $es] as $member) {
                $this->assertNotSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $member->fresh()->status);
            }
        }
    }

    #[Test]
    public function a_grouped_proposal_requires_every_member_to_be_confirmed_before_applying(): void
    {
        $user = $this->authorizedUser();
        [$en, $es] = $this->bilingualPair();

        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id], 'zzz_task0006b_es_conf', 'zzz_task0006b_en_conf', $user,
            preparedByActorType: TaxonomyReviewedProposal::ACTOR_AGENT,
        );

        $this->actingAs($user);
        // Solo UNO de los dos miembros confirmado.
        (new ReviewedProposalService())->confirm($frozen['proposals'][0]->id, $user, 'Issue #2 comentario 5936206843');

        $outcome = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B test-suite 12');

        $this->assertSame(ReviewedProposalService::RESULT_HUMAN_CONFIRMATION_REQUIRED, $outcome['result']);
        $this->assertSame([$frozen['proposals'][1]->id], $outcome['application_result']['unconfirmed_proposal_ids']);

        // Con los dos confirmados, la compuerta deja de ser el obstáculo.
        (new ReviewedProposalService())->confirm($frozen['proposals'][1]->id, $user, 'Issue #2 comentario 5936206843');
        $this->assertSame(
            ReviewedProposalService::RESULT_APPLIED,
            (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B test-suite 13')['result'],
        );
    }

    // =========================================================================================
    // SECCIÓN E - camino de REJECT de relaciones (el que usan las decisiones del dueño para 61/62)
    // =========================================================================================

    #[Test]
    public function a_relation_reject_decision_freezes_without_touching_the_source_relation(): void
    {
        $user = $this->authorizedUser();
        $relation = $this->candidateRelation();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            TaxonomyReviewedProposal::DECISION_REJECT,
            $user,
            ['notes' => 'zzz_task0006b evidencia léxica insuficiente', 'rejection_reason_category' => 'INSUFFICIENT_EVIDENCE'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);
        $this->assertSame(TaxonomyReviewedProposal::DECISION_REJECT, $frozen['proposal']->decision);
        $this->assertSame('INSUFFICIENT_EVIDENCE', $frozen['proposal']->decision_payload['rejection_reason_category']);

        // freeze() NUNCA toca la fila fuente: la relación sigue candidate, sin revisor ni fecha.
        $fresh = $relation->fresh();
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $fresh->status);
        $this->assertNull($fresh->reviewed_at);
        $this->assertNull($fresh->reviewed_by);
    }

    #[Test]
    public function an_agent_prepared_relation_reject_also_needs_confirmation_before_applying(): void
    {
        $user = $this->authorizedUser();
        $relation = $this->candidateRelation();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            TaxonomyReviewedProposal::DECISION_REJECT,
            $user,
            ['notes' => 'zzz_task0006b preparado por agente'],
            TaxonomyReviewedProposal::ACTOR_AGENT,
        );

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B test-suite 14');

        $this->assertSame(ReviewedProposalService::RESULT_HUMAN_CONFIRMATION_REQUIRED, $outcome['result']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);
    }
}
