<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesCiraEvaluator;
use App\Models\Cira\CiraEvaluation;
use App\Models\Cira\CiraSearchLog;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * TASK-0027: evaluate one CIRA search for one of the 8 guided tests.
 *
 * Entry from CIRA Test («Evaluar esta búsqueda»): `?log=<search_log_id>&sid=<CIRA Test session>`. The search is shown
 * and linked only if it belongs to that browser session (CiraEvaluationService::claimableLog); otherwise nothing of it is
 * shown and the evaluator can still evaluate without a log, writing the query. `?evaluacion=<id>` reopens one of the
 * evaluator's OWN evaluations (another evaluator's id answers 404). The ids that decide what is evaluated are #[Locked]:
 * the browser cannot change them between requests.
 */
class CiraEvaluationFormPage extends Page implements HasForms
{
    use AuthorizesCiraEvaluator;
    use InteractsWithForms;

    protected static ?string $title = 'Evaluar una búsqueda de CIRA';

    protected static ?string $slug = 'evaluacion-cira/evaluar';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.cira-evaluation-form';

    #[Locked]
    public ?int $logId = null;

    #[Locked]
    public ?string $sid = null;

    #[Locked]
    public ?int $evaluationId = null;

    #[Locked]
    public string $clientToken = '';

    #[Locked]
    public bool $logRefused = false;

    public ?array $data = [];

    public function mount(): void
    {
        $invitation = $this->service()->requireInvitation(Auth::user());
        $request = request();

        $evaluationId = ctype_digit((string) $request->query('evaluacion')) ? (int) $request->query('evaluacion') : null;
        if ($evaluationId) {
            $evaluation = $this->service()->ownEvaluation($invitation, $evaluationId);
            abort_if($evaluation === null, 404);
            $this->loadEvaluation($evaluation);

            return;
        }

        $logId = ctype_digit((string) $request->query('log')) ? (int) $request->query('log') : null;
        if ($logId) {
            $sid = (string) $request->query('sid', '');
            $log = $this->service()->claimableLog($logId, $sid);
            if ($log === null) {
                $this->logRefused = true;
            } else {
                $this->logId = (int) $log->id;
                $this->sid = $sid;
            }
        }

        $test = in_array($request->query('test'), CiraEvaluationService::testCodes(), true) ? (string) $request->query('test') : null;
        if ($test !== null && $this->logId !== null && ($existing = $this->service()->existingFor($invitation, $test, $this->logId))) {
            $this->loadEvaluation($existing);

            return;
        }

        $this->clientToken = (string) Str::uuid();
        $this->form->fill(['test_code' => $test, 'extra_empresa_ids' => [], 'missing_empresa_ids' => []]);
    }

    private function loadEvaluation(CiraEvaluation $evaluation): void
    {
        $this->evaluationId = (int) $evaluation->id;
        $this->logId = $evaluation->search_log_id !== null ? (int) $evaluation->search_log_id : null;
        $this->clientToken = (string) $evaluation->client_token;
        $this->form->fill([
            'test_code' => $evaluation->test_code,
            'rating' => $evaluation->rating,
            'extra_empresa_ids' => array_map('strval', $evaluation->extra_empresa_ids),
            'missing_empresa_ids' => array_map('strval', $evaluation->missing_empresa_ids),
            'missing_text' => $evaluation->missing_text,
            'reason' => $evaluation->reason,
            'comment' => $evaluation->comment,
            'observation' => $evaluation->observation,
        ]);
    }

    /** What is being evaluated: the saved snapshot, the live log (before the first save) or nothing (free search). */
    public function snapshot(): ?array
    {
        if ($this->evaluationId) {
            $invitation = $this->service()->requireInvitation(Auth::user());

            return $this->service()->ownEvaluation($invitation, $this->evaluationId)?->snapshot;
        }
        if ($this->logId) {
            $log = CiraSearchLog::query()->find($this->logId);

            return $log ? $this->service()->snapshotFromLog($log) : null;
        }

        return null;
    }

    public function isFreeSearch(): bool
    {
        $snapshot = $this->snapshot();

        return $snapshot === null || ($snapshot['search_log_id'] ?? null) === null;
    }

    public function form(Form $form): Form
    {
        $snapshot = $this->snapshot();
        $results = is_array($snapshot['results'] ?? null) ? $snapshot['results'] : [];
        $resultOptions = collect($results)->mapWithKeys(fn ($r) => [
            (string) $r['empresa_id'] => '#'.$r['position'].' — '.($r['name'] ?? 'empresa ya no disponible (id '.$r['empresa_id'].')'),
        ])->all();

        return $form
            ->schema([
                Select::make('test_code')
                    ->label('Prueba')
                    ->options(collect(config('cira_evaluation.tests'))->mapWithKeys(fn ($t, $code) => [$code => $code.' · '.$t['title']])->all())
                    ->required()
                    ->native() // plain <select>: accessible on phones, keyboard and screen readers
                    ->disabled(fn () => $this->evaluationId !== null)
                    ->dehydrated()
                    ->helperText(fn () => $this->evaluationId !== null ? 'La prueba de una evaluación guardada no se cambia.' : 'Elija a cuál de las 8 pruebas corresponde esta búsqueda.'),
                TextInput::make('query_text')
                    ->label('Búsqueda que hizo en CIRA')
                    ->helperText('Esta búsqueda no quedó registrada: escríbala tal como la hizo. Los resultados no se guardan porque no hay registro de ellos.')
                    ->maxLength(2000)
                    ->required(fn () => $this->isFreeSearch() && $this->evaluationId === null)
                    ->visible(fn () => $this->isFreeSearch() && $this->evaluationId === null),
                Radio::make('rating')
                    ->label('Valoración del resultado')
                    ->options(config('cira_evaluation.ratings'))
                    ->inline()
                    ->helperText('Correcta: lo que esperaba. Parcial: útil, pero falta o sobra algo. Incorrecta: no sirve para lo buscado. Obligatoria para enviar.'),
                CheckboxList::make('extra_empresa_ids')
                    ->label('Resultados que sobran (no corresponden a lo buscado)')
                    ->options($resultOptions)
                    ->columns(1)
                    ->visible($resultOptions !== []),
                Select::make('missing_empresa_ids')
                    ->label('Empresas que faltan (afiliadas que deberían aparecer)')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => CiraEvaluationService::searchCompanies($search))
                    ->getOptionLabelsUsing(fn (array $values) => CiraEvaluationService::companyNames($values))
                    ->helperText('Escriba al menos 3 letras del nombre.'),
                Textarea::make('missing_text')
                    ->label('Otras empresas faltantes (si no las encuentra en la lista)')
                    ->maxLength(1000)
                    ->rows(2),
                Select::make('reason')
                    ->label('Motivo principal')
                    ->options(config('cira_evaluation.reasons'))
                    ->native(),
                Textarea::make('comment')
                    ->label('Comentario')
                    ->helperText('Qué esperaba y qué vio. Por ejemplo: «Esperaba ver primero a las empresas de transporte pesado; la tercera es de alimentos».')
                    ->maxLength(2000)
                    ->rows(3),
                Textarea::make('observation')
                    ->label('Observación adicional (opcional)')
                    ->maxLength(2000)
                    ->rows(2),
            ])
            ->statePath('data');
    }

    public function saveDraft(): void
    {
        $this->persist(false);
    }

    public function submit(): void
    {
        $this->persist(true);
    }

    private function persist(bool $submit): void
    {
        $invitation = $this->service()->requireInvitation(Auth::user());
        $state = $this->form->getState();

        $existing = null;
        if ($this->evaluationId) {
            $existing = $this->service()->ownEvaluation($invitation, $this->evaluationId);
            abort_if($existing === null, 404);
        }
        $log = null;
        if ($existing === null && $this->logId) {
            $log = $this->service()->claimableLog($this->logId, $this->sid);
            abort_if($log === null, 403);
        }

        $evaluation = $this->service()->save($invitation, $state, $existing, $log, $state['query_text'] ?? null, $this->clientToken, $submit);
        $this->evaluationId = (int) $evaluation->id;
        $this->logId = $evaluation->search_log_id !== null ? (int) $evaluation->search_log_id : null;
        $this->clientToken = (string) $evaluation->client_token;

        Notification::make()->success()
            ->title($evaluation->status === 'enviada' ? 'Evaluación enviada' : 'Borrador guardado')
            ->body($evaluation->status === 'enviada'
                ? 'Gracias. Puede volver a abrirla desde «Mis pruebas» para corregirla.'
                : 'Puede seguir más tarde desde «Mis pruebas».')
            ->send();
    }

    protected function getViewData(): array
    {
        $snapshot = $this->snapshot();
        $votes = $this->service()->votesForLog($snapshot['search_log_id'] ?? null, $this->sid);

        return [
            'snapshot' => $snapshot,
            'votesByCompany' => $votes->whereNotNull('empresa_id')->groupBy('empresa_id'),
            'otherVotes' => $votes->whereNull('empresa_id')->values(),
            'tests' => config('cira_evaluation.tests'),
            'reasonsCira' => ['otro_sector' => 'Otro sector', 'solo_coincide_palabra' => 'Solo coincide una palabra', 'empresa_inactiva' => 'Empresa inactiva', 'falta_empresa' => 'Falta una empresa', 'otro' => 'Otro'],
            'status' => $this->evaluationId ? CiraEvaluation::query()->whereKey($this->evaluationId)->value('status') : null,
        ];
    }
}
