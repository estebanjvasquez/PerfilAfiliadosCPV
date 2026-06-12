<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\InfraRegion;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\InfraRegionResource\Pages;
use App\Filament\Resources\InfraRegionResource\RelationManagers;

class InfraRegionResource extends Resource
{
    protected static ?string $model = InfraRegion::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Mantenimiento';

    public static ?string $label = 'Región o Distrito';

    public static ?string $navigationLabel = 'Región o Distrito';

    protected static ?string $pluralModelLabel = 'Regiones o Distritos';

    protected static ?int $navigationSort = 9;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('region_name')
                    ->label('Región')
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
                Tables\Columns\TextColumn::make('region_name')->label('Región'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, $record) {

                        //CHECKS IF THERE ARE ASOCIATED FACILITIES............................
                        if ($record->infrafacilities->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Instalaciones dependientes!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        }

                        //CHECKS IF THERE ARE ASOCIATED INFRASYSTEMS...........................
                        if ($record->infrasystems->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro está asociado a Sistemas!')
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInfraRegions::route('/'),
            'create' => Pages\CreateInfraRegion::route('/create'),
            'edit' => Pages\EditInfraRegion::route('/{record}/edit'),
        ];
    }
}
