<?php

namespace App\Filament\Resources\CiraEvaluationResource\Pages;

use App\Filament\Resources\CiraEvaluationResource;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/** TASK-0027: one evaluation with its immutable evidence, the votes of that search and the evaluator's judgement. */
class ViewCiraEvaluation extends ViewRecord
{
    protected static string $resource = CiraEvaluationResource::class;

    protected static string $view = 'filament.resources.cira-evaluation.view';

    protected function getHeaderActions(): array
    {
        return [CiraEvaluationResource::classifyAction(Actions\Action::make('classify'))];
    }

    protected function getViewData(): array
    {
        $record = $this->getRecord();
        $service = app(CiraEvaluationService::class);
        $votes = $service->votesForLog($record->snapshot['search_log_id'] ?? null);
        $resultIds = collect($record->snapshot['results'] ?? [])->pluck('empresa_id')->map(fn ($i) => (int) $i)->all();

        return [
            'e' => $record,
            'snapshot' => $record->snapshot,
            'hashOk' => hash_equals((string) $record->snapshot_sha256, CiraEvaluationService::snapshotHash($record->snapshot)),
            'votesByCompany' => $votes->whereNotNull('empresa_id')->groupBy('empresa_id'),
            'unlinkedVotes' => $votes->filter(fn ($v) => $v['empresa_id'] !== null && ! in_array($v['empresa_id'], $resultIds, true))->values(),
            'otherVotes' => $votes->whereNull('empresa_id')->values(),
            'names' => CiraEvaluationService::companyNames(array_merge($record->missing_empresa_ids, $record->extra_empresa_ids)),
            'tests' => config('cira_evaluation.tests'),
            'ratings' => config('cira_evaluation.ratings'),
            'reasons' => config('cira_evaluation.reasons'),
            'findings' => config('cira_evaluation.finding_categories'),
        ];
    }
}
