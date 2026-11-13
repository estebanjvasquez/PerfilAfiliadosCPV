<?php

namespace App\Policies;

use App\Models\User;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-0027: the internal panel of the CIRA evaluation (campaigns, invitations, evaluations, report) is for the privileged
 * role only, read fresh (PrivilegedRoleGuard). No new global role or permission is created. Nothing is deleted from the
 * panel: campaigns are closed, invitations revoked, and evaluations are evidence. Evaluators never use these abilities:
 * they reach only their own evaluations through the evaluator pages.
 *
 * One class serves the three models (campaign, invitation, evaluation): the rule is the same.
 */
class CiraEvaluationPanelPolicy
{
    private function panel(User $user): bool
    {
        return CiraEvaluationService::isPanelUser($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->panel($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->panel($user);
    }

    public function create(User $user): bool
    {
        return $this->panel($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->panel($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
