<?php

namespace App\Filament\Resources\TaxonomyTermResource\RelationManagers;

use App\Filament\Resources\Concerns\ManagesTaxonomyRelationReview;
use App\Models\TaxonomyTermCpvRelation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * TAXV2-6 (sección 4.6): relaciones Término -> CPV de ESTE término puntual. Para revisar en bloque
 * las 9.289 relaciones `needs_review` de TODOS los términos a la vez, ver
 * `TaxonomyTermCpvRelationResource` (cola global) - acá es la vista de detalle/edición rápida
 * dentro de un término.
 */
class CpvRelationsRelationManager extends RelationManager
{
    use ManagesTaxonomyRelationReview;

    protected static string $relationship = 'cpvRelations';

    protected static ?string $recordTitleAttribute = 'cpv_code';

    public static ?string $label = 'Relación CPV';

    public static ?string $navigationLabel = 'Relaciones CPV';

    protected static ?string $pluralModelLabel = 'Relaciones CPV';

    public function form(Form $form): Form
    {
        return $form->schema([
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
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                ->helperText('Fuerza semántica entre este término y este código CPV. Aumentarla incrementa la relevancia de empresas asociadas a ese código en búsquedas relacionadas. No cambia el peso general del término.'),
            Forms\Components\TextInput::make('confidence')
                ->label('Confianza')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required()
                ->helperText('Confianza del algoritmo que propuso el mapping - no es un peso de negocio.'),
            Forms\Components\Textarea::make('matched_on')->label('Coincidió con')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('source')->label('Fuente')->default('manual')->required()->maxLength(50),
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cpv_code')
            ->modifyQueryUsing(fn ($query) => $query->with('category.translations'))
            ->columns([
                Tables\Columns\TextColumn::make('cpv_code')->label('Código CPV')->searchable(),
                Tables\Columns\TextColumn::make('category_breadcrumb')
                    ->label('Categoría')
                    ->getStateUsing(fn (TaxonomyTermCpvRelation $record) => $record->category?->breadcrumb('es') ?? '— código sin categoría —')
                    ->wrap(),
                Tables\Columns\TextColumn::make('relation_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('confidence')->label('Confianza')->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
                        'success' => TaxonomyTermCpvRelation::STATUS_APPROVED,
                        'danger' => TaxonomyTermCpvRelation::STATUS_REJECTED,
                        'gray' => TaxonomyTermCpvRelation::STATUS_DEPRECATED,
                    ]),
                Tables\Columns\TextColumn::make('source')->label('Fuente')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('weight', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                static::reviewApproveAction(),
                static::reviewRejectAction(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
