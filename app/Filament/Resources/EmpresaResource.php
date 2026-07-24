<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\User;
use Filament\Tables;
use App\Models\Sector;
use App\Models\Empresa;
use Barryvdh\DomPDF\PDF;
use App\Models\empresa_user;
use Filament\Resources\Form;
use Filament\Resources\Table;
use App\Models\JoinViewsModel;
use App\Exports\JoinViewExport;
use App\Models\countries_cities;
use Filament\Resources\Resource;
use App\Filament\Pages\JoinViews;
use Illuminate\Support\Facades\DB;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Layout;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Blade;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\ViewColumn;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\MultiSelect;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Filament\Forms\Components\BelongsToSelect;
use Filament\Widgets\StatsOverviewWidget\Card;
use Illuminate\Validation\ValidationException;
use App\Filament\Resources\EmpresaResource\Pages;
use AlperenErsoy\FilamentExport\Tests\Models\Post;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use App\Filament\Resources\EmpresaResource\Widgets\StatsOverview;

class EmpresaResource extends Resource
{
    protected static ?string $model = Empresa::class;

    public static ?string $label = 'Empresa';

    public static ?string $navigationLabel = 'Empresas';

    protected static ?string $navigationIcon = 'heroicon-s-office-building';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('rif')->required()->unique(),
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\TextInput::make('ano_fund')->numeric()->minValue(1901),
                Forms\Components\TextInput::make('phone'),
                Select::make('city_id')->relationship('city', 'city_name')->placeholder('Seleccione una Ciudad')->searchable()
                    ->label('Ciudad'),
                Forms\Components\TextInput::make('website')->url(),
                Forms\Components\TextInput::make('linkedin_profile'),
                Forms\Components\TextInput::make('twitter_profile'),
                Forms\Components\TextInput::make('instagram_profile'),
                Forms\Components\TextInput::make('facebook_profile'),
                Forms\Components\TextInput::make('youtube_profile'),
                Forms\Components\TextInput::make('otros_profile'),
                Select::make('sector_principal_id')
                    ->label('Sector Principal')
                    ->options(Sector::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Seleccione el Sector Principal')
                    ->reactive()
                    ->required(),
                Select::make('sector_secundario_id')
                    ->label('Sector Secundario (opcional)')
                    ->options(fn (callable $get) => Sector::orderBy('name')
                        ->where('id', '<>', $get('sector_principal_id'))
                        ->pluck('name', 'id'))
                    ->placeholder('Seleccione el Sector Secundario')
                    ->different('sector_principal_id'),

                Select::make('billing_id')
                    ->options([
                        '1' => '< 100000 USD',
                        '2' => '100001 - 1000000 USD',
                        '3' => '1000001 - 10000000 USD',
                        '4' => '> 10000001 USD'
                    ])->required(),

                Select::make('employees_id')
                    ->options([
                        '1' => '< 50',
                        '2' => '51 - 100',
                        '3' => '101 - 500',
                        '4' => '> 500'
                    ])->required(),

                Select::make('status_id')
                    ->options([
                        '1' => 'Activa',
                        '0' => 'Inactiva',
                    ]) //->required(),
                ,
                Select::make('property_id')
                    ->options([
                        '1' => 'Privado',
                        '0' => 'Público',
                    ])->required(),

                Select::make('origin_id')
                    ->options([
                        '1' => 'Nacional',
                        '0' => 'Internacional',
                    ])->required(),

                Repeater::make('customers_country')
                    ->schema([
                        Forms\Components\TextInput::make('customer_name')->required(),
                        Select::make('country_id')->relationship('country', 'country_name')->required(),

                    ])
                    ->columns(3)

            ])->columns(3);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereRelation('users', 'users.id', '=', Auth::User()->id);
    }

    public static function table(Table $table): Table
    {
        static $max = 2155;

        return $table
            ->columns([

                Tables\Columns\IconColumn::make('')
                    ->options([
                        //'heroicon-o-printer',
                        'heroicon-o-download',
                    ])
                    ->label('PDF')
                    //->color('success')
                    //->icon('heroicon-o-download')
                    ->url(fn (Empresa $record) => route('pdf', $record))
                    ->openUrlInNewTab(),
                //->url(fn (Empresa $record): string => route('filament.pages.join-views', $record)), RUTA PARA REPORTE HTML........


                Tables\Columns\TextColumn::make('name')->label('Nombre de la Empresa'),
                Tables\Columns\IconColumn::make('status_id')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ano_fund')->label('Año de Fundación'),
                Tables\Columns\TextColumn::make('city.city_name')->label('Ciudad'),
                Tables\Columns\TextColumn::make('city.countries.country_name')
                    ->label('País')
                    ->wrap(),
                Tables\Columns\TextColumn::make('sectorPrincipal.name')->label('Sector Principal'),
                Tables\Columns\TextColumn::make('services.name')
                    ->label('Servicios')
                    ->limit(40)
                    ->tooltip(fn (Empresa $record): ?string => $record->services->pluck('name')->join(', ') ?: null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('completitud')
                    ->label('% Perfil')
                    ->getStateUsing(fn (Empresa $record): string => $record->completionPercentage() . ' %'),

            ])
            ->actions([
                //CUANDO EL REPORTE ESTABA DEL LADO DERECHO..................................
                /*Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->color('success')
                    ->icon('heroicon-o-download')
                    ->url(fn (Empresa $record) => route('pdf', $record))
                    ->openUrlInNewTab(),*/

                //EN CASO DE QUERER AÑADIR REPORTE EN XLS........................................
                /* Tables\Actions\Action::make('xls')
                    ->label('XLS')
                    ->color('success')
                    ->icon('heroicon-o-download')
                    ->url(fn (Empresa $record) => route('xls', $record))
                    ->openUrlInNewTab(), */


                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, $record) {
                        if ($record->assets->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Recursos asociados!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->experiences->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Experiencias asociadas!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->contacts->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Contactos asociados!')
                                ->body('Si desea eliminarlo debe desvincular primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->services->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Servicios asociados!')
                                ->body('Si desea eliminarlo debe desvincular primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->management->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Sistemas de Gestión asociados!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->presence) {
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Presencia Internacional asociada!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->sustainabilities->count() > 0) {
                            //dd($record->sustainabilities);
                            Notification::make()
                                ->danger()
                                ->title('Éste registro tiene Enfoques de Sostenibilidad asociados!')
                                ->body('Si desea eliminarlo debe eliminar primero los registros asociados.')
                                ->send(5);
                            $action->cancel();
                        } else if ($record->users->count() > 0) {
                            $idempresa = $record->id;
                            DB::delete('delete from empresa_user where empresa_id = ?', [$idempresa]);
                        }
                    })

            ])

            ->bulkActions([
                FilamentExportBulkAction::make('export')
            ])

            ->filters([
                TernaryFilter::make('status_id')->label('Activo'),
            ]);
    }


    public static function getRelations(): array
    {
        return [
            RelationManagers\ChambersRelationManager::class,
            RelationManagers\ContactsRelationManager::class,
            RelationManagers\UsersRelationManager::class,
            RelationManagers\ServicesRelationManager::class,
            RelationManagers\AssetsRelationManager::class,
            RelationManagers\ManagementRelationManager::class,
            RelationManagers\ExperiencesRelationManager::class,
            RelationManagers\PresenceRelationManager::class,
            RelationManagers\SustainabilitiesRelationManager::class,
        ];
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmpresas::route('/'),
            'create' => Pages\CreateEmpresa::route('/create'),
            'edit' => Pages\EditEmpresa::route('/{record}/edit'),

        ];
    }


    protected static function getNavigationBadge(): ?string
    {
        //return self::$model::all()->count();
        return self::$model::whereRelation('users', 'users.id', '=', Auth::User()->id)->count();
    }

    public static function getWidgets(): array
    {
        return [
            StatsOverview::class,
        ];
    }

    /*  public function incidentReport($id)
    {
        $record = JoinViews::find($id);
        $pdf = App::make('dompdf.wrapper');
        $pdf->loadView('pdf.report_pdf', compact('record')); // Pass the variable $record to the blade file
        return $pdf->stream(); // renders the PDF in the browser
    } */
}
