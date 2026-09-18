<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\ManagesTaxonomyRelationReview;
use App\Models\LegacyServiceCpvRelation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\LegacyServiceCpvRelationResource\Pages;

/**
 * TAXV2-7 (sección 4.8 del documento de instrucciones): cola GLOBAL de revisión de relaciones
 * Servicio legacy -> CPV, cruzando los 112 servicios - mismo patrón que
 * `TaxonomyTermCpvRelationResource` (cola global de términos), separado en su propio Resource
 * porque son 2 workflows de revisión distintos (sección 4.6 vs 4.8 del documento).
 */
class LegacyServiceCpvRelationResource extends Resource
{
    use ManagesTaxonomyRelationReview;

    protected static ?string $model = LegacyServiceCpvRelation::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Relaciones Servicio↔CPV';

    public static ?string $label = 'Relación Servicio↔CPV';

    protected static ?string $pluralModelLabel = 'Relaciones Servicio↔CPV';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'cpv_code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['legacyService', 'category.translations']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('legacy_service_id')
                ->label('Servicio legacy')
                ->relationship('legacyService', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\TextInput::make('cpv_code')->label('Código CPV')->required()->maxLength(50),
            Forms\Components\Select::make('level')
                ->label('Nivel')
                ->options(['group' => 'Grupo', 'family' => 'Familia', 'category' => 'Categoría'])
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
                ->helperText('Fuerza semántica entre este servicio legacy y este código CPV.'),
            Forms\Components\TextInput::make('confidence')
                ->label('Confianza')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required(),
            Forms\Components\Textarea::make('matched_on')->label('Coincidió con')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('source')->label('Fuente')->required()->maxLength(50),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    LegacyServiceCpvRelation::STATUS_NEEDS_REVIEW => 'En revisión',
                    LegacyServiceCpvRelation::STATUS_APPROVED => 'Aprobada',
                    LegacyServiceCpvRelation::STATUS_REJECTED => 'Rechazada',
                    LegacyServiceCpvRelation::STATUS_DEPRECATED => 'Descartada',
                ])
                ->native(false)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('legacyService.name')->label('Servicio legacy')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('cpv_code')->label('Código CPV')->searchable(),
                Tables\Columns\TextColumn::make('category_breadcrumb')
                    ->label('Categoría')
                    ->getStateUsing(fn (LegacyServiceCpvRelation $record) => $record->category?->breadcrumb('es') ?? '— sin categoría —')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('relation_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('confidence')->label('Confianza')->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => LegacyServiceCpvRelation::STATUS_NEEDS_REVIEW,
                        'success' => LegacyServiceCpvRelation::STATUS_APPROVED,
                        'danger' => LegacyServiceCpvRelation::STATUS_REJECTED,
                        'gray' => LegacyServiceCpvRelation::STATUS_DEPRECATED,
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        LegacyServiceCpvRelation::STATUS_NEEDS_REVIEW => 'En revisión',
                        LegacyServiceCpvRelation::STATUS_APPROVED => 'Aprobada',
                        LegacyServiceCpvRelation::STATUS_REJECTED => 'Rechazada',
                        LegacyServiceCpvRelation::STATUS_DEPRECATED => 'Descartada',
                    ])
                    ->default(LegacyServiceCpvRelation::STATUS_NEEDS_REVIEW),
                Tables\Filters\QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('service_name')
                            ->label('Servicio legacy')
                            ->relationship('legacyService', 'name'),
                        TextConstraint::make('cpv_code')->label('Código CPV'),
                        SelectConstraint::make('relation_type')
                            ->label('Tipo de relación')
                            ->options(array_combine(
                                ['exact', 'explicit_synonym', 'strong_lexical', 'lexical', 'contextual', 'ancestor', 'manual'],
                                ['Exacto', 'Sinónimo explícito', 'Léxico fuerte', 'Léxico', 'Contextual', 'Ancestro', 'Manual']
                            ))
                            ->multiple(),
                        NumberConstraint::make('weight')->label('Peso'),
                    ]),
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
            'index' => Pages\ListLegacyServiceCpvRelations::route('/'),
            'create' => Pages\CreateLegacyServiceCpvRelation::route('/create'),
            'edit' => Pages\EditLegacyServiceCpvRelation::route('/{record}/edit'),
        ];
    }
}
