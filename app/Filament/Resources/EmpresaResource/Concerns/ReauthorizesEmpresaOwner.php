<?php

namespace App\Filament\Resources\EmpresaResource\Concerns;

/**
 * CPV-SEC-01-R3 (F-SEC01-R3-01): ReauthorizesEmpresaAccess for the company's relation managers, plus the same check
 * when the manager mounts (its owner record is set by the page; this refuses a manager bound to a company the user may
 * not edit, whatever bound it).
 */
trait ReauthorizesEmpresaOwner
{
    use ReauthorizesEmpresaAccess;

    public function mount(): void
    {
        parent::mount();

        $this->ensureEmpresaAccess();
    }
}
