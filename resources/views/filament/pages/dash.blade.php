<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use App\Filament\Widgets\StatsOverviewWidget;

class Dash extends Page
{
    protected function getHeaderWidgets(): array
    {
        return [
            //Filament\Resource\EmpresaResource\Widgets\StatsOverview::class
        ];
    }
}
