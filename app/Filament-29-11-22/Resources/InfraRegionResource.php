<?php

namespace App\Filament\Resources;

use Throwable;
use Filament\Forms;
use Filament\Tables;
use App\Models\InfraRegion;
use Filament\Resources\Form;
use App\Models\InfraFacility;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\QueryException;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Notifications\Actions\Action;
use Illuminate\Database\Eloquent\Collection;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Validation\ValidationException;
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

    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('infrasystems_id')
                    ->label('Sistema')
                    ->relationship('infrasystems', 'system_name')
                    ->required(),
                Forms\Components\TextInput::make('region_name')
                    ->label('Región')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('infrasystems.system_name')->label('Sistema'),
                Tables\Columns\TextColumn::make('region_name')->label('Región'),
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
                        if ($record->infrafacilities->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene dependencias!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                //->persistent()
                                //->duration(5000)
                                // ->actions([
                                //     Action::make('subscribe')
                                //         ->button()
                                //         ->url(route('subscribe'), shouldOpenInNewTab: true),
                                // ])
                                ->send(5);
                            $action->cancel();
                        }
                    })
            ])
            ->bulkActions([
                /*  Tables\Actions\DeleteBulkAction::make()
                    ->action(function ($action, Collection $records, array $data): void {
                        $i = 0;
                        foreach ($records as $record) {
                            if ($record->infrafacilities->count() > 0) {
                                Notification::make()
                                    ->danger()
                                    ->title('Éste registro tiene dependencias (' . $record->infrafacilities->pluck("facility_name") . ')')
                                    ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                    ->send(5);
                                $action->cancel();
                            } else {
                                BulkAction::make('delete')
                                    ->action(fn (Collection $records) => $records->each->delete());
                            }
                        }
 
                    }), */
                //->action(fn (Collection $records) => $records->each->delete()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageInfraRegions::route('/'),
        ];
    }

    protected function onValidationError(ValidationException $exception): void
    {

        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->send();
    }
}
