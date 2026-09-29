<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Console\Command;

/**
 * TASK-0004 (Phase C2): CLI de `ReviewedProposalService::apply()` - equivalente operativo del
 * `--apply` de `taxonomy:build-canonical-concepts` para Phase C1, pero para el segundo paso del
 * contrato (payload YA congelado -> escritura real autorizada). NO congela payloads (eso requiere
 * un `$reviewer` autenticado - ver `ReviewedProposalService::freeze()`, invocado hoy desde
 * servicio/tests; wiring de UI de Filament para que un humano dispare `freeze()` queda fuera de
 * alcance de esta tarea, ver `docs/orquestador/tasks/0004-phase-c2-immutable-apply.md`).
 *
 * Sin autorización real de aplicar contra el ambiente compartido (Issue #2, TASK-0004: "no real C2
 * apply against shared/production data without a NEW explicit human authorization"), este comando
 * no debe correrse contra `production`/`staging` en esta ronda - existe para pruebas manuales
 * locales y para que el operador lo tenga disponible el día que sí haya autorización explícita.
 */
class ApplyTaxonomyReviewedProposal extends Command
{
    protected $signature = 'taxonomy:apply-reviewed-proposal
        {id : id de la fila en taxonomy_reviewed_proposals a aplicar}
        {--authorized-by= : OBLIGATORIO - referencia de autorización de ESTA ejecución (ej. "Issue #2 comment 5886148283"), debe incluir al menos un dígito. Distinta de quién revisó (eso ya quedó congelado en el payload)}';

    protected $description = 'Phase C2: aplica un payload de revisión YA congelado (taxonomy_reviewed_proposals) - valida contra el estado real y escribe transaccionalmente, o aborta sin escribir nada.';

    public function handle(ReviewedProposalService $service): int
    {
        $authorizationReference = trim((string) $this->option('authorized-by'));
        if ($authorizationReference === '' || ! preg_match('/\d/', $authorizationReference)) {
            $this->error('--authorized-by="<referencia con al menos un dígito>" es obligatorio (mismo criterio que --apply de Phase C1). No se ejecutó nada.');

            return self::FAILURE;
        }

        $result = $service->apply((int) $this->argument('id'), $authorizationReference);

        $this->info("Resultado: {$result['result']}");
        if ($result['abort_reason']) {
            $this->warn("Motivo de aborto: {$result['abort_reason']}");
        }
        if ($result['application_result']) {
            $this->table(['Campo', 'Valor'], collect($result['application_result'])->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all());
        }

        return in_array($result['result'], [ReviewedProposalService::RESULT_APPLIED, ReviewedProposalService::RESULT_ALREADY_APPLIED], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
