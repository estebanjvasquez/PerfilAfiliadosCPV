<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\InfraSystem;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\InfraSystemResource\Pages;
use App\Filament\Resources\InfraSystemResource\RelationManagers;

class InfraSystemResource extends Resource
{
    protected static ?string $model = InfraSystem::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationGroup = 'Mantenimiento';

    public static ?string $label = 'Sistema de Infraestructura';

    public static ?string $navigationLabel = 'Sistemas de Infraestructura';

    protected static ?string $pluralModelLabel = 'Sistemas de Infraestructura';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('infratypes_id')
                    ->label('Tipo')
                    ->relationship('infratypes', 'type_name')
                    ->required(),

                Forms\Components\TextInput::make('system_name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100)
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('infratypes.type_name')->label('Tipo'),
                Tables\Columns\TextColumn::make('system_name')->label('Sistema'),
                Tables\Columns\TextColumn::make('created_at')->label('Creado')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizado')
                    ->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, $record) {
                        if ($record->infraregions->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene dependencias!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        }
                    })
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageInfraSystems::route('/'),
        ];
    }
}
