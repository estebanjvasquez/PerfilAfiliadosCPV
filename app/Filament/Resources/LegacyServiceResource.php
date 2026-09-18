<?php

namespace App\Filament\Resources;

use App\Models\LegacyService;
use App\Models\Sector;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\LegacyServiceResource\Pages;
use App\Filament\Resources\LegacyServiceResource\RelationManagers;

/**
 * TAXV2-7 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.7): espejo
 * administrable del catálogo legacy `services` (mysql, 112 filas, poblado por
 * `taxonomy:import-legacy-service-relations`, TAXV2-3). `name`/`sectors_id` son de solo lectura acá
 * (vienen de mysql, la fuente real - editar el nombre de un servicio legacy sigue siendo cosa del
 * `ServiceResource` de solo lectura, no de acá); lo único administrable es `status`
 * (legacy_active/deprecated/disabled).
 *
 * `sectors_id` no tiene relación Eloquent real (services/sectors viven en mysql, esta tabla en
 * pgsql - mismo patrón cross-connection que el resto del proyecto) - el nombre del sector se
 * resuelve con una consulta aparte, cacheada en memoria para toda la tabla (evita 1 query por fila).
 */
class LegacyServiceResource extends Resource
{
    protected static ?string $model = LegacyService::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Servicios legacy';

    public static ?string $label = 'Servicio legacy';

    protected static ?string $pluralModelLabel = 'Servicios legacy';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['cpvRelations', 'termRelations']);
    }

    private static function sectorNames(): array
    {
        return Sector::query()->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        $sectors = static::sectorNames();

        return $form->schema([
            Forms\Components\Section::make('Identidad (solo lectura - sincronizada desde el catálogo real)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('id')
                        ->label('ID (= services.id en mysql)')
                        ->disabled()
                        ->dehydrated(false),
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->disabled()
                        ->dehydrated(false),
                    Forms\Components\Select::make('sectors_id')
                        ->label('Sector')
                        ->options($sectors)
                        ->disabled()
                        ->dehydrated(false),
                ]),
            Forms\Components\Section::make('Estado')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options([
                            LegacyService::STATUS_LEGACY_ACTIVE => 'Activo (legacy)',
                            LegacyService::STATUS_DEPRECATED => 'Deprecado',
                            LegacyService::STATUS_DISABLED => 'Deshabilitado',
                        ])
                        ->native(false)
                        ->required()
                        ->helperText('Retirar un servicio (deprecated/disabled) no borra sus relaciones a CPV - solo deja de proponerse como origen de nuevas homologaciones.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $sectors = static::sectorNames();

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('sectors_id')
                    ->label('Sector')
                    ->formatStateUsing(fn ($state) => $sectors[$state] ?? "#{$state}")
                    ->toggleable(),
                Tables\Columns\SelectColumn::make('status')
                    ->label('Estado')
                    ->options([
                        LegacyService::STATUS_LEGACY_ACTIVE => 'Activo (legacy)',
                        LegacyService::STATUS_DEPRECATED => 'Deprecado',
                        LegacyService::STATUS_DISABLED => 'Deshabilitado',
                    ])
                    ->selectablePlaceholder(false),
                Tables\Columns\TextColumn::make('cpv_relations_count')->label('Rel. CPV')->toggleable(),
                Tables\Columns\TextColumn::make('term_relations_count')->label('Términos relacionados')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        LegacyService::STATUS_LEGACY_ACTIVE => 'Activo (legacy)',
                        LegacyService::STATUS_DEPRECATED => 'Deprecado',
                        LegacyService::STATUS_DISABLED => 'Deshabilitado',
                    ]),
                Tables\Filters\SelectFilter::make('sectors_id')
                    ->label('Sector')
                    ->options($sectors),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CpvRelationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLegacyServices::route('/'),
            'edit' => Pages\EditLegacyService::route('/{record}/edit'),
        ];
    }
}
