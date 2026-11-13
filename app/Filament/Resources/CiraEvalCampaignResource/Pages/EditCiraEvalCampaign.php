<?php

namespace App\Filament\Resources\CiraEvalCampaignResource\Pages;

use App\Filament\Resources\CiraEvalCampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditCiraEvalCampaign extends EditRecord
{
    protected static string $resource = CiraEvalCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
