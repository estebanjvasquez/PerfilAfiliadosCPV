<?php

namespace App\Filament\Resources\AssetResource\Pages;

use Closure;
use Filament\Forms;
use App\Models\Asset;
use App\Models\Empresa;
use Filament\Pages\Actions;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\AssetResource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateAsset extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = AssetResource::class;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRelation('user', 'users.id', '=', Auth::User()->id);
    }

    protected function getSteps(): array
    {
        $vemp = 'assets';

        $emps = Empresa::get();
        foreach ($emps as $key => $value) {
        }

        $titles = json_decode(file_get_contents(storage_path() . "/tituloshr.json"), true);
        //dd($titles['personal']);
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

            Forms\Components\Wizard\Step::make('1- Recursos Humanos')

                ->schema([

                    Select::make('empresa_id')
                        ->options($value->getEmpresaUser($vemp)->pluck('name', 'id'))->label('Empresa'),

                    Repeater::make('employee')
                        ->schema([
                            Select::make('employee_type')->required()
                                ->label('Tipo de Recurso')
                                ->options(
                                    $employees,
                                ),
                            TextInput::make('junior_q')
                                ->required()->numeric()->minValue(0)
                                ->default(0)->reactive()->label('Junior')
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                            TextInput::make('medium_q')
                                ->required()->numeric()->minValue(0)
                                ->default(0)->reactive()->label('Medium')
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                            TextInput::make('senior_q')
                                ->required()->numeric()->minValue(0)
                                ->default(0)->reactive()->label('Senior')
                                ->afterStateUpdated(function (Closure $set, $get, $state) {
                                    $temporal = $get('medium_q') + $get('junior_q') + $get('senior_q');
                                    $set('tfila', Str::slug($temporal));
                                }),
                        ])->columns(5)->orderable(false)->label('Recursos Humanos'),
                ])->columns(1),

            Forms\Components\Wizard\Step::make('2 - Maquinaria y Equipos Propios')
                ->schema([
                    Repeater::make('machinery')
                        ->schema([
                            Select::make('machinery_name')->required()
                                ->label('Equipo')
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
                                    '1' => '< 100.000 USD',
                                    '2' => '100.001 - 1.000.000 USD',
                                    '3' => '1.000.001 - 10.000.000 USD',
                                    '4' => '> 10.000.001 USD'
                                ])->label('Valor Estimado')
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
                                        ->thousandsSeparator('.')
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
                            Forms\Components\Select::make('inventory_est')
                                ->options([
                                    '1' => '< 100.000 USD',
                                    '2' => '100.001 - 1.000.000 USD',
                                    '3' => '1.000.001 - 10.000.000 USD',
                                    '4' => '> 10.000.001 USD'
                                ])->label('Valor actual Estimado')
                                ->placeholder('Por favor seleccione una opción')->required(),

                        ])->columns(4)->orderable(false)->label('Inventario'),
                ])->columns(1),
            //Actions\DeleteAction::make(),
        ];
    }
}
