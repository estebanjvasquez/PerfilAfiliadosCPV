<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class ChambersRelationManager extends RelationManager
{
    protected static string $relationship = 'chambers';

    // TASK-0011: sin carga diferida, la sección se ve con su título y ayuda en vez de una caja vacía.
    protected static bool $isLazy = false;

    protected static ?string $title = 'Cámaras';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Cámara';

    public static ?string $navigationLabel = 'Cámaras';

    protected static ?string $pluralModelLabel = 'Cámaras';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return true;
    }
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Cámaras y asociaciones en las que participa su empresa. «Vincular» y «Desvincular» se guardan al instante.')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                // Filament ya excluye del buscador las cámaras que la empresa tiene vinculadas
                // (AttachAction::getRecordSelect() -> whereDoesntHave), por eso nunca se puede
                // vincular una duplicada. El helper text explica por qué el buscador "no encuentra"
                // una cámara que en realidad ya está vinculada.
                Tables\Actions\AttachAction::make()
                    ->recordSelect(fn (Forms\Components\Select $select) => $select
                        ->helperText('Las cámaras en las que su empresa ya está vinculada no aparecen en esta lista.')),
            ])
            ->actions([
                /* Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(), */
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                FilamentExportBulkAction::make('export')
                    ->label('Exportar')
                    ->additionalColumnsAddButtonLabel('Agregar columna'),
            ]);
    }
}
