<?php

namespace App\Filament\Resources\CiraEvalCampaignResource\Pages;

use App\Filament\Resources\CiraEvalCampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCiraEvalCampaign extends CreateRecord
{
    protected static string $resource = CiraEvalCampaignResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $record = new (static::getModel())($data);
        $record->forceFill(['created_by' => Auth::id()])->save();

        return $record;
    }
}
