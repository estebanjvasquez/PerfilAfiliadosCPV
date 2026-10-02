<?php

namespace App\Console\Commands;

use App\Models\TaxonomyReviewedProposal;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006E (Issue #2 comentario `5955148859`): CLI de
 * `ReviewedProposalService::supersedeStaleProposal()` - retira de la cola una revisión OBSOLETA sin
 * destruirla, para que su candidato vuelva a la revisión humana normal contra el estado actual.
 *
 * Por qué existe como comando y no como script suelto: la transición es una acción de gobernanza sobre
 * datos reales, así que tiene que ser código revisable, versionado y repetible, con la misma convención
 * de autorización explícita que `taxonomy:apply-reviewed-proposal` (`--authorized-by` obligatorio, no
 * vacío y con al menos un dígito).
 *
 * LO QUE **NO** HACE, y conviene decirlo porque es justamente lo que lo vuelve seguro: no aplica, no
 * publica, no borra, no crea una propuesta sucesora y no decide nada en nombre de nadie. Después de
 * correrlo, la decisión nueva sobre el candidato la toma una PERSONA por la UI de Filament.
 *
 * `--dry-run` corre todas las validaciones y muestra qué pasaría sin escribir: el equivalente de
 * `taxonomy:reviewed-proposals-preflight` para esta transición.
 */
class SupersedeStaleTaxonomyReviewedProposal extends Command
{
    protected $signature = 'taxonomy:supersede-stale-reviewed-proposal
        {--id=* : OBLIGATORIO - ids de taxonomy_reviewed_proposals a superseder. Se puede repetir}
        {--authorized-by= : OBLIGATORIO - referencia de gobernanza de ESTA supersesión (debe incluir al menos un dígito)}
        {--reason= : OBLIGATORIO - motivo explícito; queda durable en supersession_reason}
        {--dry-run : valida y muestra el efecto esperado SIN escribir nada}';

    protected $description = 'TASK-0006E: supersede (de forma NO destructiva) una propuesta revisada obsoleta, liberando su slot para una revisión humana nueva. No aplica, no publica y no crea sucesor.';

    public function handle(ReviewedProposalService $service): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->option('id')))));
        $reference = trim((string) $this->option('authorized-by'));
        $reason = trim((string) $this->option('reason'));

        if ($ids === []) {
            $this->error('--id=<n> es obligatorio (se puede repetir). No se ejecutó nada.');

            return self::FAILURE;
        }

        if ($reference === '' || ! preg_match('/\d/', $reference)) {
            $this->error('--authorized-by="<referencia con al menos un dígito>" es obligatorio - misma convención que apply()/confirm(). No se ejecutó nada.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason="<motivo>" es obligatorio: retirar una decisión humana de la cola sin motivo registrado no es auditable. No se ejecutó nada.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Precondiciones leídas ANTES de escribir, y reportadas: el operador ve contra qué estado va a
        // actuar en vez de confiar en que el comando acertó.
        $this->line('Conteos protegidos ANTES: '.$this->protectedCountsLine());
        $this->newLine();

        $exit = self::SUCCESS;
        foreach ($ids as $id) {
            $proposal = TaxonomyReviewedProposal::query()->find($id);

            if (! $proposal) {
                $this->error("#{$id}: no existe.");
                $exit = self::FAILURE;

                continue;
            }

            $this->line("== #{$id} ==");
            $this->line('  estado actual: '.$proposal->status.' | decision: '.$proposal->decision.' | origen: '
                .($proposal->candidate_link_id ? 'candidato #'.$proposal->candidate_link_id : 'relación #'.$proposal->concept_relation_id));

            if ($dryRun) {
                // El preflight ya responde "¿es obsoleta?" sin escribir, reusando la MISMA cadena de
                // validación que apply(). Acá se reutiliza en vez de reimplementar el diagnóstico.
                $report = $service->preflight($id);
                $this->line('  preflight: '.$report['blocker'].' | obsoleta: '.self::yesNo($report['stale'] ?? null)
                    .' | payload válido: '.self::yesNo($report['payload_fingerprint_valid'] ?? null));
                $this->warn('  DRY-RUN: no se escribió nada.');
                $this->newLine();

                continue;
            }

            $outcome = $service->supersedeStaleProposal($id, $reference, $reason);

            $this->line('  resultado: '.$outcome['result']);

            if ($outcome['result'] !== ReviewedProposalService::RESULT_SUPERSEDED) {
                $this->warn('  no se supersedió (ver el resultado de arriba). Cero escrituras para esta propuesta.');
                $exit = self::FAILURE;
                $this->newLine();

                continue;
            }

            foreach ($outcome['proposals'] as $superseded) {
                $this->info(sprintf(
                    '  #%d -> %s | superseded_at=%s | sucesor=%s | ref=%s',
                    $superseded->id,
                    $superseded->status,
                    $superseded->superseded_at?->format('Y-m-d H:i:s'),
                    $superseded->superseded_by_proposal_id === null ? 'NINGUNO (por diseño)' : '#'.$superseded->superseded_by_proposal_id,
                    $superseded->supersession_reference,
                ));
            }

            $delta = $outcome['state_delta'] ?? [];
            $this->line('  delta de estado: fingerprint congelado '.substr((string) ($delta['frozen_taxonomy_state_fingerprint'] ?? '?'), 0, 12)
                .' -> actual '.substr((string) ($delta['current_taxonomy_state_fingerprint'] ?? '?'), 0, 12)
                .' | conceptos creados desde la revisión: '.($delta['concepts_created_since_review_count'] ?? '?'));
            foreach (($delta['concepts_created_since_review'] ?? []) as $concept) {
                $this->line(sprintf('    + concepto #%d  %s / %s  (%s)', $concept['id'], $concept['canonical_name_es'] ?? '—', $concept['canonical_name_en'] ?? '—', $concept['created_at']));
            }
            $this->newLine();
        }

        $this->line('Conteos protegidos DESPUÉS: '.$this->protectedCountsLine());
        $this->newLine();
        $this->info('Supersesión NO destructiva: cero APPLY, cero publicación, cero sucesores. La decisión nueva sobre cada candidato la toma una PERSONA por la UI de Filament.');

        return $exit;
    }

    private function protectedCountsLine(): string
    {
        $c = DB::connection('pgsql');

        return sprintf(
            'candidatos=%d relaciones=%d TERM->CONCEPT=%d conceptos=%d TERM->CPV=%d propuestas=%d (PENDING_APPLY=%d APPLIED=%d ABORTED=%d SUPERSEDED=%d)',
            $c->table('taxonomy_candidate_concept_links')->count(),
            $c->table('taxonomy_concept_relations')->count(),
            $c->table('taxonomy_term_concepts')->count(),
            $c->table('taxonomy_canonical_concepts')->count(),
            $c->table('taxonomy_term_cpv_relations')->count(),
            $c->table('taxonomy_reviewed_proposals')->count(),
            $c->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count(),
            $c->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_APPLIED)->count(),
            $c->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_ABORTED)->count(),
            $c->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_SUPERSEDED)->count(),
        );
    }

    private static function yesNo(?bool $value): string
    {
        return match ($value) {
            true => 'SI',
            false => 'no',
            null => '—',
        };
    }
}
