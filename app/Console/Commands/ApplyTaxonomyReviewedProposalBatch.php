<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\ReviewedProposalBatchManifest;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 1/PARTE 6/PARTE 8: CLI del lote atómico.
 *
 * DOS MODOS, Y EL SEGURO ES EL PREDETERMINADO. Sin `--execute` el comando corre el PREFLIGHT de lote
 * de solo lectura y mide sus propios statements: una invocación por descuido no puede aplicar nada.
 * Con `--execute` corre `applyBatch()` de verdad, y para eso exige CINCO cosas juntas: manifiesto,
 * hash autorizado citado aparte, referencia de autorización, entorno esperado declarado por quien
 * ejecuta, y confirmación.
 *
 * POR QUÉ `--expect-environment` ES OBLIGATORIO PARA EJECUTAR (PARTE 8): el entorno real NUNCA lo
 * provee quien llama - se auto-captura con `app()->environment()`, igual que `$targetEnvironment`
 * desde TASK-0004. Lo que `--expect-environment` aporta es lo contrario de una declaración: obliga al
 * operador a decir dónde CREE que está, y el comando se niega si no coincide con el entorno real. Así
 * un `--execute` escrito para staging no se ejecuta por error en otro lado.
 *
 * TRES CORRECCIONES DEL RE-AUDIT `6011317053`, las tres de gobernanza de ejecución:
 *
 * 1. `--id` está PROHIBIDO junto con `--execute`. Antes, `--id` sobrescribía los ids tomados del
 *    manifiesto y el lote ejecutaba ese conjunto más chico mientras el manifiesto verificaba con
 *    éxito, así que `--manifest=<FULL aprobado> --id=491 --execute` ejecutaba sólo #491. Eso es
 *    exactamente lo que el manifiesto existe para impedir, y el daño no es «queda una fila sin
 *    aplicar»: #491 escribe `taxonomy_term_concepts`, así que tras esa ejecución parcial el fingerprint
 *    global cambia y las once revisiones restantes pueden quedar obsoletas. El servicio además exige
 *    igualdad exacta de conjuntos (`BATCH_MANIFEST_REQUEST_MISMATCH`), como defensa en profundidad.
 * 2. SÓLO `staging` puede ejecutar por este comando. `local` queda fuera a propósito: los artefactos
 *    de la ronda 1 probaron que el `APP_ENV=local` de la estación de desarrollo está conectado al
 *    dataset compartido REAL, así que permitir `--execute --expect-environment=local` era más amplio
 *    que lo autorizado. `local` conserva preview y generación de manifiestos, que son de solo lectura.
 *    `testing` es la excepción de los tests automatizados y este comando NO la acepta. `production`
 *    sigue prohibido en el servicio, no sólo acá.
 * 3. `--expect-manifest-fingerprint` es obligatorio para ejecutar. Un manifiesto auto-hasheado sólo
 *    prueba «este archivo no se editó sin cambiar su hash»; NO prueba «este es el hash que el dueño
 *    autorizó». Con el hash citado aparte, un manifiesto distinto pero internamente válido se rechaza
 *    aunque coincida con el estado vivo.
 *
 * NO EJECUTA NADA EN ESTA RONDA. TASK-0007 ronda 1 autoriza el código, los tests, el manifiesto de
 * solo lectura y el preflight de lote contra datos reales - explícitamente NO el APPLY real. El modo
 * `--execute` existe para el día en que haya una autorización humana nueva que cite el
 * manifest_fingerprint.
 */
class ApplyTaxonomyReviewedProposalBatch extends Command
{
    protected $signature = 'taxonomy:apply-reviewed-proposal-batch
        {--manifest= : ruta del manifiesto JSON autorizado (OBLIGATORIO con --execute). Se verifica contra el estado vivo DENTRO de la transacción}
        {--id=* : ids explícitos del lote. PROHIBIDO junto con --execute: un manifiesto autoriza UN conjunto atómico y --id no puede redefinirlo}
        {--execute : ejecuta el lote de VERDAD. Sin esta opción el comando es un preflight de solo lectura}
        {--authorized-by= : OBLIGATORIO con --execute - referencia de autorización de ESTA ejecución (con al menos un dígito). Distinta de quién revisó}
        {--expect-manifest-fingerprint= : OBLIGATORIO con --execute - el manifest_fingerprint que el DUEÑO autorizó, citado aparte del archivo. Si no coincide con el manifiesto cargado, no se ejecuta}
        {--expect-environment= : OBLIGATORIO con --execute - el entorno donde el operador cree que está. Si no coincide con el real, no se ejecuta}
        {--force : omite la confirmación interactiva de --execute (para una ejecución no interactiva ya autorizada)}
        {--allow-subset-manifest : permite --execute con un manifiesto cuyo ALCANCE es EXPLICIT_IDS, o sea que el MANIFIESTO ata un subconjunto a propósito. No habilita tomar un subconjunto de un manifiesto ya atado}
        {--json= : ruta donde guardar el informe completo en JSON (el artefacto de auditoría)}';

    protected $description = 'TASK-0007: preflight de SOLO LECTURA (por defecto) o APPLY ATÓMICO de un lote de propuestas revisadas contra un baseline único, en una sola transacción.';

    public function handle(ReviewedProposalService $service): int
    {
        $manifestPath = $this->option('manifest');
        $manifest = null;

        if ($manifestPath !== null && $manifestPath !== '') {
            $manifest = ReviewedProposalBatchManifest::loadFromFile($manifestPath);

            if ($manifest === null) {
                $this->error("No se pudo leer un manifiesto JSON válido en {$manifestPath}. No se ejecutó nada.");

                return self::FAILURE;
            }
        }

        $explicitIds = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        // =====================================================================================
        // Re-audit `6011317053`, BLOQUEO 1, opción PREFERIDA del orquestador: con `--execute`, `--id`
        // no puede redefinir el manifiesto **en absoluto**. Se rechaza el uso simultáneo en vez de
        // intentar reconciliarlos.
        //
        // Por qué rechazar y no sólo exigir igualdad (que el servicio igual exige, como defensa en
        // profundidad): si los dos insumos pueden describir conjuntos distintos, alguien va a creer
        // alguna vez que `--id` "acota" una ejecución autorizada. No lo acota: la rompe. Un manifiesto
        // autoriza UN conjunto atómico, y quitarle una fila puede dejar obsoletas las demás.
        // =====================================================================================
        if ($this->option('execute') && $explicitIds !== []) {
            $this->error('--id no se puede usar junto con --execute: un manifiesto autoriza UN conjunto atómico y --id no puede redefinirlo ni acotarlo. Ejecutar un subconjunto de un manifiesto autorizado no es "ejecutar menos" - es ejecutar algo que nadie autorizó, y además puede dejar obsoletas las revisiones restantes si alguna de las ejecutadas cambia el grafo. No se ejecutó nada.');

            return self::FAILURE;
        }

        $ids = $explicitIds;

        if ($ids === [] && $manifest !== null) {
            $ids = array_map(fn (array $row) => (int) $row['proposal_id'], $manifest['proposals'] ?? []);
        }

        if ($ids === []) {
            // Sin ids ni manifiesto: la cola ejecutable completa. Útil para el preflight; para
            // `--execute` el manifiesto es obligatorio, así que este camino nunca ejecuta a ciegas.
            $ids = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
                ->where('status', \App\Models\TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->option('execute')
            ? $this->runExecute($service, $ids, $manifest)
            : $this->runPreflight($service, $ids, $manifest);
    }

    private function runPreflight(ReviewedProposalService $service, array $ids, ?array $manifest): int
    {
        $report = $service->previewBatch($ids, $manifest);

        $this->line('modo:                       '.$report['mode']);
        $this->line('entorno:                    '.$report['target_environment'].($report['environment_authorized_for_execution'] ? '' : ' (NO autorizado para ejecutar)'));
        $this->line('baseline de taxonomía:      '.$report['baseline_taxonomy_fingerprint']);
        $this->line('manifest_fingerprint:       '.($report['manifest_fingerprint'] ?? '— (sin manifiesto)'));
        $this->line('propuestas aceptadas:       '.$report['accepted_proposal_count'].' ('.implode(', ', array_map(fn ($id) => '#'.$id, $report['accepted_proposal_ids'])).')');
        $this->line('unidades de ejecución:      '.$report['execution_unit_count']);

        if ($report['execution_units'] !== []) {
            $this->newLine();
            $this->table(
                ['Unidad', 'Entrada', 'Filas', 'Decisión', 'Origen', 'Payload', 'Obsoleta', 'Drift', 'Bloqueo'],
                array_map(fn (array $unit) => [
                    $unit['kind'],
                    '#'.$unit['entry_proposal_id'],
                    implode(',', array_map(fn ($id) => '#'.$id, $unit['proposal_ids'])),
                    $unit['decision'],
                    $unit['source_label'],
                    self::tristate($unit['payload_fingerprint_valid'] ?? null, 'ok', 'INVALIDO'),
                    self::tristate($unit['stale'] ?? null, 'SI', 'no'),
                    self::tristate($unit['source_snapshot_drift'] ?? null, 'SI', 'no'),
                    $unit['batch_blocker'] ?? '—',
                ], $report['execution_units']),
            );
        }

        $this->newLine();
        if ($report['blocker'] === null) {
            $this->info('LOTE SIN BLOQUEOS: las '.$report['accepted_proposal_count'].' propuestas validan contra UN mismo baseline en '.$report['execution_unit_count'].' unidades de ejecución.');
        } else {
            $this->error('LOTE BLOQUEADO: '.$report['blocker']);
            $this->line('  '.($report['detail']['note'] ?? ''));
            if (($report['detail']['blocking_proposal_id'] ?? null) !== null) {
                $this->line('  propuesta que bloquea: #'.$report['detail']['blocking_proposal_id']);
            }
        }

        if ($report['projected_write_set'] !== []) {
            $this->newLine();
            $this->line('PROYECCIÓN de escrituras del lote (descripción, NO ejecución):');
            foreach ($report['projected_write_set'] as $write) {
                $this->line(sprintf('  %-36s %-7s x%d', $write['table'], $write['operation'], $write['rows']));
            }
            $this->line('  total de filas: '.$report['projected_write_count']);
        }

        if ($report['projected_protected_counts'] !== null) {
            $this->newLine();
            $this->line('CONTEOS PROTEGIDOS proyectados:');
            foreach ($report['projected_protected_counts']['counts'] as $key => $value) {
                $delta = $report['projected_protected_counts']['delta'][$key];
                $this->line(sprintf('  %-38s %6d -> %6d  (%+d)', $key, $report['protected_counts_now'][$key], $value, $delta));
            }
        }

        if ($report['write_statements_observed'] !== 0) {
            $this->newLine();
            $this->error('ABORTADO: el preflight de lote emitió '.$report['write_statements_observed'].' statement(s) de ESCRITURA, lo que viola su contrato de solo lectura.');
            foreach ($report['write_statements'] as $sql) {
                $this->line('  '.$sql);
            }

            return self::FAILURE;
        }

        $this->persistJson($report);

        $this->newLine();
        $this->info('Preflight de lote de SOLO LECTURA completado: 0 statements de escritura, 0 APPLY, 0 publicación. Un lote sin bloqueos significa "hoy nada lo impide", NO una autorización de ejecución.');

        return $report['blocker'] === null ? self::SUCCESS : self::FAILURE;
    }

    private function runExecute(ReviewedProposalService $service, array $ids, ?array $manifest): int
    {
        if ($manifest === null) {
            $this->error('--execute exige --manifest=<ruta>: un lote real no se ejecuta sobre "los ids que llegaron" sino sobre la cola exacta que se autorizó (PARTE 4). No se ejecutó nada.');

            return self::FAILURE;
        }

        $scope = $manifest['scope'] ?? ReviewedProposalBatchManifest::SCOPE_FULL_PENDING_QUEUE;
        if ($scope !== ReviewedProposalBatchManifest::SCOPE_FULL_PENDING_QUEUE && ! $this->option('allow-subset-manifest')) {
            $this->error("El manifiesto declara alcance `{$scope}`: ata un SUBCONJUNTO de la cola y dejaría otras propuestas PENDING_APPLY sin ejecutar. Si eso es lo que se autorizó, hay que decirlo explícitamente con --allow-subset-manifest. No se ejecutó nada.");

            return self::FAILURE;
        }

        $authorizationReference = trim((string) $this->option('authorized-by'));
        if ($authorizationReference === '' || ! preg_match('/\d/', $authorizationReference)) {
            $this->error('--authorized-by="<referencia con al menos un dígito>" es obligatorio con --execute (mismo criterio que apply()). No se ejecutó nada.');

            return self::FAILURE;
        }

        // =====================================================================================
        // Re-audit `6011317053`, BLOQUEO 3: el SEGUNDO insumo de confianza. El fingerprint que el
        // dueño autorizó se cita aparte y NUNCA se deduce del archivo cargado - deducirlo colapsaría
        // los dos insumos en uno y volvería a no probar nada. La validación de forma y la comparación
        // viven en el servicio (`authorizationFingerprintBlocker()`), que es el único camino de
        // escritura; acá sólo se exige que el operador lo haya provisto, para poder dar un mensaje
        // útil antes de llegar al servicio.
        // =====================================================================================
        $expectedFingerprint = trim((string) $this->option('expect-manifest-fingerprint'));
        if ($expectedFingerprint === '') {
            $this->error('--expect-manifest-fingerprint=<sha256> es obligatorio con --execute: el manifiesto auto-hasheado sólo prueba que el archivo no se editó, no que sea el que el dueño autorizó. Hay que citar el hash autorizado aparte. No se ejecutó nada.');

            return self::FAILURE;
        }

        $expected = trim((string) $this->option('expect-environment'));
        $actual = app()->environment();
        if ($expected === '') {
            $this->error('--expect-environment=<entorno> es obligatorio con --execute (PARTE 8): el comando se niega a ejecutar si el entorno real no es el que el operador esperaba. No se ejecutó nada.');

            return self::FAILURE;
        }
        if ($expected !== $actual) {
            $this->error("El entorno real es `{$actual}` y se esperaba `{$expected}`. No se ejecutó nada.");

            return self::FAILURE;
        }

        // Re-audit `6011317053`, BLOQUEO 2: el CLI acepta SÓLO los entornos operativos, sin la
        // excepción de `testing`. Esa excepción existe para los tests automatizados, que llaman al
        // servicio directamente dentro de una transacción que nunca commitea; una persona corriendo
        // este comando en `testing` no es un test de fixture. Y `local` queda fuera a propósito:
        // los artefactos de la ronda 1 probaron que el `local` de esta estación lee el dataset
        // compartido REAL, así que ejecutar desde acá sería más amplio de lo autorizado.
        if (! in_array($actual, ReviewedProposalService::BATCH_EXECUTABLE_ENVIRONMENTS, true)) {
            $this->error("El entorno `{$actual}` no puede ejecutar un lote por este comando (único entorno operativo autorizado: ".implode(', ', ReviewedProposalService::BATCH_EXECUTABLE_ENVIRONMENTS).'). `local` conserva preview y generación de manifiestos, que son de solo lectura; `'.ReviewedProposalService::BATCH_FIXTURE_TEST_ENVIRONMENT.'` es la excepción de tests automatizados, no un objetivo operativo. No se ejecutó nada.');

            return self::FAILURE;
        }

        $this->warn('================================================================');
        $this->warn(' APPLY ATÓMICO REAL de '.count($ids).' propuesta(s) revisada(s)');
        $this->warn('  entorno:              '.$actual);
        $this->warn('  alcance manifiesto:   '.$scope);
        $this->warn('  manifest_fingerprint: '.($manifest['manifest_fingerprint'] ?? '—'));
        $this->warn('  hash autorizado:      '.$expectedFingerprint);
        $this->warn('  autorización:         '.$authorizationReference);
        $this->warn('  ids:                  '.implode(', ', array_map(fn ($id) => '#'.$id, $ids)));
        $this->warn(' Esto PUBLICA taxonomía y es irreversible por esta vía.');
        $this->warn('================================================================');

        if (! $this->option('force') && ! $this->confirm('¿Ejecutar el lote con esta autorización?', false)) {
            $this->info('Cancelado. No se ejecutó nada.');

            return self::FAILURE;
        }

        $result = $service->applyBatch($ids, $authorizationReference, $manifest, $expectedFingerprint);

        $this->newLine();
        $this->line('resultado:                  '.$result['result']);
        $this->line('baseline de taxonomía:      '.($result['baseline_taxonomy_fingerprint'] ?? '—'));

        if ($result['blocker'] !== null) {
            $this->error('LOTE BLOQUEADO: '.$result['blocker'].' - CERO escrituras y CERO propuestas abortadas.');
            $this->line('  '.($result['detail']['note'] ?? ''));
            if (($result['detail']['blocking_proposal_id'] ?? null) !== null) {
                $this->line('  propuesta que bloquea: #'.$result['detail']['blocking_proposal_id']);
            }
        } else {
            $this->info('LOTE APLICADO: '.$result['applied_proposal_count'].' propuesta(s) en '.$result['execution_unit_count'].' unidad(es), en una sola transacción.');
            foreach ($result['units'] as $unit) {
                $this->line(sprintf('  %-16s #%-6s %-18s %s', $unit['kind'], $unit['entry_proposal_id'], $unit['decision'], json_encode($unit['application_result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            }
        }

        $this->persistJson(array_merge($result, [
            'mode' => ReviewedProposalService::BATCH_MODE_EXECUTE,
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'protected_counts_after' => ReviewedProposalService::protectedQueueShape(),
        ]));

        return $result['result'] === ReviewedProposalService::BATCH_RESULT_APPLIED
            || $result['result'] === ReviewedProposalService::BATCH_RESULT_ALREADY_EXECUTED
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function persistJson(array $report): void
    {
        if (! ($path = $this->option('json'))) {
            return;
        }

        // Las unidades del resultado de ejecución traen modelos Eloquent en algunos campos; el
        // informe de preflight ya viene plano. `json_encode` con PARTIAL_OUTPUT_ON_ERROR evitaría
        // perder el artefacto entero por un campo no serializable, pero preferimos que falle
        // ruidosamente si eso pasa: un artefacto de auditoría a medias es peor que ninguno.
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info("Informe JSON guardado en {$path}");
    }

    /** `null` = la compuerta no se evaluó porque una anterior bloqueó primero - nunca "pasó". */
    private static function tristate(?bool $value, string $yes, string $no): string
    {
        return match ($value) {
            true => $yes,
            false => $no,
            null => '—',
        };
    }
}
