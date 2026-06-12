<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InfraFacilityResource\Pages;
use App\Filament\Resources\InfraFacilityResource\RelationManagers;
use App\Models\InfraFacility;
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

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('infraregions_id')
                    ->label('Región')
                    ->relationship('infraregions', 'region_name')
                    ->required(),
                Forms\Components\TextInput::make('facility_name')
                    ->label('Instalación')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('infraregions.region_name')->label('Región'),
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
