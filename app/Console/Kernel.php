<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        // Red de seguridad para empresas.completion_percentage (cache de
        // Empresa::completionPercentage(), ver migracion 2026_09_01_130000):
        // cubre mutation paths que no se pueden enganchar limpiamente (p.ej.
        // el CreateAction por defecto de "Crear Contacto" de Filament/Breezy).
        $schedule->command('empresas:refresh-completion --only-stale')->everyFifteenMinutes();

        // TAXV2-12: avanza el checkpoint de UNA fuente habilitada por corrida (ver
        // CrawlTaxonomySource::pickNextSource) - sin argumentos es un no-op mientras
        // TaxonomySourceResource no habilite ninguna fuente (enabled=false por defecto), así que es
        // seguro dejarlo programado desde el día 1. withoutOverlapping() evita que 2 corridas del
        // mismo pool PHP-FPM del hosting compartido se pisen si una tarda más que el intervalo.
        //
        // TAXV2-13 (crawler de webs de empresas) NO se programa acá a propósito: a diferencia de las
        // fuentes terminológicas, no tiene un "enabled" global que lo mantenga en no-op por defecto -
        // es la fase de mayor riesgo/menos especificada del documento (sección 6), así que arrancar
        // vía cron requiere que un admin lo decida explícitamente y agregue su propia entrada.
        $schedule->command('taxonomy:crawl-source')->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
