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
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    /**
     * IMPORTANTE: el componente Builder de Filament (usado por el formulario
     * original) guarda cada bloque envuelto asi, con clave UUID:
     *   ["uuid-x" => ["type" => "Experiencia Relevante", "data" => [...campos reales...]]]
     * No es un array plano de campos por indice numerico. Todo el manejo de
     * exp_year debe respetar esta estructura para leer/escribir los datos reales.
     */
    protected static function blockType(): string
    {
        return 'Experiencia Relevante';
    }

    /**
     * Convierte una entrada del arreglo exp_year en un modelo Experience
     * NO PERSISTIDO (exists = false), solo para que Filament pueda mostrarla
     * como una fila real de la tabla y operar sobre ella via acciones.
     * Nunca se guarda directamente: las acciones editar/eliminar siempre
     * reescriben el arreglo completo en el registro real (saveEntry/deleteEntry).
     */
    protected function buildRow(string $key, array $entry, ?Experience $parent): Experience
    {
        $data = $entry['data'] ?? $entry;

        $row = new Experience();
        $row->exists = false;
        $row->id = $key;
        $row->empresa_id = $this->ownerRecord->id;
        $row->created_at = $parent?->created_at;
        $row->updated_at = $parent?->updated_at;
        $row->setAttribute('row_year', $data['exp_year'] ?? null);
        $row->setAttribute('row_data', $data);

        return $row;
    }

    public function getTableRecords(): EloquentCollection
    {
        $parent = $this->ownerRecord->experiences()->first();
        $entries = $parent?->exp_year ?? [];

        $rows = collect($entries)
            ->map(fn ($entry, $key) => $this->buildRow((string) $key, $entry, $parent))
            ->sortByDesc('row_year')
            ->values();

        return new EloquentCollection($rows->all());
    }

    protected function resolveTableRecord(?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        return $this->getTableRecords()->first(fn (Experience $row) => (string) $row->getKey() === (string) $key);
    }

    protected static function saveEntry(RelationManager $livewire, array $data, ?string $key): void
    {
        $experience = $livewire->ownerRecord->experiences()->first();

        if (! $experience) {
            $experience = $livewire->ownerRecord->experiences()->create(['exp_year' => []]);
        }

        $entries = $experience->exp_year ?? [];
        $key ??= (string) Str::uuid();

        $entries[$key] = [
            'type' => static::blockType(),
            'data' => $data,
        ];

        $experience->update(['exp_year' => $entries]);
    }

    protected static function deleteEntry(RelationManager $livewire, string $key): void
    {
        $experience = $livewire->ownerRecord->experiences()->first();

        if (! $experience) {
            return;
        }

        $entries = $experience->exp_year ?? [];
        unset($entries[$key]);

        $experience->update(['exp_year' => $entries]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('row_year')->label('Año')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Registrado')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualización')->dateTime('d/m/Y H:i'),
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
            ])
            ->actions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil')
                    ->modalHeading('Editar Experiencia')
                    ->modalWidth('7xl')
                    ->form(static::fields())
                    ->mountUsing(fn (ComponentContainer $form, Experience $record) => $form->fill($record->row_data ?? []))
                    ->action(fn (array $data, Experience $record, RelationManager $livewire) => static::saveEntry($livewire, $data, (string) $record->getKey())),

                Action::make('eliminar')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Experience $record, RelationManager $livewire) => static::deleteEntry($livewire, (string) $record->getKey())),
            ])
            ->bulkActions([]);
    }
}
