<?php

namespace App\Filament\Resources\EmpresaResource\Concerns;

use App\Models\Empresa;
use App\Support\Security\EmpresaAccess;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * CPV-SEC-01-R3 (Issue #2 `6316775771`, F-SEC01-R3-01): the company edit page and its relation managers re-check, on
 * EVERY Livewire request, that the current user may still edit the company.
 *
 * Before: the company link was only applied when the page resolved its record (EmpresaResource::getEloquentQuery());
 * the module policies check permissions only, and several module actions have no authorization of their own. A page
 * (or a saved snapshot of it) opened while the user was linked kept writing after the link was revoked or the edit
 * permission withdrawn. Livewire runs `hydrate{Trait}` on every request after the first, so the check now precedes
 * any action; relation managers also check when they mount (defence in depth: the owner record is server-issued).
 * A refusal is the same 404 the user gets for a company that is not theirs.
 */
trait ReauthorizesEmpresaAccess
{
    public function hydrateReauthorizesEmpresaAccess(): void
    {
        $this->ensureEmpresaAccess();
    }

    protected function ensureEmpresaAccess(): void
    {
        $empresa = $this instanceof RelationManager ? $this->getOwnerRecord() : $this->getRecord();

        abort_unless($empresa instanceof Empresa && EmpresaAccess::mayEdit(auth()->user(), $empresa), 404);
    }
}
