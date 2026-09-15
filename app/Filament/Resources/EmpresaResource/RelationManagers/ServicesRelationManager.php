<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\RelationManagers\RelationManager;

class ServicesRelationManager extends RelationManager
{
    //protected static string $model = 'empresasectorservice';

    protected static string $relationship = 'services';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Sectores y servicios';

    public static ?string $navigationLabel = 'Sectores y servicios';

    protected static ?string $pluralModelLabel = 'Sectores y servicios';

    protected static ?string $title = 'Sectores y servicios';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
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
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sectors.name')->label('Sectores'),
                Tables\Columns\TextColumn::make('name')->label('Servicios'),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([
                //
            ])
            ->filters([
                //
            ]);
    }
}
