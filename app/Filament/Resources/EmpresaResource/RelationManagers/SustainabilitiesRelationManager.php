<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\CompletionBadge;
use App\Filament\Support\NoAplicaAction;
use App\Models\Area;
use App\Models\EmpresaModuleStatus;
use App\Models\Sustainability;
use Filament\Forms;
use Filament\Resources\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;


class SustainabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'sustainabilities';

    protected static ?string $recordTitleAttribute = 'sust_title';

    public static ?string $label = 'Enfoque de Sostenibilidad';

    public static ?string $navigationLabel = 'Enfoque de Sostenibilidad';

    protected static ?string $pluralModelLabel = 'Enfoques de Sostenibilidad';

    public static function form(Form $form): Form
    {
        $varea = Area::get();
        //dd($varea);
        foreach ($varea as $key => $valuet) {
            //dd($valuet);
        }

        return $form

            ->schema([

                Forms\Components\Select::make('areas_id')
                    ->label('Area')
                    ->options($valuet->getArea()->pluck('sust_title', 'id'))
                    ->reactive()
                    ->afterStateUpdated(fn (callable $set) => $set('areas.sust_description', null)),

                Forms\Components\Placeholder::make('areas.sust_description')
                    ->content(function (callable $get) {
                        $descripcion = Area::find($get('areas_id'));
                        if (!$descripcion) {
                            $value_descripcion = Sustainability::all();
                        } else
                            $value_descripcion = $descripcion;

                        foreach ($descripcion as $key => $valor) {
                            //
                        }
                        return $descripcion->sust_description;
                    })
                    ->hidden(fn (callable $get) => $get('areas_id') === null),

                Forms\Components\Select::make('sust_status')
                    ->options([
                        '0' => 'No',
                        '1' => 'Sí: Inactivo',
                        '2' => 'Sí: Activo',
                    ])->required()->label('Status'),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('areas.sust_title'),
                Tables\Columns\TextColumn::make('areas.sust_description')->limit(50)->label('Descripción'),
                Tables\Columns\IconColumn::make('sust_status')
                    ->label('Status')
                    ->options([
                        'heroicon-o-check-circle',
                        'heroicon-o-x-circle' => '0',
                        'heroicon-o-check-circle' => '1',
                        'heroicon-o-check-circle' => '2',
                    ])
                    ->colors([
                        'secondary',
                        'danger' => '0',
                        'warning' => '1',
                        'success' => '2',
                    ])
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
                CompletionBadge::make(EmpresaModuleStatus::MODULE_SOSTENIBILIDAD),
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_SOSTENIBILIDAD),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                //Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
