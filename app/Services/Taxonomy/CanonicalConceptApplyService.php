<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyConceptRelation;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase C1: MATERIALIZACIÓN DE COLA (`--apply`) del Canonical Concept Builder.
 *
 * TASK-0003, hallazgo 1 (corrección de contrato - LEER ANTES DE USAR ESTA CLASE):
 *
 * El contrato de Phase C originalmente acordado era REVIEWED_PROPOSAL -> PAYLOAD INMUTABLE Y CON
 * FINGERPRINT -> APPLY(payload) -> VALIDATE -> COMMIT/ROLLBACK, con el invariante "APPLY(resultado
 * de un dry-run) nunca recalcula ni sustituye una propuesta distinta después de la aprobación
 * humana". Esta clase NO implementa ese contrato. Lo que hace es tomar un dry-run del Builder y
 * encolarlo en las colas de revisión - un paso previo útil (**Phase C1: QUEUE_MATERIALIZATION**),
 * pero no la aplicación de un payload ya revisado por un humano (**Phase C2: no implementada
 * todavía**). No describir esta clase como "Phase C completa" en ningún doc/handoff - ver
 * `docs/orquestador/tasks/0003-phase-c-corrections.md` y `audit/phase3_c1_corrections_2026-09-28.md`.
 *
 * QUÉ ESCRIBE Y QUÉ NO (la decisión de diseño central de esta fase, esto SÍ sigue siendo cierto):
 *
 * `--apply` materializa las propuestas del dry-run en las DOS COLAS DE REVISIÓN, nunca en el grafo
 * publicado:
 *   - `taxonomy_candidate_concept_links` con `status=pending` (cola de revisión humana de Phase 3.1/B.1)
 *   - `taxonomy_concept_relations` con `status=candidate` (nunca `approved`)
 *
 * **Nunca escribe `taxonomy_term_concepts`.** Publicar un vínculo término→concepto sigue siendo
 * exclusivamente responsabilidad de `CandidateConceptApprovalService::approve()`, disparado por un
 * humano desde Filament. Esto es deliberado: la salvaguarda global del proyecto
 * (`docs/task.md` sección 14/15) dice "población controlada (dry-run revisado por humano →
 * aprobación manual, nunca bulk-apply)". Un tier `AUTO_ACCEPT` acá NO significa "publicar solo",
 * significa "encolar con alta confianza para que el revisor lo despache rápido".
 *
 * Tampoco toca jamás `taxonomy_term_cpv_relations` (las 9.749 filas protegidas) - se verifica antes/
 * después dentro de la misma transacción (conteo Y fingerprint de contenido, hallazgo 5), y se
 * aborta si cambió.
 *
 * PROPIEDADES DE SEGURIDAD (las mismas que ya tiene el camino manual, no un segundo estándar):
 *   1. Transacción única - o entra todo o no entra nada.
 *   2. Idempotencia CONCURRENCY-SAFE (TASK-0003, hallazgo 3) - `taxonomy_candidate_concept_links`
 *      tiene ahora índices únicos parciales (migración
 *      `2026_09_28_165653_add_unique_constraints_to_taxonomy_candidate_concept_links`) sobre
 *      (suggested_term_id, suggested_concept_id) y sobre suggested_term_id cuando
 *      suggested_concept_id es NULL. `taxonomy_concept_relations` YA tenía
 *      `UNIQUE(source_concept_id, target_concept_id, relation_type)` desde su creación. Las
 *      escrituras usan `insertOrIgnore()` (compila a `INSERT ... ON CONFLICT DO NOTHING` en
 *      Postgres) - la base decide atómicamente si la fila ya existe, no una lectura-luego-escritura
 *      de la aplicación (el `exists()` que había antes era un TOCTOU real entre dos corridas
 *      concurrentes).
 *   3. Guarda de obsolescencia AMPLIADA (TASK-0003, hallazgo 4) - el fingerprint que se recalcula
 *      DENTRO de la transacción y se compara con el del dry-run ya no es solo el grafo publicado
 *      (`conceptGraphFingerprint()`); es `CanonicalConceptBuilderService::dryRunInputFingerprint()`,
 *      que cubre todos los insumos reales del scoring (términos, CPV, embeddings, settings, etc. -
 *      ver el docblock de ese método). Si cualquiera de esos insumos cambió entre el scoring y la
 *      escritura, se aborta: las propuestas se calcularon contra un estado que ya no existe.
 *   4. Tope de escrituras - un plan más grande que `maxWrites` aborta antes de escribir nada.
 *   5. Autorización humana explícita (TASK-0003, hallazgo 2) - `apply()` exige un `$authorizedBy`
 *      no vacío. Sin él, ni siquiera compila la llamada (parámetro obligatorio, no una bandera
 *      opcional que se pueda olvidar). Queda registrado en cada fila de audit log - "development
 *      authorization" (poder desarrollar Phase C) nunca implica "production write authorization"
 *      (poder correr `--apply` contra un ambiente real) - son autorizaciones distintas.
 *   6. Audit log por cada fila creada (`actor_type=system` + `algorithm_version` + quién autorizó
 *      la corrida), nunca silencioso.
 *   7. Provenance - cada candidato queda estampado con `taxonomy_state_fingerprint` (Phase C1 es el
 *      "productor" que faltaba del contrato de Phase B.1, sección 16 de su auditoría).
 *   8. Protección estructural de tablas protegidas (TASK-0003, hallazgo 5) - esta clase no tiene
 *      NINGÚN camino de escritura hacia `taxonomy_term_concepts`, `taxonomy_canonical_concepts` ni
 *      `taxonomy_term_cpv_relations` (verificable leyendo el archivo: los únicos `create()`/
 *      `insertOrIgnore()` de esta clase son hacia `taxonomy_candidate_concept_links` y
 *      `taxonomy_concept_relations`). El chequeo antes/después ya no es solo conteo (que no prueba
 *      ausencia de UPDATE in-place) - suma un fingerprint de contenido de esas 3 tablas. Los
 *      conteos siguen ahí como diagnóstico legible, no como la garantía real.
 */
class CanonicalConceptApplyService
{
    /** Ver el docblock de la clase - esto es Phase C1 (materialización de cola), no Phase C2. */
    public const PHASE_LABEL = 'PHASE_C1_QUEUE_MATERIALIZATION';

    /**
     * Versión del resolutor que produjo estas filas. Queda en el audit log de cada escritura para
     * poder responder "¿qué algoritmo generó este candidato?" meses después - el otro campo que la
     * auditoría de Phase B.1 marcó como faltante para el contrato de Phase C.
     */
    public const ALGORITHM_VERSION = 'canonical-concept-builder/phase-c1-v1';

    /** Tope por defecto de filas a crear en una sola corrida. Conservador a propósito. */
    public const DEFAULT_MAX_WRITES = 500;

    public const RESULT_APPLIED = 'APPLIED';

    public const RESULT_ABORTED_STALE_FINGERPRINT = 'ABORTED_STALE_FINGERPRINT';

    public const RESULT_ABORTED_WRITE_CAP = 'ABORTED_WRITE_CAP';

    public const RESULT_ABORTED_PROTECTED_TABLE_CHANGED = 'ABORTED_PROTECTED_TABLE_CHANGED';

    public const RESULT_NOTHING_TO_APPLY = 'NOTHING_TO_APPLY';

    public function __construct(private readonly CanonicalConceptBuilderService $builder) {}

    /**
     * Traduce un resultado de `dryRun()` a un plan de escritura. Función PURA: no consulta ni
     * escribe la base. Existe separada de `apply()` para poder testear (y para que el comando pueda
     * mostrar) exactamente qué se va a crear, antes de abrir ninguna transacción.
     *
     * @param  array  $dryRun  Resultado tal cual lo devuelve CanonicalConceptBuilderService::dryRun()
     * @return array{term_concept_candidates:array, new_concept_candidates:array, concept_relations:array, total_writes:int, source_fingerprint:?string}
     */
    public function planFrom(array $dryRun): array
    {
        $termConceptCandidates = [];

        // Un par por (term_id, concept_id): `all_results` trae TODOS los pares puntuados, incluidos
        // los REJECT (que no se encolan - el scoring ya decidió que no corresponden) y posibles
        // repetidos si el retrieval devolvió el mismo concepto dos veces para un término.
        $seenPairs = [];
        foreach ($dryRun['all_results'] ?? [] as $scored) {
            if (($scored['tier'] ?? null) === TaxonomyCandidateConceptLink::TIER_REJECT) {
                continue;
            }

            $pairKey = $scored['term_id'].':'.$scored['concept_id'];
            if (isset($seenPairs[$pairKey])) {
                continue;
            }
            $seenPairs[$pairKey] = true;

            $termConceptCandidates[] = [
                'suggested_term_id' => $scored['term_id'],
                'suggested_concept_id' => $scored['concept_id'],
                'signals' => $scored['signals'] ?? [],
                'confidence' => $scored['score'] ?? 0.0,
                'tier' => $scored['tier'],
            ];
        }

        // Propuestas de concepto NUEVO: `suggested_concept_id` queda NULL y el nombre propuesto va en
        // `suggested_new_concept_name` - es el caso que `CandidateConceptApprovalService::
        // resolveNewConceptProposal()` (Phase B3) sabe resolver con MAP_TO_EXISTING/CREATE_NEW/REJECT.
        $newConceptCandidates = [];
        $seenNewConceptTerms = [];
        foreach ($dryRun['propose_new_concept_details'] ?? [] as $proposal) {
            if (isset($seenNewConceptTerms[$proposal['term_id']])) {
                continue;
            }
            $seenNewConceptTerms[$proposal['term_id']] = true;

            $newConceptCandidates[] = [
                'suggested_term_id' => $proposal['term_id'],
                'suggested_new_concept_name' => $proposal['suggested_canonical_name'],
                'possible_existing_concepts' => $proposal['possible_existing_concepts'] ?? [],
                'reason' => $proposal['reason'] ?? null,
            ];
        }

        $conceptRelations = [];
        foreach ($dryRun['concept_relation_proposals']['proposals'] ?? [] as $proposal) {
            $conceptRelations[] = [
                'source_concept_id' => $proposal['source_concept_id'],
                'target_concept_id' => $proposal['target_concept_id'],
                'relation_type' => $proposal['relation_type'],
                'confidence' => $proposal['confidence'] ?? 0.0,
                'provenance' => $proposal['provenance'] ?? [],
                'evidence' => $proposal['evidence'] ?? [],
            ];
        }

        return [
            'term_concept_candidates' => $termConceptCandidates,
            'new_concept_candidates' => $newConceptCandidates,
            'concept_relations' => $conceptRelations,
            'total_writes' => count($termConceptCandidates) + count($newConceptCandidates) + count($conceptRelations),
            // TASK-0003, hallazgo 4: preferí el fingerprint amplio; si un dry-run viejo (sin la
            // clave nueva) llegara acá, cae al angosto antes que a null - null desactivaría la
            // guarda de obsolescencia por completo, peor que una guarda parcial.
            'source_fingerprint' => $dryRun['dry_run_input_fingerprint'] ?? $dryRun['concept_graph_fingerprint'] ?? null,
        ];
    }

    /**
     * Materializa el plan. Todo o nada.
     *
     * @param  array  $dryRun  Resultado de `dryRun()` - se usa el MISMO objeto que produjo el scoring,
     *                         nunca una corrida nueva, para que el fingerprint estampado corresponda
     *                         al estado contra el que se calcularon las propuestas.
     * @param  string  $authorizedBy  TASK-0003, hallazgo 2: quién autorizó ESTA corrida contra ESTE
     *                                ambiente - obligatorio, sin default, para que sea estructuralmente
     *                                imposible invocar `apply()` sin una autorización explícita
     *                                registrada. "Autorización para desarrollar Phase C" (la que ya
     *                                dio el usuario) no es lo mismo que "autorización para esta
     *                                escritura real" - queda una por corrida, en el audit log.
     * @return array{result:string, created:array, skipped:array, plan:array, before:array, after:array, fingerprint:?string}
     */
    public function apply(array $dryRun, string $authorizedBy, int $maxWrites = self::DEFAULT_MAX_WRITES): array
    {
        if (trim($authorizedBy) === '') {
            throw new \InvalidArgumentException('apply() requiere $authorizedBy no vacío - quién autorizó esta escritura real (TASK-0003, hallazgo 2). No es opcional.');
        }

        $plan = $this->planFrom($dryRun);

        if ($plan['total_writes'] === 0) {
            return $this->outcome(self::RESULT_NOTHING_TO_APPLY, $plan);
        }

        if ($plan['total_writes'] > $maxWrites) {
            return $this->outcome(self::RESULT_ABORTED_WRITE_CAP, $plan, [
                'note' => "El plan intenta crear {$plan['total_writes']} filas y el tope es {$maxWrites}. No se escribió nada. Subí --max-writes conscientemente o acotá con --limit.",
            ]);
        }

        return DB::connection('pgsql')->transaction(function () use ($plan, $authorizedBy) {
            $before = $this->counts();
            $protectedBefore = $this->protectedTableSignature();

            // Guarda de obsolescencia (propiedad 3, hallazgo 4): el dry-run pudo haber corrido hace
            // rato - se compara contra TODOS los insumos de dryRun(), no solo el grafo publicado.
            $currentFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();
            if ($plan['source_fingerprint'] !== null && $plan['source_fingerprint'] !== $currentFingerprint) {
                return $this->outcome(self::RESULT_ABORTED_STALE_FINGERPRINT, $plan, [
                    'note' => 'El estado usado para generar las propuestas (grafo de conceptos, términos, CPV, settings de scoring, etc.) cambió entre el dry-run y la escritura - las propuestas se calcularon contra un estado que ya no existe. No se escribió nada. Volvé a correr el dry-run.',
                    'before' => $before,
                    'expected_fingerprint' => $plan['source_fingerprint'],
                    'current_fingerprint' => $currentFingerprint,
                ]);
            }

            $created = ['term_concept_candidates' => 0, 'new_concept_candidates' => 0, 'concept_relations' => 0];
            $skipped = ['term_concept_candidates' => 0, 'new_concept_candidates' => 0, 'concept_relations' => 0];

            foreach ($plan['term_concept_candidates'] as $candidate) {
                // Idempotencia concurrency-safe (propiedad 2, hallazgo 3): la base decide vía el
                // índice único parcial (migración add_unique_constraints...), no una lectura previa
                // de la aplicación. `insertOrIgnore()` compila a `INSERT ... ON CONFLICT DO NOTHING`
                // en Postgres - atómico, no TOCTOU. Un `rejected` previo sigue bloqueando el reintento
                // (el índice es por (term_id, concept_id) sin importar el status).
                $affected = DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->insertOrIgnore([
                    'suggested_term_id' => $candidate['suggested_term_id'],
                    'suggested_concept_id' => $candidate['suggested_concept_id'],
                    'signals' => json_encode($candidate['signals']),
                    'confidence' => $candidate['confidence'],
                    'tier' => $candidate['tier'],
                    'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
                    'taxonomy_state_fingerprint' => $currentFingerprint,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($affected === 0) {
                    $skipped['term_concept_candidates']++;

                    continue;
                }

                $row = TaxonomyCandidateConceptLink::where('suggested_term_id', $candidate['suggested_term_id'])
                    ->where('suggested_concept_id', $candidate['suggested_concept_id'])
                    ->firstOrFail();

                $this->audit($row->id, "Candidato término→concepto encolado por --apply (term_id={$candidate['suggested_term_id']}, concept_id={$candidate['suggested_concept_id']}, tier={$candidate['tier']}, confidence={$candidate['confidence']}, autorizado_por={$authorizedBy})");
                $created['term_concept_candidates']++;
            }

            foreach ($plan['new_concept_candidates'] as $candidate) {
                $affected = DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->insertOrIgnore([
                    'suggested_term_id' => $candidate['suggested_term_id'],
                    'suggested_concept_id' => null,
                    'suggested_new_concept_name' => $candidate['suggested_new_concept_name'],
                    // Los "posibles duplicados" que el Builder ya evaluó viajan como señales para que
                    // el revisor los vea sin tener que recalcular el scoring en el momento.
                    'signals' => json_encode(['possible_existing_concepts' => $candidate['possible_existing_concepts']]),
                    'confidence' => 0.0,
                    'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
                    'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
                    'review_notes' => $candidate['reason'],
                    'taxonomy_state_fingerprint' => $currentFingerprint,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($affected === 0) {
                    $skipped['new_concept_candidates']++;

                    continue;
                }

                $row = TaxonomyCandidateConceptLink::where('suggested_term_id', $candidate['suggested_term_id'])
                    ->whereNull('suggested_concept_id')
                    ->firstOrFail();

                $this->audit($row->id, "Propuesta de concepto NUEVO encolada por --apply (term_id={$candidate['suggested_term_id']}, nombre sugerido=\"{$candidate['suggested_new_concept_name']}\", autorizado_por={$authorizedBy})");
                $created['new_concept_candidates']++;
            }

            foreach ($plan['concept_relations'] as $relation) {
                // Re-validación dentro de la transacción: `proposeConceptRelations()` ya descartó los
                // pares existentes, pero eso fue en el dry-run. Acá se vuelve a preguntar contra el
                // estado real (duplicado exacto, simétrico, vía inverso, y ciclos).
                $validation = $this->builder->validateConceptRelationProposal(
                    $relation['source_concept_id'],
                    $relation['target_concept_id'],
                    $relation['relation_type'],
                );

                if (! $validation['valid']) {
                    $skipped['concept_relations']++;

                    continue;
                }

                // Idempotencia concurrency-safe (hallazgo 3): esta tabla YA tenía
                // UNIQUE(source_concept_id, target_concept_id, relation_type) desde su creación -
                // insertOrIgnore() la aprovecha en vez de dejar que una violación de esa constraint
                // reviente toda la transacción de --apply si dos corridas compiten por el mismo par
                // exacto entre la validación de arriba y el insert.
                $affected = DB::connection('pgsql')->table('taxonomy_concept_relations')->insertOrIgnore([
                    'source_concept_id' => $relation['source_concept_id'],
                    'target_concept_id' => $relation['target_concept_id'],
                    'relation_type' => $relation['relation_type'],
                    'confidence' => $relation['confidence'],
                    // `status=candidate`, NUNCA `approved`: una relación concepto↔concepto sigue
                    // necesitando aprobación humana igual que antes de Phase C.
                    'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
                    'provenance' => json_encode($relation['provenance'] + [
                        'algorithm_version' => self::ALGORITHM_VERSION,
                        'taxonomy_state_fingerprint' => $currentFingerprint,
                        'evidence' => $relation['evidence'],
                        'authorized_by' => $authorizedBy,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($affected === 0) {
                    $skipped['concept_relations']++;

                    continue;
                }

                $row = TaxonomyConceptRelation::where('source_concept_id', $relation['source_concept_id'])
                    ->where('target_concept_id', $relation['target_concept_id'])
                    ->where('relation_type', $relation['relation_type'])
                    ->firstOrFail();

                TaxonomyAuditLogger::record(
                    entityType: TaxonomyConceptRelation::class,
                    entityId: $row->id,
                    field: 'status',
                    oldValue: null,
                    newValue: $row->status,
                    reason: "Relación concepto↔concepto propuesta por --apply ({$relation['source_concept_id']} -{$relation['relation_type']}-> {$relation['target_concept_id']}, confidence={$relation['confidence']}, autorizado_por={$authorizedBy})",
                    actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
                    algorithmVersion: self::ALGORITHM_VERSION,
                );
                $created['concept_relations']++;
            }

            $after = $this->counts();
            $protectedAfter = $this->protectedTableSignature();

            // Propiedad "no migración destructiva" (salvaguarda global 1 de docs/task.md): ni las
            // relaciones CPV protegidas ni el grafo publicado pueden haber cambiado acá. TASK-0003
            // hallazgo 5: el conteo NO prueba ausencia de UPDATE in-place - por eso se suma un
            // fingerprint de contenido (`protectedTableSignature()`). Si cualquiera de los dos
            // cambió, algo escribió lo que no debía - se revierte TODO.
            if ($after['taxonomy_term_cpv_relations'] !== $before['taxonomy_term_cpv_relations']
                || $after['taxonomy_term_concepts'] !== $before['taxonomy_term_concepts']
                || $after['taxonomy_canonical_concepts'] !== $before['taxonomy_canonical_concepts']
                || $protectedAfter !== $protectedBefore) {
                throw new \RuntimeException(
                    'ABORTADO: --apply modificó una tabla protegida (taxonomy_term_cpv_relations / '.
                    'taxonomy_term_concepts / taxonomy_canonical_concepts). Transacción revertida. '.
                    'antes='.json_encode($before).' después='.json_encode($after)
                );
            }

            return $this->outcome(self::RESULT_APPLIED, $plan, [
                'created' => $created,
                'skipped' => $skipped,
                'before' => $before,
                'after' => $after,
                'fingerprint' => $currentFingerprint,
                'authorized_by' => $authorizedBy,
            ]);
        });
    }

    /**
     * TASK-0003, hallazgo 5: fingerprint de CONTENIDO (no solo conteo) de las 3 tablas protegidas -
     * las 2 chicas (grafo) con hash completo vía `tableFingerprint()`, la grande
     * (`taxonomy_term_cpv_relations`, ~9.7k filas) con la señal barata COUNT+MAX(updated_at) vía
     * `tableVersionSignal()` (mismo mecanismo del hallazgo 4, no uno nuevo) - hashear 9.7k filas dos
     * veces por cada `--apply` sería caro dada la latencia documentada hacia Supabase.
     */
    private function protectedTableSignature(): string
    {
        return hash('sha256', implode('|', [
            CanonicalConceptBuilderService::tableFingerprint('taxonomy_term_concepts'),
            CanonicalConceptBuilderService::tableFingerprint('taxonomy_canonical_concepts'),
            CanonicalConceptBuilderService::tableVersionSignal('taxonomy_term_cpv_relations'),
        ]));
    }

    /**
     * Conteos de las tablas que importan para el before/after de cualquier corrida de escritura -
     * las que Phase C1 SÍ toca y las protegidas que NO debe tocar.
     *
     * TASK-0003, hallazgo 5: esto es DIAGNÓSTICO/legible-para-humano, no la garantía real de que
     * las tablas protegidas no se tocaron - un UPDATE in-place no cambia un conteo. La garantía
     * real es `protectedTableSignature()` (fingerprint de contenido), comparada en `apply()`.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $tables = [
            'taxonomy_candidate_concept_links',
            'taxonomy_concept_relations',
            'taxonomy_term_concepts',
            'taxonomy_canonical_concepts',
            'taxonomy_term_cpv_relations',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::connection('pgsql')->table($table)->count();
        }

        return $counts;
    }

    private function audit(int $candidateId, string $reason): void
    {
        TaxonomyAuditLogger::record(
            entityType: TaxonomyCandidateConceptLink::class,
            entityId: $candidateId,
            field: 'status',
            oldValue: null,
            newValue: TaxonomyCandidateConceptLink::STATUS_PENDING,
            reason: $reason,
            actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
            algorithmVersion: self::ALGORITHM_VERSION,
        );
    }

    private function outcome(string $result, array $plan, array $extra = []): array
    {
        return array_merge([
            'result' => $result,
            'plan' => $plan,
            'created' => ['term_concept_candidates' => 0, 'new_concept_candidates' => 0, 'concept_relations' => 0],
            'skipped' => ['term_concept_candidates' => 0, 'new_concept_candidates' => 0, 'concept_relations' => 0],
            'before' => [],
            'after' => [],
            'fingerprint' => null,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ], $extra);
    }
}
