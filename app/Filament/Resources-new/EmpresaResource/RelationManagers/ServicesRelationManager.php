<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use App\Models\Sector;
use App\Models\Service;
use Prophecy\Call\Call;
use Filament\Resources\Form;
use Filament\Resources\Table;
use Illuminate\Console\Application;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Actions\AttachAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Filament\Resources\RelationManagers\BelongsToManyRelationManager;

class ServicesRelationManager extends BelongsToManyRelationManager
{
    //protected static string $model = 'empresasectorservice';

    protected static string $relationship = 'services';

    protected static ?string $recordTitleAttribute = 'name';

    public static ?string $label = 'Sectores y Servicios';

    public static ?string $navigationLabel = 'Sectores y Servicios';

    protected static ?string $pluralModelLabel = 'Sectores y Servicios';

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }



    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    /*  public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRelation('empresasectorservice', 'sectors_id', '=', 2);
    } */

    public static function table(Table $table): Table
    {
        return $table

            ->columns([
                Tables\Columns\TextColumn::make('sectors.name')->label('Sectores'),
                Tables\Columns\TextColumn::make('name')->label('Servicios'),

            ])->headerActions([

                Tables\Actions\AttachAction::make()->form(fn (AttachAction $action): array => [

                    Forms\Components\Select::make('sectors_id')
                        ->relationship('sectors', 'name')
                        ->label('Sectores')
                        ->options(Sector::all()->pluck('name', 'id')->toArray())
                        ->reactive()
                        ->afterStateUpdated(fn (callable $set) => $set('services.id', null)),

                    $action->getRecordSelect()
                        ->label(('Servicios'))
                        ->disableLabel(false)
                        ->options(function (callable $get) {
                            $sector = Sector::find($get('sectors_id'));
                            if (!$sector) {
                                $sector_values = Service::all();
                            } else
                                $sector_values = $sector->services;

                            return $sector_values->pluck('name', 'id');
                        })
                        ->searchable(false)
                        ->hidden(fn (callable $get) => $get('sectors_id') === null),

                ]),

            ])
            ->actions([
                Tables\Actions\DetachAction::make(),

            ])
            ->bulkActions([
                //
            ])
            ->filters([
                //
            ]);
    }
}
