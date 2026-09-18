<?php

namespace App\Filament\Resources\TaxonomyTermResource\RelationManagers;

use App\Models\TaxonomyTermAlias;
use App\Services\TaxonomyAuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AliasesRelationManager extends RelationManager
{
    protected static string $relationship = 'aliases';

    protected static ?string $recordTitleAttribute = 'alias';

    public static ?string $label = 'Alias';

    public static ?string $navigationLabel = 'Alias';

    protected static ?string $pluralModelLabel = 'Alias';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('alias')
                ->label('Alias')
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('alias')->label('Alias')->searchable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (TaxonomyTermAlias $record) => TaxonomyAuditLogger::record(
                        entityType: TaxonomyTermAlias::class,
                        entityId: $record->id,
                        field: 'alias',
                        oldValue: null,
                        newValue: $record->alias,
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(function (TaxonomyTermAlias $record) {
                        TaxonomyAuditLogger::record(
                            entityType: TaxonomyTermAlias::class,
                            entityId: $record->id,
                            field: 'alias',
                            oldValue: $record->getOriginal('alias'),
                            newValue: $record->alias,
                        );
                    }),
                Tables\Actions\DeleteAction::make()
                    ->before(fn (TaxonomyTermAlias $record) => TaxonomyAuditLogger::record(
                        entityType: TaxonomyTermAlias::class,
                        entityId: $record->id,
                        field: 'alias',
                        oldValue: $record->alias,
                        newValue: null,
                    )),
            ]);
    }
}
