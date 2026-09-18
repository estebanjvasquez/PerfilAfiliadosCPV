<?php

namespace App\Filament\Resources;

use App\Models\CompanyCrawlRun;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use App\Filament\Resources\CompanyCrawlRunResource\Pages;
use App\Filament\Resources\CompanyCrawlRunResource\RelationManagers;

/**
 * TAXV2-13 (sección 6 del documento de instrucciones - la fase de mayor riesgo/menos especificada):
 * panel de supervisión, de solo lectura a propósito, de las corridas de `taxonomy:crawl-company-website`
 * y la evidencia (`company_term_matches`) que encontraron. No hay creación manual (solo el crawler
 * origina estas filas) ni edición de sus datos crudos - la única acción humana posible es
 * confirmar/descartar evidencia desde el `TermMatchesRelationManager`, nunca tocar
 * `empresa_taxonomy_category` directo desde acá (ver docblock de la migración de
 * `company_term_matches`).
 */
class CompanyCrawlRunResource extends Resource
{
    protected static ?string $model = CompanyCrawlRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Crawler de empresas';

    public static ?string $label = 'Corrida de crawler (empresa)';

    protected static ?string $pluralModelLabel = 'Corridas de crawler (empresas)';

    protected static ?int $navigationSort = 14;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Corrida (solo lectura - generada por el crawler)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('empresa_id')->label('Empresa (id en mysql)')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('status')->label('Estado')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('pages_processed')->label('Páginas procesadas')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('matches_found')->label('Coincidencias encontradas')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('started_at')->label('Inicio')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('finished_at')->label('Fin')->disabled()->dehydrated(false),
                    Forms\Components\Textarea::make('error_message')->label('Error')->disabled()->dehydrated(false)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('empresa_id')->label('Empresa (id)')->searchable()->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => CompanyCrawlRun::STATUS_RUNNING,
                        'success' => CompanyCrawlRun::STATUS_COMPLETED,
                        'danger' => [CompanyCrawlRun::STATUS_FAILED, CompanyCrawlRun::STATUS_BLOCKED],
                    ]),
                Tables\Columns\TextColumn::make('pages_processed')->label('Páginas')->sortable(),
                Tables\Columns\TextColumn::make('matches_found')->label('Matches')->sortable(),
                Tables\Columns\TextColumn::make('started_at')->label('Inicio')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('finished_at')->label('Fin')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        CompanyCrawlRun::STATUS_RUNNING => 'En curso',
                        CompanyCrawlRun::STATUS_COMPLETED => 'Completada',
                        CompanyCrawlRun::STATUS_FAILED => 'Fallida',
                        CompanyCrawlRun::STATUS_BLOCKED => 'Bloqueada (robots.txt)',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->label('Ver'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TermMatchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanyCrawlRuns::route('/'),
            'edit' => Pages\EditCompanyCrawlRun::route('/{record}/edit'),
        ];
    }
}
