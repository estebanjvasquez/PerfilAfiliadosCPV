<?php

namespace App\Filament\Pages;

use App\Models\TaxonomySelectionSettings;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Fase 4 del proyecto de taxonomía: los 2 límites del buscador de autocarga
 * (TaxonomyCategoriesRelationManager) - configurables por administradores, no hardcodeados (pedido
 * explícito del usuario). Dejar un campo vacío = sin tope para esa categoría.
 *
 * Solo Super Admin (mismo criterio ya usado para el RIF en EmpresaResource) - son reglas globales
 * que afectan a todas las empresas, no un dato de una empresa puntual.
 */
class TaxonomySelectionSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Límites de selección';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.taxonomy-selection-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(config('filament-shield.super_admin.name')) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(TaxonomySelectionSettings::current()->only([
            'max_categorias_principales',
            'max_categorias_secundarias',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('max_categorias_principales')
                    ->label('Máximo de categorías principales por empresa')
                    ->numeric()
                    ->minValue(1)
                    ->nullable()
                    ->helperText('Vacío = sin tope.'),
                TextInput::make('max_categorias_secundarias')
                    ->label('Máximo de categorías secundarias por empresa')
                    ->numeric()
                    ->minValue(1)
                    ->nullable()
                    ->helperText('Vacío = sin tope.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        TaxonomySelectionSettings::current()->update($this->form->getState());

        Notification::make()->success()->title('Límites actualizados')->send();
    }
}
