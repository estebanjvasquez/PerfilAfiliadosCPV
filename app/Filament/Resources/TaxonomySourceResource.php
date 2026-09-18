<?php

namespace App\Filament\Resources;

use App\Models\TaxonomySource;
use App\Services\TaxonomyAuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

use App\Filament\Resources\TaxonomySourceResource\Pages;

/**
 * TAXV2-8 (sección 4.11 del documento de instrucciones): administración de las 4 fuentes del
 * diccionario V2 (`CURATED` + las 3 crawleables SLB/OSHA/IADC, sembradas por `taxonomy:seed-sources`
 * en TAXV2-1). `enabled` empieza en `false` para las 3 crawleables (mismo default que trae el JSON)
 * - un administrador debe habilitarlas a propósito antes de que `taxonomy:crawl-source` (TAXV2-12,
 * todavía no construido) pueda correr contra ellas.
 *
 * Estadísticas de crawleo (`last_crawl`, `terms_discovered`, etc. de la sección 4.11) no existen
 * todavía como columnas - se derivan de `taxonomy_crawl_runs` recién en TAXV2-12, no se duplican
 * acá.
 */
class TaxonomySourceResource extends Resource
{
    protected static ?string $model = TaxonomySource::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Fuentes y actualización';

    public static ?string $label = 'Fuente';

    protected static ?string $pluralModelLabel = 'Fuentes';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identidad')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('source_id')
                        ->label('ID de fuente')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation) => $operation === 'edit'),
                    Forms\Components\TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                    Forms\Components\TextInput::make('base_url')->label('URL base')->url()->maxLength(255),
                    Forms\Components\TextInput::make('discovery_url')->label('URL de descubrimiento')->url()->maxLength(255),
                    Forms\Components\Toggle::make('crawlable')
                        ->label('Es crawleable')
                        ->helperText('"CURATED" no lo es - se cargan términos a mano, nunca por crawler.')
                        ->disabled(fn (string $operation) => $operation === 'edit'),
                ]),
            Forms\Components\Section::make('Configuración de crawleo')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enabled')
                        ->label('Habilitada para crawlear')
                        ->helperText('Debe activarse a propósito - por defecto viene apagada (mismo criterio que trae el JSON V2).'),
                    Forms\Components\Toggle::make('respect_robots_txt')
                        ->label('Respetar robots.txt')
                        ->default(true)
                        ->disabled()
                        ->helperText('No configurable - siempre se respeta (regla 1 de la sección 5 del documento de instrucciones).'),
                    Forms\Components\TextInput::make('rate_limit_rpm')
                        ->label('Límite de requests por minuto')
                        ->numeric()
                        ->minValue(1),
                    Forms\Components\Toggle::make('requires_admin_approval_before_import')
                        ->label('Requiere aprobación antes de importar')
                        ->default(true)
                        ->helperText('Ningún término nuevo entra directo a producción (regla 8 de la sección 5).'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source_id')->label('ID'),
                Tables\Columns\TextColumn::make('name')->label('Nombre')->wrap(),
                Tables\Columns\IconColumn::make('crawlable')->label('Crawleable')->boolean(),
                Tables\Columns\IconColumn::make('enabled')->label('Habilitada')->boolean(),
                Tables\Columns\TextColumn::make('rate_limit_rpm')->label('Rate limit (rpm)')->toggleable(),
                Tables\Columns\TextColumn::make('base_url')->label('URL base')->toggleable(isToggledHiddenByDefault: true)->url(fn (?string $state) => $state)->openUrlInNewTab(),
            ])
            ->defaultSort('source_id')
            ->actions([
                Tables\Actions\Action::make('toggle_enabled')
                    ->label(fn (TaxonomySource $record) => $record->enabled ? 'Deshabilitar' : 'Habilitar')
                    ->icon(fn (TaxonomySource $record) => $record->enabled ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (TaxonomySource $record) => $record->enabled ? 'gray' : 'success')
                    ->visible(fn (TaxonomySource $record) => $record->crawlable && (Auth::user()?->can('taxonomy_manage_sources') ?? false))
                    ->requiresConfirmation()
                    ->action(function (TaxonomySource $record) {
                        $oldValue = $record->enabled;
                        $record->update(['enabled' => ! $oldValue]);

                        TaxonomyAuditLogger::record(
                            entityType: TaxonomySource::class,
                            entityId: $record->getKey(),
                            field: 'enabled',
                            oldValue: $oldValue,
                            newValue: $record->enabled,
                        );

                        Notification::make()->success()->title($record->enabled ? 'Fuente habilitada' : 'Fuente deshabilitada')->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomySources::route('/'),
            'edit' => Pages\EditTaxonomySource::route('/{record}/edit'),
        ];
    }
}
