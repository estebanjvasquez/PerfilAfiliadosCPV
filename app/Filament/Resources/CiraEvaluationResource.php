<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CiraEvaluationResource\Pages;
use App\Models\Cira\CiraEvalCampaign;
use App\Models\Cira\CiraEvaluation;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0027: every evaluation of the CIRA campaigns, for the privileged role only (CiraEvaluationPanelPolicy). The
 * evaluator appears by pseudonym. The panel classifies findings; it never edits the evaluator's judgement or evidence and
 * never deletes an evaluation.
 */
class CiraEvaluationResource extends Resource
{
    protected static ?string $model = CiraEvaluation::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'CIRA';

    protected static ?string $navigationLabel = 'Evaluaciones recibidas';

    protected static ?string $modelLabel = 'evaluación de CIRA';

    protected static ?string $pluralModelLabel = 'Evaluaciones de CIRA';

    protected static ?string $breadcrumb = 'Evaluaciones de CIRA';

    protected static ?string $slug = 'cira-evaluaciones';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invitation:id,pseudonym', 'campaign:id,code']);
    }

    public static function table(Table $table): Table
    {
        $tests = collect(config('cira_evaluation.tests'))->mapWithKeys(fn ($t, $c) => [$c => $c.' · '.$t['title']])->all();

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('campaign.code')->label('Campaña'),
                Tables\Columns\TextColumn::make('test_code')->label('Prueba')->sortable(),
                Tables\Columns\TextColumn::make('invitation.pseudonym')->label('Evaluador'),
                Tables\Columns\TextColumn::make('query_text')->label('Búsqueda')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('rating')->label('Valoración')->badge()
                    ->formatStateUsing(fn ($state) => config('cira_evaluation.ratings')[$state] ?? '—')
                    ->color(fn ($state) => ['correcta' => 'success', 'parcial' => 'warning', 'incorrecta' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('status')->label('Estado')->formatStateUsing(fn ($state) => $state === 'enviada' ? 'Enviada' : 'Borrador'),
                Tables\Columns\TextColumn::make('search_log_id')->label('Registro')->placeholder('sin registro')->formatStateUsing(fn ($state) => '#'.$state),
                Tables\Columns\TextColumn::make('finding_category')->label('Hallazgo')
                    ->formatStateUsing(fn ($state) => config('cira_evaluation.finding_categories')[$state] ?? '—')->placeholder('sin clasificar'),
                Tables\Columns\TextColumn::make('updated_at')->label('Actualizada')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')->label('Campaña')->options(fn () => CiraEvalCampaign::query()->orderByDesc('id')->pluck('code', 'id')->all()),
                Tables\Filters\SelectFilter::make('test_code')->label('Prueba')->options($tests),
                Tables\Filters\SelectFilter::make('rating')->label('Valoración')->options(config('cira_evaluation.ratings')),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(['enviada' => 'Enviada', 'borrador' => 'Borrador']),
                Tables\Filters\SelectFilter::make('finding_category')->label('Hallazgo')->options(config('cira_evaluation.finding_categories')),
                Tables\Filters\SelectFilter::make('invitation_id')->label('Evaluador')->relationship('invitation', 'pseudonym'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::classifyAction(Tables\Actions\Action::make('classify')),
            ])
            ->bulkActions([]);
    }

    /** Finding classification by the reviewer: the only thing the panel writes on an evaluation. */
    public static function classifyAction($action)
    {
        return $action
            ->label('Clasificar hallazgo')
            ->icon('heroicon-o-tag')
            ->authorize(fn () => CiraEvaluationService::isPanelUser(Auth::user()))
            ->fillForm(fn (CiraEvaluation $record) => ['finding_category' => $record->finding_category, 'reviewer_note' => $record->reviewer_note])
            ->form([
                Forms\Components\Select::make('finding_category')->label('Tipo de hallazgo')->options(config('cira_evaluation.finding_categories'))->native(false),
                Forms\Components\Textarea::make('reviewer_note')->label('Nota de revisión')->maxLength(2000)->rows(3),
            ])
            ->action(function (CiraEvaluation $record, array $data): void {
                abort_unless(CiraEvaluationService::isPanelUser(Auth::user()), 403);
                $category = $data['finding_category'] ?? null;
                abort_if($category !== null && ! array_key_exists($category, config('cira_evaluation.finding_categories')), 422);
                $record->forceFill([
                    'finding_category' => $category,
                    'reviewer_note' => filled($data['reviewer_note'] ?? null) ? mb_substr(trim($data['reviewer_note']), 0, 2000) : null,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ])->save();
                Notification::make()->success()->title('Hallazgo clasificado')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCiraEvaluations::route('/'),
            'view' => Pages\ViewCiraEvaluation::route('/{record}'),
        ];
    }
}
