<?php

namespace App\Filament\Pages;

use App\Models\Cira\CiraEvalCampaign;
use App\Models\Cira\CiraEvalTestDecision;
use App\Models\Cira\CiraEvaluation;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-0027: consolidated acceptance report of a CIRA evaluation campaign (privileged role only, checked on every
 * request). It separates the technical result (what the engine returned, kept in each evaluation's snapshot) from the
 * sectoral judgement (the evaluators' ratings) and from the Chamber's decision per test, which is ALWAYS explicit: the
 * page never declares the search engine accepted by itself. The CSV export is optional; the page is the interface.
 */
class CiraEvaluationReportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'CIRA';

    protected static ?string $navigationLabel = 'Informe de evaluación';

    protected static ?string $title = 'Informe consolidado de la evaluación de CIRA';

    protected static ?string $slug = 'evaluacion-cira/informe';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.cira-evaluation-report';

    public ?int $campaignId = null;

    /** @var array<string, array{decision: ?string, note: ?string}> */
    public array $decisions = [];

    public static function canAccess(): bool
    {
        return CiraEvaluationService::isPanelUser(Auth::user());
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        $requested = ctype_digit((string) request()->query('campana')) ? (int) request()->query('campana') : null;
        $this->campaignId = CiraEvalCampaign::query()->when($requested, fn ($q) => $q->whereKey($requested))->orderByRaw("status = 'open' desc")->orderByDesc('id')->value('id');
        $this->loadDecisions();
    }

    public function updatedCampaignId(): void
    {
        $this->loadDecisions();
    }

    private function campaign(): ?CiraEvalCampaign
    {
        return $this->campaignId ? CiraEvalCampaign::query()->find($this->campaignId) : null;
    }

    private function loadDecisions(): void
    {
        $saved = $this->campaignId ? CiraEvalTestDecision::query()->where('campaign_id', $this->campaignId)->get()->keyBy('test_code') : collect();
        $this->decisions = [];
        foreach (CiraEvaluationService::testCodes() as $code) {
            $this->decisions[$code] = ['decision' => $saved[$code]->decision ?? null, 'note' => $saved[$code]->note ?? null];
        }
    }

    public function decide(string $testCode): void
    {
        abort_unless(static::canAccess(), 403);
        $campaign = $this->campaign();
        abort_if($campaign === null || ! in_array($testCode, CiraEvaluationService::testCodes(), true), 404);
        $decision = $this->decisions[$testCode]['decision'] ?? null;
        $note = trim((string) ($this->decisions[$testCode]['note'] ?? ''));
        if ($decision === null || $decision === '') {
            CiraEvalTestDecision::query()->where('campaign_id', $campaign->getKey())->where('test_code', $testCode)->delete();
            Notification::make()->success()->title("{$testCode}: sin decisión")->send();

            return;
        }
        if (! array_key_exists($decision, config('cira_evaluation.decisions')) || mb_strlen($note) > 2000) {
            $this->addError("decisions.{$testCode}.decision", 'Decisión no válida o nota demasiado larga (máximo 2000 caracteres).');

            return;
        }
        $row = CiraEvalTestDecision::query()->where('campaign_id', $campaign->getKey())->where('test_code', $testCode)->first() ?? new CiraEvalTestDecision();
        $row->forceFill([
            'campaign_id' => $campaign->getKey(),
            'test_code' => $testCode,
            'decision' => $decision,
            'note' => $note === '' ? null : $note,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
        ])->save();
        Notification::make()->success()->title("{$testCode}: decisión registrada")->send();
    }

    public function exportCsv(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $campaign = $this->campaign();
        if ($campaign === null) {
            return null;
        }
        $rows = CiraEvaluation::query()->with('invitation:id,pseudonym')->where('campaign_id', $campaign->getKey())->orderBy('id')->get();
        $ratings = config('cira_evaluation.ratings');

        return response()->streamDownload(function () use ($rows, $ratings) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'prueba', 'evaluador', 'estado', 'valoracion', 'motivo', 'busqueda', 'registro', 'version_motor', 'sobran_ids', 'faltan_ids', 'faltan_texto', 'comentario', 'observacion', 'hallazgo', 'actualizada']);
            foreach ($rows as $e) {
                // Leading = + - @ would be read as formulas by a spreadsheet: neutralise them.
                $safe = fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
                fputcsv($out, array_map($safe, [
                    $e->id, $e->test_code, $e->invitation?->pseudonym, $e->status, $ratings[$e->rating] ?? '', $e->reason, $e->query_text,
                    $e->snapshot['search_log_id'] ?? '', $e->snapshot['engine_version'] ?? '', implode(' ', $e->extra_empresa_ids),
                    implode(' ', $e->missing_empresa_ids), $e->missing_text, $e->comment, $e->observation, $e->finding_category,
                    $e->updated_at?->toIso8601String(),
                ]));
            }
            fclose($out);
        }, 'evaluacion-cira-'.$campaign->code.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function getViewData(): array
    {
        $campaign = $this->campaign();

        return [
            'campaigns' => CiraEvalCampaign::query()->orderByDesc('id')->get(['id', 'code', 'name', 'status']),
            'campaign' => $campaign,
            'report' => $campaign ? app(CiraEvaluationService::class)->report($campaign) : null,
            'decisionOptions' => config('cira_evaluation.decisions'),
        ];
    }
}
