<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Sector;
use App\Models\Empresa;
use App\Models\Service;
use App\Models\InfraType;
use App\Models\Experience;
use App\Models\InfraRegion;
use App\Models\InfraSector;
use App\Models\InfraSystem;
use Filament\Resources\Form;
use App\Models\InfraFacility;
use Filament\Resources\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\infraregion_infrasystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ExperienceResource\Pages;
use App\Filament\Resources\ExperienceResource\RelationManagers;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class ExperienceResource extends Resource
{
    protected static ?string $model = Experience::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-search';

    public static ?string $label = 'Experiencia Relevante';

    public static ?string $navigationLabel = 'Experiencia Relevante';

    protected static ?string $pluralModelLabel = 'Experiencia Relevante';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        $vemp = 'experiences';

        $emps = Empresa::get();
        foreach ($emps as $key => $valuemp) {
        }

        return $form
            ->schema([

                Forms\Components\Select::make('empresa_id')->relationship('empresa', 'name')
                    ->disabled()
                    ->columnSpan(3)
                    ->visibleOn('edit'),

                Forms\Components\Select::make('empresa_id') //->relationship('empresa', 'name')

                    ->options($valuemp->getEmpresaUser($vemp)->pluck('name', 'id'))->required()
                    /* ->options(function ($valuemp, $vemp) {
                        if (!$valuemp) {
                            return [];
                        } else return $valuemp->getEmpresaUser($vemp)->pluck('name', 'id'); */
                    ->required()
                    ->columnSpan(3)
                    ->visibleOn('create'),


                Forms\Components\Builder::make('exp_year')
                    ->blocks([

                        Forms\Components\Builder\Block::make('Experiencia Relevante')
                            ->schema([
                                Forms\Components\Group::make([
                                    Forms\Components\TextInput::make('exp_year')
                                        ->label('Año')
                                        ->required()
                                        ->numeric()->minValue(1901)->maxValue(2150)

                                ])->columns(1),
                                Forms\Components\Group::make([
                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\Select::make('infrasectors_id')
                                            ->label('Sector')
                                            ->options(InfraSector::all()->pluck('sector_name', 'id')->toArray())
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infratypes_id', null)),

                                        Forms\Components\Select::make('infratypes_id')
                                            ->label('Tipo')
                                            ->options(function (callable $get) {
                                                $isector = InfraSector::find($get('infrasectors_id'));
                                                if (!$isector) {
                                                    return [];
                                                    //return InfraType::all()->pluck('type_name', 'id')
                                                }
                                                return $isector->infratypes->pluck('type_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infrasystems_id', null)),

                                        // **************************************************************************************************
                                        Forms\Components\Select::make('infrasystems_id')
                                            ->label('Sistema')
                                            ->options(function (callable $get) {
                                                $itype = InfraType::find($get('infratypes_id'));
                                                if (!$itype) {
                                                    return [];
                                                    //return InfraSystem::all()->pluck('system_name', 'id');
                                                }
                                                return $itype->infrasystems->pluck('system_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infraregions_id', null)),

                                        // **************************************************************************************************
                                        Forms\Components\Select::make('infraregions_id')
                                            ->label('Región o Distrito')
                                            ->options(function (callable $get) {
                                                $isystem = InfraSystem::find($get('infrasystems_id'));

                                                if (!$isystem) {
                                                    return [];
                                                }
                                                return $isystem->infraregions->pluck('region_name', 'id');
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('infrafacilities_id', null)),

                                        // *************************************************************************************************
                                        Forms\Components\Select::make('infrafacilities_id')
                                            ->label('Instalación')
                                            ->options(function (callable $get) {
                                                $iregsys = InfraRegion::find($get('infraregions_id'));
                                                //dd($iregsys);
                                                if (!$iregsys) {
                                                    return [];
                                                }
                                                //return [$iregsys->id];
                                                //return [$iregsys->getFacility($iregsys->id, $get('infrasystems_id'))->count()];
                                                return $iregsys->getFacility($iregsys->id, $get('infrasystems_id'))->pluck('facility_name', 'id');
                                            }),

                                    ])->columns(1)->label('Infraestructura en la que Trabajó'),
                                ])
                                    ->columns(1)->columnSpan(2),


                                Forms\Components\Group::make([
                                    Forms\Components\Select::make('magnitud')
                                        ->options([
                                            '1' => '< 100000 USD',
                                            '2' => '100001 - 1000000 USD',
                                            '3' => '1000001 - 10000000 USD',
                                            '4' => '> 10000001 USD'
                                        ])->label('Orden de Magnitud del Contrato')
                                        ->placeholder('Por favor seleccione una opción'),

                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\TextInput::make('prof_tech')
                                            ->label('Prof. y Técnicos')->numeric()->minValue(0),
                                        Forms\Components\TextInput::make('manpower')
                                            ->label('Mano de Obra Directa')->numeric()->minValue(0),
                                    ])->columns(2)->label('Esfuerzo H-H'),

                                    Forms\Components\Fieldset::make('clasificacion')->schema([
                                        Forms\Components\Select::make('sectors_id')
                                            //->relationship('sectors', 'name')
                                            ->label('Sector')
                                            ->options(Sector::all()->pluck('name', 'id')->toArray())
                                            ->reactive()
                                            ->afterStateUpdated(fn (callable $set) => $set('services_id', null)),

                                        Forms\Components\Select::make('services_id')
                                            ->label('Servicios')
                                            ->options(function (callable $get) {
                                                $sector = Sector::find($get('sectors_id'));
                                                if (!$sector) {
                                                    return Service::all()->pluck('name', 'id');
                                                }
                                                return $sector->services->pluck('name', 'id');
                                            }),
                                    ])->columns(1)->label('Clasificación del Tipo de Trabajo Realizado')

                                ])->columns(1)->columnSpan(2),
                                Forms\Components\Group::make([
                                    Forms\Components\Textarea::make('Descripcion')
                                        ->label('Breve Descripción del Trabajo Realizado')
                                        ->rows(18)
                                ])->columns(1)->columnSpan(2)
                            ])->columns(7),
                    ])->minItems(1)->maxItems(10)->disableLabel()->label(__('Experiencia')),
            ])->columns(1);
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
            'index' => Pages\ListExperiences::route('/'),
            'create' => Pages\CreateExperience::route('/create'),
            'edit' => Pages\EditExperience::route('/{record}/edit'),
        ];
    }
}
