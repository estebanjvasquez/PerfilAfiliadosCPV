<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\View\View;

class Settings extends Page
{

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Settings';

    protected static string $view = 'filament.pages.settings';

    protected static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
