<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\InfraType;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\InfraTypeResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\InfraTypeResource\RelationManagers;

class InfraTypeResource extends Resource
{
    protected static ?string $model = InfraType::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-list';

    protected static ?string $navigationGroup = 'Mantenimiento';

    public static ?string $label = 'Tipo de Infraestructura';

    public static ?string $navigationLabel = 'Tipos de Infraestructura';

    protected static ?string $pluralModelLabel = 'Tipos de Infraestructura';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('infrasectors_id')
                    ->label('Sector')
                    ->relationship('infrasectors', 'sector_name')
                    ->required(),
                Forms\Components\TextInput::make('type_name')
                    ->label('Tipo')
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
                Tables\Columns\TextColumn::make('infrasectors.sector_name')->label('Sector'),
                Tables\Columns\TextColumn::make('type_name')->label('Tipo'),
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
                        if ($record->infrasystems->count() > 0) {
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
            'index' => Pages\ManageInfraTypes::route('/'),
        ];
    }
}
