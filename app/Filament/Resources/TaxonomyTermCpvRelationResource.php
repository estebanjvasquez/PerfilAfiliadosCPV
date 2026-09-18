<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ManagesTaxonomyRelationReview;
use App\Models\TaxonomyTermCpvRelation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

use App\Filament\Resources\TaxonomyTermCpvRelationResource\Pages;

/**
 * TAXV2-6 (sección 4.6 del documento de instrucciones): cola GLOBAL de revisión de relaciones
 * Término -> CPV, cruzando los 1.837 términos - a diferencia de
 * `TaxonomyTermResource\RelationManagers\CpvRelationsRelationManager` (vista de detalle dentro de
 * UN término), acá un administrador puede filtrar/ordenar/aprobar en bloque las 9.289 filas
 * `needs_review` que dejó el auto-triage de `taxonomy:import-term-cpv-relations` (TAXV2-2), sin
 * entrar término por término.
 *
 * Por defecto ordena por peso descendente dentro de "en revisión" - las relaciones más fuertes
 * (más cerca de `automatic_accept`) primero, para que revisar sea más eficiente.
 */
class TaxonomyTermCpvRelationResource extends Resource
{
    use ManagesTaxonomyRelationReview;

    protected static ?string $model = TaxonomyTermCpvRelation::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Relaciones Término↔CPV';

    public static ?string $label = 'Relación Término↔CPV';

    protected static ?string $pluralModelLabel = 'Relaciones Término↔CPV';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'cpv_code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['term', 'category.translations']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('term_id')
                ->label('Término')
                ->relationship('term', 'term')
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\TextInput::make('cpv_code')->label('Código CPV')->required()->maxLength(50),
            Forms\Components\Select::make('level')
                ->label('Nivel')
                ->options([
                    TaxonomyTermCpvRelation::LEVEL_GROUP => 'Grupo',
                    TaxonomyTermCpvRelation::LEVEL_FAMILY => 'Familia',
                    TaxonomyTermCpvRelation::LEVEL_CATEGORY => 'Categoría',
                ])
                ->native(false)->required(),
            Forms\Components\Select::make('relation_type')
                ->label('Tipo de relación')
                ->options(array_combine(
                    ['exact', 'explicit_synonym', 'strong_lexical', 'lexical', 'contextual', 'ancestor', 'manual'],
                    ['Exacto', 'Sinónimo explícito', 'Léxico fuerte', 'Léxico', 'Contextual', 'Ancestro', 'Manual']
                ))
                ->native(false)->required(),
            Forms\Components\TextInput::make('weight')
                ->label('Peso')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()->live()
                ->helperText('Fuerza semántica entre este término y este código CPV. Aumentarla incrementa la relevancia de empresas asociadas a ese código en búsquedas relacionadas. No cambia el peso general del término.'),
            Forms\Components\Placeholder::make('impact_preview')
                ->label('Preview de impacto')
                ->content(function (Forms\Get $get, ?TaxonomyTermCpvRelation $record) {
                    $categoryId = $record?->category_id;

                    if (! $categoryId) {
                        return 'Este código CPV todavía no resuelve a una categoría existente - no hay empresas que impactar.';
                    }

                    $empresas = DB::connection('pgsql')->table('empresa_taxonomy_category')
                        ->where('category_id', $categoryId)->count();

                    return "{$empresas} empresa(s) ya vinculada(s) a esta categoría verían cambiar su relevancia en búsquedas relacionadas con este término.";
                }),
            Forms\Components\TextInput::make('confidence')
                ->label('Confianza')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                ->helperText('Confianza del algoritmo que propuso el mapping - no es un peso de negocio, no se auto-ajusta al cambiar el peso.'),
            Forms\Components\Textarea::make('matched_on')->label('Coincidió con')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('source')->label('Fuente')->required()->maxLength(50),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW => 'En revisión',
                    TaxonomyTermCpvRelation::STATUS_APPROVED => 'Aprobada',
                    TaxonomyTermCpvRelation::STATUS_REJECTED => 'Rechazada',
                    TaxonomyTermCpvRelation::STATUS_DEPRECATED => 'Descartada',
                ])
                ->native(false)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term.term')->label('Término')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('cpv_code')->label('Código CPV')->searchable(),
                Tables\Columns\TextColumn::make('category_breadcrumb')
                    ->label('Categoría')
                    ->getStateUsing(fn (TaxonomyTermCpvRelation $record) => $record->category?->breadcrumb('es') ?? '— sin categoría —')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('relation_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('confidence')->label('Confianza')->sortable(),
                Tables\Columns\TextColumn::make('matched_on')->label('Coincidió con')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('source')->label('Fuente')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
                        'success' => TaxonomyTermCpvRelation::STATUS_APPROVED,
                        'danger' => TaxonomyTermCpvRelation::STATUS_REJECTED,
                        'gray' => TaxonomyTermCpvRelation::STATUS_DEPRECATED,
                    ]),
                Tables\Columns\TextColumn::make('reviewed_at')->label('Revisada')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW => 'En revisión',
                        TaxonomyTermCpvRelation::STATUS_APPROVED => 'Aprobada',
                        TaxonomyTermCpvRelation::STATUS_REJECTED => 'Rechazada',
                        TaxonomyTermCpvRelation::STATUS_DEPRECATED => 'Descartada',
                    ])
                    ->default(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW),
                Tables\Filters\QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('term_term')
                            ->label('Término')
                            ->relationship('term', 'term'),
                        TextConstraint::make('cpv_code')->label('Código CPV'),
                        SelectConstraint::make('relation_type')
                            ->label('Tipo de relación')
                            ->options(array_combine(
                                ['exact', 'explicit_synonym', 'strong_lexical', 'lexical', 'contextual', 'ancestor', 'manual'],
                                ['Exacto', 'Sinónimo explícito', 'Léxico fuerte', 'Léxico', 'Contextual', 'Ancestro', 'Manual']
                            ))
                            ->multiple(),
                        TextConstraint::make('source')->label('Fuente')->nullable(),
                        NumberConstraint::make('weight')->label('Peso'),
                        NumberConstraint::make('confidence')->label('Confianza'),
                    ]),
                Tables\Filters\Filter::make('sin_categoria')
                    ->label('Código sin categoría (huérfano)')
                    ->query(fn (Builder $query) => $query->whereNull('category_id')),
            ])
            ->defaultSort('weight', 'desc')
            ->actions([
                static::reviewApproveAction(),
                static::reviewRejectAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::reviewApproveBulkAction(),
                    static::reviewRejectBulkAction(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyTermCpvRelations::route('/'),
            'create' => Pages\CreateTaxonomyTermCpvRelation::route('/create'),
            'edit' => Pages\EditTaxonomyTermCpvRelation::route('/{record}/edit'),
        ];
    }
}
