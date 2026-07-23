<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\NoAplicaAction;
use App\Models\EmpresaModuleStatus;
use App\Models\Experience;
use App\Models\InfraRegion;
use App\Models\InfraSector;
use App\Models\InfraSystem;
use App\Models\InfraType;
use App\Models\Sector;
use App\Models\Service;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Support\HtmlString;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class ExperiencesRelationManager extends RelationManager
{
    protected static string $relationship = 'experiences';

    public static ?string $label = 'Experiencia Relevante';

    public static ?string $navigationLabel = 'Experiencia Relevante';

    protected static ?string $pluralModelLabel = 'Experiencia Relevante';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Builder::make('exp_year')
                    ->blocks([

                        Forms\Components\Builder\Block::make('Experiencia Relevante')
                            ->schema([
                                Forms\Components\Group::make([
                                    Forms\Components\TextInput::make('exp_year')
                                        ->label('Año')
                                        ->required()
                                        ->numeric()->minValue(1901)->maxValue(2150)

                                ])->columns(1),
                                Forms\Components\Group::make([
                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\Select::make('infrasectors_id')
                                            ->label('Sector')
                                            ->options(InfraSector::all()->pluck('sector_name', 'id')->toArray())
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infratypes_id', null)),

                                        Forms\Components\Select::make('infratypes_id')
                                            ->label('Tipo')
                                            ->options(function (callable $get) {
                                                $isector = InfraSector::find($get('infrasectors_id'));
                                                if (!$isector) {
                                                    return [];
                                                }
                                                return $isector->infratypes->pluck('type_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infrasystems_id', null)),

                                        Forms\Components\Select::make('infrasystems_id')
                                            ->label('Sistema')
                                            ->options(function (callable $get) {
                                                $itype = InfraType::find($get('infratypes_id'));
                                                if (!$itype) {
                                                    return [];
                                                }
                                                return $itype->infrasystems->pluck('system_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infraregions_id', null)),

                                        Forms\Components\Select::make('infraregions_id')
                                            ->label('Región o Distrito')
                                            ->options(function (callable $get) {
                                                $isystem = InfraSystem::find($get('infrasystems_id'));

                                                if (!$isystem) {
                                                    return [];
                                                }
                                                return $isystem->infraregions->pluck('region_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infrafacilities_id', null)),

                                        Forms\Components\Select::make('infrafacilities_id')
                                            ->label('Instalación')
                                            ->options(function (callable $get) {
                                                $iregsys = InfraRegion::find($get('infraregions_id'));
                                                if (!$iregsys) {
                                                    return [];
                                                }
                                                return $iregsys->getFacility($iregsys->id, $get('infrasystems_id'))->pluck('facility_name', 'id');
                                            }),

                                    ])->columns(1)->label('Infraestructura en la que Trabajó'),
                                ])
                                    ->columns(1)->columnSpan(2),


                                Forms\Components\Group::make([
                                    Forms\Components\Select::make('magnitud')
                                        ->options([
                                            '1' => '< 100.000 USD',
                                            '2' => '100.001 - 1.000.000 USD',
                                            '3' => '1.000.001 - 10.000.000 USD',
                                            '4' => '> 10.000.001 USD'
                                        ])->label('Orden de Magnitud del Contrato')
                                        ->placeholder('Por favor seleccione una opción'),

                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\TextInput::make('prof_tech')
                                            ->label('Prof. y Técnicos')->numeric()->minValue(0),
                                        Forms\Components\TextInput::make('manpower')
                                            ->label('Mano de Obra Directa')->numeric()->minValue(0),
                                    ])->columns(2)->label('Esfuerzo H-H'),

                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\Select::make('sectors_id')
                                            ->label('Sector')
                                            ->options(Sector::all()->pluck('name', 'id')->toArray())
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('services_id', null)),

                                        Forms\Components\Select::make('services_id')
                                            ->label('Servicios')
                                            ->multiple()
                                            ->options(function (callable $get) {
                                                $sector = Sector::find($get('sectors_id'));
                                                if (!$sector) {
                                                    return Service::all()->pluck('name', 'id');
                                                }
                                                return $sector->services->pluck('name', 'id');
                                            }),
                                    ])->columns(1)->label('Clasificación del Tipo de Trabajo Realizado')

                                ])->columns(1)->columnSpan(2),
                                Forms\Components\Group::make([
                                    Forms\Components\Textarea::make('Descripcion')
                                        ->label('Breve Descripción del Trabajo Realizado')
                                        ->rows(18)
                                ])->columns(1)->columnSpan(2)
                            ])->columns(7),
                    ])->minItems(1)->maxItems(100)->disableLabel()->label(__('Experiencia')),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('exp_year')
                    ->label('Experiencias por Año')
                    ->getStateUsing(fn (?Experience $record) => $record)
                    ->formatStateUsing(function ($state) {
                        $rows = collect($state?->exp_year ?? [])
                            ->map(fn ($item) => [
                                'exp_year' => $item['exp_year'] ?? '-',
                                'registrado' => $state->created_at?->format('d/m/Y H:i'),
                                'actualizado' => $state->updated_at?->format('d/m/Y H:i'),
                            ])
                            ->sortByDesc('exp_year')
                            ->values()
                            ->all();

                        return new HtmlString(
                            view('filament.tables.columns.repeater-summary', [
                                'items' => $rows,
                                'columns' => [
                                    'exp_year' => 'Año',
                                    'registrado' => 'Registrado',
                                    'actualizado' => 'Actualización',
                                ],
                            ])->render()
                        );
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->modalWidth('7xl')
                    ->visible(fn (RelationManager $livewire) => ! $livewire->ownerRecord->experiences()->exists()),
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_EXPERIENCIAS),
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
