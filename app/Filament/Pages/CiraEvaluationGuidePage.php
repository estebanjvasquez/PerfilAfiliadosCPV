<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCiraEvaluator;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** TASK-0027: the evaluation guide for the Chamber (access, steps, criteria, useful comments, duration). */
class CiraEvaluationGuidePage extends Page
{
    use AuthorizesCiraEvaluator {
        canAccess as evaluatorCanAccess;
    }

    protected static ?string $title = 'Guía de evaluación de CIRA';

    protected static ?string $slug = 'evaluacion-cira/guia';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.cira-evaluation-guide';

    /** Evaluators, and the panel role that organises the campaign. */
    public static function canAccess(): bool
    {
        return static::evaluatorCanAccess() || CiraEvaluationService::isPanelUser(Auth::user());
    }

    protected function getViewData(): array
    {
        return [
            'tests' => config('cira_evaluation.tests'),
            'ratings' => config('cira_evaluation.ratings'),
            'minutes' => config('cira_evaluation.estimated_minutes'),
        ];
    }
}
