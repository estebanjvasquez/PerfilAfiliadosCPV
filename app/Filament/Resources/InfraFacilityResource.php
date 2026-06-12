<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InfraFacilityResource\Pages;
use App\Filament\Resources\InfraFacilityResource\RelationManagers;
use App\Models\InfraFacility;
use App\Models\InfraRegion;
use App\Models\infraregion_infrasystem;
use App\Models\InfraSystem;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class InfraFacilityResource extends Resource
{
    protected static ?string $model = InfraFacility::class;

    protected static ?string $navigationIcon = 'heroicon-o-office-building';

    protected static ?string $navigationGroup = 'Mantenimiento';

    public static ?string $label = 'Instalación';

    public static ?string $navigationLabel = 'Instalaciones';

    protected static ?string $pluralModelLabel = 'Instalaciones';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        $regsys = infraregion_infrasystem::get();
        foreach ($regsys as $key => $value) {
        }
        //dd($value->getRegsys()->count());
        return $form
            ->schema([

                /*  Forms\Components\Select::make('infraregion_infrasystem_id')
                    ->label('Region-Sistema')
                    ->relationship('infraregion_infrasystem', 'infrasystem_id'), */

                Forms\Components\Select::make('infraregion_infrasystem_id')
                    ->label('Sistema / Región')
                    //->relationship('infraregion_infrasystem', 'id'),
                    ->options($value->getRegsys()->pluck('regsys', 'infraid'))
                    ->searchable(),

                /* Forms\Components\Select::make('infrasystems_id')
                    ->label('Sistema')
                    ->required()
                    ->options(function (callable $get) {
                        $region = InfraRegion::find($get('infraregions_id'));

                        if (!$region) {
                            $sistema = InfraSystem::all();
                        } else
                            $sistema = $region->infrasystems;

                        return $sistema->pluck('system_name', 'id');
                    }), */

                Forms\Components\TextInput::make('facility_name')
                    ->label('Instalación')
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
                //Tables\Columns\TextColumn::make('infrasystems.system_name')->label('Sistema'),
                //Tables\Columns\TextColumn::make('infraregions.region_name')->label('Región'),
                //Tables\Columns\TextColumn::make('infraregion_infrasystem_id')->label('Region-Sistema'),
                Tables\Columns\TextColumn::make('facility_name')->label('Nombre de Instalación'),
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
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageInfraFacilities::route('/'),
        ];
    }
}
