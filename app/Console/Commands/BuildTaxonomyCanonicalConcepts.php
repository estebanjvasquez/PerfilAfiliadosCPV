<?php

namespace App\Console\Commands;

use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Illuminate\Console\Command;

/**
 * Phase 3 (secciones 14-16 del pedido): CLI del Canonical Concept Builder.
 *
 * SALVAGUARDA PRINCIPAL - WRITE BARRIER (sección 16): sin opciones, este comando SIEMPRE se
 * comporta como AUDIT (solo lectura) - nunca como publish. `--dry-run` corre además el Builder
 * multi-signal (candidate retrieval + scoring + tiers), también 100% en memoria. `--apply` está
 * deliberadamente BLOQUEADO en esta entrega (sección 16/17: "‑‑apply NO debe habilitarse todavía
 * para poblar concept relations automáticamente") - existe en el signature para que la interfaz ya
 * esté preparada, pero cualquier intento de usarlo termina en error, sin tocar la base de datos.
 */
class BuildTaxonomyCanonicalConcepts extends Command
{
    protected $signature = 'taxonomy:build-canonical-concepts
        {--mode=audit : audit (default, solo lectura) es el único modo soportado fuera de --dry-run}
        {--dry-run : Corre además el Builder multi-signal (retrieval + scoring + tiers), sin persistir nada}
        {--apply : BLOQUEADO en esta entrega - ver sección 16/17 del pedido de Phase 3}
        {--limit= : Tope de términos a procesar en --dry-run (para corridas rápidas de verificación)}';

    protected $description = 'Phase 3: AUDIT_EXISTING (default) y/o dry-run del Canonical Concept Builder. Nunca escribe sin --apply, que está bloqueado en esta entrega.';

    public function handle(CanonicalConceptBuilderService $builder): int
    {
        if ($this->option('apply')) {
            $this->error('WRITE MODE BLOQUEADO: --apply no está habilitado en esta entrega de Phase 3 (sección 16/17 del pedido). No se ejecutó ninguna escritura.');

            return self::FAILURE;
        }

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

        $result = ['mode' => 'audit', 'audit' => $audit];

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('DRY-RUN Builder multi-signal - candidate retrieval + scoring + tiers (CERO escritura)...');
            $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
            $dryRun = $builder->dryRun($limit);

            $this->info("Términos procesados: {$dryRun['terms_processed']} | Pares candidatos: {$dryRun['candidate_pairs_generated']} | Pares puntuados: {$dryRun['pairs_scored']}");
            $this->table(['Tier', 'Candidatos'], collect($dryRun['candidates_by_tier'])->map(fn ($c, $tier) => [$tier, $c])->values()->all());
            $this->info("Propuestas de concepto nuevo (PROPOSE_NEW_CONCEPT): {$dryRun['propose_new_concept_candidates']}");
            $this->info("Runtime: {$dryRun['runtime_ms']}ms | DB queries: {$dryRun['db_queries']} | Embedding calls: {$dryRun['embedding_calls_made']} (siempre 0 en dry-run)");

            $result['dry_run'] = $dryRun;
        }

        return self::SUCCESS;
    }
}
