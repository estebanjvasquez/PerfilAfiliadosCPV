<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use Filament\Resources\RelationManagers\BelongsToManyRelationManager;

class ContactsRelationManager extends BelongsToManyRelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Contacto';

    public static ?string $navigationLabel = 'Contactos';

    protected static ?string $pluralModelLabel = 'Contactos';

    //RETORNAR TRUE SI QUIERO QUE PUEDA EDITAR............ (LO MISMO CON CANCREATE)....
    protected function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->label('Nombre')
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),
                Forms\Components\TextInput::make('position')->label('Posición')
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),
                Forms\Components\TextInput::make('phone')->tel()->label('Teléfono'),
                Forms\Components\TextInput::make('email')->email()
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre'),
                Tables\Columns\TextColumn::make('position')->label('Posición'),
                Tables\Columns\TextColumn::make('phone')->label('Teléfono'),
                Tables\Columns\TextColumn::make('email'),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
                /* Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, $record) {
                        if ($record->contact_empresa->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Debe desvincular el Contacto antes de borrarlo!')
                                //->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        }
                    }) */
            ])
            ->bulkActions([
                FilamentExportBulkAction::make('export')
                    ->additionalColumnsAddButtonLabel('Add Column'),
            ])
            ->filters([
                //
            ]);
    }
}
