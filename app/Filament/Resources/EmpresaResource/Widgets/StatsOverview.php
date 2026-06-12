<?php

namespace App\Filament\Resources\EmpresaResource\Widgets;

use App\Models\Empresa;
use Illuminate\Support\Facades\Auth;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class StatsOverview extends BaseWidget
{
    protected function getCards(): array
    {
        $vemp = 'assets';
        //$emps = Empresa::get();
        $user_count = Empresa::whereRelation('users', 'users.id', '=', Auth::User()->id)->count();
        /* foreach ($emps as $key => $valuemp) {
        } */
        //$user_count = $valuemp->getAllEmpresaUser()->count();
        $total_count = Empresa::count();
        //$percentage = (100 * $user_count) / $total_count;
        $percentage = (100 * $user_count) / $total_count;

        return [
            Card::make('','La Cámara Petrolera de Venezuela no se hace responsable de la veracidad de la información suministrada por la empresa afiliada en esta plataforma','La empresa afiliada es responsable de la veracidad de la información suministrada de manera directa y voluntaria en este formulario'),
            Card::make('','La empresa afiliada es responsable de la veracidad de la información suministrada de manera directa y voluntaria en este formulario'),
            //Card::make('Porcentaje en Sistema', number_format($percentage, 2) . ' ' . '%'),
            //Card::make('Enlace', 'Manual de Usuario')->url("user.pdf", true)->descriptionIcon('heroicon-s-trending-up'),
        ];
    }
}
