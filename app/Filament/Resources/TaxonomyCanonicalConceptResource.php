<?php

namespace App\Filament\Resources;

use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\TaxonomyCanonicalConceptResource\Pages;
use App\Filament\Resources\TaxonomyCanonicalConceptResource\RelationManagers;

/**
 * TAXV3-2 (ajuste V3 puntos 5/6): CRUD de conceptos canónicos. La mayoría se crean por
 * `taxonomy:bootstrap-canonical-concepts` (uno por cada grupo de términos `external_verified`) - la
 * creación manual acá sirve para el caso que ese comando no puede resolver solo: vincular a mano un
 * regionalismo sin ningún solapamiento léxico con su equivalente verificado (ej. "mechurrio" con el
 * concepto de "flare").
 */
class TaxonomyCanonicalConceptResource extends Resource
{
    protected static ?string $model = TaxonomyCanonicalConcept::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Conceptos canónicos';

    public static ?string $label = 'Concepto canónico';

    protected static ?string $pluralModelLabel = 'Conceptos canónicos';

    protected static ?int $navigationSort = 15;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('terms');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['canonical_name_en', 'canonical_name_es'];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('canonical_name_en')->label('Nombre canónico (EN)')->maxLength(255),
            Forms\Components\TextInput::make('canonical_name_es')->label('Nombre canónico (ES)')->maxLength(255),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    TaxonomyCanonicalConcept::STATUS_ACTIVE => 'Activo',
                    TaxonomyCanonicalConcept::STATUS_MERGED => 'Fusionado con otro concepto',
                ])
                ->native(false)
                ->required()
                ->default(TaxonomyCanonicalConcept::STATUS_ACTIVE),
            Forms\Components\Select::make('concept_type')
                ->label('Tipo de concepto (Phase 3, opcional)')
                ->helperText('Clasificación opcional - los conceptos existentes quedan en null hasta que un administrador los revise a mano. Ver vocabulario en "Tipos de concepto".')
                ->options(fn () => TaxonomyConceptType::activeOptions())
                ->native(false)
                ->nullable(),
            Forms\Components\TextInput::make('domain')
                ->label('Dominio (Phase 3, opcional)')
                ->maxLength(50)
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('canonical_name_en')->label('EN')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('canonical_name_es')->label('ES')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('terms_count')->label('Términos vinculados')->sortable(),
                Tables\Columns\TextColumn::make('concept_type')->label('Tipo (Phase 3)')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('domain')->label('Dominio (Phase 3)')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'success' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
                        'gray' => TaxonomyCanonicalConcept::STATUS_MERGED,
                    ]),
            ])
            ->filters([
                Tables\Filters\Filter::make('sin_terminos')
                    ->label('Sin ningún término vinculado')
                    ->query(fn (Builder $query) => $query->doesntHave('terms')),
            ])
            ->defaultSort('canonical_name_en')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TermsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyCanonicalConcepts::route('/'),
            'create' => Pages\CreateTaxonomyCanonicalConcept::route('/create'),
            'edit' => Pages\EditTaxonomyCanonicalConcept::route('/{record}/edit'),
        ];
    }
}
