<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use Clorure;
use Filament\Forms;
use App\Filament\Resources\EmpresaResource;
//use App\Models\City;
use App\Models\Country;
use Closure;
use App\Models\City;
use App\Models\Sector;
//use App\Models\State;
use Filament\Notifications\Notification;
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

    /**
     * Las empresas solo pueden operar en 2 sectores (principal y secundario).
     * Si la empresa tiene servicios asociados a sectores fuera de los 2 elegidos,
     * se bloquea el guardado hasta que desvincule esos servicios o ajuste sus sectores.
     */
    protected function beforeSave(): void
    {
        $data = $this->form->getState();

        $allowed = array_map('intval', array_filter([
            $data['sector_principal_id'] ?? null,
            $data['sector_secundario_id'] ?? null,
        ]));

        $outside = array_diff($this->record->distinctSectorIds(), $allowed);

        if (count($outside) > 0) {
            $names = Sector::whereIn('id', $outside)->pluck('name')->implode(', ');

            Notification::make()
                ->danger()
                ->title('Su empresa tiene servicios en más de 2 sectores')
                ->body("Solo se permiten un Sector Principal y uno Secundario. En la pestaña \"Sectores y Servicios\" desvincule los servicios de: {$names}; o ajuste sus sectores seleccionados.")
                ->persistent()
                ->send();

            $this->halt();
        }
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
                    Forms\Components\Placeholder::make('completitud')
                        ->label('Completitud del Perfil')
                        ->content(function ($record) {
                            if (!$record) {
                                return '';
                            }

                            $data = $record->completionData();
                            $pendientes = collect($data)->filter(fn ($completo) => !$completo)->keys()->implode(', ');

                            return $record->completionPercentage() . '% completado'
                                . ($pendientes !== '' ? ' — Pendiente: ' . $pendientes : '');
                        })->columnSpan(3),
                    Forms\Components\TextInput::make('rif')->required()->disabled()
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('name')->label(__('Nombre de la Empresa'))->required()
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('ano_fund')->numeric()
                        ->minValue(1901)->maxValue(2150)
                        ->label('Año de Fundación')
                        ->placeholder('aaaa'),
                    //Forms\Components\TextInput::make('ano_fund')->placeholder('AAAA'),

                    Fieldset::make('Dirección')->schema([
                        Forms\Components\TextInput::make('phone')->tel()->label('Teléfono'),
                        Forms\Components\TextInput::make('street')->maxLength(100)->label('Dirección')
                            ->afterStateUpdated(function ($component, $state, $set) {
                                return $set($component, mb_strtoupper($state));
                            }),


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
                    Fieldset::make('Sectores de Actividad Económica (máximo 2)')->schema([
                        Select::make('sector_principal_id')
                            ->label('Sector Principal')
                            ->options(Sector::orderBy('name')->pluck('name', 'id'))
                            ->placeholder('Por favor seleccione una opción')
                            ->reactive()
                            ->required(),
                        Select::make('sector_secundario_id')
                            ->label('Sector Secundario (opcional)')
                            ->options(fn (callable $get) => Sector::orderBy('name')
                                ->where('id', '<>', $get('sector_principal_id'))
                                ->pluck('name', 'id'))
                            ->placeholder('Por favor seleccione una opción')
                            ->different('sector_principal_id')
                            ->helperText('Solo podrá asociar servicios de los sectores aquí seleccionados.'),
                    ])->columns(2),
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
                            Forms\Components\TextInput::make('customer_name')->label('Nombre del Cliente')
                                ->afterStateUpdated(function ($component, $state, $set) {
                                    return $set($component, mb_strtoupper($state));
                                }),
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
