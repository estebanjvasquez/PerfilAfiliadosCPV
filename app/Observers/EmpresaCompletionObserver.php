<?php

namespace App\Observers;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Mantiene actualizada la columna cacheada empresas.completion_percentage
 * (ver migracion 2026_09_01_130000 y Empresa::refreshCompletionPercentage()).
 *
 * Un mismo observer generico para Asset/Management/EmpresaModuleStatus/
 * Presence/Experience/Sustainability - todos tienen una columna empresa_id
 * (aunque el nombre del metodo de relacion inversa varia: "empresa()" en la
 * mayoria, "empresas()" en Sustainability), asi que se resuelve por la FK
 * directamente en vez de por el nombre de la relacion.
 *
 * NO cubre servicios/contactos (belongsToMany via attach/detach) - esos
 * pivots no disparan eventos de modelo en Laravel 9. Se refrescan aparte con
 * hooks ->after() en ServicesRelationManager/ContactsRelationManager (ver
 * AppServiceProvider::boot() para el registro completo).
 */
class EmpresaCompletionObserver
{
    public function saved(Model $model): void
    {
        $this->refresh($model);
    }

    public function deleted(Model $model): void
    {
        $this->refresh($model);
    }

    private function refresh(Model $model): void
    {
        $empresaId = $model->getAttribute('empresa_id');

        if (blank($empresaId)) {
            return;
        }

        Empresa::find($empresaId)?->refreshCompletionPercentage();
    }
}
