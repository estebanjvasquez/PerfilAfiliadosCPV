<?php

namespace App\Filament\Resources\CountryResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\State;
use Livewire\Livewire;
use App\Models\Country;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;

class CitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'cities';

    protected static ?string $recordTitleAttribute = 'city_name';

    public static ?string $label = 'Ciudad';

    protected static ?string $pluralModelLabel = 'Ciudades';

    public static function form(Form $form): Form
    {
        /* $country =
            DB::table('states')
            ->selectRaw('country_id')
            ->where('country_id', '=', '1')
            ->get(); */

        return $form
            ->schema([

                Forms\Components\BelongsToSelect::make('states_id')
                    ->searchable()
                    ->relationship('states', 'state_name')
                    ->label('Estado'),

                Forms\Components\TextInput::make('city_name')
                    ->label('Ciudad')
                    ->required()
                    ->maxLength(255)
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //Tables\Columns\TextColumn::make('states.country_id'),
                Tables\Columns\TextColumn::make('states.state_name')->label('Estados'),
                Tables\Columns\TextColumn::make('city_name')->label('Ciudades'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                /*     ->before(function (DeleteAction $action, $record) {
                        if ($record->cities->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene dependencias!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        }
                    }) */
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
