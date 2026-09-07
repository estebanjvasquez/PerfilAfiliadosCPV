<?php

namespace App\Filament\Resources;

use App\Models\TaxonomyCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\TaxonomyCategoryResource\Pages;
use App\Filament\Resources\TaxonomyCategoryResource\RelationManagers;

/**
 * Panel de administración de la taxonomía CPV (Grupo > Familia > Categoría) — módulo pedido el
 * 7 sep 2026 para poder corregir/editar/agregar/borrar categorías, administrar el "Belongs"
 * (`chamber_relevance`/`is_active`) y administrar traducciones/diccionario de sinónimos sin volver
 * a correr `taxonomy:import`. Ver docs/taxonomia/acuerdos_pendientes_con_lorenzo.md para el
 * contexto de cada campo.
 */
class TaxonomyCategoryResource extends Resource
{
    protected static ?string $model = TaxonomyCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Categorías';

    public static ?string $label = 'Categoría';

    protected static ?string $pluralModelLabel = 'Categorías';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'branch', 'subbranch'];
    }

    public static function getEloquentQuery(): Builder
    {
        // Precarga la traduccion en espanol (la que de verdad se le muestra al afiliado) con
        // fallback a ingles para la columna de nombre - evita 1 query extra por fila en la tabla
        // (mismo criterio de rendimiento ya establecido en Empresa::moduleBreakdown()).
        return parent::getEloquentQuery()
            ->with(['translations' => fn ($q) => $q->whereIn('locale', ['es', 'en'])])
            ->withCount(['children', 'empresaLinks']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidad')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código (CPV)')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('Ej. CPV-01.01.01G — el código de Lorenzo con el prefijo CPV- agregado. No repetir.'),
                    Forms\Components\Select::make('parent_id')
                        ->label('Categoría padre')
                        ->relationship(
                            name: 'parent',
                            titleAttribute: 'code',
                            modifyQueryUsing: fn (Builder $query, $record) => $record
                                ? $query->where('id', '!=', $record->id)
                                : $query
                        )
                        ->searchable()
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (TaxonomyCategory $record) => "{$record->code} — {$record->nameIn('es')}")
                        ->helperText('Vacío = nodo raíz (Grupo).'),
                    Forms\Components\Select::make('tipo_oferta')
                        ->label('Tipo de oferta')
                        ->options([
                            TaxonomyCategory::TIPO_BIEN => 'Bien',
                            TaxonomyCategory::TIPO_SERVICIO => 'Servicio',
                        ])
                        ->native(false)
                        ->helperText('Solo aplica a categorías hoja (Goods/Services de Lorenzo).'),
                    Forms\Components\TextInput::make('source_version')
                        ->label('Versión de origen')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('La carga (Excel) de donde vino esta fila.'),
                ]),

            Forms\Components\Section::make('Etiquetas descriptivas (sin código propio)')
                ->description('Branch/Subbranch del archivo de Lorenzo — texto libre, no son nodos navegables (acuerdo punto 2).')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('branch')->label('Branch')->maxLength(255),
                    Forms\Components\TextInput::make('subbranch')->label('Subbranch')->maxLength(255),
                ]),

            Forms\Components\Section::make('Relevancia para la Cámara ("Belongs")')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('chamber_relevance')
                        ->label('Clasificación')
                        ->options([
                            TaxonomyCategory::RELEVANCE_BELONGS => 'Belongs',
                            TaxonomyCategory::RELEVANCE_MAYBE => 'Maybe',
                            TaxonomyCategory::RELEVANCE_DOES_NOT_BELONG => 'Does not belong',
                        ])
                        ->native(false),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Activa (visible en el buscador para las empresas)')
                        ->default(true),
                    Forms\Components\Textarea::make('relevance_basis')
                        ->label('Fundamento de la clasificación')
                        ->columnSpanFull()
                        ->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('name_es')
                    ->label('Nombre (ES)')
                    ->getStateUsing(fn (TaxonomyCategory $record) => $record->translations->firstWhere('locale', 'es')?->name)
                    ->placeholder('— sin traducir —')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'translations',
                        fn ($q) => $q->where('locale', 'es')->where('name', 'like', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('name_en')
                    ->label('Nombre (EN)')
                    ->getStateUsing(fn (TaxonomyCategory $record) => $record->translations->firstWhere('locale', 'en')?->name)
                    ->toggleable(),
                Tables\Columns\BadgeColumn::make('level')
                    ->label('Nivel')
                    ->formatStateUsing(fn (int $state) => [0 => 'Grupo', 1 => 'Familia', 2 => 'Categoría'][$state] ?? $state)
                    ->colors(['primary' => 0, 'warning' => 1, 'success' => 2]),
                Tables\Columns\TextColumn::make('tipo_oferta')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        TaxonomyCategory::TIPO_BIEN => 'Bien',
                        TaxonomyCategory::TIPO_SERVICIO => 'Servicio',
                        default => '—',
                    })
                    ->toggleable(),
                Tables\Columns\SelectColumn::make('chamber_relevance')
                    ->label('Belongs')
                    ->options([
                        TaxonomyCategory::RELEVANCE_BELONGS => 'Belongs',
                        TaxonomyCategory::RELEVANCE_MAYBE => 'Maybe',
                        TaxonomyCategory::RELEVANCE_DOES_NOT_BELONG => 'Does not belong',
                    ])
                    ->selectablePlaceholder(false),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Activa'),
                Tables\Columns\TextColumn::make('branch')->label('Branch')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subbranch')->label('Subbranch')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('children_count')->label('Hijos')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('empresa_links_count')->label('Empresas')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')
                    ->label('Nivel')
                    ->options([0 => 'Grupo', 1 => 'Familia', 2 => 'Categoría']),
                Tables\Filters\SelectFilter::make('chamber_relevance')
                    ->label('Belongs')
                    ->options([
                        TaxonomyCategory::RELEVANCE_BELONGS => 'Belongs',
                        TaxonomyCategory::RELEVANCE_MAYBE => 'Maybe',
                        TaxonomyCategory::RELEVANCE_DOES_NOT_BELONG => 'Does not belong',
                    ]),
                Tables\Filters\SelectFilter::make('tipo_oferta')
                    ->label('Tipo de oferta')
                    ->options([
                        TaxonomyCategory::TIPO_BIEN => 'Bien',
                        TaxonomyCategory::TIPO_SERVICIO => 'Servicio',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Activa'),
                Tables\Filters\Filter::make('sin_traducir_es')
                    ->label('Sin traducir a español')
                    ->query(fn (Builder $query) => $query->whereDoesntHave('translations', fn ($q) => $q->where('locale', 'es'))),
            ])
            ->defaultSort('code')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, TaxonomyCategory $record) {
                        if ($record->children()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Esta categoría tiene subcategorías')
                                ->body('Reasigná o borrá primero sus hijos (borrarla las dejaría huérfanas, no las elimina en cascada).')
                                ->send();
                            $action->cancel();

                            return;
                        }

                        if ($record->empresaLinks()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Esta categoría tiene empresas vinculadas')
                                ->body('Desvinculá primero las empresas que la seleccionaron antes de borrarla.')
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activar')
                        ->label('Marcar activas')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('desactivar')
                        ->label('Marcar inactivas')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TranslationsRelationManager::class,
            RelationManagers\SynonymsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyCategories::route('/'),
            'create' => Pages\CreateTaxonomyCategory::route('/create'),
            'edit' => Pages\EditTaxonomyCategory::route('/{record}/edit'),
        ];
    }
}
