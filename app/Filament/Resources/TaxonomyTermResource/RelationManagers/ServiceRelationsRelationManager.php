<?php

namespace App\Filament\Resources\TaxonomyTermResource\RelationManagers;

use App\Models\LegacyService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * TAXV2-6: relaciones Término -> Servicio legacy de este término (`records[].legacy_service_relations[]`
 * del JSON V2, TAXV2-3). `status` acá es el estado operativo del servicio (legacy_active/deprecated/
 * disabled), no un workflow de revisión - ver docblock de la migración de esta tabla.
 */
class ServiceRelationsRelationManager extends RelationManager
{
    protected static string $relationship = 'serviceRelations';

    protected static ?string $recordTitleAttribute = 'id';

    public static ?string $label = 'Relación con servicio legacy';

    public static ?string $navigationLabel = 'Servicios legacy relacionados';

    protected static ?string $pluralModelLabel = 'Servicios legacy relacionados';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('legacy_service_id')
                ->label('Servicio legacy')
                ->relationship('legacyService', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\TextInput::make('relation_type')->label('Tipo de relación')->default('manual')->required()->maxLength(30),
            Forms\Components\TextInput::make('weight')->label('Peso')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required(),
            Forms\Components\TextInput::make('confidence')->label('Confianza')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->required(),
            Forms\Components\Select::make('status')
                ->label('Estado del servicio')
                ->options([
                    LegacyService::STATUS_LEGACY_ACTIVE => 'Activo (legacy)',
                    LegacyService::STATUS_DEPRECATED => 'Deprecado',
                    LegacyService::STATUS_DISABLED => 'Deshabilitado',
                ])
                ->native(false)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('legacyService'))
            ->columns([
                Tables\Columns\TextColumn::make('legacyService.name')->label('Servicio')->wrap(),
                Tables\Columns\TextColumn::make('relation_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('confidence')->label('Confianza')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        LegacyService::STATUS_LEGACY_ACTIVE => 'Activo (legacy)',
                        LegacyService::STATUS_DEPRECATED => 'Deprecado',
                        LegacyService::STATUS_DISABLED => 'Deshabilitado',
                        default => $state,
                    }),
            ])
            ->defaultSort('weight', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
