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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class ExperiencesRelationManager extends RelationManager
{
    protected static string $relationship = 'experiences';

    public static ?string $label = 'Experiencia Relevante';

    public static ?string $navigationLabel = 'Experiencia Relevante';

    protected static ?string $pluralModelLabel = 'Experiencia Relevante';

    /**
     * Campos de una experiencia individual (un "año" de experiencia).
     * Se usan tanto para agregar una nueva como para editar una existente.
     */
    protected static function fields(): array
    {
        return [
            Group::make([
                TextInput::make('exp_year')
                    ->label('Año')
                    ->required()
                    ->numeric()->minValue(1901)->maxValue(2150),
            ])->columns(1),

            Group::make([
                Fieldset::make('clasificacion')->schema([
                    Select::make('infrasectors_id')
                        ->label('Sector')
                        ->options(InfraSector::all()->pluck('sector_name', 'id')->toArray())
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('infratypes_id', null)),

                    Select::make('infratypes_id')
                        ->label('Tipo')
                        ->options(function (callable $get) {
                            $isector = InfraSector::find($get('infrasectors_id'));
                            if (! $isector) {
                                return [];
                            }
                            return $isector->infratypes->pluck('type_name', 'id');
                        })
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('infrasystems_id', null)),

                    Select::make('infrasystems_id')
                        ->label('Sistema')
                        ->options(function (callable $get) {
                            $itype = InfraType::find($get('infratypes_id'));
                            if (! $itype) {
                                return [];
                            }
                            return $itype->infrasystems->pluck('system_name', 'id');
                        })
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('infraregions_id', null)),

                    Select::make('infraregions_id')
                        ->label('Región o Distrito')
                        ->options(function (callable $get) {
                            $isystem = InfraSystem::find($get('infrasystems_id'));
                            if (! $isystem) {
                                return [];
                            }
                            return $isystem->infraregions->pluck('region_name', 'id');
                        })
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('infrafacilities_id', null)),

                    Select::make('infrafacilities_id')
                        ->label('Instalación')
                        ->options(function (callable $get) {
                            $iregsys = InfraRegion::find($get('infraregions_id'));
                            if (! $iregsys) {
                                return [];
                            }
                            return $iregsys->getFacility($iregsys->id, $get('infrasystems_id'))->pluck('facility_name', 'id');
                        }),
                ])->columns(1)->label('Infraestructura en la que Trabajó'),
            ])->columns(1)->columnSpan(2),

            Group::make([
                Select::make('magnitud')
                    ->options([
                        '1' => '< 100.000 USD',
                        '2' => '100.001 - 1.000.000 USD',
                        '3' => '1.000.001 - 10.000.000 USD',
                        '4' => '> 10.000.001 USD',
                    ])->label('Orden de Magnitud del Contrato')
                    ->placeholder('Por favor seleccione una opción'),

                Fieldset::make('clasificacion')->schema([
                    TextInput::make('prof_tech')->label('Prof. y Técnicos')->numeric()->minValue(0),
                    TextInput::make('manpower')->label('Mano de Obra Directa')->numeric()->minValue(0),
                ])->columns(2)->label('Esfuerzo H-H'),

                Fieldset::make('clasificacion')->schema([
                    Select::make('sectors_id')
                        ->label('Sector')
                        ->options(Sector::all()->pluck('name', 'id')->toArray())
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('services_id', null)),

                    Select::make('services_id')
                        ->label('Servicios')
                        ->multiple()
                        ->options(function (callable $get) {
                            $sector = Sector::find($get('sectors_id'));
                            if (! $sector) {
                                return Service::all()->pluck('name', 'id');
                            }
                            return $sector->services->pluck('name', 'id');
                        }),
                ])->columns(1)->label('Clasificación del Tipo de Trabajo Realizado'),
            ])->columns(1)->columnSpan(2),

            Group::make([
                Textarea::make('Descripcion')
                    ->label('Breve Descripción del Trabajo Realizado')
                    ->rows(10),
            ])->columns(1)->columnSpan(2),
        ];
    }

    protected static function entries(RelationManager $livewire): array
    {
        return $livewire->ownerRecord->experiences()->first()?->exp_year ?? [];
    }

    protected static function saveEntry(RelationManager $livewire, array $data, ?int $index): void
    {
        $experience = $livewire->ownerRecord->experiences()->first();

        if (! $experience) {
            $experience = $livewire->ownerRecord->experiences()->create(['exp_year' => []]);
        }

        $entries = $experience->exp_year ?? [];

        if ($index === null) {
            $entries[] = $data;
        } else {
            $entries[$index] = $data;
        }

        $experience->update(['exp_year' => array_values($entries)]);
    }

    protected static function deleteEntry(RelationManager $livewire, int $index): void
    {
        $experience = $livewire->ownerRecord->experiences()->first();

        if (! $experience) {
            return;
        }

        $entries = $experience->exp_year ?? [];
        unset($entries[$index]);

        $experience->update(['exp_year' => array_values($entries)]);
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
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_EXPERIENCIAS),

                Action::make('agregar_experiencia')
                    ->label('Agregar Experiencia')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Agregar Experiencia')
                    ->modalWidth('7xl')
                    ->form(static::fields())
                    ->action(fn (array $data, RelationManager $livewire) => static::saveEntry($livewire, $data, null)),

                Action::make('editar_experiencia')
                    ->label('Editar / Eliminar Experiencia')
                    ->icon('heroicon-o-pencil')
                    ->modalHeading('Editar / Eliminar Experiencia')
                    ->modalWidth('7xl')
                    ->visible(fn (RelationManager $livewire) => count(static::entries($livewire)) > 0)
                    ->form(function (RelationManager $livewire) {
                        $entries = static::entries($livewire);

                        return [
                            Select::make('_selected_index')
                                ->label('Seleccione la experiencia a editar')
                                ->options(collect($entries)->mapWithKeys(
                                    fn ($entry, $index) => [$index => 'Año ' . ($entry['exp_year'] ?? '-')]
                                ))
                                ->reactive()
                                ->required()
                                ->afterStateUpdated(function ($state, callable $set) use ($entries) {
                                    $entry = $entries[$state] ?? [];
                                    foreach ($entry as $field => $value) {
                                        $set($field, $value);
                                    }
                                }),
                            Checkbox::make('_eliminar')
                                ->label('Eliminar esta experiencia (en vez de guardar cambios)'),
                            ...static::fields(),
                        ];
                    })
                    ->action(function (array $data, RelationManager $livewire) {
                        $index = (int) $data['_selected_index'];

                        if ($data['_eliminar'] ?? false) {
                            static::deleteEntry($livewire, $index);
                            return;
                        }

                        unset($data['_selected_index'], $data['_eliminar']);
                        static::saveEntry($livewire, $data, $index);
                    }),
            ])
            ->actions([])
            ->bulkActions([
                FilamentExportBulkAction::make('export')
                    ->additionalColumnsAddButtonLabel('Add Column'),
            ]);
    }
}
