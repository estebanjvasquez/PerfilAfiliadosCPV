<?php

namespace App\Filament\Resources\Concerns;

use App\Services\TaxonomyAuditLogger;

/**
 * TAXV2-9/TAXV2-11: captura el estado del registro ANTES de guardar (`beforeSave`, cuando
 * `getOriginal()` todavía es confiable) y lo compara contra el estado después de guardar
 * (`afterSave`) - ver el docblock de `TaxonomyAuditLogger::recordModelChanges()` para el bug real
 * que este trait evita (leer `getOriginal()` en `afterSave()` directamente devuelve el valor NUEVO,
 * no el viejo, porque Eloquent ya resincronizó "original" para ese momento).
 *
 * Se usa en las páginas Edit de las Resources del diccionario V2 (`EditTaxonomySource`,
 * `EditTaxonomyTerm`, `EditTaxonomyTermCpvRelation`, `EditLegacyServiceCpvRelation`,
 * `EditLegacyService`) - todas comparten exactamente esta lógica de auditoría.
 */
trait LogsAuditOnSave
{
    protected array $auditBeforeSnapshot = [];

    protected function beforeSave(): void
    {
        $this->auditBeforeSnapshot = $this->record->getOriginal();
    }

    protected function afterSave(): void
    {
        TaxonomyAuditLogger::recordModelChanges($this->record, $this->auditBeforeSnapshot);
    }
}
