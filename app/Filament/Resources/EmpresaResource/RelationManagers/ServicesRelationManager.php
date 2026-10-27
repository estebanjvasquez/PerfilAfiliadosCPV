<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Models\Service;
use App\Support\Security\EmpresaAccess;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Resources\RelationManagers\RelationManager;

class ServicesRelationManager extends RelationManager
{
    // Mantiene la autorización de la empresa propietaria en las solicitudes Livewire del gestor.
    use \App\Filament\Resources\EmpresaResource\Concerns\ReauthorizesEmpresaOwner;

    //protected static string $model = 'empresasectorservice';

    protected static string $relationship = 'services';

    // TASK-0011: sin carga diferida, la sección se ve con su título y ayuda en vez de una caja vacía.
    protected static bool $isLazy = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Servicio';

    public static ?string $navigationLabel = 'Sectores y servicios (histórico)';

    protected static ?string $pluralModelLabel = 'Servicios';

    protected static ?string $title = 'Sectores y servicios (histórico)';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * TASK-0011 (R1): quitar un vínculo viejo es lo que pide EditEmpresa::beforeSave() cuando hay servicios fuera de los
     * sectores de la empresa, y desde `8352cad` no había cómo hacerlo. Lo decide el mismo acceso que la ficha (vinculado,
     * verificado y con permiso de edición), igual para admin y afiliado; ServicePolicy no tiene `detach`.
     */
    protected function canDetach(Model $record): bool
    {
        return EmpresaAccess::mayEdit(auth()->user(), $this->getOwnerRecord());
    }

    protected function canDetachAny(): bool
    {
        return EmpresaAccess::mayEdit(auth()->user(), $this->getOwnerRecord());
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

    /*  public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRelation('empresasectorservice', 'sectors_id', '=', 2);
    } */

    /**
     * Catálogo viejo puesto en SOLO LECTURA (15 sep 2026) al retomar la Fase 3 del proyecto de
     * taxonomía: sigue siendo la fuente de los reportes actuales (por eso la tabla se deja
     * visible, con sus sectores/servicios ya cargados), pero deja de poder editarse desde acá -
     * la vinculación empresa-categoría hacia adelante vive en la taxonomía CPV nueva
     * (`empresa_taxonomy_category`, ver TaxonomyCategoryResource / EmpresaTaxonomyCategory).
     * Se quitó el AttachAction/DetachAction (antes acá abajo, con la validación de "máximo 2
     * sectores" - esa lógica de negocio sigue viviendo en Empresa::allowedSectorIds()/
     * distinctSectorIds(), solo se removió el punto de entrada de la UI).
     *
     * TASK-0011: vuelve solo «Desvincular» (ver canDetach()); vincular sigue cerrado.
     */
    public function table(Table $table): Table
    {
        return $table
            ->description('Clasificación anterior, guardada por historia y usada en reportes actuales. Ya no se agregan servicios aquí: '
                .'declare lo que hace su empresa en «Categorías CPV». Puede desvincular los servicios que no correspondan; el cambio se guarda al instante.')
            ->columns([
                Tables\Columns\TextColumn::make('sectors.name')->label('Sector'),
                Tables\Columns\TextColumn::make('name')->label('Servicio'),
            ])
            ->headerActions([])
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
