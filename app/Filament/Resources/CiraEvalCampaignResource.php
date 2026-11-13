<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CiraEvalCampaignResource\Pages;
use App\Filament\Resources\CiraEvalCampaignResource\RelationManagers\InvitationsRelationManager;
use App\Models\Cira\CiraEvalCampaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * TASK-0027: CIRA evaluation campaigns (privileged role only, CiraEvaluationPanelPolicy). A campaign is opened, closed
 * and never deleted; its evaluators are invited from the «Evaluadores» tab.
 */
class CiraEvalCampaignResource extends Resource
{
    protected static ?string $model = CiraEvalCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'CIRA';

    protected static ?string $navigationLabel = 'Campañas de evaluación';

    protected static ?string $modelLabel = 'campaña de evaluación';

    protected static ?string $pluralModelLabel = 'Campañas de evaluación';

    protected static ?string $breadcrumb = 'Campañas de evaluación';

    protected static ?string $slug = 'cira-campanas';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->required()
                ->maxLength(40)
                ->regex('/^[A-Z0-9][A-Z0-9-]{1,39}$/')
                ->unique(ignoreRecord: true)
                ->helperText('Mayúsculas, números y guiones. Ejemplo: CPV-2026-11.'),
            Forms\Components\TextInput::make('name')->label('Nombre')->required()->maxLength(200),
            Forms\Components\Textarea::make('description')->label('Descripción')->maxLength(4000)->rows(3),
            Forms\Components\Select::make('status')->label('Estado')->options(CiraEvalCampaign::STATUSES)->required()->default('draft')->native(false)
                ->helperText('Solo una campaña «Abierta» (y dentro de sus fechas, si las tiene) admite evaluaciones.'),
            Forms\Components\DateTimePicker::make('opens_at')->label('Abre (opcional)')->seconds(false),
            Forms\Components\DateTimePicker::make('closes_at')->label('Cierra (opcional)')->seconds(false)->after('opens_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Código')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => CiraEvalCampaign::STATUSES[$state] ?? $state),
                Tables\Columns\TextColumn::make('invitations_count')->label('Evaluadores')->counts('invitations'),
                Tables\Columns\TextColumn::make('evaluations_count')->label('Evaluaciones')->counts('evaluations'),
                Tables\Columns\TextColumn::make('closes_at')->label('Cierra')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [InvitationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCiraEvalCampaigns::route('/'),
            'create' => Pages\CreateCiraEvalCampaign::route('/create'),
            'edit' => Pages\EditCiraEvalCampaign::route('/{record}/edit'),
        ];
    }
}
