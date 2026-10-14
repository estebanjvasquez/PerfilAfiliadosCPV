<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyAuditLog;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresiones DETERMINISTAS de resolución temporal, pedidas por la revisión `6167931953`,
 * puntos 4 y 5.
 *
 * EL DEFECTO, medido y no supuesto. Laravel liga las fechas con el formato `Y-m-d H:i:s` del
 * grammar - sin microsegundos - así que dos escrituras separadas por milisegundos quedan
 * guardadas con el mismo valor. Eso llegaba a la aplicación por dos caminos distintos:
 *
 *   1. el guard de no-op de `TaxonomyAuditLogger::record()` comparaba `confirmed_at` contra
 *      `invalidated_at` como cadenas y, al ser idénticas, DESCARTABA la fila de auditoría de una
 *      anulación de confirmación;
 *   2. el delta de supersesión derivaba los conceptos nuevos con `created_at > reviewed_at`, y el
 *      `>` estricto excluía los creados dentro del mismo segundo.
 *
 * POR QUÉ ESTOS TESTS SON DETERMINISTAS. No usan `sleep()` ni dependen de la latencia de la red
 * para cruzar un borde de segundo - que es precisamente lo que hacía que los tests originales
 * pasaran contra una base remota y fallaran contra una local. Aquí el reloj se FIJA con
 * `Carbon::setTestNow()`, de modo que confirmar y anular ocurren en el mismo segundo por
 * construcción, siempre, en cualquier máquina.
 */
class SameSecondTimestampRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'zzz_same_second_role', 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => 'update_taxonomy::reviewed::proposal', 'guard_name' => 'web']
            )
        );

        return $user->fresh();
    }

    // =========================================================================================
    // Punto 4 - el evento de auditoría sobrevive al mismo segundo
    // =========================================================================================

    /**
     * El guard genérico de no-op SIGUE intacto: un cambio de valor que no cambió nada no debe
     * registrarse. Eso es lo correcto y la corrección no lo tocó.
     */
    #[Test]
    public function the_generic_no_op_guard_still_discards_an_unchanged_value(): void
    {
        $before = TaxonomyAuditLog::query()->count();

        TaxonomyAuditLogger::record(
            entityType: TaxonomyReviewedProposal::class,
            entityId: 999999,
            field: 'zzz_same_value',
            oldValue: 'identico',
            newValue: 'identico',
            reason: 'no debe registrarse',
        );

        $this->assertSame($before, TaxonomyAuditLog::query()->count(),
            'Un cambio de valor sin cambio real debe seguir sin registrarse: ese guard es correcto.');
    }

    /**
     * Y un EVENTO sí se registra aunque los dos lados coincidan, porque no es un cambio de valor:
     * es un hecho que ocurrió.
     */
    #[Test]
    public function an_event_is_recorded_even_when_both_sides_are_identical(): void
    {
        $before = TaxonomyAuditLog::query()->count();

        TaxonomyAuditLogger::recordEvent(
            entityType: TaxonomyReviewedProposal::class,
            entityId: 999998,
            field: 'zzz_event_same_value',
            oldValue: 'identico',
            newValue: 'identico',
            reason: 'un hecho, no un cambio de valor',
        );

        $this->assertSame($before + 1, TaxonomyAuditLog::query()->count(),
            'Un evento con ambos lados iguales debe registrarse: la igualdad de dos instantes no significa que no pasó nada.');
    }

    /**
     * El caso real, con reloj fijo: confirmar y anular dentro del MISMO SEGUNDO deja rastro.
     *
     * Antes de la corrección esto perdía la fila en silencio, que para una anulación de
     * confirmación es un defecto de gobernanza y no ruido de bitácora.
     */
    #[Test]
    public function invalidating_a_confirmation_in_the_same_second_still_leaves_an_audit_trail(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->confirmedProposalAtFrozenClock($user);

        // El reloj NO avanza: la anulación cae exactamente en el mismo segundo que la confirmación.
        $outcome = (new ReviewedProposalService())->invalidateConfirmation(
            $proposal->id, 'Issue #2 - regresión mismo segundo', 'motivo de la corrección',
        );
        $this->assertSame(ReviewedProposalService::RESULT_CONFIRMATION_INVALIDATED, $outcome['result']);

        $fresh = $proposal->fresh();
        $this->assertSame(
            $fresh->confirmation_invalidated_at?->format('Y-m-d H:i:s'),
            $this->frozenNow()->format('Y-m-d H:i:s'),
            'Precondición del test: la anulación tiene que caer en el segundo congelado.'
        );

        $audit = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_id', (string) $proposal->id)
            ->where('field', 'confirmation_invalidated_at')
            ->get();

        $this->assertCount(1, $audit,
            'La anulación ocurrió en el mismo segundo que la confirmación y su rastro de auditoría desapareció.');
        $this->assertStringContainsString('CONFIRMATION_INVALIDATED', $audit->first()->reason);
        // Corregir no es ejecutar: estos dos siguen vacíos.
        $this->assertNull($audit->first()->authorization_reference);
        $this->assertNull($audit->first()->target_environment);
        // Y el payload identifica QUÉ se anuló, no sólo cuándo.
        $this->assertStringContainsString('correction_reference=', (string) $audit->first()->new_value);
        $this->assertStringContainsString('reference=', (string) $audit->first()->old_value);
    }

    // =========================================================================================
    // Punto 5 - el delta distingue evidencia exacta de candidatos del mismo segundo
    // =========================================================================================

    /**
     * Un concepto creado en el MISMO SEGUNDO que la revisión ya no desaparece: aparece marcado
     * como candidato conservador, que es lo máximo que se puede afirmar con resolución de segundo.
     */
    #[Test]
    public function a_concept_created_in_the_same_second_is_reported_as_a_boundary_candidate(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->frozenProposalAtFrozenClock($user);

        // Mismo segundo congelado: el concepto nace indistinguible del instante de la revisión.
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_same_second_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal(
            $proposal->id, 'Issue #2 - regresión mismo segundo', 'obsoleta por cambio de estado',
        );
        $delta = $outcome['state_delta'];

        $ids = array_column($delta['concepts_created_since_review'], 'id');
        $this->assertContains($concept->id, $ids,
            'El concepto creado en el mismo segundo que la revisión desapareció del delta.');

        $entry = collect($delta['concepts_created_since_review'])->firstWhere('id', $concept->id);
        $this->assertSame('boundary_same_second', $entry['evidence'],
            'Un concepto indistinguible del instante de la revisión debe reportarse como candidato, no como hecho.');
        $this->assertContains($concept->id, $delta['concepts_created_since_review_boundary_ids']);
        $this->assertGreaterThanOrEqual(1, $delta['concepts_created_since_review_boundary_count']);

        // Y el límite queda DECLARADO en el propio delta.
        $this->assertStringContainsString('boundary_same_second', $delta['limitation']);
        $this->assertStringContainsString('HASH', $delta['limitation']);

        // Las salvaguardas no se relajaron para conseguir esto.
        $this->assertTrue($delta['stale']);
        $this->assertNotSame(
            $delta['frozen_taxonomy_state_fingerprint'],
            $delta['current_taxonomy_state_fingerprint'],
            'El guard de fingerprint tiene que seguir detectando que el estado cambió.'
        );
    }

    /**
     * Y un concepto creado DESPUÉS, en un segundo distinto, sigue siendo evidencia exacta: la
     * corrección no degrada todo a "candidato".
     */
    #[Test]
    public function a_concept_created_in_a_later_second_is_reported_as_exact_evidence(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->frozenProposalAtFrozenClock($user);

        // El reloj avanza un segundo entero: ahora sí se puede ordenar sin ambigüedad.
        Carbon::setTestNow($this->frozenNow()->copy()->addSecond());
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_later_second_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        $outcome = (new ReviewedProposalService())->supersedeStaleProposal(
            $proposal->id, 'Issue #2 - regresión segundo posterior', 'obsoleta por cambio de estado',
        );
        $delta = $outcome['state_delta'];

        $entry = collect($delta['concepts_created_since_review'])->firstWhere('id', $concept->id);
        $this->assertNotNull($entry, 'Un concepto creado un segundo después tiene que estar en el delta.');
        $this->assertSame('exact', $entry['evidence'],
            'Un concepto creado en un segundo posterior es evidencia exacta, no un candidato.');
        $this->assertGreaterThanOrEqual(1, $delta['concepts_created_since_review_exact_count']);
    }

    // =========================================================================================
    // Andamiaje
    // =========================================================================================

    private function frozenNow(): Carbon
    {
        // Un instante fijo y con microsegundos distintos de cero, para que si alguien subiera la
        // precisión de persistencia el test siguiera siendo honesto en vez de pasar por accidente.
        return Carbon::create(2026, 10, 14, 12, 0, 30, 'UTC')->setMicrosecond(123456);
    }

    private function newCandidate(): object
    {
        // Mismos atributos que usan los demás tests de taxonomía: `taxonomy_terms` no tiene
        // columna `status`, y el modelo exige term_type / mapping_review_status.
        $canonical = 'zzz_same_second_term_'.substr(uniqid('', true), -10);
        $term = TaxonomyTerm::create([
            'external_id' => 'zzz-same-second-'.uniqid('', true),
            'term' => $canonical,
            'language' => 'es',
            'canonical_term' => $canonical,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [],
            'negative_context' => [],
            'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ])->id;
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_same_second_target_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
        $linkId = DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->insertGetId([
            'suggested_term_id' => $term,
            'suggested_concept_id' => $concept->id,
            'signals' => '[]',
            'confidence' => 0.5,
            'tier' => 'REVIEW',
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['link_id' => $linkId, 'term_id' => $term, 'concept_id' => $concept->id];
    }

    private function frozenProposalAtFrozenClock(User $user): TaxonomyReviewedProposal
    {
        Carbon::setTestNow($this->frozenNow());
        $candidate = $this->newCandidate();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->link_id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $user,
            ['term_id' => $candidate->term_id, 'target_concept_id' => $candidate->concept_id],
        );

        return $frozen['proposal'];
    }

    private function confirmedProposalAtFrozenClock(User $user): TaxonomyReviewedProposal
    {
        $proposal = $this->frozenProposalAtFrozenClock($user);

        // Confirmación en el mismo segundo congelado, por el servicio y no a mano, para que el
        // test ejercite el camino real.
        $proposal->forceFill([
            'requires_human_confirmation' => true,
            'confirmed_by_id' => $user->id,
            'confirmed_at' => now(),
            'confirmation_reference' => 'Issue #2 - confirmación de regresión',
            'confirmation_channel' => TaxonomyReviewedProposal::CHANNEL_HTTP,
            'confirmation_note' => 'confirmada dentro del mismo segundo',
        ])->save();

        return $proposal->fresh();
    }
}
