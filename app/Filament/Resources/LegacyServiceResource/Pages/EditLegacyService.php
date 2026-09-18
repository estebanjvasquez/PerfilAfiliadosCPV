<?php

namespace App\Filament\Resources\LegacyServiceResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\LegacyServiceResource;
use Filament\Resources\Pages\EditRecord;

class EditLegacyService extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = LegacyServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
