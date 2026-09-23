<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.1 (sección 9 del pedido): endurece la operación Approve/Reject de
 * `TaxonomyCandidateConceptLinkResource`, que hasta esta fase vivía inline en el Action del recurso
 * sin transacción, sin re-lectura con lock, y sin log de auditoría.
 *
 * Salvaguardas implementadas (auditoría de la sección 9, cada una mapeada a un test dedicado en
 * `CandidateConceptApprovalServiceTest`):
 * - **autorización re-verificada DENTRO del servicio** (no solo en el `visible()` del botón de
 *   Filament, que solo oculta la UI - nunca impide una llamada directa al servicio). Usa la MISMA
 *   policy (`TaxonomyCandidateConceptLinkPolicy::update`) que ya gobierna el recurso Filament, vía
 *   `Gate`/`$user->can()` - no un chequeo de rol paralelo.
 * - **status válido re-verificado DENTRO de la transacción** (no solo en el `visible()` del botón,
 *   que solo oculta la UI - no impide una segunda invocación ya en vuelo).
 * - **`lockForUpdate()`**: protección real contra doble click / doble request concurrente - una
 *   segunda transacción concurrente se serializa detrás de la primera y, al obtener el lock, ve
 *   `status` ya cambiado y devuelve `ALREADY_PROCESSED` en vez de duplicar.
 * - **transacción**: el INSERT en `taxonomy_term_concepts` y el UPDATE del candidato son atómicos -
 *   si cualquiera falla (ej. el concepto fue borrado entre tanto, viola la FK), NINGUNO de los dos
 *   persiste.
 * - **idempotencia real de la publicación**: si el link TÉRMINO->CONCEPTO ya existe (por cualquier
 *   vía), reutiliza su id en vez de intentar otro INSERT que la `UNIQUE(term_id, concept_id)` de
 *   `taxonomy_term_concepts` rechazaría con una excepción SQL cruda.
 * - **audit log**: reusa `TaxonomyAuditLogger` (TAXV2-9), el mecanismo YA establecido en este
 *   proyecto para decisiones de aprobar/rechazar - no se inventa un mecanismo paralelo.
 * - **reviewed_by/reviewed_at/published_term_concept_id**: se siguen poblando, ahora dentro de la
 *   misma transacción.
 */
class CandidateConceptApprovalService
{
    public const RESULT_APPROVED = 'APPROVED';

    public const RESULT_REJECTED = 'REJECTED';

    public const RESULT_ALREADY_PROCESSED = 'ALREADY_PROCESSED';

    public const RESULT_NOT_SUPPORTED = 'NOT_SUPPORTED_PROPOSE_NEW_CONCEPT';

    public const RESULT_NOT_FOUND = 'NOT_FOUND';

    public const RESULT_UNAUTHORIZED = 'UNAUTHORIZED';

    // Phase B (B3, secciones 15-18 del pedido): resolución de candidatos "proponer concepto
    // nuevo" - `approve()` de arriba sigue rechazándolos (RESULT_NOT_SUPPORTED, sin cambios, test
    // existente intacto) porque un candidato de concepto nuevo no es un sí/no binario; necesita
    // que el revisor elija entre 3 caminos, resueltos acá.
    public const DECISION_MAP_TO_EXISTING = 'MAP_TO_EXISTING';

    public const DECISION_CREATE_NEW = 'CREATE_NEW';

    public const DECISION_REJECT = 'REJECT';

    public const RESULT_MAPPED_TO_EXISTING = 'MAPPED_TO_EXISTING';

    public const RESULT_CREATED_NEW_CONCEPT = 'CREATED_NEW_CONCEPT';

    public const RESULT_NOT_APPLICABLE = 'NOT_APPLICABLE_NOT_A_NEW_CONCEPT_PROPOSAL';

    public function __construct(private ?CanonicalConceptBuilderService $conceptBuilder = null) {}

    private function conceptBuilder(): CanonicalConceptBuilderService
    {
        // Lazy + resuelto por el contenedor (no `new` directo) para que
        // IntentContaminationDetector::fromGovernedVocabulary() (bind de AppServiceProvider) se
        // use en producción, sin romper los tests existentes que siguen instanciando este
        // servicio con `new CandidateConceptApprovalService()` sin argumentos.
        return $this->conceptBuilder ??= app(CanonicalConceptBuilderService::class);
    }

    // Phase B.1 (sección 7 del pedido): categorías conceptuales de motivo de rechazo. No existe
    // ninguna columna/enum de "reject_reason" en el esquema real (auditado antes de escribir esto -
    // ver audit/phase3_phase_b1_review_workflow.md) y el pedido pide explícitamente no inventar un
    // enum arbitrario sin chequear antes - por eso esto NO es una columna nueva, es una convención de
    // formato de texto ("[CATEGORIA] nota libre") compuesta dentro de la columna `review_notes` que
    // YA existe, la misma que ya usan `reject()`/`resolveNewConceptProposal()`.
    public const REJECT_REASON_DUPLICATE = 'DUPLICATE';

    public const REJECT_REASON_IRRELEVANT = 'IRRELEVANT';

    public const REJECT_REASON_INSUFFICIENT_EVIDENCE = 'INSUFFICIENT_EVIDENCE';

    public const REJECT_REASON_AMBIGUOUS = 'AMBIGUOUS';

    public const REJECT_REASON_INCORRECT_EXTRACTION = 'INCORRECT_EXTRACTION';

    public const REJECT_REASON_WRONG_CONTEXT = 'WRONG_CONTEXT';

    public const REJECT_REASON_OTHER = 'OTHER';

    public const REJECT_REASON_LABELS = [
        self::REJECT_REASON_DUPLICATE => 'Duplicado de un concepto/relación ya existente',
        self::REJECT_REASON_IRRELEVANT => 'Irrelevante para la taxonomía CPV',
        self::REJECT_REASON_INSUFFICIENT_EVIDENCE => 'Evidencia insuficiente',
        self::REJECT_REASON_AMBIGUOUS => 'Ambiguo - no se puede decidir con la evidencia disponible',
        self::REJECT_REASON_INCORRECT_EXTRACTION => 'Extracción incorrecta del término/concepto',
        self::REJECT_REASON_WRONG_CONTEXT => 'Contexto incorrecto',
        self::REJECT_REASON_OTHER => 'Otro (requiere nota)',
    ];

    /**
     * Phase B.1 (sección 7 del pedido): compone el motivo estructurado + nota libre en el formato
     * persistido a `review_notes`. Función pura, sin efectos secundarios - reutilizable por
     * cualquier UI (Filament hoy, otra cosa mañana) sin duplicar el formato.
     */
    public static function composeReviewReason(string $reasonCategory, ?string $notes): string
    {
        $label = self::REJECT_REASON_LABELS[$reasonCategory] ?? $reasonCategory;
        $trimmedNotes = trim((string) $notes);

        return $trimmedNotes === '' ? "[{$reasonCategory}] {$label}" : "[{$reasonCategory}] {$trimmedNotes}";
    }

    /**
     * Phase B.1 (sección 9 del pedido): ¿el candidato fue generado contra un estado del grafo de
     * conceptos distinto del actual? Ver `CanonicalConceptBuilderService::conceptGraphFingerprint()`.
     * Solo lectura, sin efectos secundarios - la UI de revisión la llama antes de mostrar el
     * formulario de decisión, nunca dentro de la transacción de escritura (esa sigue siendo la
     * autoridad real vía re-chequeo de `status`, no de fingerprint - el fingerprint es informativo
     * para el humano, no una segunda salvaguarda de concurrencia).
     *
     * `tracked=false` (fingerprint NULL) es el caso de HOY para el 100% de los candidatos, porque
     * ningún proceso los estampa todavía (Phase C no existe) - se reporta como "no rastreado", nunca
     * como una falsa alarma de "obsoleto".
     *
     * @return array{tracked:bool, stale:bool, current_fingerprint:string, stored_fingerprint:?string}
     */
    public function conceptGraphStaleness(TaxonomyCandidateConceptLink $candidate): array
    {
        $current = CanonicalConceptBuilderService::conceptGraphFingerprint();
        $stored = $candidate->taxonomy_state_fingerprint;

        return [
            'tracked' => $stored !== null,
            'stale' => $stored !== null && $stored !== $current,
            'current_fingerprint' => $current,
            'stored_fingerprint' => $stored,
        ];
    }

    /**
     * Phase B (B3, sección 17 del pedido): "antes de proponer un concepto nuevo, buscar
     * duplicados posibles". Reutiliza el MISMO pipeline de retrieval+scoring que `dryRun()`
     * (`CanonicalConceptBuilderService::scoredCandidatesForTerm()`) - no un segundo algoritmo de
     * similitud paralelo. Solo lectura, no cambia ningún estado.
     *
     * @return array<int, array{concept_id:int, concept_name:string, score:float, tier:string}>
     */
    public function findPossibleDuplicateConcepts(TaxonomyCandidateConceptLink $candidate): array
    {
        if (! $candidate->isProposingNewConcept()) {
            return [];
        }

        $term = $candidate->term;
        if (! $term) {
            return [];
        }

        return array_map(fn (array $s) => [
            'concept_id' => $s['concept_id'],
            'concept_name' => $s['concept_name'],
            'score' => $s['score'],
            'tier' => $s['tier'],
        ], $this->conceptBuilder()->scoredCandidatesForTerm($term));
    }

    /**
     * Phase B (B3, secciones 15-18 del pedido): resuelve un candidato "proponer concepto nuevo"
     * según la decisión explícita del revisor - mismas salvaguardas que `approve()`/`reject()`
     * (transacción, lock, autorización re-verificada, idempotencia, audit log). NUNCA se invoca
     * automáticamente ni en bulk - siempre requiere un `$reviewer` autorizado y una `$decision`
     * explícita.
     *
     * @return array{result:string, candidate:?TaxonomyCandidateConceptLink, concept_id:?int, term_concept_id:?int}
     */
    public function resolveNewConceptProposal(
        int $candidateId,
        ?User $reviewer,
        string $decision,
        ?int $targetConceptId = null,
        ?string $notes = null,
    ): array {
        if ($decision === self::DECISION_REJECT) {
            $outcome = $this->reject($candidateId, $reviewer, $notes);

            return ['result' => $outcome['result'], 'candidate' => $outcome['candidate'], 'concept_id' => null, 'term_concept_id' => null];
        }

        if (! in_array($decision, [self::DECISION_MAP_TO_EXISTING, self::DECISION_CREATE_NEW], true)) {
            return ['result' => self::RESULT_NOT_APPLICABLE, 'candidate' => null, 'concept_id' => null, 'term_concept_id' => null];
        }

        return DB::connection('pgsql')->transaction(function () use ($candidateId, $reviewer, $decision, $targetConceptId, $notes) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'candidate' => null, 'concept_id' => null, 'term_concept_id' => null];
            }

            if (! $candidate->isProposingNewConcept()) {
                return ['result' => self::RESULT_NOT_APPLICABLE, 'candidate' => $candidate, 'concept_id' => null, 'term_concept_id' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'candidate' => $candidate, 'concept_id' => null, 'term_concept_id' => null];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'candidate' => $candidate, 'concept_id' => null, 'term_concept_id' => $candidate->published_term_concept_id];
            }

            if ($decision === self::DECISION_MAP_TO_EXISTING) {
                if (! $targetConceptId || ! TaxonomyCanonicalConcept::query()->whereKey($targetConceptId)->exists()) {
                    return ['result' => self::RESULT_NOT_FOUND, 'candidate' => $candidate, 'concept_id' => null, 'term_concept_id' => null];
                }
                $conceptId = $targetConceptId;
                $resultCode = self::RESULT_MAPPED_TO_EXISTING;
            } else {
                $term = $candidate->term;
                $concept = TaxonomyCanonicalConcept::query()->create([
                    'canonical_name_es' => $term?->language === 'en' ? null : ($candidate->suggested_new_concept_name ?? $term?->canonical_term),
                    'canonical_name_en' => $term?->language === 'en' ? ($candidate->suggested_new_concept_name ?? $term?->canonical_term) : null,
                    'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
                ]);
                $conceptId = $concept->id;
                $resultCode = self::RESULT_CREATED_NEW_CONCEPT;
            }

            $existingLink = DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->where('term_id', $candidate->suggested_term_id)
                ->where('concept_id', $conceptId)
                ->first();

            $termConceptId = $existingLink->id ?? DB::connection('pgsql')->table('taxonomy_term_concepts')->insertGetId([
                'term_id' => $candidate->suggested_term_id,
                'concept_id' => $conceptId,
                'created_at' => now(),
            ]);

            $previousStatus = $candidate->status;

            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'published_term_concept_id' => $termConceptId,
                'review_notes' => $notes ?? $candidate->review_notes,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyCandidateConceptLink::class,
                entityId: $candidate->id,
                field: 'status',
                oldValue: $previousStatus,
                newValue: $candidate->status,
                reason: "{$decision} -> concept_id={$conceptId}, taxonomy_term_concepts#{$termConceptId}".($notes ? " ({$notes})" : ''),
            );

            return ['result' => $resultCode, 'candidate' => $candidate, 'concept_id' => $conceptId, 'term_concept_id' => $termConceptId];
        });
    }

    /** @return array{result:string, candidate:?TaxonomyCandidateConceptLink, term_concept_id:?int} */
    public function approve(int $candidateId, ?User $reviewer): array
    {
        return DB::connection('pgsql')->transaction(function () use ($candidateId, $reviewer) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'candidate' => null, 'term_concept_id' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'candidate' => $candidate, 'term_concept_id' => null];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'candidate' => $candidate, 'term_concept_id' => $candidate->published_term_concept_id];
            }

            if ($candidate->isProposingNewConcept()) {
                return ['result' => self::RESULT_NOT_SUPPORTED, 'candidate' => $candidate, 'term_concept_id' => null];
            }

            $existingLink = DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->where('term_id', $candidate->suggested_term_id)
                ->where('concept_id', $candidate->suggested_concept_id)
                ->first();

            $termConceptId = $existingLink->id ?? DB::connection('pgsql')->table('taxonomy_term_concepts')->insertGetId([
                'term_id' => $candidate->suggested_term_id,
                'concept_id' => $candidate->suggested_concept_id,
                'created_at' => now(),
            ]);

            $previousStatus = $candidate->status;

            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'published_term_concept_id' => $termConceptId,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyCandidateConceptLink::class,
                entityId: $candidate->id,
                field: 'status',
                oldValue: $previousStatus,
                newValue: $candidate->status,
                reason: "Aprobado -> taxonomy_term_concepts#{$termConceptId} (term_id={$candidate->suggested_term_id}, concept_id={$candidate->suggested_concept_id})",
            );

            return ['result' => self::RESULT_APPROVED, 'candidate' => $candidate, 'term_concept_id' => $termConceptId];
        });
    }

    /** @return array{result:string, candidate:?TaxonomyCandidateConceptLink} */
    public function reject(int $candidateId, ?User $reviewer, ?string $notes = null): array
    {
        return DB::connection('pgsql')->transaction(function () use ($candidateId, $reviewer, $notes) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'candidate' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'candidate' => $candidate];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'candidate' => $candidate];
            }

            $previousStatus = $candidate->status;

            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes ?? $candidate->review_notes,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyCandidateConceptLink::class,
                entityId: $candidate->id,
                field: 'status',
                oldValue: $previousStatus,
                newValue: $candidate->status,
                reason: $notes,
            );

            return ['result' => self::RESULT_REJECTED, 'candidate' => $candidate];
        });
    }
}
