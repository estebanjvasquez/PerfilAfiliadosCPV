<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Diccionario de sinónimos/términos comerciales locales de una categoría — para que el buscador
 * encuentre la categoría correcta aunque la empresa escriba un término venezolano que no es ni el
 * nombre oficial ni su traducción formal (ver docs/taxonomia/analisis_taxonomia_supplyhigh.md
 * sección 3, tabla taxonomy_category_synonyms).
 */
class SynonymsRelationManager extends RelationManager
{
    protected static string $relationship = 'synonyms';

    protected static ?string $recordTitleAttribute = 'term';

    public static ?string $label = 'Sinónimo';

    public static ?string $navigationLabel = 'Sinónimos / diccionario';

    protected static ?string $pluralModelLabel = 'Sinónimos';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('term')
                ->label('Término')
                ->required()
                ->maxLength(255)
                ->helperText('Ej. "tubo" para la categoría "Pipe".'),
            Forms\Components\Select::make('locale')
                ->label('Idioma')
                ->options(['es' => 'Español', 'en' => 'Inglés'])
                ->native(false)
                ->helperText('Vacío = aplica sin importar el idioma (ej. una sigla).'),
            Forms\Components\TextInput::make('origen')
                ->label('Origen')
                ->default('manual')
                ->helperText('Ej. "manual", "local_venezuela", "lorenzo".')
                ->required()
                ->maxLength(100),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term')->label('Término')->searchable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label('Idioma')
                    ->formatStateUsing(fn (?string $state) => ['es' => 'Español', 'en' => 'Inglés'][$state] ?? '—')
                    ->badge(),
                Tables\Columns\TextColumn::make('origen')->label('Origen'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
