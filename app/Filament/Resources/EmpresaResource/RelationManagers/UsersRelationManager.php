<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Filters\Filter;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    // TASK-0011: sin carga diferida, la sección se ve con su título y ayuda en vez de una caja vacía.
    protected static bool $isLazy = false;

    protected static ?string $title = 'Usuarios';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Usuarios';

    public static ?string $navigationLabel = 'Usuarios';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->label('Nombre'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Cuentas con acceso a esta empresa en el sistema. Para dar o quitar acceso, contacte a la Cámara.')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre'),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                //
            ])
            ->filters([
                //Filter::make('empresas', fn ($query) => $query->where('user_id', 2)),
            ]);
    }
}
