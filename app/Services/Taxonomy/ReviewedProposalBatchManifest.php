<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyReviewedProposal;

/**
 * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 4: el MANIFIESTO de lote.
 *
 * QUÉ PROBLEMA RESUELVE, que no es el mismo que resuelve la validación. `applyBatch()` valida que
 * cada propuesta del conjunto sea aplicable; el manifiesto valida algo distinto y anterior: que el
 * conjunto sea EXACTAMENTE el que un humano autorizó. Son dos preguntas separadas y las dos hacen
 * falta - un lote técnicamente impecable sobre una cola que cambió después de la autorización
 * ejecutaría algo que nadie aprobó.
 *
 * La autorización humana de TASK-0007 va a citar un fingerprint de manifiesto. Eso sólo sirve si el
 * fingerprint es DETERMINÍSTICO: regenerar el manifiesto sobre el mismo estado tiene que dar el mismo
 * hash. Por eso `generated_at`, el entorno y las notas quedan FUERA del hash - son evidencia de
 * cuándo se generó, no parte de lo que se autoriza. Lo que entra al hash es exactamente lo que no
 * puede cambiar sin invalidar la autorización:
 *
 * - los ids exactos de las propuestas;
 * - la decisión de cada una;
 * - su `payload_fingerprint` (identidad inmutable del payload revisado);
 * - su `taxonomy_state_fingerprint` (el snapshot contra el que se revisó);
 * - el candidato/relación de origen;
 * - el `proposal_group_id` donde haya;
 * - las unidades de ejecución resultantes;
 * - el fingerprint de baseline de la taxonomía;
 * - la forma de la cola protegida.
 *
 * ES DATO DE EJECUCIÓN, NO LÓGICA DE NEGOCIO (requisito explícito del comentario): acá no hay una
 * sola mención a petroleum/refinery/pipeline ni a los ids reales. El manifiesto se GENERA leyendo la
 * cola, cualquiera que esa cola sea; los 12 ids reales viven en el artefacto JSON de auditoría, no en
 * el código.
 *
 * SOLO LECTURA de punta a punta: esta clase no escribe nada en ninguna tabla.
 */
final class ReviewedProposalBatchManifest
{
    public const MANIFEST_VERSION = 'taxonomy-reviewed-proposal-batch-manifest/v1';

    /**
     * El manifiesto ata TODA la cola ejecutable: declara que `PENDING_APPLY` y "lo autorizado" son el
     * mismo conjunto. Es el caso real de TASK-0007, y es el único alcance donde «apareció una
     * propuesta PENDING_APPLY fuera del manifiesto» es un hallazgo: si la autorización cubre la cola
     * entera, una fila nueva significa que la cola cambió después de autorizarla.
     */
    public const SCOPE_FULL_PENDING_QUEUE = 'FULL_PENDING_QUEUE';

    /**
     * El manifiesto ata un SUBCONJUNTO explícito. Acá "hay otras PENDING_APPLY" no es drift: es
     * exactamente lo que el subconjunto significa - se autorizó parte de la cola y el resto queda
     * deliberadamente sin ejecutar. El alcance entra en el fingerprint, así que un manifiesto de
     * subconjunto no se puede re-etiquetar como de cola completa sin invalidar su hash.
     */
    public const SCOPE_EXPLICIT_IDS = 'EXPLICIT_IDS';

    /**
     * Genera el manifiesto desde la cola VIVA.
     *
     * @param  int[]  $proposalIds  Vacío = todas las propuestas `PENDING_APPLY` (la cola ejecutable
     *                completa, que es el caso real de TASK-0007). Con ids explícitos, exactamente esos.
     */
    public static function generate(array $proposalIds = []): array
    {
        $ids = ReviewedProposalService::normalisedBatchIds($proposalIds);

        $proposals = TaxonomyReviewedProposal::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [], fn ($q) => $q->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY))
            ->orderBy('id')
            ->get();

        $bound = [];
        foreach ($proposals as $proposal) {
            $bound[] = self::boundFields($proposal);
        }

        $binding = [
            'manifest_version' => self::MANIFEST_VERSION,
            'scope' => $ids === [] ? self::SCOPE_FULL_PENDING_QUEUE : self::SCOPE_EXPLICIT_IDS,
            'baseline_taxonomy_fingerprint' => CanonicalConceptBuilderService::dryRunInputFingerprint(),
            'proposals' => $bound,
            'execution_units' => self::executionUnits($proposals),
            'protected_queue_shape' => ReviewedProposalService::protectedQueueShape(),
        ];

        return array_merge($binding, [
            'manifest_fingerprint' => self::fingerprintFor($binding),
            // Evidencia, deliberadamente FUERA del fingerprint: ver el docblock de la clase.
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'generated_in_environment' => app()->environment(),
            'payload_version' => ReviewedProposalService::PAYLOAD_VERSION,
            'task' => 'TASK-0007',
            'governance_reference' => 'Issue #2 comentario 5997693379',
            'authorization_status' => 'NOT_AUTHORIZED_FOR_EXECUTION',
            'authorization_note' => 'Generar un manifiesto NO autoriza ejecutarlo. La ejecución real exige una autorización humana nueva y explícita que cite este manifest_fingerprint y este conjunto de ids.',
            'fingerprint_excludes' => ['generated_at', 'generated_in_environment', 'manifest_fingerprint', 'payload_version', 'task', 'governance_reference', 'authorization_status', 'authorization_note', 'fingerprint_excludes'],
        ]);
    }

    /**
     * Los campos ATADOS de una propuesta: los que el comentario exige y ni uno más. Que la lista sea
     * fija y corta es parte del diseño - atar columnas que cambian legítimamente (ej. las de
     * confirmación) haría que el manifiesto caducara por motivos que no son drift.
     */
    private static function boundFields(TaxonomyReviewedProposal $proposal): array
    {
        return [
            'proposal_id' => (int) $proposal->id,
            'proposal_type' => $proposal->proposal_type,
            'decision' => $proposal->decision,
            'payload_fingerprint' => $proposal->payload_fingerprint,
            'taxonomy_state_fingerprint' => $proposal->taxonomy_state_fingerprint,
            'candidate_link_id' => $proposal->candidate_link_id === null ? null : (int) $proposal->candidate_link_id,
            'concept_relation_id' => $proposal->concept_relation_id === null ? null : (int) $proposal->concept_relation_id,
            'proposal_group_id' => $proposal->proposal_group_id,
        ];
    }

    /**
     * Las unidades de ejecución que el conjunto describe: un grupo bilingüe cuenta UNA vez, con sus
     * miembros listados. Va dentro del fingerprint porque es parte de lo que se autoriza: "11
     * unidades para 12 filas" es una afirmación verificable, y si mañana el mismo conjunto produjera
     * 12 unidades, algo cambió en la agrupación y la autorización ya no describe la ejecución.
     */
    private static function executionUnits(\Illuminate\Support\Collection $proposals): array
    {
        $units = [];
        $seenGroups = [];

        foreach ($proposals as $proposal) {
            if ($proposal->proposal_group_id !== null) {
                if (isset($seenGroups[$proposal->proposal_group_id])) {
                    continue;
                }
                $seenGroups[$proposal->proposal_group_id] = true;

                $memberIds = $proposals->where('proposal_group_id', $proposal->proposal_group_id)
                    ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                sort($memberIds);

                $units[] = [
                    'kind' => 'BILINGUAL_GROUP',
                    'entry_proposal_id' => $memberIds[0],
                    'proposal_ids' => $memberIds,
                    'proposal_group_id' => $proposal->proposal_group_id,
                ];

                continue;
            }

            $units[] = [
                'kind' => 'SINGLE_PROPOSAL',
                'entry_proposal_id' => (int) $proposal->id,
                'proposal_ids' => [(int) $proposal->id],
                'proposal_group_id' => null,
            ];
        }

        return $units;
    }

    /**
     * Hash determinístico del contenido ATADO. Mismo criterio que
     * `ReviewedProposalService::computePayloadFingerprint()`: claves ordenadas recursivamente antes
     * de serializar, para que el valor no dependa del orden en que PHP/Postgres entreguen el JSON.
     */
    public static function fingerprintFor(array $manifest): string
    {
        $binding = [
            'manifest_version' => $manifest['manifest_version'] ?? null,
            'scope' => $manifest['scope'] ?? null,
            'baseline_taxonomy_fingerprint' => $manifest['baseline_taxonomy_fingerprint'] ?? null,
            'proposals' => $manifest['proposals'] ?? [],
            'execution_units' => $manifest['execution_units'] ?? [],
            'protected_queue_shape' => $manifest['protected_queue_shape'] ?? [],
        ];

        return ReviewedProposalService::computePayloadFingerprint(['decision_payload' => $binding]);
    }

    /**
     * Verifica el manifiesto contra el estado VIVO. Solo lectura.
     *
     * Las seis causas de rechazo que pide el comentario, en el orden en que se evalúan:
     *
     * 1. el manifiesto no es íntegro consigo mismo (su propio hash no coincide) -> `BATCH_TAMPER_DETECTED`;
     * 2. el conjunto PEDIDO no es exactamente el conjunto ATADO -> `BATCH_MANIFEST_REQUEST_MISMATCH`
     *    (re-audit `6011317053`, BLOQUEO 1);
     * 3. el baseline de la taxonomía cambió -> `BATCH_BASELINE_STALE`;
     * 4. alguna propuesta esperada desapareció o dejó de ser `PENDING_APPLY` -> `BATCH_QUEUE_DRIFT`
     *    (o `BATCH_ALREADY_EXECUTED` si TODAS están ya `APPLIED`: replay, no drift);
     * 5. la identidad atada difiere (payload/fingerprint de estado/decisión/origen/grupo) ->
     *    `BATCH_TAMPER_DETECTED`, porque un payload congelado no cambia por vías legítimas;
     * 6. apareció una `PENDING_APPLY` FUERA del manifiesto -> `BATCH_QUEUE_DRIFT`;
     * 7. la forma de la cola protegida difiere -> `BATCH_QUEUE_DRIFT`.
     *
     * El ORDEN de las dos primeras importa: la integridad del archivo se comprueba ANTES que la
     * igualdad de conjuntos, así que un manifiesto al que le editaron la lista de propuestas se
     * reporta como TAMPER -que es el hallazgo correcto- y no como un simple desajuste de pedido.
     *
     * LÍMITE DECLARADO, no escondido: la causa 5 es una foto del instante de la verificación. Un
     * `freeze()` que commitee un milisegundo después no se vería - `freeze()` no toma el lock de
     * ejecución, y no debe tomarlo (una revisión humana no puede quedar esperando una ejecución). Eso
     * es correcto y no debilita nada: una propuesta recién congelada no está en el lote, no está
     * bloqueada y no se escribe. La compuerta existe para detectar drift en la ventana de
     * GOBERNANZA entre autorizar y ejecutar, que se mide en horas.
     *
     * @param  string  $currentBaselineFingerprint  Computado UNA vez por el lote, para no recomputarlo acá.
     * @param  int[]  $requestedProposalIds  El conjunto que el lote va a ejecutar. OBLIGATORIO y sin
     *                valor por defecto a propósito: con un default, un llamador futuro podría omitirlo
     *                y volver a habilitar en silencio la ejecución de un SUBCONJUNTO de un manifiesto
     *                autorizado, que es exactamente el BLOQUEO 1 del re-audit `6011317053`.
     * @return array{ok:bool, blocker:?string, detail:array, manifest_fingerprint:?string, findings:array}
     */
    public static function verify(array $manifest, string $currentBaselineFingerprint, array $requestedProposalIds): array
    {
        $declaredFingerprint = $manifest['manifest_fingerprint'] ?? null;

        if (($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION) {
            return self::failure(ReviewedProposalService::BATCH_TAMPER_DETECTED, [
                'note' => 'El manifiesto no declara la versión esperada: no se puede verificar un formato desconocido.',
                'expected_manifest_version' => self::MANIFEST_VERSION,
                'declared_manifest_version' => $manifest['manifest_version'] ?? null,
            ], $declaredFingerprint);
        }

        $recomputed = self::fingerprintFor($manifest);

        if ($declaredFingerprint !== $recomputed) {
            return self::failure(ReviewedProposalService::BATCH_TAMPER_DETECTED, [
                'note' => 'El manifiesto no coincide con su propio fingerprint: su contenido se editó después de generarlo. No se ejecuta nada.',
                'declared_manifest_fingerprint' => $declaredFingerprint,
                'recomputed_manifest_fingerprint' => $recomputed,
            ], $declaredFingerprint);
        }

        // ============================================================================
        // Re-audit `6011317053`, BLOQUEO 1: IGUALDAD EXACTA entre lo pedido y lo atado.
        //
        // Antes faltaba, y lo que faltaba no era una comprobación cosmética: el manifiesto verificaba
        // que todas SUS propuestas siguieran vivas y que la cola no tuviera ninguna de más, pero nadie
        // comprobaba que el conjunto a EJECUTAR fuera ese mismo. Con eso,
        // `--manifest=<FULL aprobado> --id=491` verificaba con éxito y ejecutaba sólo #491.
        //
        // Y el daño va más allá de "queda una fila sin aplicar": #491 escribe
        // `taxonomy_term_concepts`, así que tras esa ejecución parcial el fingerprint global cambia y
        // las otras once revisiones quedan obsoletas - el conjunto atómico autorizado deja de ser
        // recuperable COMO ESE CONJUNTO. Por eso la compuerta corre antes del baseline y de la
        // liveness: no tiene sentido verificar el estado vivo de un manifiesto que no es el que se va a
        // ejecutar.
        //
        // La comparación es de CONJUNTOS normalizados, así que el orden de llegada de los ids y los
        // duplicados no importan - lo que importa es que sean los mismos.
        // ============================================================================
        $boundIds = ReviewedProposalService::normalisedBatchIds(
            array_map(fn (array $row) => (int) $row['proposal_id'], $manifest['proposals'] ?? [])
        );
        $requestedIds = ReviewedProposalService::normalisedBatchIds($requestedProposalIds);

        if ($boundIds !== $requestedIds) {
            return self::failure(ReviewedProposalService::BATCH_MANIFEST_REQUEST_MISMATCH, [
                'note' => 'El conjunto que se pidió ejecutar NO es exactamente el que el manifiesto ata. Un manifiesto autoriza UN conjunto atómico: ejecutar un subconjunto suyo no es "ejecutar menos", es ejecutar algo que nadie autorizó, y además puede dejar obsoletas las revisiones restantes si alguna de las ejecutadas cambia el grafo. Cero escrituras.',
                'manifest_bound_proposal_ids' => $boundIds,
                'requested_proposal_ids' => $requestedIds,
                'requested_but_not_bound' => array_values(array_diff($requestedIds, $boundIds)),
                'bound_but_not_requested' => array_values(array_diff($boundIds, $requestedIds)),
                'scope' => $manifest['scope'] ?? null,
                'subset_flag_note' => 'Un alcance EXPLICIT_IDS significa que el MANIFIESTO ata un subconjunto a propósito; nunca habilita tomar un subconjunto arbitrario de un manifiesto ya atado.',
            ], $declaredFingerprint);
        }

        if (($manifest['baseline_taxonomy_fingerprint'] ?? null) !== $currentBaselineFingerprint) {
            return self::failure(ReviewedProposalService::BATCH_BASELINE_STALE, [
                'note' => 'El estado de la taxonomía cambió desde que se generó el manifiesto, así que el conjunto autorizado ya no describe el estado real. Hace falta regenerar el manifiesto y una autorización nueva - no un reintento.',
                'manifest_baseline_fingerprint' => $manifest['baseline_taxonomy_fingerprint'] ?? null,
                'current_baseline_fingerprint' => $currentBaselineFingerprint,
            ], $declaredFingerprint);
        }

        $expected = $manifest['proposals'] ?? [];
        $expectedIds = array_map(fn (array $row) => (int) $row['proposal_id'], $expected);

        $live = TaxonomyReviewedProposal::query()->whereIn('id', $expectedIds)->orderBy('id')->get()->keyBy('id');

        $missing = [];
        $notPending = [];
        $identityMismatches = [];
        $appliedIds = [];

        foreach ($expected as $row) {
            $proposal = $live->get($row['proposal_id']);

            if (! $proposal) {
                $missing[] = (int) $row['proposal_id'];

                continue;
            }

            if ($proposal->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                $appliedIds[] = (int) $proposal->id;
            }

            if ($proposal->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY) {
                $notPending[] = ['proposal_id' => (int) $proposal->id, 'status' => $proposal->status];
            }

            $liveBound = self::boundFields($proposal);
            $expectedBound = array_intersect_key($row, $liveBound);
            foreach ($liveBound as $field => $value) {
                if (($expectedBound[$field] ?? null) !== $value) {
                    $identityMismatches[] = [
                        'proposal_id' => (int) $proposal->id,
                        'field' => $field,
                        'manifest_value' => $expectedBound[$field] ?? null,
                        'live_value' => $value,
                    ];
                }
            }
        }

        // Replay del lote ENTERO ya ejecutado: no es drift, no queda nada por hacer.
        if ($expectedIds !== [] && count($appliedIds) === count($expectedIds) && $identityMismatches === []) {
            return self::failure(ReviewedProposalService::BATCH_ALREADY_EXECUTED, [
                'note' => 'Todas las propuestas del manifiesto ya están APPLIED: este manifiesto ya se ejecutó. Cero escrituras nuevas.',
                'applied_proposal_ids' => $appliedIds,
            ], $declaredFingerprint);
        }

        if ($missing !== [] || $notPending !== []) {
            return self::failure(ReviewedProposalService::BATCH_QUEUE_DRIFT, [
                'note' => 'La cola viva ya no coincide con el manifiesto autorizado.',
                'missing_proposal_ids' => $missing,
                'non_pending_proposals' => $notPending,
                'blocking_proposal_id' => $missing[0] ?? $notPending[0]['proposal_id'] ?? null,
            ], $declaredFingerprint);
        }

        if ($identityMismatches !== []) {
            return self::failure(ReviewedProposalService::BATCH_TAMPER_DETECTED, [
                'note' => 'La identidad atada de alguna propuesta difiere del manifiesto. Un payload congelado y sus fingerprints no cambian por ninguna vía legítima, así que esto es un hallazgo de seguridad: INVESTIGAR antes de ejecutar nada (ver taxonomy_audit_log).',
                'identity_mismatches' => $identityMismatches,
                'blocking_proposal_id' => $identityMismatches[0]['proposal_id'],
            ], $declaredFingerprint);
        }

        // Sólo tiene sentido si el manifiesto declara cubrir la cola ENTERA - ver
        // `SCOPE_EXPLICIT_IDS`: en un manifiesto de subconjunto, que existan otras `PENDING_APPLY` no
        // es drift sino la definición del subconjunto.
        $scope = $manifest['scope'] ?? self::SCOPE_FULL_PENDING_QUEUE;
        $extra = [];

        if ($scope === self::SCOPE_FULL_PENDING_QUEUE) {
            $extra = TaxonomyReviewedProposal::query()
                ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
                ->whereNotIn('id', $expectedIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($extra !== []) {
            return self::failure(ReviewedProposalService::BATCH_QUEUE_DRIFT, [
                'note' => 'Apareció al menos una propuesta PENDING_APPLY FUERA del manifiesto autorizado, que declara cubrir la cola ejecutable COMPLETA. El lote no se ejecuta: aplicar "las autorizadas" mientras la cola tiene una fila más dejaría una decisión humana sin ejecutar sin que nadie lo haya decidido.',
                'unauthorised_pending_proposal_ids' => $extra,
                'blocking_proposal_id' => $extra[0],
            ], $declaredFingerprint);
        }

        $shapeNow = ReviewedProposalService::protectedQueueShape();
        $shapeDiff = [];
        foreach (($manifest['protected_queue_shape'] ?? []) as $key => $value) {
            if (($shapeNow[$key] ?? null) !== $value) {
                $shapeDiff[$key] = ['manifest' => $value, 'live' => $shapeNow[$key] ?? null];
            }
        }

        if ($shapeDiff !== []) {
            return self::failure(ReviewedProposalService::BATCH_QUEUE_DRIFT, [
                'note' => 'La forma de la cola protegida cambió desde el manifiesto (conteos de candidatos/relaciones/conceptos/propuestas). Hace falta regenerar y reautorizar.',
                'protected_shape_differences' => $shapeDiff,
            ], $declaredFingerprint);
        }

        return [
            'ok' => true,
            'blocker' => null,
            'detail' => [],
            'manifest_fingerprint' => $declaredFingerprint,
            'findings' => [
                'manifest_version' => self::MANIFEST_VERSION,
                'scope' => $scope,
                'manifest_fingerprint_recomputed_ok' => true,
                'requested_set_equals_bound_set' => true,
                'baseline_matches_current' => true,
                'bound_proposal_count' => count($expectedIds),
                'bound_proposal_ids' => $expectedIds,
                'requested_proposal_ids' => $requestedIds,
                'all_bound_proposals_pending_apply' => true,
                'identity_matches' => true,
                // `null` = no aplica a un manifiesto de subconjunto, nunca "pasó" - misma convención
                // de honestidad que el resto de los informes de esta fase.
                'no_unauthorised_pending_proposals' => $scope === self::SCOPE_FULL_PENDING_QUEUE ? true : null,
                'protected_shape_matches' => true,
            ],
        ];
    }

    private static function failure(string $blocker, array $detail, ?string $manifestFingerprint): array
    {
        return [
            'ok' => false,
            'blocker' => $blocker,
            'detail' => $detail,
            'manifest_fingerprint' => $manifestFingerprint,
            'findings' => [],
        ];
    }

    /**
     * Lee un manifiesto de un archivo JSON. Devuelve `null` si no existe o no es JSON válido - el
     * llamador decide qué hacer, porque "no pude leer el manifiesto" y "el manifiesto no coincide"
     * son dos cosas distintas y colapsarlas haría ambiguo el diagnóstico.
     */
    public static function loadFromFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
