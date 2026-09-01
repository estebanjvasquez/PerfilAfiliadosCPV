<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\View\View;

class Settings extends Page
{

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Settings';

    protected static string $view = 'filament.pages.settings';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
