<?php

namespace App\Filament\Resources\TaxonomyCanonicalConceptResource\RelationManagers;

use App\Models\TaxonomyTerm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * TAXV3-2: acá un admin vincula a mano un regionalismo sin solapamiento léxico (mechurrio, arbolito,
 * guaya fina) con su concepto verificado - el único caso que
 * `taxonomy:bootstrap-canonical-concepts` no puede resolver solo (ver docblock del comando).
 */
class TermsRelationManager extends RelationManager
{
    protected static string $relationship = 'terms';

    protected static ?string $recordTitleAttribute = 'term';

    public static ?string $label = 'Término';

    public static ?string $navigationLabel = 'Términos';

    protected static ?string $pluralModelLabel = 'Términos';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('term')
            ->columns([
                Tables\Columns\TextColumn::make('term')->label('Término')->searchable(),
                Tables\Columns\TextColumn::make('canonical_term')->label('Canónico')->toggleable(),
                Tables\Columns\TextColumn::make('language')->label('Idioma')->badge(),
                Tables\Columns\TextColumn::make('display_source')->label('Fuente')->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->recordSelect(fn (Forms\Components\Select $select) => $select
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => TaxonomyTerm::query()
                            ->where('term', 'ilike', "%{$search}%")
                            ->orWhere('canonical_term', 'ilike', "%{$search}%")
                            ->limit(20)
                            ->pluck('term', 'id'))
                        ->getOptionLabelUsing(fn ($value) => TaxonomyTerm::find($value)?->term)),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
