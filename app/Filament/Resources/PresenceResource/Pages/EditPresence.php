<?php

namespace App\Filament\Resources\PresenceResource\Pages;

use Filament\Forms;
use App\Models\Country;
use App\Models\Empresa;
use Filament\Pages\Actions;
use Filament\Resources\Form;
use FIlament\Forms\Components;
use EditRecord\Concerns\HasTabs;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\PresenceResource;

class EditPresence extends EditRecord
{
    use EditRecord\Concerns\HasWizard;

    protected static string $resource = PresenceResource::class;

    protected function getActions(): array
    {
        return [];
    }

    //protected function getActions(): array
    protected function getSteps(): array
    {
        $vemp = 'presences';

        $vcountry = Country::get();

        foreach ($vcountry as $key => $value) {
        }

        return [

            Forms\Components\Wizard\Step::make('¿Tiene presencia formal en otros países?')
                ->schema([
                    Select::make('empresa_id')->relationship('empresa', 'name')->disabled()->label('Empresa'),
                    Card::make()
                        ->schema([
                            Toggle::make('has_offices')
                                ->label('Tiene Oficinas o Sucursales Fuera de Venezuela')
                                ->reactive()
                                ->afterStateUpdated(fn (callable $set) => $set('office', false)),
                        ]),
                    Grid::make(
                        1
                    )->schema([
                        Repeater::make('office_data')
                            ->schema([
                                Select::make('country_id')
                                    ->label('País')
                                    ->options($value->getCountry()->pluck('country_name', 'id')),
                                TextInput::make('offices_surf')->label('Superficie (㎡)')->numeric()->minValue(0)
                                    ->mask(
                                        fn (TextInput\Mask $mask) => $mask
                                            ->numeric()
                                            ->decimalPlaces(2)
                                            ->minValue(0)
                                            ->thousandsSeparator(',')
                                    ),
                                TextInput::make('employees_q')->label('Empleados (n)')->numeric()->minValue(0)
                                    ->mask(
                                        fn (TextInput\Mask $mask) => $mask
                                            ->numeric()
                                            ->decimalPlaces(0)
                                            ->minValue(0)
                                            ->thousandsSeparator(',')
                                    ),
                                Checkbox::make('status')->label(('Activa?'))->inline(false),
                            ])->columns(4)->orderable(false)->label('Datos de Oficinas')

                    ])->hidden(fn (callable $get) => $get('has_offices') === false),
                ])->columns(1),
            Forms\Components\Wizard\Step::make('¿Tiene experiencia desarrollando proyectos en otros países?')
                //Forms\Components\Wizard\Step::make('2 - Maquinaria y Equipos Propios')
                ->schema([
                    Card::make()
                        ->schema([
                            Toggle::make('has_experience')
                                ->label('Tiene Experiencia Desarrollando Proyectos Fuera de Venezuela')
                                ->reactive()
                                ->afterStateUpdated(fn (callable $set) => $set('experience', false)),
                        ]),
                    Grid::make(
                        1
                    )->schema([
                        Repeater::make('experience_data')
                            ->schema([
                                Select::make('expcountry_id')
                                    ->options($value->getCountry()->pluck('country_name', 'id'))->label('País'),
                                TextInput::make('projects_q')->label('Nro. de Proyectos')
                                    ->numeric()->minValue(0)
                                    ->mask(
                                        fn (TextInput\Mask $mask) => $mask
                                            ->numeric()
                                            ->decimalPlaces(0)
                                            ->minValue(0)
                                    ),
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
                                    ->mask(
                                        fn (TextInput\Mask $mask) => $mask
                                            ->numeric()
                                            ->decimalPlaces(0)
                                            ->minValue(0)
                                    ),
                                TextInput::make('main_clients')->label('Principales clientes')
                                    ->afterStateUpdated(function ($component, $state, $set) {
                                        return $set($component, mb_strtoupper($state));
                                    }),
                            ])->columns(6)->orderable(false)->label('Datos de Experiencia')
                    ])->hidden(fn (callable $get) => $get('has_experience') === false),
                    //  ])
                ])->columns(1),
        ];
    }
}
