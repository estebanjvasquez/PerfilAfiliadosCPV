<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\ReviewedProposalBatchManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 4: genera el manifiesto de lote, de SOLO
 * LECTURA.
 *
 * Para qué existe un comando aparte en vez de generarlo dentro del apply: el manifiesto es la
 * evidencia que la autorización humana va a CITAR, así que tiene que poder producirse y revisarse
 * ANTES de que exista cualquier autorización de ejecución. Generarlo dentro del comando que ejecuta
 * obligaría a correr el ejecutor para poder leer lo que se va a autorizar.
 *
 * Igual que `taxonomy:reviewed-proposals-preflight`, instala un detector de escrituras y falla si
 * observa un solo statement de escritura: la promesa de solo lectura se mide, no se declara. No acepta
 * `--authorized-by` y no lo necesita - generar un manifiesto no autoriza nada.
 */
class GenerateTaxonomyReviewedProposalBatchManifest extends Command
{
    protected $signature = 'taxonomy:reviewed-proposal-batch-manifest
        {--id=* : ids concretos a atar; sin esta opción ata TODA la cola PENDING_APPLY}
        {--out= : ruta donde guardar el manifiesto JSON (el artefacto que la autorización va a citar)}';

    protected $description = 'TASK-0007: genera de SOLO LECTURA el manifiesto del lote de propuestas revisadas (ids, decisiones, fingerprints, unidades de ejecución, baseline y forma de la cola protegida). Cero escrituras, cero APPLY.';

    public function handle(): int
    {
        $writeStatements = [];
        DB::listen(function ($query) use (&$writeStatements) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writeStatements[] = $query->sql;
            }
        });

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $manifest = ReviewedProposalBatchManifest::generate($ids);

        if ($writeStatements !== []) {
            $this->error('ABORTADO: la generación del manifiesto emitió '.count($writeStatements).' statement(s) de ESCRITURA, lo que viola su contrato de solo lectura.');
            foreach (array_slice($writeStatements, 0, 10) as $sql) {
                $this->line('  '.$sql);
            }

            return self::FAILURE;
        }

        if ($manifest['proposals'] === []) {
            $this->warn('No hay propuestas que atar'.($ids === [] ? ' (la cola PENDING_APPLY está vacía).' : ' con esos ids.'));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Tipo', 'Decisión', 'Candidato', 'Relación', 'Grupo', 'payload_fingerprint'],
            array_map(fn (array $row) => [
                $row['proposal_id'],
                $row['proposal_type'],
                $row['decision'],
                $row['candidate_link_id'] ?? '—',
                $row['concept_relation_id'] ?? '—',
                $row['proposal_group_id'] === null ? '—' : substr($row['proposal_group_id'], 0, 8).'…',
                substr((string) $row['payload_fingerprint'], 0, 16).'…',
            ], $manifest['proposals']),
        );

        $this->newLine();
        $this->line('propuestas atadas:          '.count($manifest['proposals']));
        $this->line('unidades de ejecución:      '.count($manifest['execution_units']).' (un grupo bilingüe cuenta UNA vez)');
        foreach ($manifest['execution_units'] as $unit) {
            $this->line(sprintf('  %-16s entrada #%-6s filas: %s', $unit['kind'], $unit['entry_proposal_id'], implode(', ', array_map(fn ($id) => '#'.$id, $unit['proposal_ids']))));
        }
        $this->line('baseline de taxonomía:      '.$manifest['baseline_taxonomy_fingerprint']);
        $this->info('manifest_fingerprint:       '.$manifest['manifest_fingerprint']);

        if ($path = $this->option('out')) {
            file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Manifiesto guardado en {$path}");
        }

        $this->newLine();
        $this->warn('Generar un manifiesto NO autoriza ejecutarlo. La ejecución real exige una autorización humana nueva y explícita que cite este manifest_fingerprint.');

        return self::SUCCESS;
    }
}
