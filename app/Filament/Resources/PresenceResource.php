<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Country;
use App\Models\Empresa;
use Livewire\Component;
use App\Models\Presence;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Tabs;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\PresenceResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\PresenceResource\RelationManagers;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;


class PresenceResource extends Resource
{

    protected static ?string $model = Presence::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe';

    public static ?string $label = 'Presencia Internacional';

    public static ?string $navigationLabel = 'Presencia Internacional';

    protected static ?string $pluralModelLabel = 'Presencia Internacional';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        $vemp = 'presences';

        $emps = Empresa::get();
        foreach ($emps as $key => $valuemp) {
        }

        $vcountry = Country::get();
        //dd($vcountry);
        foreach ($vcountry as $key => $value) {
        }
        //dd($value);

        return $form
            ->schema([])->columns(1);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRelation('user', 'users.id', '=', Auth::User()->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('empresa.name'),
                Tables\Columns\BooleanColumn::make('has_offices')->label('Tiene Presencia Internacional'),
                Tables\Columns\BooleanColumn::make('has_experience')->label('Tiene Experiencia Internacional'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
                FilamentExportBulkAction::make('export')
                    ->additionalColumnsAddButtonLabel('Add Column'),
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
            'index' => Pages\ListPresences::route('/'),
            'create' => Pages\CreatePresence::route('/create'),
            'edit' => Pages\EditPresence::route('/{record}/edit'),
        ];
    }
}
