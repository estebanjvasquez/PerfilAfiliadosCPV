<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use Filament\Resources\RelationManagers\RelationManager;

class ContactsRelationManager extends RelationManager
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

    // Se desactiva "Vincular" (AttachAction): buscaba en TODA la tabla contacts sin filtrar por
    // empresa, exponiendo nombre/telefono/email de contactos de otras empresas afiliadas. Los
    // contactos ahora solo se cargan con "Crear Contacto", propios de cada empresa.
    protected function canAttach(): bool
    {
        return false;
    }

    public function form(Form $form): Form
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
                Forms\Components\TextInput::make('phone')->tel()->label('Teléfono')
                    ->mask('+58-999-9999999')
                    ->placeholder('+58-XXX-XXXXXXX'),
                Forms\Components\TextInput::make('email')->email()
                    ->afterStateUpdated(function ($component, $state, $set) {
                        return $set($component, mb_strtoupper($state));
                    }),

            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre'),
                Tables\Columns\TextColumn::make('position')->label('Posición'),
                Tables\Columns\TextColumn::make('phone')->label('Teléfono'),
                Tables\Columns\TextColumn::make('email'),
            ])
            ->actions([
                // "Crear Contacto" (headerAction por defecto del RelationManager, no declarado
                // explicitamente aca) tambien cambia el % de "Contactos" pero NO se le agrega
                // ->after() aca: reimplementar ese CreateAction a mano se arriesga a romper su
                // wiring interno (form, creacion+attach en un solo paso). Ese caso (y cualquier
                // otro que se escape) queda cubierto por el refresco periodico programado en
                // app/Console/Kernel.php (empresas:refresh-completion --only-stale).
                Tables\Actions\DetachAction::make()
                    ->after(fn (RelationManager $livewire) => $livewire->ownerRecord->refreshCompletionPercentage()),
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
