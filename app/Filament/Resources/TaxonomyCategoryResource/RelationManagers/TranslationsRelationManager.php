<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Administra las traducciones de una categoría (una fila por idioma — nunca columnas `name_es`/
 * `name_en` pegadas, ver docs/taxonomia/acuerdos_pendientes_con_lorenzo.md punto 5). La traducción
 * al español la trae Lorenzo con sus propias herramientas; este panel es para cargarla/corregirla
 * a mano mientras tanto, o agregar un idioma nuevo el día que haga falta.
 */
class TranslationsRelationManager extends RelationManager
{
    protected static string $relationship = 'translations';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Traducción';

    public static ?string $navigationLabel = 'Traducciones';

    protected static ?string $pluralModelLabel = 'Traducciones';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('locale')
                ->label('Idioma')
                ->options(['es' => 'Español', 'en' => 'Inglés'])
                ->required()
                ->native(false)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('category_id', $this->getOwnerRecord()->id)),
            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->label('Descripción')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('locale')
                    ->label('Idioma')
                    ->formatStateUsing(fn (string $state) => ['es' => 'Español', 'en' => 'Inglés'][$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('name')->label('Nombre')->wrap(),
                Tables\Columns\TextColumn::make('description')->label('Descripción')->limit(60),
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
