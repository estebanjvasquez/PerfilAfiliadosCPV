<?php

namespace App\Filament\Resources\CiraEvaluationResource\Pages;

use App\Filament\Resources\CiraEvaluationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCiraEvaluations extends ListRecords
{
    protected static string $resource = CiraEvaluationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('report')->label('Informe consolidado')->icon('heroicon-o-chart-bar')->color('gray')
                ->url(\App\Filament\Pages\CiraEvaluationReportPage::getUrl()),
        ];
    }
}
