<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\NoAplicaAction;
use App\Models\Asset;
use App\Models\EmpresaModuleStatus;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    public static ?string $label = 'Recursos en Venezuela';

    public static ?string $navigationLabel = 'Recursos en Venezuela';

    protected static ?string $pluralModelLabel = 'Recursos en Venezuela';

    protected static array $qtyOptions = [
        '1' => '1 - 10',
        '2' => '11 - 50',
        '3' => '51 - 100',
        '4' => '> 100',
    ];

    protected static array $valueOptions = [
        '1' => '< 100.000 USD',
        '2' => '100.001 - 1.000.000 USD',
        '3' => '1.000.001 - 10.000.000 USD',
        '4' => '> 10.000.001 USD',
    ];

    protected static function titles(): array
    {
        $titles = json_decode(file_get_contents(storage_path() . '/tituloshr.json'), true);

        return [
            'employee' => $titles['personal'][0] ?? [],
            'machinery' => $titles['maquinaria'][0] ?? [],
            'facility' => $titles['instalaciones'][0] ?? [],
            'inventory' => $titles['inventario'][0] ?? [],
        ];
    }

    protected static function saveField(RelationManager $livewire, string $field, $value): void
    {
        $asset = $livewire->ownerRecord->assets()->first();

        if (! $asset) {
            $asset = $livewire->ownerRecord->assets()->create([]);
        }

        $asset->update([$field => $value]);
    }

    /**
     * Switch "No Aplica" embebido en el form de un tipo individual de recurso
     * (no confundir con NoAplicaAction, que marca el módulo completo).
     */
    protected static function noAplicaToggle(string $subType): Toggle
    {
        $subTypeLabel = EmpresaModuleStatus::SUB_TYPES[EmpresaModuleStatus::MODULE_RECURSOS][$subType];

        return Toggle::make('no_aplica')
            ->label('No Aplica')
            ->helperText("Si lo activa, \"{$subTypeLabel}\" contará como completado en el perfil aunque no cargue datos. Si guarda datos, la marca se elimina automáticamente.")
            ->reactive()
            ->default(fn (RelationManager $livewire) => EmpresaModuleStatus::isNoAplica(
                (int) $livewire->ownerRecord->id,
                EmpresaModuleStatus::MODULE_RECURSOS,
                $subType
            ));
    }

    protected static function saveFieldWithNoAplica(RelationManager $livewire, string $field, string $subType, array $data): void
    {
        EmpresaModuleStatus::setStatus(
            (int) $livewire->ownerRecord->id,
            EmpresaModuleStatus::MODULE_RECURSOS,
            (bool) ($data['no_aplica'] ?? false),
            $subType
        );

        // Filament omite del $data dehidratado cualquier campo dentro de un
        // Group oculto (ver HasState::dehydrateState en el core de Filament) —
        // cuando "No Aplica" está activo el Repeater ni siquiera aparece en
        // $data, así que no se toca el dato ya guardado en BD.
        if (array_key_exists($field, $data)) {
            static::saveField($livewire, $field, $data[$field]);
        }
    }

    protected static function summaryColumn(string $field, string $subType, string $label, array $columns, array $optionsMap = []): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($field)
            ->label($label)
            // Importante: getState() de Filament convierte automaticamente cualquier
            // array en string via implode() ANTES de que formatStateUsing() se ejecute
            // (rompe con "Array to string conversion" si el array tiene sub-arrays).
            // Por eso se pasa el $record completo (no un array) y se extrae el campo
            // JSON manualmente dentro de formatStateUsing().
            ->getStateUsing(fn (?Asset $record) => $record)
            ->formatStateUsing(fn ($state, RelationManager $livewire) => new HtmlString(
                view('filament.tables.columns.repeater-summary', [
                    'items' => $state?->{$field} ?? [],
                    'columns' => $columns,
                    'optionsMap' => $optionsMap,
                    'noAplica' => EmpresaModuleStatus::isNoAplica(
                        (int) $livewire->ownerRecord->id,
                        EmpresaModuleStatus::MODULE_RECURSOS,
                        $subType
                    ),
                ])->render()
            ));
    }

    public static function table(Table $table): Table
    {
        $titles = static::titles();

        return $table
            ->columns([
                static::summaryColumn('employee', 'employee', 'Recursos Humanos', [
                    'employee_type' => 'Tipo de Recurso',
                    'junior_q' => 'Junior',
                    'medium_q' => 'Medium',
                    'senior_q' => 'Senior',
                ], [
                    'employee_type' => $titles['employee'],
                ]),
                static::summaryColumn('machinery', 'machinery', 'Maquinaria y Equipos', [
                    'machinery_name' => 'Equipo',
                    'machinery_qid' => 'Cantidad',
                    'machinery_est' => 'Valor Estimado',
                ], [
                    'machinery_name' => $titles['machinery'],
                    'machinery_qid' => static::$qtyOptions,
                    'machinery_est' => static::$valueOptions,
                ]),
                static::summaryColumn('facility', 'facility', 'Instalaciones', [
                    'facility_type' => 'Tipo de Instalación',
                    'facility_q' => 'Cantidad',
                    'facility_surf' => 'Sup (m²)',
                    'facility_own' => 'Propiedad',
                ], [
                    'facility_type' => $titles['facility'],
                    'facility_own' => ['1' => 'Propia', '2' => 'Alquilada', '3' => 'Ambas'],
                ]),
                static::summaryColumn('inventory', 'inventory', 'Inventario', [
                    'inventory_name' => 'Tipo de Inventario',
                    'inventory_q' => 'Cantidad',
                    'inventory_unit' => 'Unidad',
                    'inventory_est' => 'Valor Estimado',
                ], [
                    'inventory_name' => $titles['inventory'],
                    'inventory_q' => static::$qtyOptions,
                    'inventory_est' => static::$valueOptions,
                ]),
            ])
            ->headerActions([
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_RECURSOS),

                Action::make('recursos_humanos')
                    ->label('Recursos Humanos')
                    ->icon('heroicon-o-user-group')
                    ->modalHeading('Agregar / Editar Recursos Humanos')
                    ->modalWidth('4xl')
                    ->form([
                        static::noAplicaToggle('employee'),
                        Group::make([
                            Repeater::make('employee')
                                ->label('Recursos Humanos')
                                ->default(fn (RelationManager $livewire) => $livewire->ownerRecord->assets()->first()?->employee ?? [])
                                ->schema([
                                    Select::make('employee_type')->required()->label('Tipo de Recurso')->options($titles['employee']),
                                    TextInput::make('junior_q')->required()->numeric()->minValue(0)->default(0)->label('Junior'),
                                    TextInput::make('medium_q')->required()->numeric()->minValue(0)->default(0)->label('Medium'),
                                    TextInput::make('senior_q')->required()->numeric()->minValue(0)->default(0)->label('Senior'),
                                ])
                                ->columns(4)
                                ->orderable(false)
                                ->createItemButtonLabel('Agregar otro tipo de Recurso Humano'),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldWithNoAplica($livewire, 'employee', 'employee', $data)),

                Action::make('maquinaria')
                    ->label('Maquinaria y Equipos')
                    ->icon('heroicon-o-cog')
                    ->modalHeading('Agregar / Editar Maquinaria y Equipos Propios')
                    ->modalWidth('4xl')
                    ->form([
                        static::noAplicaToggle('machinery'),
                        Group::make([
                            Repeater::make('machinery')
                                ->label('Maquinaria y Equipos')
                                ->default(fn (RelationManager $livewire) => $livewire->ownerRecord->assets()->first()?->machinery ?? [])
                                ->schema([
                                    Select::make('machinery_name')->required()->label('Equipo')->options($titles['machinery']),
                                    Select::make('machinery_qid')->options(static::$qtyOptions)->label('Cantidad (n)')->placeholder('Por favor seleccione una opción')->required(),
                                    Select::make('machinery_est')->options(static::$valueOptions)->label('Valor Estimado')->placeholder('Por favor seleccione una opción')->required(),
                                ])
                                ->columns(3)
                                ->orderable(false)
                                ->createItemButtonLabel('Agregar otro equipo'),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldWithNoAplica($livewire, 'machinery', 'machinery', $data)),

                Action::make('instalaciones')
                    ->label('Instalaciones')
                    ->icon('heroicon-o-office-building')
                    ->modalHeading('Agregar / Editar Instalaciones')
                    ->modalWidth('4xl')
                    ->form([
                        static::noAplicaToggle('facility'),
                        Group::make([
                            Repeater::make('facility')
                                ->label('Instalaciones')
                                ->default(fn (RelationManager $livewire) => $livewire->ownerRecord->assets()->first()?->facility ?? [])
                                ->schema([
                                    Select::make('facility_type')->required()->label('Tipo de Instalación')->options($titles['facility']),
                                    TextInput::make('facility_q')->required()->label('Cantidad')->numeric()->minValue(0),
                                    TextInput::make('facility_surf')->required()->label('Sup (mt2)')->numeric()->minValue(0)
                                        ->mask(fn (TextInput\Mask $mask) => $mask->numeric()->decimalPlaces(2)->minValue(0)->thousandsSeparator('.')),
                                    Select::make('facility_own')->options(['1' => 'Propia', '2' => 'Alquilada', '3' => 'Ambas'])->label('Tipo de Propiedad')->placeholder('Por favor seleccione una opción')->required(),
                                ])
                                ->columns(4)
                                ->orderable(false)
                                ->createItemButtonLabel('Agregar otra instalación'),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldWithNoAplica($livewire, 'facility', 'facility', $data)),

                Action::make('inventario')
                    ->label('Inventario')
                    ->icon('heroicon-o-archive')
                    ->modalHeading('Agregar / Editar Inventario')
                    ->modalWidth('4xl')
                    ->form([
                        static::noAplicaToggle('inventory'),
                        Group::make([
                            Repeater::make('inventory')
                                ->label('Inventario')
                                ->default(fn (RelationManager $livewire) => $livewire->ownerRecord->assets()->first()?->inventory ?? [])
                                ->schema([
                                    Select::make('inventory_name')->required()->label('Tipo de Inventario')->options($titles['inventory']),
                                    Select::make('inventory_q')->options(static::$qtyOptions)->label('Cantidad (n)')->placeholder('Por favor seleccione una opción')->required(),
                                    TextInput::make('inventory_unit')->required()->label('Unidad')
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                    Select::make('inventory_est')->options(static::$valueOptions)->label('Valor actual Estimado')->placeholder('Por favor seleccione una opción')->required(),
                                ])
                                ->columns(4)
                                ->orderable(false)
                                ->createItemButtonLabel('Agregar otro ítem de inventario'),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldWithNoAplica($livewire, 'inventory', 'inventory', $data)),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
