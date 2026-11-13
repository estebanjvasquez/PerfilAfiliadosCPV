<?php

namespace App\Filament\Pages\Concerns;

use App\Services\CiraEvaluation\CiraEvaluationService;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0027: the evaluator pages are for users with an ACTIVE invitation (not revoked, not expired, campaign open).
 * Checked on every Livewire request (mount and every later update/action), not only when the page is opened, so a
 * revoked invitation or a closed campaign stops a page that is already open (the lesson of F-SEC01-R3-02).
 */
trait AuthorizesCiraEvaluator
{
    public static function canAccess(): bool
    {
        return app(CiraEvaluationService::class)->activeInvitation(Auth::user()) !== null;
    }

    public function bootAuthorizesCiraEvaluator(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function service(): CiraEvaluationService
    {
        return app(CiraEvaluationService::class);
    }
}
