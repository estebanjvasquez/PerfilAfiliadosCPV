<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\CanonicalConceptApplyService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Phase 3 (secciones 14-16 del pedido) + Phase C: CLI del Canonical Concept Builder.
 *
 * WRITE BARRIER: sin opciones, este comando SIEMPRE se comporta como AUDIT (solo lectura).
 * `--dry-run` corre además el Builder multi-signal (candidate retrieval + scoring + tiers), también
 * 100% en memoria.
 *
 * `--apply` (Phase C) SÍ escribe, pero solo en las COLAS DE REVISIÓN
 * (`taxonomy_candidate_concept_links` en `pending`, `taxonomy_concept_relations` en `candidate`) -
 * nunca publica en `taxonomy_term_concepts`, que sigue siendo exclusivamente resultado de una
 * aprobación humana en Filament. Ver `CanonicalConceptApplyService` para el detalle de las 6
 * propiedades de seguridad. `--apply` implica `--dry-run` (necesita las propuestas para
 * materializarlas, y usa ESA corrida, no uno nuevo, para que el fingerprint estampado corresponda).
 */
class BuildTaxonomyCanonicalConcepts extends Command
{
    protected $signature = 'taxonomy:build-canonical-concepts
        {--mode=audit : audit (default, solo lectura) es el único modo soportado fuera de --dry-run}
        {--dry-run : Corre además el Builder multi-signal (retrieval + scoring + tiers), sin persistir nada}
        {--apply : Phase C1 - materializa las propuestas del dry-run en las colas de revisión (NUNCA publica en taxonomy_term_concepts). Implica --dry-run}
        {--authorized-by= : OBLIGATORIO con --apply (TASK-0003, hallazgo 2, cerrado en Issue #2 comentario 5877665979) - referencia de autorización de esta escritura real (ej. "Issue #2 comment 5877665979", "TASK-0003"), debe incluir al menos un dígito. El ambiente objetivo se auto-detecta (app()->environment()), no se pasa acá. Ambos quedan en columnas propias del audit log}
        {--max-writes= : Phase C1 - tope de filas a crear en una corrida de --apply (default 500). Un plan mayor aborta sin escribir nada}
        {--limit= : Tope de términos a procesar en --dry-run (para corridas rápidas de verificación)}
        {--skip-audit : Phase 3.1 - omite AUDIT_EXISTING para medir --dry-run de forma aislada (diagnóstico de performance)}
        {--save-snapshot= : Phase 3.1 - vuelca el dry-run completo (all_results + instrumentación) a un JSON, para result-equivalence antes/después de un refactor}';

    protected $description = 'Phase 3/C: AUDIT_EXISTING (default), dry-run del Canonical Concept Builder, y --apply para encolar propuestas para revisión humana.';

    public function handle(CanonicalConceptBuilderService $builder, CanonicalConceptApplyService $applier): int
    {
        $result = [];

        if (! $this->option('skip-audit')) {
            $this->info('AUDIT_EXISTING (solo lectura) - clasificando los conceptos canónicos ya existentes...');
            $audit = $builder->auditExisting();

            $this->info("Conceptos auditados (carga dinámica, sin conteo fijo asumido): {$audit['concept_count']}");
            $this->table(['Flag', 'Conceptos'], collect($audit['flag_summary'])->map(fn ($count, $flag) => [$flag, $count])->values()->all());

            foreach ($audit['concepts'] as $concept) {
                if (in_array('VALID_IDENTITY_CLUSTER', $concept['flags'], true) && count($concept['flags']) === 1) {
                    continue;
                }
                $this->line("  #{$concept['concept_id']} \"{$concept['concept_name']}\" ({$concept['term_count']} términos) -> ".implode(', ', $concept['flags']));
            }

            $result['mode'] = 'audit';
            $result['audit'] = $audit;
        }

        if ($this->option('dry-run') || $this->option('apply')) {
            $this->newLine();
            $this->info('DRY-RUN Builder multi-signal - candidate retrieval + scoring + tiers (CERO escritura)...');
            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
            $dryRun = $builder->dryRun($limit);

            $this->info("Términos procesados: {$dryRun['terms_processed']} | Pares candidatos: {$dryRun['candidate_pairs_generated']} | Pares puntuados: {$dryRun['pairs_scored']}");
            $this->table(['Tier', 'Candidatos'], collect($dryRun['candidates_by_tier'])->map(fn ($c, $tier) => [$tier, $c])->values()->all());
            $this->info("Propuestas de concepto nuevo (PROPOSE_NEW_CONCEPT): {$dryRun['propose_new_concept_candidates']}");
            $this->info("Runtime: {$dryRun['runtime_ms']}ms | DB queries: {$dryRun['db_queries']} ({$dryRun['queries_per_term']}/término, {$dryRun['queries_per_candidate']}/candidato) | Embedding calls: {$dryRun['embedding_calls_made']} (siempre 0 en dry-run)");
            $this->table(['Etapa', 'Queries', 'Tiempo (ms)'], collect($dryRun['query_count_by_stage'])->map(fn ($c, $stage) => [$stage, $c, $dryRun['time_ms_by_stage'][$stage] ?? 0])->values()->all());

            $result['dry_run'] = $dryRun;
        }

        if ($this->option('apply')) {
            $this->newLine();
            $this->info('APPLY (Phase C1: '.CanonicalConceptApplyService::PHASE_LABEL.') - materializando propuestas en las colas de REVISIÓN...');
            $this->warn('Recordatorio: --apply NUNCA publica en taxonomy_term_concepts. Todo queda en estado pending/candidate esperando aprobación humana.');
            $this->warn('Esto NO es Phase C2 (aplicación de un payload ya revisado, inmutable) - ver docblock de CanonicalConceptApplyService.');

            $authorizationReference = trim((string) $this->option('authorized-by'));
            if ($authorizationReference === '' || ! preg_match('/\d/', $authorizationReference)) {
                $this->error('--apply requiere --authorized-by="<referencia de autorización con al menos un dígito, ej. \'Issue #2 comment 5877665979\' o \'TASK-0003\'>" (TASK-0003, hallazgo 2). No se ejecutó nada.');

                return self::FAILURE;
            }

            $maxWrites = $this->option('max-writes') !== null
                ? (int) $this->option('max-writes')
                : CanonicalConceptApplyService::DEFAULT_MAX_WRITES;

            $plan = $applier->planFrom($dryRun);
            $this->table(['A crear', 'Filas'], [
                ['taxonomy_candidate_concept_links (término→concepto)', count($plan['term_concept_candidates'])],
                ['taxonomy_candidate_concept_links (concepto nuevo)', count($plan['new_concept_candidates'])],
                ['taxonomy_concept_relations (status=candidate)', count($plan['concept_relations'])],
                ['TOTAL', $plan['total_writes']],
            ]);

            $apply = $applier->apply($dryRun, $authorizationReference, $maxWrites);
            $result['apply'] = $apply;

            if ($apply['result'] !== CanonicalConceptApplyService::RESULT_APPLIED) {
                $this->error("APPLY no se ejecutó: {$apply['result']}");
                if (isset($apply['note'])) {
                    $this->line('  '.$apply['note']);
                }

                if ($this->option('save-snapshot')) {
                    File::put($this->option('save-snapshot'), json_encode($result, JSON_PRETTY_PRINT));
                    $this->info('Snapshot guardado en '.$this->option('save-snapshot'));
                }

                return $apply['result'] === CanonicalConceptApplyService::RESULT_NOTHING_TO_APPLY
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            $this->info('APPLY OK - algoritmo '.$apply['algorithm_version']);
            $this->table(
                ['Cola', 'Creados', 'Omitidos (ya existían)'],
                collect($apply['created'])->map(fn ($count, $key) => [$key, $count, $apply['skipped'][$key] ?? 0])->values()->all()
            );
            $this->table(
                ['Tabla', 'Antes', 'Después'],
                collect($apply['before'])->map(fn ($count, $table) => [$table, $count, $apply['after'][$table] ?? '?'])->values()->all()
            );
            $this->info("Fingerprint estampado en cada candidato: {$apply['fingerprint']}");
            $this->info("Referencia de autorización: {$apply['authorization_reference']} | Ambiente: {$apply['target_environment']}");
        }

        if ($this->option('save-snapshot')) {
            File::put($this->option('save-snapshot'), json_encode($result, JSON_PRETTY_PRINT));
            $this->info('Snapshot guardado en '.$this->option('save-snapshot'));
        }

        return self::SUCCESS;
    }
}
