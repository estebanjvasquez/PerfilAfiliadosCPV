<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Models\Empresa;
use App\Models\Sector;
use App\Models\Service;
use App\Support\Security\EmpresaAccess;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * Sectores y servicios de la empresa (`empresa_sector_service`).
 *
 * Decisión del propietario (2026-11-07, TASK-0013): sectores y servicios son dato vigente, se editan desde aquí y el
 * buscador los usa como evidencia principal; las Categorías CPV son información adicional, no su reemplazo. Desde
 * `8352cad` (15 sep 2026) esta sección estaba en solo lectura y TASK-0011 había devuelto solo «Desvincular».
 *
 * Vincular sigue la regla de 2 sectores de `cbc1e37`: con sector principal/secundario definidos, solo servicios de esos
 * sectores; sin ellos, como máximo 2 sectores distintos. El servicio «X» (marcador sin servicio concreto, uno por sector)
 * ya no se puede vincular: se reemplaza por el servicio real y después se quita del catálogo.
 */
class ServicesRelationManager extends RelationManager
{
    // Mantiene la autorización de la empresa propietaria en las solicitudes Livewire del gestor.
    use \App\Filament\Resources\EmpresaResource\Concerns\ReauthorizesEmpresaOwner;

    protected static string $relationship = 'services';

    // TASK-0011: sin carga diferida, la sección se ve con su título y ayuda en vez de una caja vacía.
    protected static bool $isLazy = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Servicio';

    public static ?string $navigationLabel = 'Sectores y servicios';

    protected static ?string $pluralModelLabel = 'Servicios';

    protected static ?string $title = 'Sectores y servicios';

    /** Nombre del servicio marcador que no describe nada concreto. */
    public const PLACEHOLDER_SERVICE_NAME = 'X';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    /** Vincular y desvincular los decide el mismo acceso que la ficha (vinculado, verificado y con permiso de edición). */
    protected function canAttach(): bool
    {
        return EmpresaAccess::mayEdit(auth()->user(), $this->getOwnerRecord());
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    protected function canDetach(Model $record): bool
    {
        return EmpresaAccess::mayEdit(auth()->user(), $this->getOwnerRecord());
    }

    protected function canDetachAny(): bool
    {
        return EmpresaAccess::mayEdit(auth()->user(), $this->getOwnerRecord());
    }

    public static function isPlaceholder(?Service $service): bool
    {
        return $service !== null && mb_strtoupper(trim((string) $service->name)) === self::PLACEHOLDER_SERVICE_NAME;
    }

    /**
     * Sectores entre los que la empresa puede elegir servicios: su principal y secundario si están definidos; si no,
     * los que ya usa cuando llegó a 2; si no, todos.
     *
     * @return int[]|null null = todos
     */
    public static function selectableSectorIds(Empresa $empresa): ?array
    {
        $allowed = $empresa->allowedSectorIds();
        if ($allowed !== []) {
            return $allowed;
        }
        $current = $empresa->distinctSectorIds();

        return count($current) >= 2 ? $current : null;
    }

    /**
     * Motivo por el que no se puede vincular `$service` a `$empresa` (título y texto del aviso), o null si se puede.
     * Se revisa en el servidor al guardar, aunque el formulario ya filtre las opciones.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function attachRefusal(Empresa $empresa, ?Service $service): ?array
    {
        if ($service === null) {
            return ['Servicio no encontrado', 'Elija un servicio de la lista.'];
        }
        if (static::isPlaceholder($service)) {
            return ['Servicio no disponible', '«X» no describe un servicio. Elija el servicio que corresponde a su empresa.'];
        }
        if ($empresa->services()->whereKey($service->getKey())->exists()) {
            return ['Servicio ya vinculado', 'Su empresa ya tiene este servicio.'];
        }

        $sectorId = (int) $service->sectors_id;
        $allowed = $empresa->allowedSectorIds();
        if ($allowed !== []) {
            return in_array($sectorId, $allowed, true) ? null : ['Sector no permitido',
                'Solo puede asociar servicios de su sector principal o secundario. Si necesita cambiar de sectores, edite los datos de la empresa.'];
        }

        $current = $empresa->distinctSectorIds();

        return (! in_array($sectorId, $current, true) && count($current) >= 2) ? ['Máximo 2 sectores por empresa',
            'Su empresa ya tiene servicios en 2 sectores. Defina su sector principal y secundario en los datos de la empresa, o desvincule servicios de otros sectores.'] : null;
    }

    private function attachService(array $data): void
    {
        /** @var Empresa $empresa */
        $empresa = $this->getOwnerRecord();
        abort_unless(EmpresaAccess::mayEdit(auth()->user(), $empresa), 404);

        $service = Service::find($data['service_id'] ?? null);
        $refusal = static::attachRefusal($empresa, $service);
        if ($refusal !== null) {
            Notification::make()->danger()->title($refusal[0])->body($refusal[1])->persistent()->send();

            return;
        }

        DB::connection($empresa->getConnectionName())->transaction(function () use ($empresa, $service) {
            $empresa->services()->attach($service->getKey(), ['created_at' => now(), 'updated_at' => now()]);
            // attach()/detach() de un pivote no dispara eventos de modelo: el % de completitud se refresca a mano.
            $empresa->refreshCompletionPercentage();
        });

        Notification::make()->success()->title('Servicio vinculado')->body($service->name)->send();
    }

    /** Solo borra filas de empresa_sector_service de esta empresa (el registro sale de su relación), nunca el catálogo. */
    private function detachLinks(Collection $records): void
    {
        $empresa = $this->getOwnerRecord();

        DB::connection($empresa->getConnectionName())->transaction(function () use ($empresa, $records) {
            $empresa->services()->detach($records->modelKeys());
            $empresa->refreshCompletionPercentage();
        });
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Los sectores y servicios de su empresa. Se usan en el buscador y en los reportes; además puede declarar lo que hace en «Categorías CPV». '
                .'Vincule los servicios que su empresa presta y desvincule los que no correspondan; el cambio se guarda al instante.')
            ->columns([
                Tables\Columns\TextColumn::make('sectors.name')->label('Sector'),
                Tables\Columns\TextColumn::make('name')->label('Servicio'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('attach')
                    ->label('Vincular servicio')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Vincular servicio')
                    ->modalSubmitActionLabel('Vincular')
                    ->visible(fn () => $this->canAttach())
                    ->form([
                        Forms\Components\Select::make('sector_id')
                            ->label('Sector')
                            ->options(function () {
                                $ids = static::selectableSectorIds($this->getOwnerRecord());

                                return Sector::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->pluck('name', 'id');
                            })
                            ->helperText('Limitado al sector principal y secundario definidos en los datos de la empresa (máximo 2 sectores).')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('service_id', null)),
                        Forms\Components\Select::make('service_id')
                            ->label('Servicio')
                            ->options(fn (Forms\Get $get) => Service::query()
                                ->where('sectors_id', $get('sector_id'))
                                ->whereRaw('upper(trim(name)) <> ?', [self::PLACEHOLDER_SERVICE_NAME])
                                ->whereNotIn('id', $this->getOwnerRecord()->services()->pluck('services.id'))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->helperText('Los servicios que su empresa ya tiene vinculados no aparecen en esta lista.')
                            ->required()
                            ->hidden(fn (Forms\Get $get) => blank($get('sector_id'))),
                    ])
                    ->action(fn (array $data) => $this->attachService($data)),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->modalHeading('Desvincular servicio')
                    ->modalDescription('Se quita este servicio de su empresa. El servicio no se borra del catálogo de la Cámara.')
                    ->using(fn (Service $record) => $this->detachLinks(new Collection([$record]))),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()
                    ->modalDescription('Se quitan los servicios seleccionados de su empresa. No se borran del catálogo de la Cámara.')
                    ->using(fn (Collection $records) => $this->detachLinks($records)),
            ])
            ->filters([
                //
            ]);
    }
}
