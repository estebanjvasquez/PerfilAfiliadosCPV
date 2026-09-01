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
