<?php

namespace App\Filament\Resources;

use App\Models\Empresa;
use App\Filament\Resources\ManagementResource\Pages;
use App\Filament\Resources\ManagementResource\RelationManagers;
use App\Models\Management;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use PhpParser\Node\Stmt\Label;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ManagementResource extends Resource
{
    protected static ?string $model = Management::class;

    protected static ?string $navigationIcon = 'heroicon-o-badge-check';

    public static ?string $label = 'Sistemas de Gestión';

    public static ?string $navigationLabel = 'Sistemas de Gestión';

    protected static ?string $pluralModelLabel = 'Sistemas de Gestión';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        $vemp = 'management';

        $emps = Empresa::get();
        foreach ($emps as $key => $valuemp) {
        }

        return $form

            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Placeholder::make('')
                            ->content('Su Empresa tiene implementado alguno de los siguientes Sistemas de Gestión?'),
                    ]),


                Forms\Components\Select::make('empresa_id')->relationship('empresa', 'name')
                    ->disabled()
                    ->columnSpan(3)
                    ->visibleOn('edit'),

                Forms\Components\Select::make('empresa_id') //->relationship('empresa', 'name')
                    ->options($valuemp->getEmpresaUser($vemp)->pluck('name', 'id'))->required()
                    ->columnSpan(3)
                    ->visibleOn('create'),


                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Calidad')
                            ->schema([
                                Forms\Components\Checkbox::make('iso9001')->label('ISO 9001:2015  Sistema de Gestión de la Calidad'),
                                Forms\Components\Checkbox::make('iso17025')->label('ISO 17025:2005 Laboratorios de ensayo y de calibración'),
                                Forms\Components\Toggle::make('quality_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('quality', false)),
                                Forms\Components\Repeater::make('quality_data')->label('Sistema de Gestión de la Calidad')
                                    ->schema([
                                        Forms\Components\TextInput::make('quality_otros_name')
                                            ->label('Calidad: otros')
                                            ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                return $set($component, mb_strtoupper($state));
                                            }),
                                    ])
                                    ->orderable(false)
                                    ->disableLabel()
                                    ->hidden(fn (callable $get) => $get('quality_otros') === false),
                            ]),
                    ])->columnSpan(['lg' => 1]),

                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Ambiente')
                            ->schema([
                                Forms\Components\Checkbox::make('iso14001')->label('ISO 14001:2015 Sistemas de Gestión Ambiental'),
                                Forms\Components\Checkbox::make('iso50001')->label('ISO 50001:2018 Sistemas de Gestión de la Energía.'),
                                Forms\Components\Toggle::make('environment_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('environment', false)),
                                Forms\Components\Repeater::make('environment_data')->label('Ambiente')
                                    ->schema([
                                        Forms\Components\TextInput::make('environment_otros_name')
                                            ->label('Ambiente: otros')
                                            ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                return $set($component, mb_strtoupper($state));
                                            }),
                                    ])
                                    ->orderable(false)
                                    ->disableLabel()
                                    ->hidden(fn (callable $get) => $get('environment_otros') === false),
                            ]),
                    ])->columnSpan(['lg' => 1]),

                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Credibilidad y Transparencia')
                            ->schema([
                                Forms\Components\Checkbox::make('dun')->label('Dun & Bradstreet.'),
                                Forms\Components\Checkbox::make('iso37001')->label('ISO 37001:2016 Sistemas de Gestión Antisoborno.'),
                                Forms\Components\Toggle::make('credibility_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('credibility', false)),
                                Forms\Components\Repeater::make('credibility_data')->label('Credibilidad y Transparencia')
                                    ->schema([
                                        Forms\Components\TextInput::make('credibility_otros_name')
                                            ->label('Credibilidad: otros')
                                            ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                return $set($component, mb_strtoupper($state));
                                            }),
                                    ])
                                    ->orderable(false)
                                    ->disableLabel()
                                    ->hidden(fn (callable $get) => $get('credibility_otros') === false),
                            ]),
                    ])->columnSpan(['lg' => 1]),

                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Seguridad')
                            ->schema([
                                Forms\Components\Checkbox::make('iso45001')->label('ISO 45001:2018 Seguridad y Salud en el Trabajo.'),
                                Forms\Components\Checkbox::make('ovid')->label('OVID.'),
                                Forms\Components\Toggle::make('security_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('security', false)),
                                Forms\Components\Repeater::make('security_data')->label('Seguridad')
                                    ->schema([
                                        Forms\Components\TextInput::make('security_otros_name')
                                            ->label('Seguridad: otros')
                                            ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                return $set($component, mb_strtoupper($state));
                                            }),
                                    ])
                                    ->orderable(false)
                                    ->disableLabel()
                                    ->hidden(fn (callable $get) => $get('security_otros') === false),
                            ]),
                    ])->columnSpan(['lg' => 1]),

                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Gestión de Proyectos')
                            ->schema([
                                Forms\Components\Checkbox::make('pmi')->label('Project Management Professional (PMI).'),
                                Forms\Components\Toggle::make('pmi_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('projects', false)),
                                Grid::make(1)
                                    ->schema([
                                        Forms\Components\Repeater::make('pmi_data')->label('Gestión de Proyectos')
                                            ->schema([
                                                Forms\Components\TextInput::make('pmi_otros_name')
                                                    ->label('PMI: otros')
                                                    ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                        return $set($component, mb_strtoupper($state));
                                                    }),
                                                Forms\Components\TextInput::make('pmi_otros_q')->label('Gestión de Proyectos')
                                                    ->label('PMI: otros')
                                                    ->numeric()
                                                    ->default(0)
                                                    ->required(),
                                            ])
                                            ->columns(2)
                                            ->orderable(false)
                                            ->disableLabel()
                                            ->hidden(fn (callable $get) => $get('pmi_otros') === false),
                                    ]),
                            ]),
                    ])->columnSpan(['lg' => 1]),

                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Section::make('Seguridad de la Información')
                            ->schema([
                                Forms\Components\Checkbox::make('iso27001')->label('ISO 27001 Sistemas de Gestión de la Seguridad de la Información.'),
                                Forms\Components\Toggle::make('info_otros')
                                    ->label('Otros (Especifíque)')
                                    ->reactive()
                                    ->afterStateUpdated(fn (callable $set) => $set('information', false)),
                                Forms\Components\Repeater::make('info_data')->label('Seguridad de la Información')
                                    ->schema([
                                        Forms\Components\TextInput::make('info_otros_name')
                                            ->label('Info Seguridad: otros')
                                            ->required()->afterStateUpdated(function ($component, $state, $set) {
                                                return $set($component, mb_strtoupper($state));
                                            }),
                                    ])
                                    ->orderable(false)
                                    ->disableLabel()
                                    ->hidden(fn (callable $get) => $get('info_otros') === false),
                            ]),
                    ])->columnSpan(['lg' => 1]),
            ])->columns(3);
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
            'index' => Pages\ListManagement::route('/'),
            'create' => Pages\CreateManagement::route('/create'),
            'edit' => Pages\EditManagement::route('/{record}/edit'),
        ];
    }
}
