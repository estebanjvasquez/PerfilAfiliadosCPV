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
        // EmpresaCompletionObserver + los hooks ->after() en
        // ServicesRelationManager/ContactsRelationManager cubren la gran
        // mayoria de los caminos de mutacion, pero "Crear Contacto" (accion
        // por defecto de BelongsToManyRelationManager, crea+adjunta en un
        // solo paso) queda sin cubrir a proposito - reimplementarla a mano
        // se arriesgaba a romper su wiring interno (ver comentario en
        // ContactsRelationManager). --only-stale hace que sea barato correrlo
        // seguido: solo escribe las filas que de verdad quedaron
        // desactualizadas.
        //
        // OJO: esto no corre solo. El scheduler de Laravel necesita que algo
        // del sistema operativo llame "php artisan schedule:run" cada minuto
        // (cron en Linux, Programador de tareas en Windows) - sin eso, esta
        // linea queda inerte. Ver docs/PLAN_DESPLIEGUE_PRODUCCION.md para
        // confirmar si ya existe esa entrada o hay que agregarla.
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
