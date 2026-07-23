<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\NoAplicaAction;
use App\Models\Country;
use App\Models\EmpresaModuleStatus;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class PresenceRelationManager extends RelationManager
{
    protected static string $relationship = 'presence';

    public static ?string $label = 'Presencia Internacional';

    public static ?string $navigationLabel = 'Presencia Internacional';

    protected static ?string $pluralModelLabel = 'Presencia Internacional';

    public static function form(Form $form): Form
    {
        $countries = (new Country())->getCountry();

        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make('¿Tiene presencia formal en otros países?')
                        ->schema([
                            Card::make()
                                ->schema([
                                    Toggle::make('has_offices')
                                        ->label('Tiene Oficinas o Sucursales Fuera de Venezuela')
                                        ->reactive()
                                        ->afterStateUpdated(fn (callable $set) => $set('office', false)),
                                ]),
                            Grid::make(1)->schema([
                                Repeater::make('office_data')
                                    ->schema([
                                        Select::make('country_id')
                                            ->label('País')
                                            ->options($countries->pluck('country_name', 'id')),
                                        TextInput::make('offices_surf')->label('Superficie (㎡)')->numeric()->minValue(0)
                                            ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(2)->minValue(0)->thousandsSeparator(',')),
                                        TextInput::make('employees_q')->label('Empleados (n)')->numeric()->minValue(0)
                                            ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(0)->minValue(0)->thousandsSeparator(',')),
                                        Checkbox::make('status')->label('Activa?')->inline(false),
                                    ])->columns(2)->orderable(false)->label('Datos de Oficinas')
                            ])->hidden(fn (callable $get) => $get('has_offices') === false),
                        ]),
                    Wizard\Step::make('¿Tiene experiencia desarrollando proyectos en otros países?')
                        ->schema([
                            Card::make()
                                ->schema([
                                    Toggle::make('has_experience')
                                        ->label('Tiene Experiencia Desarrollando Proyectos Fuera de Venezuela')
                                        ->reactive()
                                        ->afterStateUpdated(fn (callable $set) => $set('experience', false)),
                                ]),
                            Grid::make(1)->schema([
                                Repeater::make('experience_data')
                                    ->schema([
                                        Select::make('expcountry_id')
                                            ->options($countries->pluck('country_name', 'id'))->label('País'),
                                        TextInput::make('projects_q')->label('Nro. de Proyectos')
                                            ->numeric()->minValue(0)
                                            ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(0)->minValue(0)),
                                        Select::make('role')
                                            ->options([
                                                '1' => 'Subcontratista',
                                                '2' => 'Contratista Principal',
                                                '3' => 'Ambos',
                                            ])->required()->label('Rol'),
                                        Select::make('executed_q')
                                            ->options([
                                                '1' => '< 100.000 USD',
                                                '2' => '100.001 - 1.000.000 USD',
                                                '3' => '1.000.001 - 10.000.000 USD',
                                                '4' => '> 10.000.001 USD'
                                            ])->required()->label('Monto Total Ejecutado'),
                                        TextInput::make('expemployees_q')->label('Empleados (n)')
                                            ->numeric()->minValue(0)
                                            ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(0)->minValue(0)),
                                        TextInput::make('main_clients')->label('Principales clientes')
                                            ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                    ])->columns(3)->orderable(false)->label('Datos de Experiencia')
                            ])->hidden(fn (callable $get) => $get('has_experience') === false),
                        ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BooleanColumn::make('has_offices')->label('Tiene Presencia Internacional'),
                Tables\Columns\BooleanColumn::make('has_experience')->label('Tiene Experiencia Internacional'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('7xl')
                    ->visible(fn (RelationManager $livewire) => ! $livewire->ownerRecord->presence()->exists()),
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_PRESENCIA),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalWidth('7xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                FilamentExportBulkAction::make('export')
                    ->additionalColumnsAddButtonLabel('Add Column'),
            ]);
    }
}
