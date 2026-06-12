<?php

namespace App\Filament\Resources\EmpresaResource\Widgets;

use App\Models\Empresa;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class StatsOverview extends BaseWidget
{
    protected function getCards(): array
    {
        $vemp = 'assets';
        $emps = Empresa::get();
        foreach ($emps as $key => $valuemp) {
        }
        $user_count = $valuemp->getAllEmpresaUser()->count();
        $total_count = Empresa::count();
        $percentage = (100 * $user_count) / $total_count;

        return [
            Card::make('Cantidad de Empresas a las que tiene acceso', $user_count),
            Card::make('Porcentaje en Sistema', number_format($percentage, 2) . ' ' . '%'),
            //Card::make('Enlace', 'Manual de Usuario')->url("user.pdf", true)->descriptionIcon('heroicon-s-trending-up'),
        ];
    }
}
