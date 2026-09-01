<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use Illuminate\Console\Command;

/**
 * Backfill/reconciliacion manual de empresas.completion_percentage (ver
 * migracion 2026_09_01_130000 y Empresa::refreshCompletionPercentage()).
 *
 * Necesario UNA VEZ despues de correr la migracion (la columna nace en 0
 * para todas las filas existentes). Tambien sirve como red de seguridad
 * periodica por si algun mutation path futuro se olvida de refrescar el
 * cache (mejor una discrepancia detectada y corregida por cron que una
 * silenciosa para siempre).
 */
class RefreshCompletionPercentages extends Command
{
    protected $signature = 'empresas:refresh-completion {--only-stale : Solo recalcula si el valor cacheado no coincide con el real (mas lento por fila, pero reporta cuantas discrepancias habia)}';

    protected $description = 'Recalcula y persiste empresas.completion_percentage para todas las empresas';

    public function handle(): int
    {
        $onlyReportStale = (bool) $this->option('only-stale');
        $total = 0;
        $stale = 0;

        Empresa::query()->withCompletionData()->chunkById(50, function ($empresas) use (&$total, &$stale, $onlyReportStale) {
            foreach ($empresas as $empresa) {
                $total++;
                $real = $empresa->completionPercentage();

                if ($onlyReportStale && (int) $empresa->completion_percentage === $real) {
                    continue;
                }

                if ((int) $empresa->completion_percentage !== $real) {
                    $stale++;
                }

                // Asignacion directa, no updateQuietly(['completion_percentage' => ...]) - ver
                // el comentario en Empresa::refreshCompletionPercentage() (mass-assignment lo
                // descarta en silencio, $fillable no incluye este campo a proposito).
                $empresa->completion_percentage = $real;
                $empresa->saveQuietly();
            }
        });

        $this->info("Procesadas {$total} empresas. Discrepancias encontradas y corregidas: {$stale}.");

        return self::SUCCESS;
    }
}
