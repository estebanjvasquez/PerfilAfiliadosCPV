<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use Filament\Forms;
use App\Filament\Resources\EmpresaResource;
use App\Models\City;
use Filament\Resources\Pages\CreateRecord;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput\Mask;

class CreateEmpresa extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = EmpresaResource::class;

    protected function getSteps(): array
    {
        $value = '';
        $ciudades = City::get();
        foreach ($ciudades as $key => $value) {
            //
        }

        return [
            Wizard\Step::make('1 - Datos Generales')
                ->schema([
                    Forms\Components\TextInput::make('rif')->required()->unique()
                        ->placeholder('X-XXXXXXXX')
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('name')->required()->label('Nombre de la Empresa')
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('ano_fund')->numeric()->minValue(1901)->maxValue(2150)
                        ->label('Año de Fundación')->placeholder('aaaa'),
                    Fieldset::make('Dirección')->schema([
                        Forms\Components\TextInput::make('phone')->tel()->label('Tefléfono')
                            ->placeholder('+(XX)XXXXXXX'),
                        Forms\Components\TextInput::make('street')->maxLength(100)->label('Dirección')
                            ->afterStateUpdated(function ($component, $state, $set) {
                                return $set($component, mb_strtoupper($state));
                            }),
                        Select::make('city_id')
                            ->label('Ciudad')
                            ->options($value->getFullCity()->pluck('full_location', 'id'))
                            ->searchable()

                    ])->columns(3),
                ])->columns(3),
            Wizard\Step::make('2 - Datos de Contacto')
                ->schema([
                    Fieldset::make('Redes Sociales')->schema([
                        Forms\Components\TextInput::make('website')->mask(fn (Mask $mask) => $mask->pattern('http://**********************************')),
                        Forms\Components\TextInput::make('linkedin_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************')),
                        Forms\Components\TextInput::make('twitter_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************')),
                        Forms\Components\TextInput::make('instagram_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************')),
                        Forms\Components\TextInput::make('facebook_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************')),
                        Forms\Components\TextInput::make('youtube_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************')),
                        Forms\Components\TextInput::make('otros_profile'),
                    ])->columns(3),
                ]),
            Wizard\Step::make('3 - Operaciones')
                ->schema([
                    Fieldset::make('Operaciones en Venezuela')->schema([
                        Forms\Components\Select::make('billing_id')
                            ->options([
                                '1' => '< 100000 USD',
                                '2' => '100001 - 1000000 USD',
                                '3' => '1000001 - 10000000 USD',
                                '4' => '> 10000001 USD'
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
                            ])->label('Estatus actual')->placeholder('Por favor seleccione una opción'),
                    ])->columns(3),
                    Fieldset::make('Capital de la Empresa')->schema([
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
                            Forms\Components\TextInput::make('customer_name')->label('Nombre del Cliente')
                                ->afterStateUpdated(function ($component, $state, $set) {
                                    return $set($component, mb_strtoupper($state));
                                }),
                            //Select::make('countries_id')->relationship('cities', 'city_name')->required(),
                            Select::make('country_id')->relationship('country', 'country_name')->label('País'),
                        ])->label('Principales clientes en los últimos 15 años:')
                        ->createItemButtonLabel('Añadir cliente')
                        ->columns(2)
                        ->orderable(false)
                    //->defaultItems(3)
                ])->columns(1),
        ];
    }
}
