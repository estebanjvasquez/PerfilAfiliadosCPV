<?php

namespace App\Filament\Resources\InfraSystemResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\AttachAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\DetachAction;

class InfraregionsRelationManager extends RelationManager
{
    protected static string $relationship = 'infraregions';

    protected static ?string $recordTitleAttribute = 'region_name';

    public static ?string $label = 'Regiones';

    public static ?string $navigationLabel = 'Regiones';

    protected static ?string $pluralModelLabel = 'Regiones';


    protected function canCreate(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return true;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('region_name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('region_name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                Tables\Actions\AttachAction::make(),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->before(function (DetachAction $action, $record) {
                        if ($record->getFacility($record->id)->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene dependencias!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
