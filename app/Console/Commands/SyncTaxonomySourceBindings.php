<?php

namespace App\Console\Commands;

use App\Models\TaxonomyTermSourceBinding;
use App\Services\Taxonomy\HtmlPageExtractor;
use App\Services\Taxonomy\RobotsTxtChecker;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * TAXV3-5 (ajuste V3 puntos 3/4: "Source Sync separado de Discovery"). A diferencia de
 * `taxonomy:crawl-source` (TAXV2-12, descubre términos que TODAVÍA NO existen recorriendo un
 * catálogo), este comando re-visita URLs que YA son un `taxonomy_term_source_bindings` verificado -
 * no descubre nada nuevo (eso sigue siendo Fase B, fuera de esta fase).
 *
 * Sin tabla de "run" ni cola de pendientes/visitados: a diferencia del crawler de descubrimiento,
 * acá no hay una frontera que crece (no se siguen links) - el universo a revisar es exactamente
 * el conjunto ya conocido de bindings `sync_enabled=true`. El checkpoint es más simple: se ordena
 * por `last_seen_at` (los nunca revisados o más antiguos primero) y cada invocación revisa
 * `--limit` bindings, avanzando solo con actualizar esa columna - repetible vía cron sin necesitar
 * ningún estado adicional.
 *
 * Estados (`NEW`/`MATCHED` del documento de migración son conceptos de DISCOVERY, no aplican acá -
 * un binding en revisión de Sync ya está, por definición, matched):
 * - `verified` + `last_seen_at` actualizado: el término sigue apareciendo en la página (UNCHANGED
 *   si el hash no cambió, CHANGED si cambió - ambos casos quedan como `verified`, la sección 4 del
 *   documento no pide un status nuevo para "cambió pero sigue ahí").
 * - `missing`: la página respondió pero el término ya NO aparece - NUNCA se borra el binding ni el
 *   término, solo se marca (queda visible en la Cola de Excepciones, TAXV3-4).
 * - `source_error`: la URL no respondió (timeout/HTTP no-2xx) - se reintenta en la próxima corrida
 *   sin escalar a MISSING (podría ser un problema temporal del sitio, no del término).
 */
class SyncTaxonomySourceBindings extends Command
{
    protected $signature = 'taxonomy:sync-source-bindings {source_id? : source_id de taxonomy_sources; si se omite, revisa todas las fuentes}
        {--limit=10 : máximo de bindings a revisar en esta invocación}
        {--dry-run}';

    protected $description = 'TAXV3-5: re-verifica bindings de fuente ya conocidos (no descubre términos nuevos - eso es taxonomy:crawl-source).';

    private const USER_AGENT = 'PerfilAfiliadosCPV-SyncBot/1.0 (+revision periodica de bindings ya verificados)';

    public function handle(RobotsTxtChecker $robots, HtmlPageExtractor $extractor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $query = TaxonomyTermSourceBinding::query()
            ->where('sync_enabled', true)
            ->whereNotNull('source_url')
            ->with('term')
            ->orderByRaw('last_seen_at ASC NULLS FIRST');

        if ($sourceId = $this->argument('source_id')) {
            $query->where('source_id', $sourceId);
        }

        $bindings = $query->limit($limit)->get();

        if ($bindings->isEmpty()) {
            $this->info('No hay bindings sync_enabled para revisar.');

            return self::SUCCESS;
        }

        $this->info("Revisando {$bindings->count()} binding(s)...");

        $unchanged = 0;
        $changed = 0;
        $missing = 0;
        $sourceErrors = 0;

        foreach ($bindings as $binding) {
            if (! $robots->isAllowed($binding->source_url, self::USER_AGENT)) {
                $this->warn("Omitido (robots.txt): {$binding->source_url}");

                continue;
            }

            try {
                $response = Http::timeout(15)->withUserAgent(self::USER_AGENT)->get($binding->source_url);
            } catch (\Throwable $e) {
                $sourceErrors++;
                $this->warn("Error al pedir {$binding->source_url}: {$e->getMessage()}");
                if (! $dryRun) {
                    $binding->update(['verification_status' => TaxonomyTermSourceBinding::STATUS_SOURCE_ERROR, 'last_seen_at' => now()]);
                }

                continue;
            }

            if (! $response->successful()) {
                $sourceErrors++;
                $this->warn("HTTP {$response->status()} en {$binding->source_url}");
                if (! $dryRun) {
                    $binding->update(['verification_status' => TaxonomyTermSourceBinding::STATUS_SOURCE_ERROR, 'last_seen_at' => now()]);
                }

                continue;
            }

            $html = $response->body();
            $text = $extractor->extractVisibleText($html);
            $hash = hash('sha256', $text);

            $needle = $binding->source_term ?: $binding->term?->term;
            $stillPresent = $needle && preg_match(
                '/(?<![\p{L}\p{N}])'.preg_quote(trim($needle), '/').'(?![\p{L}\p{N}])/iu',
                $text
            ) === 1;

            if ($dryRun) {
                $this->line("[dry-run] {$binding->source_url}: ".($stillPresent ? 'presente' : 'AUSENTE').($hash === $binding->content_hash ? ' (sin cambios)' : ' (contenido cambió)'));

                continue;
            }

            if (! $stillPresent) {
                $missing++;
                $oldStatus = $binding->verification_status;
                $binding->update(['verification_status' => TaxonomyTermSourceBinding::STATUS_MISSING, 'last_seen_at' => now()]);

                TaxonomyAuditLogger::record(
                    entityType: TaxonomyTermSourceBinding::class,
                    entityId: $binding->id,
                    field: 'verification_status',
                    oldValue: $oldStatus,
                    newValue: TaxonomyTermSourceBinding::STATUS_MISSING,
                    reason: 'taxonomy:sync-source-bindings: el término ya no aparece en la URL verificada.',
                    actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
                    algorithmVersion: 'source_sync_v1',
                );

                continue;
            }

            if ($hash !== $binding->content_hash) {
                $changed++;
            } else {
                $unchanged++;
            }

            $binding->update([
                'verification_status' => TaxonomyTermSourceBinding::STATUS_VERIFIED,
                'content_hash' => $hash,
                'last_seen_at' => now(),
                'last_verified_at' => now(),
            ]);
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        $this->info("Sin cambios: {$unchanged}. Contenido cambió: {$changed}. MISSING: {$missing}. Errores de fuente: {$sourceErrors}.");

        return self::SUCCESS;
    }
}
