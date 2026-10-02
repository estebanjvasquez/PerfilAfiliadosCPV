<?php

namespace App\Console\Commands;

use App\Models\TaxonomyReviewedProposal;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1/PARTE 4: CLI de
 * `ReviewedProposalService::preflight()` - el informe de aplicabilidad pre-APPLY de las propuestas
 * congeladas, **sin escribir nada**.
 *
 * Por qué no alcanzaba `taxonomy:apply-reviewed-proposal`: ese comando aplica de verdad, y cuando la
 * validación falla `apply()` registra el hallazgo con `abort()`, que pasa la propuesta a `ABORTED` de
 * forma TERMINAL. Usarlo "para ver si todavía sirve" quema la decisión humana, y re-congelar está
 * bloqueado por el índice único parcial. Este comando responde la misma pregunta sin ese riesgo.
 *
 * GARANTÍA DE NO-EFECTOS, verificada y no solo declarada: el comando instala un listener de consultas
 * y aborta con código de error si detecta UN SOLO statement de escritura (INSERT/UPDATE/DELETE/
 * TRUNCATE/DDL) durante su ejecución. Si una refactorización futura introdujera un efecto por
 * descuido, este comando falla en vez de escribir. No es un reemplazo del test de ausencia de efectos
 * (`ReviewedProposalPreflightTest`) - es la misma garantía disponible también en producción.
 *
 * No requiere `--authorized-by` y no lo acepta: no hay nada que autorizar porque no ejecuta nada.
 * Correrlo contra el entorno compartido es seguro, y es justamente para lo que existe.
 */
class PreflightTaxonomyReviewedProposals extends Command
{
    protected $signature = 'taxonomy:reviewed-proposals-preflight
        {--id=* : ids concretos de taxonomy_reviewed_proposals; sin esta opción evalúa TODAS}
        {--json= : ruta donde guardar el informe completo en JSON (el artefacto de auditoría)}
        {--full : muestra también el write-set esperado y el detalle de cada bloqueo en la salida de consola}';

    protected $description = 'Phase C2 / TASK-0006D: valida de SOLO LECTURA si cada propuesta revisada se podría aplicar hoy. Cero escrituras, cero APPLY, cero publicación.';

    public function handle(ReviewedProposalService $service): int
    {
        $writeStatements = [];
        DB::listen(function ($query) use (&$writeStatements) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writeStatements[] = $query->sql;
            }
        });

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $report = $service->preflightAll($ids);

        // La comprobación que hace honesta la promesa del docblock: si algo escribió, se dice y se
        // falla, en vez de reportar "solo lectura" sobre una corrida que no lo fue.
        if ($writeStatements !== []) {
            $this->error('ABORTADO: el preflight emitió '.count($writeStatements).' statement(s) de ESCRITURA, lo que viola su contrato de solo lectura. Statements:');
            foreach (array_slice($writeStatements, 0, 10) as $sql) {
                $this->line('  '.$sql);
            }

            return self::FAILURE;
        }

        if ($report === []) {
            $this->warn('No hay propuestas revisadas que evaluar'.($ids === [] ? '.' : ' con esos ids.'));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Tipo', 'Decisión', 'Origen', 'Conf.', 'Obsoleta', 'Payload', 'Drift', 'Resultado', 'Categoría'],
            array_map(fn (array $row) => [
                $row['proposal_id'],
                $row['proposal_type'] === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK ? 'CANDIDATO' : 'RELACION',
                $row['decision'],
                $row['source_label'],
                self::confirmationCell($row),
                self::tristate($row['stale'] ?? null, yes: 'SI', no: 'no'),
                self::tristate($row['payload_fingerprint_valid'] ?? null, yes: 'ok', no: 'INVALIDO'),
                self::tristate($row['source_snapshot_drift'] ?? null, yes: 'SI', no: 'no'),
                $row['blocker'],
                $row['governance_category'],
            ], $report),
        );

        $byCategory = [];
        foreach ($report as $row) {
            $byCategory[$row['governance_category']][] = $row['proposal_id'];
        }
        foreach ($byCategory as $category => $proposalIds) {
            $this->line(sprintf('%-28s %d: %s', $category, count($proposalIds), implode(', ', array_map(fn ($id) => '#'.$id, $proposalIds))));
        }

        if ($this->option('full')) {
            foreach ($report as $row) {
                $this->newLine();
                $this->line("== #{$row['proposal_id']} {$row['blocker']} ==");
                $this->line('  acción requerida: '.$row['action_required']);
                if (($row['would_apply_abort_with'] ?? null) !== null) {
                    $this->warn('  apply() ABORTARIA con '.$row['would_apply_abort_with'].' (terminal) - no usar apply() como prueba.');
                }
                foreach ($row['expected_write_set'] as $write) {
                    $this->line(sprintf('  write-set: %-34s %-7s x%d  %s', $write['table'], $write['operation'], $write['rows'], $write['description']));
                }
                $this->line('  total de filas que apply() escribiría: '.$row['expected_write_count']);
            }
        }

        if ($path = $this->option('json')) {
            $payload = [
                'generated_at' => now()->format('Y-m-d H:i:s'),
                'task' => 'TASK-0006D',
                'governance_reference' => 'Issue #2 comentario 5949253156',
                'mode' => 'READ_ONLY_PREFLIGHT',
                'write_statements_observed' => 0,
                'target_environment' => app()->environment(),
                'payload_version' => ReviewedProposalService::PAYLOAD_VERSION,
                'proposals_evaluated' => count($report),
                'by_governance_category' => array_map('count', $byCategory),
                'proposals' => $report,
            ];

            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Informe JSON guardado en {$path}");
        }

        $this->newLine();
        $this->info('Preflight de SOLO LECTURA completado: 0 statements de escritura, 0 APPLY, 0 publicación. Un READY_TO_APPLY significa "hoy no hay nada que lo impida", NO una autorización de ejecución.');

        return self::SUCCESS;
    }

    private static function confirmationCell(array $row): string
    {
        if (! $row['confirmation']['required']) {
            return 'no exige';
        }

        return $row['confirmation']['confirmed_at'] !== null
            ? 'confirmada ('.($row['confirmation']['channel'] ?? '?').')'
            : 'PENDIENTE';
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
