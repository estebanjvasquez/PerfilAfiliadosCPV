<?php

namespace App\Console\Commands;

use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\Taxonomy\TaxonomyAutoMapper;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-3: corre `TaxonomyAutoMapper` SOLO sobre términos sin ninguna relación CPV todavía (277 al
 * momento de escribir esto: 225 `unmapped` heredados de V2 + 52 `needs_review` nuevos de V3) -
 * `->doesntHave('cpvRelations')` es la guarda que garantiza que las 9.727 relaciones existentes
 * nunca se tocan, sin importar cuántas veces se corra este comando.
 *
 * Política de confianza (configurable en Taxonomía CPV -> Pesos y ranking -> "Auto Mapper", nunca
 * hardcoded):
 * - >= auto_approve_confidence (0.95): `status=approved`, peso completo.
 * - >= auto_activate_confidence (0.85): `status=approved` igual (queda visible en el buscador -
 *   "auto activar"), pero con peso conservador (85% del calculado) y marcado para revisión
 *   OPCIONAL (`matched_on` lo indica) - no aparece como tarea en la Cola de Excepciones (TAXV3-4).
 * - >= candidate_confidence_floor (0.70): `status=candidate` (no aparece en el buscador, no es una
 *   tarea urgente - la Cola de Excepciones sí la muestra, de más baja prioridad).
 * - < floor: no crea relación - el término queda tal cual para cuando haya mejores datos.
 *
 * El término pasa a `mapping_review_status=auto_mapped` SOLO si terminó con una relación
 * `approved` - una relación `candidate` no "resuelve" el término (sigue `needs_review`, visible
 * como candidato de baja prioridad, no oculto).
 */
class AutoMapTaxonomyTerms extends Command
{
    protected $signature = 'taxonomy:auto-map-terms {--limit= : máximo de términos a procesar en esta invocación} {--dry-run}';

    protected $description = 'TAXV3-3: propone y aplica relaciones CPV automáticas para términos sin ninguna relación todavía, según la política de confianza configurada.';

    private const ALGORITHM_VERSION = 'auto_mapper_v1';

    public function handle(TaxonomyAutoMapper $mapper): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $autoApprove = (float) (DB::connection('pgsql')->table('taxonomy_settings')->where('key', 'auto_mapping.auto_approve_confidence')->value('value') ?? 0.95);
        $autoActivate = (float) (DB::connection('pgsql')->table('taxonomy_settings')->where('key', 'auto_mapping.auto_activate_confidence')->value('value') ?? 0.85);

        $query = TaxonomyTerm::query()
            ->whereIn('mapping_review_status', [TaxonomyTerm::MAPPING_NEEDS_REVIEW, TaxonomyTerm::MAPPING_UNMAPPED])
            ->doesntHave('cpvRelations');

        if ($limit) {
            $query->limit($limit);
        }

        $terms = $query->get();

        $this->info("Procesando {$terms->count()} término(s) sin ninguna relación CPV...");

        $approved = 0;
        $activated = 0;
        $candidates = 0;
        $unresolved = 0;

        $bar = $this->output->createProgressBar($terms->count());
        $bar->start();

        foreach ($terms as $term) {
            $proposal = $mapper->proposeCandidate($term);

            if (! $proposal) {
                $unresolved++;
                $bar->advance();

                continue;
            }

            $confidence = $proposal['confidence'];
            $isAutoApprove = $confidence >= $autoApprove;
            $isAutoActivate = ! $isAutoApprove && $confidence >= $autoActivate;

            $status = ($isAutoApprove || $isAutoActivate)
                ? TaxonomyTermCpvRelation::STATUS_APPROVED
                : TaxonomyTermCpvRelation::STATUS_CANDIDATE;

            $weight = $isAutoActivate ? round($proposal['weight'] * 0.85, 4) : $proposal['weight'];

            if ($isAutoApprove) {
                $approved++;
            } elseif ($isAutoActivate) {
                $activated++;
            } else {
                $candidates++;
            }

            if ($dryRun) {
                $this->line("\n[dry-run] {$term->term} -> {$proposal['cpv_code']} (confianza {$confidence}, status={$status})");
                $bar->advance();

                continue;
            }

            $matchedOn = $proposal['evidence'][0] ?? null;
            if ($isAutoActivate) {
                $matchedOn .= ' [peso conservador - revisión opcional]';
            }

            $relation = TaxonomyTermCpvRelation::query()->create([
                'term_id' => $term->id,
                'cpv_code' => $proposal['cpv_code'],
                'category_id' => $proposal['category_id'],
                'level' => $proposal['level'],
                'relation_type' => $proposal['relation_type'],
                'weight' => $weight,
                'confidence' => $confidence,
                'matched_on' => $matchedOn,
                'evidence' => $proposal['evidence'],
                'source' => self::ALGORITHM_VERSION,
                'status' => $status,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyTermCpvRelation::class,
                entityId: $relation->id,
                field: 'status',
                oldValue: null,
                newValue: $status,
                actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
                algorithmVersion: self::ALGORITHM_VERSION,
            );

            if ($status === TaxonomyTermCpvRelation::STATUS_APPROVED) {
                $term->update(['mapping_review_status' => TaxonomyTerm::MAPPING_AUTO_MAPPED]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Aprobados: {$approved}. Auto-activados (peso conservador): {$activated}. Candidatos (no urgentes): {$candidates}. Sin señal suficiente: {$unresolved}.");

        return self::SUCCESS;
    }
}
