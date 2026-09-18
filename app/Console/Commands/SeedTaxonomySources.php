<?php

namespace App\Console\Commands;

use App\Models\TaxonomySource;
use Illuminate\Console\Command;

/**
 * TAXV2-1: siembra `taxonomy_sources` desde `source_crawlers[]` del JSON V2 (SLB/OSHA/IADC) más
 * `CURATED` a mano (no viene en `source_crawlers[]`, pero es la fuente real de la mayoría de los
 * 1.837 términos - ver docblock de la migración `create_taxonomy_sources_table`). Idempotente
 * (upsert por `source_id`) - correrlo de nuevo con un JSON actualizado solo actualiza.
 */
class SeedTaxonomySources extends Command
{
    protected $signature = 'taxonomy:seed-sources
        {file=docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json : Ruta del JSON V2, relativa a la raiz del proyecto}';

    protected $description = 'Siembra taxonomy_sources desde source_crawlers[] del JSON V2 + la fuente CURATED';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $crawlers = $data['source_crawlers'] ?? [];

        foreach ($crawlers as $crawler) {
            TaxonomySource::query()->updateOrCreate(
                ['source_id' => $crawler['source_id']],
                [
                    'name' => $crawler['name'],
                    'base_url' => $crawler['base_url'] ?? null,
                    'discovery_url' => $crawler['discovery_url'] ?? null,
                    'crawlable' => true,
                    'enabled' => (bool) ($crawler['enabled_by_default'] ?? false),
                    'respect_robots_txt' => (bool) ($crawler['respect_robots_txt'] ?? true),
                    'rate_limit_rpm' => $crawler['rate_limit_rpm'] ?? null,
                    'requires_admin_approval_before_import' => (bool) ($crawler['requires_admin_approval_before_import'] ?? true),
                ]
            );
            $this->line("  - {$crawler['source_id']}: {$crawler['name']}");
        }

        TaxonomySource::query()->updateOrCreate(
            ['source_id' => TaxonomySource::CURATED],
            [
                'name' => 'Curado manualmente',
                'base_url' => null,
                'discovery_url' => null,
                'crawlable' => false,
                'enabled' => false,
                'respect_robots_txt' => true,
                'rate_limit_rpm' => null,
                'requires_admin_approval_before_import' => true,
            ]
        );
        $this->line('  - '.TaxonomySource::CURATED.': Curado manualmente (no crawleable)');

        // V2->V3 (docs/taxonomia/oil_gas_master_taxonomy_v3_source_driven.json -> `source_catalog`):
        // "CPV" identifica términos cuyo origen es la propia taxonomía CPV suministrada por el
        // proyecto (no un glosario externo, no curación manual libre) - tampoco crawleable.
        TaxonomySource::query()->updateOrCreate(
            ['source_id' => 'CPV'],
            [
                'name' => 'CPV supplied taxonomy',
                'base_url' => null,
                'discovery_url' => null,
                'crawlable' => false,
                'enabled' => false,
                'respect_robots_txt' => true,
                'rate_limit_rpm' => null,
                'requires_admin_approval_before_import' => true,
            ]
        );
        $this->line('  - CPV: CPV supplied taxonomy (no crawleable)');

        $total = TaxonomySource::query()->count();
        $this->info("Listo. Total en taxonomy_sources: {$total}.");

        return self::SUCCESS;
    }
}
