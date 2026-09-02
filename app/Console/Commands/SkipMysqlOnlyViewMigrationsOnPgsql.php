<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca como "ya corridas" en la tabla `migrations` de PGSQL las migraciones de vistas que usan
 * sintaxis exclusiva de MySQL (`GROUP_CONCAT(... SEPARATOR ...)`, no existe en Postgres - ahi el
 * equivalente es `STRING_AGG`).
 *
 * Encontrado al desplegar `feature/upgrade-filament-v3` al servidor de pruebas con
 * `DB_CONNECTION=pgsql`: `php artisan migrate --force` explota en la primera de estas
 * (`2022_10_19_213831_create_catalogo_view`) con `SQLSTATE[42601] syntax error ... SEPARATOR`, y
 * aborta ahi sin llegar a las demas. Las 13 vistas reales YA EXISTEN en Supabase (creadas por
 * `feature/supplhi-postgres-buscador` con su propio mecanismo, verificado con
 * `information_schema.views`) - lo que falta es que Laravel dej de considerarlas pendientes para
 * siempre en pgsql, igual que ya se resolvio el caso inverso (extension de Postgres pendiente en
 * mysql) en `SkipVendorPgsqlOnlyMigrations`.
 *
 * Sin este comando, cualquier futuro `migrate --force` contra pgsql (incluyendo produccion, el dia
 * que corresponda) va a fallar en el mismo punto antes de llegar a migraciones nuevas legitimas
 * que esten fechadas despues de estas.
 *
 * Correr UNA VEZ por cada base de datos pgsql nueva (test, produccion) - es idempotente.
 */
class SkipMysqlOnlyViewMigrationsOnPgsql extends Command
{
    protected $signature = 'db:skip-mysql-only-view-migrations';

    protected $description = 'Marca como ya corridas en pgsql las migraciones de vistas con sintaxis exclusiva de MySQL';

    /**
     * Nombres de migracion (sin ".php") que crean/modifican vistas con GROUP_CONCAT (MySQL-only).
     * Las vistas reales ya existen en Postgres via otro mecanismo - agregar aca cualquier otra
     * migracion de vista MySQL-only que aparezca en el futuro.
     */
    private const NAMES = [
        '2022_10_19_213831_create_catalogo_view',
        '2022_11_06_185050_create_capacity_view',
        '2022_11_08_224546_create_management_view',
        '2022_11_10_200304_create_sustainability_view',
        '2022_11_10_211023_create_finance_view',
        '2022_11_13_181946_create_clients_view',
        '2022_11_16_204956_create_experience_view',
        '2022_12_03_003734_create_experience_view',
        '2023_05_10_181749_create_resource_view',
        '2023_05_25_223154_create_machinery_view',
        '2023_05_26_204049_create_facility_view',
        '2023_05_31_233119_create_inventory_view',
        '2023_06_02_204233_create_management_det_view',
        '2023_09_15_225003_create_presence_view',
        '2026_08_04_000001_fix_null_bucket_defaults_in_report_views',
    ];

    public function handle(): int
    {
        $batch = DB::connection('pgsql')->table('migrations')->max('batch') ?? 0;
        $batch++;

        foreach (self::NAMES as $name) {
            $exists = DB::connection('pgsql')->table('migrations')->where('migration', $name)->exists();

            if ($exists) {
                $this->line("{$name}: ya estaba marcada, sin cambios.");

                continue;
            }

            DB::connection('pgsql')->table('migrations')->insert([
                'migration' => $name,
                'batch' => $batch,
            ]);

            $this->info("{$name}: marcada como ya corrida en pgsql.");
        }

        return self::SUCCESS;
    }
}
