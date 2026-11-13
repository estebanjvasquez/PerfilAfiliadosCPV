<?php

namespace App\Filament\Resources\CiraEvalCampaignResource\Pages;

use App\Filament\Resources\CiraEvalCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCiraEvalCampaigns extends ListRecords
{
    protected static string $resource = CiraEvalCampaignResource::class;

    // Without this Filament title-cases every word («Evaluaciones De CIRA»).
    protected static ?string $title = 'Campañas de evaluación';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('report')->label('Informe consolidado')->icon('heroicon-o-chart-bar')->color('gray')
                ->url(\App\Filament\Pages\CiraEvaluationReportPage::getUrl()),
        ];
    }
}
