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
use Filament\Notifications\Notification;
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
                        ->options(function (BelongsToManyRelationManager $livewire) {
                            $empresa = $livewire->ownerRecord;
                            $allowed = $empresa->allowedSectorIds();

                            // Solo se permiten el Sector Principal y el Secundario de la empresa
                            if (count($allowed) > 0) {
                                return Sector::whereIn('id', $allowed)->pluck('name', 'id')->toArray();
                            }

                            // Empresas que aún no definen sus sectores: se limitan a los ya usados si llegaron a 2
                            $current = $empresa->distinctSectorIds();
                            if (count($current) >= 2) {
                                return Sector::whereIn('id', $current)->pluck('name', 'id')->toArray();
                            }

                            return Sector::all()->pluck('name', 'id')->toArray();
                        })
                        ->helperText('Limitado al Sector Principal y Secundario definidos en los datos de la empresa (máximo 2 sectores).')
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

                ])->before(function (AttachAction $action, array $data, BelongsToManyRelationManager $livewire) {
                    $empresa = $livewire->ownerRecord;
                    $service = Service::find($data['recordId'] ?? null);
                    $sectorId = $service ? (int) $service->sectors_id : null;

                    $allowed = $empresa->allowedSectorIds();

                    if (count($allowed) > 0) {
                        if (!in_array($sectorId, $allowed, true)) {
                            Notification::make()
                                ->danger()
                                ->title('Sector no permitido')
                                ->body('Solo puede asociar servicios de su Sector Principal o Secundario. Si necesita cambiar de sectores, edite los datos de la empresa.')
                                ->persistent()
                                ->send();

                            $action->cancel();
                        }

                        return;
                    }

                    // Empresa sin sectores definidos: no puede superar 2 sectores distintos
                    $current = $empresa->distinctSectorIds();
                    if (!in_array($sectorId, $current, true) && count($current) >= 2) {
                        Notification::make()
                            ->danger()
                            ->title('Máximo 2 sectores por empresa')
                            ->body('Su empresa ya tiene servicios en 2 sectores. Defina su Sector Principal y Secundario en los datos de la empresa, o desvincule servicios de otros sectores.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),

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
