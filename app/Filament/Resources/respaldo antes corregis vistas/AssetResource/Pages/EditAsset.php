<?php

namespace App\Filament\Resources\AssetResource\Pages;

use Closure;
use App\Models\Empresa;
use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\Select;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Concerns\InteractsWithForms;
use Illuminate\Support\Str;

class EditAsset extends EditRecord
{
    use EditRecord\Concerns\HasWizard;

    protected static string $resource = AssetResource::class;

    protected function getActions(): array
    {
        return [];
    }

    protected function getSteps(): array
    {
        $vemp = 'assets';

        $emps = Empresa::get();
        foreach ($emps as $key => $value) {
        }

        $titles = json_decode(file_get_contents(storage_path() . "/tituloshr.json"), true);
        $value_emp = $titles['personal'];
        $value_maq = $titles['maquinaria'];
        $value_fac = $titles['instalaciones'];
        $value_inv = $titles['inventario'];

        foreach ($value_emp as $employees) {
        }
        foreach ($value_maq as $machinery) {
        }
        foreach ($value_fac as $facilities) {
        }
        foreach ($value_inv as $inventory) {
        }

        return [

            Forms\Components\Wizard\Step::make('1- Datos Generales')
                ->schema([
                    Select::make('empresa_id')->relationship('empresa', 'name')
                        //->options($value->getEmpresaUser($vemp)->pluck('name', 'id')),
                        ->disabled(),

                    Repeater::make('employee')
                        ->schema([
                            // Fieldset::make('fila')
                            //     ->schema([
                            Select::make('employee_type')->required()
                                ->label('Tipo de Recurso')
                                ->options(
                                    $employees,
                                ),
                            TextInput::make('junior_q')->required()
                                ->default(0)->reactive()->label('Junior')
                                ->numeric()->minValue(0)
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    if ($get('junior_q') == "") {
                                        $set('junior_q', 0);
                                    }
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                            TextInput::make('medium_q')->required()->numeric()->minValue(0)
                                ->default(0)->reactive()->label('Medium')
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    if ($get('medium_q') == "") {
                                        $set('medium_q', 0);
                                    }
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                            TextInput::make('senior_q')->required()->numeric()->minValue(0)
                                ->default(0)->reactive()->label('Senior')
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    if ($get('senior_q') == "") {
                                        $set('senior_q', 0);
                                    }
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                            //  ])->columns(5),

                            TextInput::make('tfila')->label('Total')->numeric()->disabled()
                                ->default(0),

                        ])->columns(5)->orderable(false)->reactive()->label('Recursos Humanos'),

                    Fieldset::make('Totales')
                        ->schema([
                            Placeholder::make('slug')->disableLabel(),
                            Placeholder::make("total_junior")
                                ->label("Total Junior")
                                ->content(function (Closure $get) {
                                    $fields = collect($get('employee'))->pluck('junior_q');
                                    $sum = 0;
                                    $i = 0;
                                    foreach ($fields as $field) {
                                        if ($field == "") {
                                            $field = 0;
                                        }
                                        $sum += $field;
                                        $i++;
                                    }
                                    return $sum;
                                }),


                            Placeholder::make("total_medium")
                                ->label("Total Medium")
                                ->content(function (Closure $get) {
                                    $fields = collect($get('employee'))->pluck('medium_q');
                                    $sum = 0;
                                    $i = 0;
                                    foreach ($fields as $field) {
                                        if ($field == "") {
                                            $field = 0;
                                        }
                                        $sum += $field;
                                        $i++;
                                    }
                                    return $sum;
                                }),

                            Placeholder::make("total_senior")
                                ->label("Total Senior")
                                ->content(function (Closure $get) {
                                    $fields = collect($get('employee'))->pluck('senior_q');
                                    $sum = 0;
                                    $i = 0;
                                    foreach ($fields as $field) {
                                        if ($field == "") {
                                            $field = 0;
                                        }
                                        $sum += $field;
                                        $i++;
                                    }
                                    return $sum;
                                }),

                            Placeholder::make('Total General')
                                ->content(function ($get) {
                                    $fields = collect($get('employee'))->pluck('tfila');
                                    $sum = 0;
                                    $i = 0;
                                    foreach ($fields as $field) {
                                        if ($field == "") {
                                            $field = 0;
                                        }
                                        $sum += $field;
                                        $i++;
                                    }
                                    return $sum;
                                }),
                        ])->columns(5),


                    /* TextInput::make("total_area")
                        ->label("Total Area")
                        ->postfix('m²')
                        ->disabled()
                        ->placeholder(function (Closure $get) {
                            $fields = $get('employee');
                            $sum = 0;
                            foreach ($fields as $field) {
                                foreach ($field as $value) {
                                    if ($value == "") {
                                        $value = 0;
                                    }
                                    $sum += $value;
                                }
                            }
                            return $sum;
                        }), */
                ])->columns(1),

            Forms\Components\Wizard\Step::make('2 - Maquinaria y Equipos Propios')
                ->schema([
                    Repeater::make('machinery')
                        ->schema([
                            Select::make('machinery_name')->required()
                                ->label('Equipos')
                                ->options(
                                    $machinery,
                                ),
                            Forms\Components\Select::make('machinery_qid')
                                ->options([
                                    '1' => '1 - 10',
                                    '2' => '11 - 50',
                                    '3' => '51 - 100',
                                    '4' => '> 100'
                                ])->label('Cantidad (n)')
                                ->placeholder('Por favor seleccione una opción')->required(),
                            Forms\Components\Select::make('machinery_est')
                                ->options([
                                    '1' => '< 100000 USD',
                                    '2' => '100001 - 1000000 USD',
                                    '3' => '1000001 - 10000000 USD',
                                    '4' => '> 10000001 USD'
                                ])->label('Valor actual estimado')
                                ->placeholder('Por favor seleccione una opción')->required(),

                        ])->columns(3)->orderable(false)->label('Maquinaria y Equipos'),
                ])->columns(1),

            Forms\Components\Wizard\Step::make('3 - Instalaciones')
                ->schema([
                    Repeater::make('facility')
                        ->schema([
                            Select::make('facility_type')->required()
                                ->label('Tipo de Instalación')
                                ->options(
                                    $facilities,
                                ),
                            TextInput::make('facility_q')->required()->label('Cantidad')->numeric()->minValue(0),
                            TextInput::make('facility_surf')->required()->label('Sup (mt2)')->numeric()->minValue(0)
                                ->mask(
                                    fn (TextInput\Mask $mask) => $mask
                                        ->numeric()
                                        ->decimalPlaces(2)
                                        ->minValue(0)
                                ),
                            Forms\Components\Select::make('facility_own')
                                ->options([
                                    '1' => 'Propia',
                                    '2' => 'Alquilada',
                                    '3' => 'Ambas',
                                ])->label('Tipo de Propiedad')
                                ->placeholder('Por favor seleccione una opción')->required(),

                        ])->columns(4)->orderable(false)->label('Instalaciones'),
                ])->columns(1),

            Forms\Components\Wizard\Step::make('4 - Inventario')
                ->schema([
                    Repeater::make('inventory')
                        ->schema([
                            Select::make('inventory_name')->required()
                                ->label('Tipo de Inventario')
                                ->options(
                                    $inventory,
                                ),
                            Forms\Components\Select::make('inventory_q')
                                ->options([
                                    '1' => '1 - 10',
                                    '2' => '11 - 50',
                                    '3' => '51 - 100',
                                    '4' => '> 100'
                                ])->label('Cantidad (n)')
                                ->placeholder('Por favor seleccione una opción')->required(),

                            TextInput::make('inventory_unit')->required()->label('Unidad')
                                ->afterStateUpdated(function ($component, $state, $set) {
                                    return $set($component, mb_strtoupper($state));
                                }),
                            Forms\Components\Select::make('facility_own')
                                ->options([
                                    '1' => '< 100000 USD',
                                    '2' => '100001 - 1000000 USD',
                                    '3' => '1000001 - 10000000 USD',
                                    '4' => '> 10000001 USD'
                                ])->label('Valor actual Estimado')
                                ->placeholder('Por favor seleccione una opción')->required(),

                        ])->columns(4)->orderable(false),
                ])->columns(1),
            //Actions\DeleteAction::make(),
        ];
    }
}
