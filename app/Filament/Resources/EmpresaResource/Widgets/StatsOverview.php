<?php

namespace App\Filament\Resources\EmpresaResource\Widgets;

use App\Models\Empresa;
use Illuminate\Support\Facades\Auth;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class StatsOverview extends BaseWidget
{
    /** TASK-0011: los avisos van al final de Inicio; el espacio principal es para la cuenta, la ayuda y «Mi empresa». */
    protected static ?int $sort = 10;

    /** TASK-0011: una sola tarjeta a todo el ancho (antes dos, con la misma frase repetida). */
    protected function getColumns(): int
    {
        return 1;
    }

    protected function getCards(): array
    {
        // TASK-0011: se quitaron dos conteos sin uso ($user_count, $total_count) que solo hacían consultas y dividían
        // por cero con la tabla de empresas vacía (pendiente «StatsOverview falla con empresas vacía»).
        // La clase cpv-home-notice da tamaño de nota (filament.pages.home-styles); el texto de los avisos no cambia.
        return [
            // Filament 3 ignora un tercer argumento de make(): la segunda frase va en description().
            Card::make('', 'La Cámara Petrolera de Venezuela no se hace responsable de la veracidad de la información suministrada por la empresa afiliada en esta plataforma')
                ->description('La empresa afiliada es responsable de la veracidad de la información suministrada de manera directa y voluntaria en este formulario')
                ->extraAttributes(['class' => 'cpv-home-notice']),
        ];
    }
}
