<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use Filament\Forms;
use App\Filament\Resources\EmpresaResource;
use App\Models\City;
use App\Models\Sector;
use Filament\Resources\Pages\CreateRecord;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;

class CreateEmpresa extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = EmpresaResource::class;

    public function getSteps(): array
    {
        $value = '';
        $ciudades = City::get();
        foreach ($ciudades as $key => $value) {
            //
        }

        return [
            Wizard\Step::make('1 - Datos generales')
                ->schema([
                    Forms\Components\TextInput::make('rif')->required()->unique()
                        ->maxLength(10)
                        ->regex('/^[VEJPG]\d{9}$/i')
                        ->placeholder('X123456789')
                        ->helperText('Formato: letra (V/E/J/P/G) seguida de 9 dígitos, sin guiones ni espacios.')
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $state)));
                        }),
                    Forms\Components\TextInput::make('name')->required()->label('Nombre de la empresa')
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('ano_fund')->numeric()->minValue(1901)->maxValue(2150)
                        ->label('Año de fundación')->placeholder('aaaa'),
                    Fieldset::make('Dirección')->schema([
                        Forms\Components\TextInput::make('phone')->tel()->label('Tefléfono')
                            ->mask('+58-999-9999999')
                            ->placeholder('+58-XXX-XXXXXXX'),
                        Forms\Components\TextInput::make('street')->maxLength(100)->label('Dirección')
                            ->afterStateUpdated(function ($component, $state, $set) {
                                return $set($component, mb_strtoupper($state));
                            }),
                        Select::make('city_id')
                            ->label('Ciudad')
                            ->options($value->getFullCity()->pluck('full_location', 'id'))
                            ->searchable()

                    ])->columns(['default' => 1, 'md' => 3]),
                ])->columns(['default' => 1, 'md' => 3]),
            Wizard\Step::make('2 - Datos de contacto')
                ->schema([
                    Fieldset::make('Redes sociales')->schema([
                        Forms\Components\TextInput::make('website')->mask('http://**********************************'),
                        Forms\Components\TextInput::make('linkedin_profile')->mask('@********************'),
                        Forms\Components\TextInput::make('twitter_profile')->mask('@********************'),
                        Forms\Components\TextInput::make('instagram_profile')->mask('@********************'),
                        Forms\Components\TextInput::make('facebook_profile')->mask('@********************'),
                        Forms\Components\TextInput::make('youtube_profile')->mask('@********************'),
                        Forms\Components\TextInput::make('otros_profile'),
                    ])->columns(['default' => 1, 'md' => 3]),
                ]),
            Wizard\Step::make('3 - Operaciones')
                ->schema([
                    Fieldset::make('Sectores de actividad económica (máximo 2)')->schema([
                        Select::make('sector_principal_id')
                            ->label('Sector principal')
                            ->options(Sector::orderBy('name')->pluck('name', 'id'))
                            ->placeholder('Por favor seleccione una opción')
                            ->reactive()
                            ->required(),
                        Select::make('sector_secundario_id')
                            ->label('Sector secundario (opcional)')
                            ->options(fn (callable $get) => Sector::orderBy('name')
                                ->where('id', '<>', $get('sector_principal_id'))
                                ->pluck('name', 'id'))
                            ->placeholder('Por favor seleccione una opción')
                            ->different('sector_principal_id')
                            ->helperText('Solo podrá asociar servicios de los sectores aquí seleccionados.'),
                    ])->columns(['default' => 1, 'md' => 2]),
                    Fieldset::make('Operaciones en Venezuela')->schema([
                        Forms\Components\Select::make('billing_id')
                            ->options([
                                '1' => '< 100.000 USD',
                                '2' => '100.001 - 1.000.000 USD',
                                '3' => '1.000.001 - 10.000.000 USD',
                                '4' => '> 10.000.001 USD'
                            ])->label('Facturación anual promedio (últimos 5 años)')
                            ->placeholder('Por favor seleccione una opción'),

                        Forms\Components\Select::make('employees_id')
                            ->options([
                                '1' => '< 50',
                                '2' => '51 - 100',
                                '3' => '101 - 500',
                                '4' => '> 500'
                            ])->label('Empleados')
                            ->placeholder('Por favor seleccione una opción'),

                        Forms\Components\Select::make('status_id')
                            ->options([
                                '1' => 'Activa',
                                '0' => 'Inactiva',
                            ])->label('Estatus actual')
                            ->default('1')
                            ->required(),
                    ])->columns(['default' => 1, 'md' => 3]),
                    Fieldset::make('Capital de la empresa')->schema([
                        Forms\Components\Select::make('property_id')
                            ->options([
                                '1' => 'Privado',
                                '0' => 'Público',
                            ])->label('Propiedad')->placeholder('Por favor seleccione una opción'),

                        Forms\Components\Select::make('origin_id')
                            ->options([
                                '1' => 'Nacional',
                                '0' => 'Internacional',
                            ])->label('Origen')->placeholder('Por favor seleccione una opción'),
                    ]),
                ]),
            Forms\Components\Wizard\Step::make('4 - Clientes')
                ->schema([

                    Repeater::make('customers_country')
                        ->schema([
                            Forms\Components\TextInput::make('customer_name')->label('Nombre del cliente')
                                ->afterStateUpdated(function ($component, $state, $set) {
                                    return $set($component, mb_strtoupper($state));
                                }),
                            //Select::make('countries_id')->relationship('cities', 'city_name')->required(),
                            Select::make('country_id')->relationship('country', 'country_name')->label('País'),
                        ])->label('Principales clientes en los últimos 15 años:')
                        ->createItemButtonLabel('Añadir cliente')
                        ->columns(['default' => 1, 'md' => 2])
                        ->orderable(false)
                    //->defaultItems(3)
                ])->columns(1),
        ];
    }
}
