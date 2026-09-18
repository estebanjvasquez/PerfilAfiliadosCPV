<?php

namespace App\Filament\Resources\TaxonomyTermResource\RelationManagers;

use App\Models\TaxonomyTermSourceBinding;
use App\Services\TaxonomyAuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * V2->V3 (ver docs/taxonomia/MIGRACION_TAXONOMIA_CPV_V2_A_V3.md sección 2): bindings VERIFICADOS
 * término<->fuente - la relación N:M real que reemplaza la idea de "1 término = 1 fuente". Un admin
 * puede agregar uno a mano si verificó personalmente una URL, además de los que trae el import
 * (`taxonomy:import-term-provenance`) o los que cree el crawler (TAXV2-12) al encontrar una
 * coincidencia exacta.
 */
class SourceBindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceBindings';

    protected static ?string $recordTitleAttribute = 'source_term';

    public static ?string $label = 'Binding de fuente';

    public static ?string $navigationLabel = 'Bindings de fuente';

    protected static ?string $pluralModelLabel = 'Bindings de fuente';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('source_id')
                ->label('Fuente')
                ->relationship('source', 'name')
                ->native(false)
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\TextInput::make('source_term')->label('Término en la fuente')->maxLength(255),
            Forms\Components\TextInput::make('source_url')->label('URL exacta')->url()->maxLength(500)->columnSpanFull(),
            Forms\Components\Select::make('language')->label('Idioma')->options(['es' => 'Español', 'en' => 'Inglés'])->native(false),
            Forms\Components\TextInput::make('binding_type')->label('Tipo de coincidencia')->maxLength(50)->placeholder('ej. exact_source_term'),
            Forms\Components\Select::make('verification_status')
                ->label('Estado de verificación')
                ->options([
                    TaxonomyTermSourceBinding::STATUS_VERIFIED => 'Verificado',
                    TaxonomyTermSourceBinding::STATUS_PENDING_VERIFICATION => 'Pendiente',
                    TaxonomyTermSourceBinding::STATUS_STALE => 'Desactualizado',
                    TaxonomyTermSourceBinding::STATUS_MISSING => 'Ya no aparece en la fuente',
                    TaxonomyTermSourceBinding::STATUS_SOURCE_ERROR => 'Error al consultar la fuente',
                ])
                ->native(false)
                ->required()
                ->default(TaxonomyTermSourceBinding::STATUS_VERIFIED),
            Forms\Components\Toggle::make('sync_enabled')
                ->label('Sincronizar con el crawler')
                ->helperText('Solo el crawler de fuentes debería tocar este binding automáticamente si está activo.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source.name')->label('Fuente')->badge(),
                Tables\Columns\TextColumn::make('source_term')->label('Término en la fuente')->searchable(),
                Tables\Columns\TextColumn::make('source_url')->label('URL')->limit(50)->url(fn (TaxonomyTermSourceBinding $record) => $record->source_url, true),
                Tables\Columns\BadgeColumn::make('verification_status')
                    ->label('Estado')
                    ->colors([
                        'success' => TaxonomyTermSourceBinding::STATUS_VERIFIED,
                        'warning' => TaxonomyTermSourceBinding::STATUS_PENDING_VERIFICATION,
                        'gray' => TaxonomyTermSourceBinding::STATUS_STALE,
                        'danger' => [TaxonomyTermSourceBinding::STATUS_MISSING, TaxonomyTermSourceBinding::STATUS_SOURCE_ERROR],
                    ]),
                Tables\Columns\IconColumn::make('sync_enabled')->label('Sync')->boolean(),
                Tables\Columns\TextColumn::make('last_verified_at')->label('Verificado')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (TaxonomyTermSourceBinding $record) => TaxonomyAuditLogger::record(
                        entityType: TaxonomyTermSourceBinding::class,
                        entityId: $record->id,
                        field: 'verification_status',
                        oldValue: null,
                        newValue: $record->verification_status,
                        reason: 'Binding agregado manualmente desde el panel.',
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
