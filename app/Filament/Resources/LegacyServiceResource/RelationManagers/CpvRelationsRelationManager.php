<?php

namespace App\Filament\Resources\LegacyServiceResource\RelationManagers;

use App\Filament\Resources\Concerns\ManagesTaxonomyRelationReview;
use App\Models\LegacyServiceCpvRelation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** TAXV2-7 (sección 4.8): relaciones Servicio legacy -> CPV de este servicio puntual. */
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
                    'group' => 'Grupo',
                    'family' => 'Familia',
                    'category' => 'Categoría',
                ])
                ->native(false)
                ->required(),
            Forms\Components\TextInput::make('weight')
                ->label('Peso')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required(),
            Forms\Components\TextInput::make('confidence')
                ->label('Confianza')
                ->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required(),
            Forms\Components\Textarea::make('matched_on')->label('Coincidió con')->rows(2)->columnSpanFull(),
            Forms\Components\TextInput::make('source')->label('Fuente')->default('manual')->required()->maxLength(50),
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cpv_code')
            ->modifyQueryUsing(fn ($query) => $query->with('category.translations'))
            ->columns([
                Tables\Columns\TextColumn::make('cpv_code')->label('Código CPV')->searchable(),
                Tables\Columns\TextColumn::make('category_breadcrumb')
                    ->label('Categoría')
                    ->getStateUsing(fn (LegacyServiceCpvRelation $record) => $record->category?->breadcrumb('es') ?? '— sin categoría —')
                    ->wrap(),
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
