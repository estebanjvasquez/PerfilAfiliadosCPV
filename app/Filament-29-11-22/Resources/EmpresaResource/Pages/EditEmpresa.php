<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use Clorure;
use Filament\Forms;
use App\Filament\Resources\EmpresaResource;
//use App\Models\City;
use App\Models\Country;
use Closure;
use App\Models\City;
//use App\Models\State;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\TextInput\Mask;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\BelongsToSelect;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EditEmpresa extends EditRecord
{
    use EditRecord\Concerns\HasWizard;

    protected static string $resource = EmpresaResource::class;

    protected function getActions(): array
    {
        return [];
    }

    protected function getSteps(): array
    {
        $ciudades = City::get();
        foreach ($ciudades as $key => $value) {
            //
        }

        return [

            Forms\Components\Wizard\Step::make('1- Datos Generales')
                ->schema([
                    Forms\Components\TextInput::make('rif')->required()->disabled(),
                    Forms\Components\TextInput::make('name')->label(__('Nombre de la Empresa'))->required(),
                    Forms\Components\TextInput::make('ano_fund')->numeric()
                        ->minValue(1901)->maxValue(2150)
                        ->label('Año de Fundación'),

                    Fieldset::make('Dirección')->schema([
                        Forms\Components\TextInput::make('phone')->tel()->label('Teléfono'),
                        Forms\Components\TextInput::make('street')->maxLength(100)->label('Dirección'),

                        Select::make('city_id')
                            ->label('Ciudad')
                            ->options($value->getFullCity()->pluck('full_location', 'id'))
                            ->searchable()



                    ])->columns(3),
                ])->columns(3)->id('uno'),
            Forms\Components\Wizard\Step::make('2 - Datos de Contacto')
                ->schema([
                    Fieldset::make('Redes Sociales')->schema([
                        Forms\Components\TextInput::make('website')->mask(fn (Mask $mask) => $mask->pattern('http://********************************')),
                        Forms\Components\TextInput::make('linkedin_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************'))
                            ->label('Perfil de LinkedIn'),
                        Forms\Components\TextInput::make('twitter_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************'))
                            ->label('Perfil de Twitter'),
                        Forms\Components\TextInput::make('instagram_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************'))
                            ->label('Perfil de Instagram'),
                        Forms\Components\TextInput::make('facebook_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************'))
                            ->label('Perfil de Facebook'),
                        Forms\Components\TextInput::make('youtube_profile')->mask(fn (Mask $mask) => $mask->pattern('@********************'))
                            ->label('Youtube'),

                        Forms\Components\TextInput::make('otros_profile')->label('Otros'),
                    ])->columns(3)->id('dos'),
                    //Fieldset::make('Persona Contacto')->schema([]),
                ]),
            Forms\Components\Wizard\Step::make('3 - Operaciones')
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
                            ])->label('Empleados')->placeholder('Por favor seleccione una opción'),

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
                            Forms\Components\TextInput::make('customer_name')->label('Nombre del Cliente'),
                            Select::make('country_id')->relationship('country', 'country_name')->label('País'),
                            //Select::make('city_id')->relationship('city', 'city_name')->required(),
                        ])->label('Principales clientes en los últimos 15 años:')
                        ->createItemButtonLabel('Añadir cliente')
                        ->columns(2)
                        ->orderable(false)
                    //->defaultItems(3)
                ])->columns(1),
        ];
    }
}
