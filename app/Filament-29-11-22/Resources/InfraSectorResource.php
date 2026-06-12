<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\InfraSector;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\InfraSectorResource\Pages;
use App\Filament\Resources\InfraSectorResource\RelationManagers;

class InfraSectorResource extends Resource
{
    protected static ?string $model = InfraSector::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Mantenimiento';

    public static ?string $label = 'Sector de Infraestructura';

    public static ?string $navigationLabel = 'Sectores de Infraestructura';

    protected static ?string $pluralModelLabel = 'Sectores de Infraestructura';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sector_name')
                    ->label('Sector')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sector_name')->label('Sector'),
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
                        if ($record->infratypes->count() > 0) {
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
            'index' => Pages\ManageInfraSectors::route('/'),
        ];
    }
}
