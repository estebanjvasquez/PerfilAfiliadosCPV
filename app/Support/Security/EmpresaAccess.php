<?php

namespace App\Support\Security;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * CPV-SEC-01-R3 (Issue #2 `6316775771`, F-SEC01-R3-01): may this actor edit this company RIGHT NOW?
 *
 * The same conditions the company edit page applies when it opens — authenticated, verified, linked to the company
 * (`empresa_user`) and holding the company edit permission (EmpresaPolicy::update) — read fresh, so a later Livewire
 * request from a page that was opened before a revocation is judged on the current state. The link is read from the
 * pivot directly: `Empresa::users()` dereferences the session user and fails without one.
 */
final class EmpresaAccess
{
    public static function mayEdit(?Authenticatable $actor, Empresa $empresa): bool
    {
        if (! $actor instanceof User || ! $actor->hasVerifiedEmail()) {
            return false;
        }

        $linked = DB::connection($empresa->getConnectionName())
            ->table('empresa_user')
            ->where('empresa_id', $empresa->getKey())
            ->where('user_id', $actor->getKey())
            ->exists();

        return $linked && $actor->can('update', $empresa);
    }
}
