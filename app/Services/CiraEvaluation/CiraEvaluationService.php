<?php

namespace App\Services\CiraEvaluation;

use App\Models\Cira\CiraEvalCampaign;
use App\Models\Cira\CiraEvalInvitation;
use App\Models\Cira\CiraEvaluation;
use App\Models\Cira\CiraSearchFeedback;
use App\Models\Cira\CiraSearchLog;
use App\Models\User;
use App\Support\Security\PrivilegedRoleGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0027 (Issue #2 `6852945146`): the rules of the CIRA evaluation campaign, in one place.
 *
 * - Access: an evaluator is an EXISTING user with an active invitation (not revoked, not expired) to an open campaign,
 *   read fresh at every request. The internal panel is for the privileged role only. No global role or permission.
 * - Evidence: an evaluation of a logged search keeps an immutable snapshot of what the engine returned (query, ids,
 *   positions, evidence, engine version), built from `cira_search_log`; names are resolved at that moment. A search that
 *   was not logged can still be evaluated, and the snapshot says so instead of inventing data.
 * - Ownership of a search: a logged search can be evaluated only from the browser session that ran it (its CIRA Test
 *   session id equals `cira_search_log.session_ref`), so nobody evaluates (or reads) someone else's search by its id.
 * - Votes: 👍/👎 stay in `cira_search_feedback`; this module only reads them by `search_log_id`, never copies them.
 * - Idempotency: one row per (invitation, test, logged search) and per client token; a double click, a reload or a
 *   second tab update the same row.
 */
class CiraEvaluationService
{
    public static function testCodes(): array
    {
        return array_keys(config('cira_evaluation.tests'));
    }

    public static function isPanelUser(?User $user): bool
    {
        return PrivilegedRoleGuard::userHasPrivilegedRole($user);
    }

    /** The user's active invitation, queried fresh (never from a cached relation). */
    public function activeInvitation(?User $user): ?CiraEvalInvitation
    {
        if (! $user instanceof User) {
            return null;
        }
        $now = now();

        return CiraEvalInvitation::query()
            ->with('campaign')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->whereHas('campaign', fn ($q) => $q->where('status', 'open')
                ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $now)))
            ->orderByDesc('id')
            ->first();
    }

    public function requireInvitation(?User $user): CiraEvalInvitation
    {
        $invitation = $this->activeInvitation($user);
        if ($invitation === null) {
            throw new AuthorizationException('No tiene una invitación vigente a una campaña de evaluación de CIRA.');
        }

        return $invitation;
    }

    /** A CIRA Test session id: the page's own format, nothing else. */
    public static function isValidSid(?string $sid): bool
    {
        return is_string($sid) && preg_match('/^[A-Za-z0-9_.:-]{8,100}$/', $sid) === 1;
    }

    /** The logged search, only if it belongs to the browser session `$sid`. Null otherwise (same answer either way). */
    public function claimableLog(?int $logId, ?string $sid): ?CiraSearchLog
    {
        if (! $logId || $logId < 1 || ! self::isValidSid($sid)) {
            return null;
        }
        $log = CiraSearchLog::query()->find($logId);
        if ($log === null || ! is_string($log->session_ref) || $log->session_ref === '') {
            return null;
        }

        return hash_equals($log->session_ref, (string) $sid) ? $log : null;
    }

    /** id => name of affiliate companies, read straight from the table (no model scopes), id and name only. */
    public static function companyNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return DB::connection('pgsql')->table('empresas')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** Company name search for «empresa faltante»: at least 3 characters, 20 results, id and name only. */
    public static function searchCompanies(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 3) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return DB::connection('pgsql')->table('empresas')->where('name', 'ilike', $like)->orderBy('name')->limit(20)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    public function snapshotFromLog(CiraSearchLog $log): array
    {
        $ids = $log->result_empresa_ids;
        $names = self::companyNames($ids);
        $evidence = collect(is_array($log->result_evidence) ? $log->result_evidence : [])
            ->filter(fn ($e) => is_array($e) && isset($e['id']))
            ->keyBy(fn ($e) => (int) $e['id']);
        $results = [];
        foreach (array_values($ids) as $i => $id) {
            $e = $evidence->get($id, []);
            $results[] = [
                'position' => $i + 1,
                'empresa_id' => $id,
                'name' => $names[$id] ?? null,
                'evidence' => [
                    'rel' => $e['rel'] ?? null,
                    'via' => $e['via'] ?? null,
                    'puntaje' => $e['puntaje'] ?? null,
                    'fuentes' => $e['fuentes'] ?? null,
                ],
            ];
        }
        $params = is_array($log->agent_params) ? $log->agent_params : [];

        return [
            'version' => 1,
            'search_log_id' => (int) $log->id,
            'logged_at' => $log->created_at?->toIso8601String(),
            'source' => $log->source,
            'user_input' => $log->user_input,
            'agent_tool' => $log->agent_tool,
            'agent_query' => isset($params['query']) && is_string($params['query']) ? $params['query'] : null,
            'interpretation' => $log->interpretation,
            'result_count' => $log->result_count,
            'engine_version' => $log->engine_version,
            'response_ms' => $log->response_ms,
            'error' => $log->error,
            'results' => $results,
            'unavailable' => null,
        ];
    }

    public function snapshotWithoutLog(string $query): array
    {
        return [
            'version' => 1,
            'search_log_id' => null,
            'logged_at' => null,
            'source' => null,
            'user_input' => $query,
            'agent_tool' => null,
            'agent_query' => null,
            'interpretation' => null,
            'result_count' => null,
            'engine_version' => null,
            'response_ms' => null,
            'error' => null,
            'results' => null,
            'unavailable' => 'La búsqueda no quedó registrada: no hay resultados, posiciones ni versión del motor que guardar.',
        ];
    }

    /** SHA-256 of the snapshot in canonical form (keys sorted), so it verifies after the JSONB round trip. */
    public static function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => self::canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** The evaluator's own evaluation, or null (another evaluator's id answers the same as a missing one). */
    public function ownEvaluation(CiraEvalInvitation $invitation, ?int $evaluationId): ?CiraEvaluation
    {
        if (! $evaluationId) {
            return null;
        }

        return CiraEvaluation::query()->where('id', $evaluationId)->where('invitation_id', $invitation->getKey())->first();
    }

    public function existingFor(CiraEvalInvitation $invitation, string $testCode, ?int $logId): ?CiraEvaluation
    {
        if (! $logId) {
            return null;
        }

        return CiraEvaluation::query()->where('invitation_id', $invitation->getKey())->where('test_code', $testCode)->where('search_log_id', $logId)->first();
    }

    /**
     * Validates the judgement fields. `$resultIds` are the ids of the snapshot (extra results must come from them).
     *
     * @return array<string, mixed> clean values
     */
    public function cleanJudgement(array $input, ?array $resultIds, bool $submit): array
    {
        $errors = [];
        $text = function (?string $value, int $max, string $field) use (&$errors): ?string {
            $value = $value === null ? null : trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
            if ($value === null || $value === '') {
                return null;
            }
            if (mb_strlen($value) > $max) {
                $errors[$field] = "Máximo {$max} caracteres.";
            }

            return mb_substr($value, 0, $max);
        };

        $test = (string) ($input['test_code'] ?? '');
        if (! in_array($test, self::testCodes(), true)) {
            $errors['test_code'] = 'Elija una de las 8 pruebas.';
        }
        $rating = $input['rating'] ?? null;
        $rating = $rating === '' ? null : $rating;
        if ($rating !== null && ! array_key_exists($rating, config('cira_evaluation.ratings'))) {
            $errors['rating'] = 'Valoración no válida.';
        }
        if ($submit && $rating === null) {
            $errors['rating'] = 'Indique si el resultado es Correcto, Parcial o Incorrecto.';
        }
        $reason = $input['reason'] ?? null;
        $reason = $reason === '' ? null : $reason;
        if ($reason !== null && ! array_key_exists($reason, config('cira_evaluation.reasons'))) {
            $errors['reason'] = 'Motivo no válido.';
        }

        $extra = array_values(array_unique(array_map('intval', array_filter((array) ($input['extra_empresa_ids'] ?? []), 'is_numeric'))));
        if ($extra !== [] && ($resultIds === null || array_diff($extra, $resultIds) !== [])) {
            $errors['extra_empresa_ids'] = 'Solo se pueden marcar como sobrantes resultados de esta búsqueda.';
        }
        $missing = array_values(array_unique(array_map('intval', array_filter((array) ($input['missing_empresa_ids'] ?? []), 'is_numeric'))));
        if (count($missing) > 50) {
            $errors['missing_empresa_ids'] = 'Máximo 50 empresas faltantes.';
        } elseif ($missing !== [] && count(self::companyNames($missing)) !== count($missing)) {
            $errors['missing_empresa_ids'] = 'Alguna empresa faltante no existe.';
        }

        $clean = [
            'test_code' => $test,
            'rating' => $rating,
            'reason' => $reason,
            'extra_empresa_ids' => $extra,
            'missing_empresa_ids' => $missing,
            'missing_text' => $text($input['missing_text'] ?? null, 1000, 'missing_text'),
            'comment' => $text($input['comment'] ?? null, 2000, 'comment'),
            'observation' => $text($input['observation'] ?? null, 2000, 'observation'),
        ];
        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ["data.{$k}" => $m])->all());
        }

        return $clean;
    }

    /**
     * Creates or updates the evaluator's evaluation. Exactly one of `$log` / `$freeQuery` describes the search for a new
     * row; for an existing row the evidence is never touched.
     */
    public function save(CiraEvalInvitation $invitation, array $input, ?CiraEvaluation $existing, ?CiraSearchLog $log, ?string $freeQuery, string $clientToken, bool $submit): CiraEvaluation
    {
        if (! Str::isUuid($clientToken)) {
            throw ValidationException::withMessages(['data.test_code' => 'Recargue la página e intente de nuevo.']);
        }
        if ($existing !== null && (int) $existing->invitation_id !== (int) $invitation->getKey()) {
            throw new AuthorizationException('Esta evaluación no es suya.');
        }

        $snapshot = $existing?->snapshot;
        if ($existing === null) {
            if ($log !== null) {
                $snapshot = $this->snapshotFromLog($log);
            } else {
                $query = trim((string) $freeQuery);
                if ($query === '' || mb_strlen($query) > 2000) {
                    throw ValidationException::withMessages(['data.query_text' => 'Escriba la búsqueda que hizo en CIRA (máximo 2000 caracteres).']);
                }
                $snapshot = $this->snapshotWithoutLog($query);
            }
        }
        $resultIds = is_array($snapshot['results'] ?? null) ? array_map(fn ($r) => (int) $r['empresa_id'], $snapshot['results']) : null;
        $clean = $this->cleanJudgement($input, $resultIds, $submit);
        if ($existing !== null && $existing->test_code !== $clean['test_code']) {
            // The test is part of what was evaluated; changing it means a new evaluation.
            throw ValidationException::withMessages(['data.test_code' => 'La prueba de una evaluación guardada no se cambia. Cree una evaluación nueva.']);
        }

        $judgement = $clean + [
            'status' => $submit ? 'enviada' : ($existing?->status === 'enviada' ? 'enviada' : 'borrador'),
        ];

        return DB::connection('pgsql')->transaction(function () use ($invitation, $existing, $log, $snapshot, $clientToken, $judgement, $submit) {
            $row = $existing
                ?? $this->existingFor($invitation, $judgement['test_code'], $log?->id)
                ?? CiraEvaluation::query()->where('client_token', $clientToken)->where('invitation_id', $invitation->getKey())->first();

            if ($row === null) {
                $row = new CiraEvaluation();
                $row->forceFill([
                    'campaign_id' => $invitation->campaign_id,
                    'invitation_id' => $invitation->getKey(),
                    'search_log_id' => $log?->id,
                    'query_text' => mb_substr((string) $snapshot['user_input'], 0, 2000),
                    'snapshot' => $snapshot,
                    'snapshot_sha256' => self::snapshotHash($snapshot),
                    'client_token' => $clientToken,
                ]);
            }
            $row->forceFill($judgement);
            if ($submit && $row->submitted_at === null) {
                $row->submitted_at = now();
            }
            try {
                DB::connection('pgsql')->transaction(fn () => $row->save());
            } catch (QueryException $e) {
                // A concurrent request created the same row first (unique index): update that one instead.
                if (($e->errorInfo[0] ?? null) !== '23505' || $row->exists) {
                    throw $e;
                }
                $row = $this->existingFor($invitation, $judgement['test_code'], $log?->id)
                    ?? CiraEvaluation::query()->where('client_token', $clientToken)->where('invitation_id', $invitation->getKey())->firstOrFail();
                $row->forceFill($judgement);
                if ($submit && $row->submitted_at === null) {
                    $row->submitted_at = now();
                }
                $row->save();
            }

            return $row->refresh();
        });
    }

    /**
     * The 👍/👎 votes of a logged search, read from cira_search_feedback. `mine` marks the votes cast from the browser
     * session `$sid` (CIRA Test stores the session id, or `sid#falta-N`, as voter_ref). Others are pseudonymised.
     */
    public function votesForLog(?int $logId, ?string $sid = null): Collection
    {
        if (! $logId) {
            return collect();
        }

        return CiraSearchFeedback::query()->where('search_log_id', $logId)->orderBy('id')->get()->map(fn (CiraSearchFeedback $f) => [
            'empresa_id' => $f->empresa_id !== null ? (int) $f->empresa_id : null,
            'rank_position' => $f->rank_position,
            'vote' => (int) $f->vote,
            'reason' => $f->reason,
            'comment' => $f->comment,
            'missing_empresa_id' => $f->missing_empresa_id !== null ? (int) $f->missing_empresa_id : null,
            'voter_kind' => $f->voter_kind,
            'voter' => 'V-'.substr(hash('sha256', (string) $f->voter_ref), 0, 6),
            'mine' => $sid !== null && is_string($f->voter_ref) && hash_equals(explode('#', $f->voter_ref)[0], $sid),
            'created_at' => $f->created_at,
        ]);
    }

    /** Progress of one evaluator: per test, submitted and draft counts. */
    public function progress(CiraEvalInvitation $invitation): array
    {
        $rows = CiraEvaluation::query()->where('invitation_id', $invitation->getKey())
            ->selectRaw("test_code, count(*) filter (where status = 'enviada') as sent, count(*) filter (where status = 'borrador') as drafts")
            ->groupBy('test_code')->get()->keyBy('test_code');
        $out = [];
        foreach (config('cira_evaluation.tests') as $code => $test) {
            $out[$code] = ['title' => $test['title'], 'sent' => (int) ($rows[$code]->sent ?? 0), 'drafts' => (int) ($rows[$code]->drafts ?? 0)];
        }

        return $out;
    }

    /**
     * Consolidated report of a campaign. Counts submitted evaluations only; the Chamber's decision per test is read from
     * cira_eval_test_decisions and is never inferred from the counts.
     */
    public function report(CiraEvalCampaign $campaign): array
    {
        $evaluations = CiraEvaluation::query()->with('invitation:id,pseudonym')->where('campaign_id', $campaign->getKey())->orderBy('id')->get();
        $sent = $evaluations->where('status', 'enviada');
        $decisions = $campaign->decisions()->get()->keyBy('test_code');

        $tests = [];
        foreach (config('cira_evaluation.tests') as $code => $test) {
            $rows = $sent->where('test_code', $code);
            $tests[$code] = [
                'title' => $test['title'],
                'correcta' => $rows->where('rating', 'correcta')->count(),
                'parcial' => $rows->where('rating', 'parcial')->count(),
                'incorrecta' => $rows->where('rating', 'incorrecta')->count(),
                'drafts' => $evaluations->where('test_code', $code)->where('status', 'borrador')->count(),
                'evaluators' => $rows->pluck('invitation_id')->unique()->count(),
                'decision' => $decisions[$code]->decision ?? null,
                'decision_note' => $decisions[$code]->note ?? null,
            ];
        }

        $countIds = function (string $field) use ($sent): array {
            $counts = [];
            foreach ($sent as $e) {
                foreach ($e->{$field} as $id) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
            arsort($counts);
            $names = self::companyNames(array_keys($counts));

            return collect($counts)->map(fn ($n, $id) => ['empresa_id' => (int) $id, 'name' => $names[$id] ?? null, 'count' => $n])->values()->all();
        };

        // Votes of the campaign's logged searches. A vote is "linked" when its company is one of that search's results
        // (or it is a whole-result / missing-company report); otherwise it is shown as not linked, never re-attributed.
        $byLog = $evaluations->whereNotNull('search_log_id')->groupBy('search_log_id');
        $votes = ['up' => 0, 'down' => 0, 'missing_reports' => 0, 'linked' => 0, 'unlinked' => 0];
        if ($byLog->isNotEmpty()) {
            foreach (CiraSearchFeedback::query()->whereIn('search_log_id', $byLog->keys()->all())->get() as $f) {
                $f->vote === 1 ? $votes['up']++ : $votes['down']++;
                if ($f->reason === 'falta_empresa') {
                    $votes['missing_reports']++;
                }
                $resultIds = collect($byLog[$f->search_log_id]->first()->snapshot['results'] ?? [])->pluck('empresa_id')->map(fn ($i) => (int) $i)->all();
                ($f->empresa_id === null || in_array((int) $f->empresa_id, $resultIds, true)) ? $votes['linked']++ : $votes['unlinked']++;
            }
        }

        return [
            'campaign' => ['code' => $campaign->code, 'name' => $campaign->name, 'status' => $campaign->status],
            'totals' => [
                'sent' => $sent->count(),
                'drafts' => $evaluations->where('status', 'borrador')->count(),
                'evaluators' => $evaluations->pluck('invitation_id')->unique()->count(),
                'invited' => $campaign->invitations()->whereNull('revoked_at')->count(),
                'without_log' => $evaluations->whereNull('search_log_id')->count(),
            ],
            'tests' => $tests,
            'missing' => $countIds('missing_empresa_ids'),
            'extra' => $countIds('extra_empresa_ids'),
            'votes' => $votes,
            'findings' => collect(config('cira_evaluation.finding_categories'))->map(fn ($label, $key) => ['label' => $label, 'count' => $sent->where('finding_category', $key)->count()])->all()
                + ['sin_clasificar' => ['label' => 'Sin clasificar', 'count' => $sent->whereNull('finding_category')->count()]],
            'comments' => $sent->filter(fn ($e) => filled($e->comment) || filled($e->observation))->map(fn ($e) => [
                'id' => $e->id, 'test' => $e->test_code, 'evaluator' => $e->invitation?->pseudonym, 'rating' => $e->rating,
                'comment' => $e->comment, 'observation' => $e->observation,
            ])->values()->all(),
        ];
    }

    /** Next free pseudonym of a campaign: E-01, E-02, ... */
    public static function nextPseudonym(CiraEvalCampaign $campaign): string
    {
        $max = CiraEvalInvitation::query()->where('campaign_id', $campaign->getKey())->pluck('pseudonym')
            ->map(fn ($p) => (int) substr((string) $p, 2))->max() ?? 0;

        return sprintf('E-%02d', $max + 1);
    }
}
