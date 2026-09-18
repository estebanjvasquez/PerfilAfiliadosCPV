<?php

namespace App\Console\Commands;

use App\Support\Taxonomy\TaxonomyRankingParameters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-4: siembra `taxonomy_settings` con los defaults de `TaxonomyRankingParameters` (que a su
 * vez replican `admin_defaults.scoring` del JSON V2). Usa `insertOrIgnore` a propósito, no upsert -
 * una re-corrida NUNCA debe pisar un peso que un administrador ya ajustó desde el panel
 * (TaxonomyRankingSettingsPage, TAXV2-4) - "restaurar default" es una acción explícita del admin,
 * no algo que este comando haga solo.
 */
class SeedTaxonomySettings extends Command
{
    protected $signature = 'taxonomy:seed-settings';

    protected $description = 'Siembra taxonomy_settings con los defaults de TaxonomyRankingParameters (sin pisar valores ya ajustados)';

    public function handle(): int
    {
        $now = now();

        $rows = array_map(fn ($key, $definition) => [
            'key' => $key,
            'value' => $definition['default'],
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys(TaxonomyRankingParameters::definitions()), TaxonomyRankingParameters::definitions());

        $inserted = DB::connection('pgsql')->table('taxonomy_settings')->insertOrIgnore($rows);

        $total = DB::connection('pgsql')->table('taxonomy_settings')->count();
        $this->info("Insertadas {$inserted} fila(s) nueva(s) (las existentes no se tocaron). Total en taxonomy_settings: {$total}.");

        return self::SUCCESS;
    }
}
