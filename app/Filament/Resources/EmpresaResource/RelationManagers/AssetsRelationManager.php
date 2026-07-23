<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\NoAplicaAction;
use App\Models\EmpresaModuleStatus;
use Closure;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Support\Str;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    public static ?string $label = 'Recursos en Venezuela';

    public static ?string $navigationLabel = 'Recursos en Venezuela';

    protected static ?string $pluralModelLabel = 'Recursos en Venezuela';

    public static function form(Form $form): Form
    {
        $titles = json_decode(file_get_contents(storage_path() . '/tituloshr.json'), true);
        // tituloshr.json envuelve cada lista en un array adicional: {"personal": [[...opciones...]]}
        $employees = $titles['personal'][0] ?? [];
        $machinery = $titles['maquinaria'][0] ?? [];
        $facilities = $titles['instalaciones'][0] ?? [];
        $inventory = $titles['inventario'][0] ?? [];

        return $form
            ->schema([
                Wizard::make([
                    Wizard\Step::make('1 - Recursos Humanos')
                        ->schema([
                            Repeater::make('employee')
                                ->schema([
                                    Select::make('employee_type')->required()
                                        ->label('Tipo de Recurso')
                                        ->options($employees),
                                    TextInput::make('junior_q')->required()->numeric()->minValue(0)
                                        ->default(0)->reactive()->label('Junior')
                                        ->afterStateUpdated(function (Closure $set, $get) {
                                            if ($get('junior_q') === '') {
                                                $set('junior_q', 0);
                                            }
                                            $set('tfila', Str::slug($get('medium_q') + $get('junior_q') + $get('senior_q')));
                                        }),
                                    TextInput::make('medium_q')->required()->numeric()->minValue(0)
                                        ->default(0)->reactive()->label('Medium')
                                        ->afterStateUpdated(function (Closure $set, $get) {
                                            if ($get('medium_q') === '') {
                                                $set('medium_q', 0);
                                            }
                                            $set('tfila', Str::slug($get('medium_q') + $get('junior_q') + $get('senior_q')));
                                        }),
                                    TextInput::make('senior_q')->required()->numeric()->minValue(0)
                                        ->default(0)->reactive()->label('Senior')
                                        ->afterStateUpdated(function (Closure $set, $get) {
                                            if ($get('senior_q') === '') {
                                                $set('senior_q', 0);
                                            }
                                            $set('tfila', Str::slug($get('medium_q') + $get('junior_q') + $get('senior_q')));
                                        }),
                                    TextInput::make('tfila')->label('Total')->numeric()->disabled()->default(0),
                                ])->columns(5)->orderable(false)->reactive()->label('Recursos Humanos'),

                            Fieldset::make('Totales')
                                ->schema([
                                    Placeholder::make('total_junior')->label('Total Junior')
                                        ->content(fn (Closure $get) => collect($get('employee'))->pluck('junior_q')->map(fn ($v) => $v === '' ? 0 : $v)->sum()),
                                    Placeholder::make('total_medium')->label('Total Medium')
                                        ->content(fn (Closure $get) => collect($get('employee'))->pluck('medium_q')->map(fn ($v) => $v === '' ? 0 : $v)->sum()),
                                    Placeholder::make('total_senior')->label('Total Senior')
                                        ->content(fn (Closure $get) => collect($get('employee'))->pluck('senior_q')->map(fn ($v) => $v === '' ? 0 : $v)->sum()),
                                    Placeholder::make('total_general')->label('Total General')
                                        ->content(fn (Closure $get) => collect($get('employee'))->pluck('tfila')->map(fn ($v) => $v === '' ? 0 : $v)->sum()),
                                ])->columns(4),
                        ]),

                    Wizard\Step::make('2 - Maquinaria y Equipos Propios')
                        ->schema([
                            Repeater::make('machinery')
                                ->schema([
                                    Select::make('machinery_name')->required()->label('Equipo')->options($machinery),
                                    Select::make('machinery_qid')
                                        ->options(['1' => '1 - 10', '2' => '11 - 50', '3' => '51 - 100', '4' => '> 100'])
                                        ->label('Cantidad (n)')->placeholder('Por favor seleccione una opción')->required(),
                                    Select::make('machinery_est')
                                        ->options(['1' => '< 100.000 USD', '2' => '100.001 - 1.000.000 USD', '3' => '1.000.001 - 10.000.000 USD', '4' => '> 10.000.001 USD'])
                                        ->label('Valor Estimado')->placeholder('Por favor seleccione una opción')->required(),
                                ])->columns(3)->orderable(false)->label('Maquinaria y Equipos'),
                        ]),

                    Wizard\Step::make('3 - Instalaciones')
                        ->schema([
                            Repeater::make('facility')
                                ->schema([
                                    Select::make('facility_type')->required()->label('Tipo de Instalación')->options($facilities),
                                    TextInput::make('facility_q')->required()->label('Cantidad')->numeric()->minValue(0),
                                    TextInput::make('facility_surf')->required()->label('Sup (mt2)')->numeric()->minValue(0)
                                        ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(2)->minValue(0)->thousandsSeparator('.')),
                                    Select::make('facility_own')
                                        ->options(['1' => 'Propia', '2' => 'Alquilada', '3' => 'Ambas'])
                                        ->label('Tipo de Propiedad')->placeholder('Por favor seleccione una opción')->required(),
                                ])->columns(4)->orderable(false)->label('Instalaciones'),
                        ]),

                    Wizard\Step::make('4 - Inventario')
                        ->schema([
                            Repeater::make('inventory')
                                ->schema([
                                    Select::make('inventory_name')->required()->label('Tipo de Inventario')->options($inventory),
                                    Select::make('inventory_q')
                                        ->options(['1' => '1 - 10', '2' => '11 - 50', '3' => '51 - 100', '4' => '> 100'])
                                        ->label('Cantidad (n)')->placeholder('Por favor seleccione una opción')->required(),
                                    TextInput::make('inventory_unit')->required()->label('Unidad')
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                    Select::make('inventory_est')
                                        ->options(['1' => '< 100.000 USD', '2' => '100.001 - 1.000.000 USD', '3' => '1.000.001 - 10.000.000 USD', '4' => '> 10.000.001 USD'])
                                        ->label('Valor actual Estimado')->placeholder('Por favor seleccione una opción')->required(),
                                ])->columns(4)->orderable(false)->label('Inventario'),
                        ]),
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Registrado el')->dateTime(),
                Tables\Columns\TextColumn::make('updated_at')->label('Última actualización')->dateTime(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('5xl')
                    ->visible(fn (RelationManager $livewire) => ! $livewire->ownerRecord->assets()->exists()),
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_RECURSOS),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->modalWidth('5xl'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }
}
