<?php

namespace App\Filament\Resources;

use App\Models\TaxonomySource;
use App\Models\TaxonomyTerm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\TaxonomyTermResource\Pages;
use App\Filament\Resources\TaxonomyTermResource\RelationManagers;

/**
 * TAXV2-6 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.5): CRUD
 * del diccionario de términos V2 (1.837 términos importados por `taxonomy:import-term-dictionary`,
 * TAXV2-1) - reemplaza en alcance/riqueza a `taxonomy_category_synonyms` (que sigue existiendo,
 * sin tocar, para el diccionario de sinónimos original de Lorenzo).
 *
 * Mismo patrón de filtros tipo Excel (QueryBuilder Y/O) y guard de borrado que
 * `TaxonomyCategoryResource` - acá el guard es sobre relaciones (CPV/servicio), no hijos/empresas.
 */
class TaxonomyTermResource extends Resource
{
    protected static ?string $model = TaxonomyTerm::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Diccionario de términos';

    public static ?string $label = 'Término';

    protected static ?string $pluralModelLabel = 'Términos';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'term';

    public static function getGloballySearchableAttributes(): array
    {
        return ['term', 'canonical_term', 'external_id'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['aliases', 'cpvRelations', 'serviceRelations']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidad')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('external_id')
                        ->label('ID externo')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->dehydrated()
                        ->helperText(fn (string $operation) => $operation === 'create'
                            ? 'Identificador único (ej. "manual_wellhead_es"). Los importados del JSON V2 usan el patrón "og_00001".'
                            : 'No editable - es la clave que usan los importadores para no duplicar.'),
                    Forms\Components\TextInput::make('term')
                        ->label('Término')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('canonical_term')
                        ->label('Término canónico')
                        ->required()
                        ->maxLength(255)
                        ->helperText('La forma "oficial" del término, si difiere de la columna anterior.'),
                    Forms\Components\Select::make('language')
                        ->label('Idioma')
                        ->options(['es' => 'Español', 'en' => 'Inglés'])
                        ->native(false)
                        ->required(),
                    Forms\Components\Select::make('term_type')
                        ->label('Tipo de término')
                        ->options([
                            TaxonomyTerm::TERM_TYPE_TECHNICAL => 'Técnico',
                            TaxonomyTerm::TERM_TYPE_COMMERCIAL_PHRASE => 'Frase comercial',
                            TaxonomyTerm::TERM_TYPE_GENERATED_DOMAIN_PHRASE => 'Frase generada del dominio',
                            TaxonomyTerm::TERM_TYPE_ACRONYM => 'Sigla/acrónimo',
                            TaxonomyTerm::TERM_TYPE_TRANSLATION_ALIAS => 'Alias de traducción',
                            TaxonomyTerm::TERM_TYPE_OILFIELD_SLANG => 'Jerga de campo petrolero',
                            TaxonomyTerm::TERM_TYPE_REGIONAL_SLANG => 'Jerga regional',
                            TaxonomyTerm::TERM_TYPE_REGIONAL_VARIANT => 'Variante regional',
                        ])
                        ->native(false)
                        ->required(),
                    Forms\Components\Select::make('source_id')
                        ->label('Fuente')
                        ->relationship('source', 'name')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->default(TaxonomySource::CURATED),
                ]),

            Forms\Components\Section::make('Clasificación temática')
                ->description('Categoría temática del término (ej. "drilling", "core") - sin relación con la jerarquía CPV, ver Relaciones CPV abajo.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('term_category')->label('Categoría temática')->maxLength(100),
                    Forms\Components\TextInput::make('term_subcategory')->label('Subcategoría temática')->maxLength(100),
                    Forms\Components\Select::make('mapping_review_status')
                        ->label('Estado de mapeo')
                        ->options([
                            TaxonomyTerm::MAPPING_AUTO_MAPPED => 'Auto-mapeado',
                            TaxonomyTerm::MAPPING_UNMAPPED => 'Sin mapear',
                        ])
                        ->native(false)
                        ->required(),
                    Forms\Components\TagsInput::make('region')
                        ->label('Región')
                        ->helperText('Ej. GLOBAL, US, VE.')
                        ->columnSpan(3),
                ]),

            Forms\Components\Section::make('Pesos y control de ruido')
                ->description('Ver "Taxonomía CPV → Contexto y control de ruido" para los defaults globales de estos campos.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('relevance_weight')
                        ->label('Peso de relevancia')
                        ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                        ->helperText('Importancia general del término (no confundir con el peso de una relación puntual a un CPV).'),
                    Forms\Components\TextInput::make('oil_gas_exclusivity')
                        ->label('Exclusividad Oil & Gas')
                        ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                        ->helperText('Qué tanto este término identifica por sí solo un contexto Oil & Gas.'),
                    Forms\Components\TextInput::make('ambiguity_penalty')
                        ->label('Penalización de ambigüedad')
                        ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                        ->helperText('Se aplica en el buscador cuando "Requiere contexto" está activo (ver abajo).'),
                    Forms\Components\Toggle::make('context_required')
                        ->label('Requiere contexto')
                        ->helperText('Un término genérico (ej. "pump", "steel") no debería bastar solo para clasificar Oil & Gas.'),
                    Forms\Components\TextInput::make('minimum_supporting_terms')
                        ->label('Términos de apoyo mínimos')
                        ->numeric()->minValue(0)->required(),
                    Forms\Components\TextInput::make('context_window_words')
                        ->label('Ventana de contexto (palabras)')
                        ->numeric()->minValue(1)->required(),
                    Forms\Components\TagsInput::make('negative_context')
                        ->label('Contexto negativo')
                        ->helperText('Palabras/frases cercanas que descartan el match.')
                        ->columnSpan(3),
                    Forms\Components\TagsInput::make('positive_context')
                        ->label('Contexto positivo')
                        ->helperText('Palabras/frases cercanas que refuerzan el match.')
                        ->columnSpan(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term')->label('Término')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('canonical_term')->label('Canónico')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('language')
                    ->label('Idioma')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ['es' => 'ES', 'en' => 'EN'][$state] ?? $state),
                Tables\Columns\TextColumn::make('term_type')
                    ->label('Tipo')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('relevance_weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('oil_gas_exclusivity')->label('Exclus. O&G')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('context_required')->label('Req. contexto')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('source.name')->label('Fuente')->toggleable(),
                Tables\Columns\BadgeColumn::make('mapping_review_status')
                    ->label('Estado')
                    ->colors([
                        'success' => TaxonomyTerm::MAPPING_AUTO_MAPPED,
                        'gray' => TaxonomyTerm::MAPPING_UNMAPPED,
                    ]),
                Tables\Columns\TextColumn::make('aliases_count')->label('Alias')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('cpv_relations_count')->label('Rel. CPV')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('service_relations_count')->label('Rel. servicios')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('term')->label('Término'),
                        TextConstraint::make('canonical_term')->label('Canónico')->nullable(),
                        TextConstraint::make('external_id')->label('ID externo')->nullable(),
                        SelectConstraint::make('language')
                            ->label('Idioma')
                            ->options(['es' => 'Español', 'en' => 'Inglés'])
                            ->multiple(),
                        SelectConstraint::make('term_type')
                            ->label('Tipo de término')
                            ->options([
                                TaxonomyTerm::TERM_TYPE_TECHNICAL => 'Técnico',
                                TaxonomyTerm::TERM_TYPE_COMMERCIAL_PHRASE => 'Frase comercial',
                                TaxonomyTerm::TERM_TYPE_GENERATED_DOMAIN_PHRASE => 'Frase generada del dominio',
                                TaxonomyTerm::TERM_TYPE_ACRONYM => 'Sigla/acrónimo',
                                TaxonomyTerm::TERM_TYPE_TRANSLATION_ALIAS => 'Alias de traducción',
                                TaxonomyTerm::TERM_TYPE_OILFIELD_SLANG => 'Jerga de campo petrolero',
                                TaxonomyTerm::TERM_TYPE_REGIONAL_SLANG => 'Jerga regional',
                                TaxonomyTerm::TERM_TYPE_REGIONAL_VARIANT => 'Variante regional',
                            ])
                            ->multiple(),
                        SelectConstraint::make('mapping_review_status')
                            ->label('Estado de mapeo')
                            ->options([
                                TaxonomyTerm::MAPPING_AUTO_MAPPED => 'Auto-mapeado',
                                TaxonomyTerm::MAPPING_UNMAPPED => 'Sin mapear',
                            ])
                            ->multiple(),
                        SelectConstraint::make('source_id')
                            ->label('Fuente')
                            ->relationship('source', 'name')
                            ->multiple(),
                        BooleanConstraint::make('context_required')->label('Requiere contexto'),
                    ]),
                Tables\Filters\Filter::make('sin_relaciones_cpv')
                    ->label('Sin ninguna relación CPV')
                    ->query(fn (Builder $query) => $query->doesntHave('cpvRelations')),
            ])
            ->defaultSort('term')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, TaxonomyTerm $record) {
                        if ($record->cpvRelations()->count() > 0 || $record->serviceRelations()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Este término tiene relaciones activas')
                                ->body('Borrá primero sus relaciones a CPV/servicios (o dejalas caer en cascada solo si estás seguro).')
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('marcar_auto_mapeado')
                        ->label('Marcar auto-mapeado')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['mapping_review_status' => TaxonomyTerm::MAPPING_AUTO_MAPPED]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AliasesRelationManager::class,
            RelationManagers\CpvRelationsRelationManager::class,
            RelationManagers\ServiceRelationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyTerms::route('/'),
            'create' => Pages\CreateTaxonomyTerm::route('/create'),
            'edit' => Pages\EditTaxonomyTerm::route('/{record}/edit'),
        ];
    }
}
