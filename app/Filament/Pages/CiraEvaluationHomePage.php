<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCiraEvaluator;
use App\Models\Cira\CiraEvaluation;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0027: «Mis pruebas» — the evaluator's progress through the 8 guided tests and their own evaluations only.
 */
class CiraEvaluationHomePage extends Page
{
    use AuthorizesCiraEvaluator;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'CIRA';

    protected static ?string $navigationLabel = 'Evaluación de CIRA';

    protected static ?string $title = 'Evaluación de CIRA: mis pruebas';

    protected static ?string $slug = 'evaluacion-cira';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.cira-evaluation-home';

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getViewData(): array
    {
        $invitation = $this->service()->requireInvitation(Auth::user());

        return [
            'campaign' => $invitation->campaign,
            'pseudonym' => $invitation->pseudonym,
            'progress' => $this->service()->progress($invitation),
            'evaluations' => CiraEvaluation::query()->where('invitation_id', $invitation->getKey())->orderByDesc('updated_at')->limit(200)->get(),
            'tests' => config('cira_evaluation.tests'),
            'ratings' => config('cira_evaluation.ratings'),
            'minutes' => config('cira_evaluation.estimated_minutes'),
        ];
    }
}
