<?php

namespace App\Filament\Resources\TaxonomyConceptRelationResource\Pages;

use App\Filament\Resources\TaxonomyConceptRelationResource;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B.1 (sección 12 del pedido): mismo motivo que CreateTaxonomyConceptRelation - si un
 * revisor cambia origen/destino/tipo de una relación existente, la validación de seguridad
 * (duplicado/ciclo) se re-evalúa contra el estado real, ignorando la fila que se está editando
 * (si no, la propia fila que se edita siempre "chocaría consigo misma" como duplicado exacto).
 *
 * TASK-0003, hallazgo 6 (corrección): el chequeo `$unchanged` original solo miraba
 * origen/destino/tipo - aprobar una relación (`status: candidate -> approved`) SIN tocar esos tres
 * campos se consideraba "sin cambios" y se guardaba SIN revalidar. Ese es exactamente el escenario
 * que el orquestador señaló: "a candidate may be valid when queued and stale when reviewed" - otra
 * relación pudo aprobarse mientras esta esperaba revisión, y la aprobación pasaba igual. Ahora
 * también se revalida cuando `status` pasa a `approved`, sin importar si origen/destino/tipo
 * cambiaron. (Ver también `TaxonomyConceptRelation::booted()` - guard de modelo como respaldo para
 * cualquier punto de entrada que no pase por esta página.)
 */
class EditTaxonomyConceptRelation extends EditRecord
{
    protected static string $resource = TaxonomyConceptRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $endpointsUnchanged = (int) $record->source_concept_id === (int) $data['source_concept_id']
            && (int) $record->target_concept_id === (int) $data['target_concept_id']
            && $record->relation_type === $data['relation_type'];

        // Nota: se compara contra $data['status'] directamente, no "¿$record->status cambió?" -
        // para cuando esto se evalúa, Filament ya puede haber sincronizado atributos del form al
        // modelo en memoria, lo que vuelve indistinguible "ya estaba approved" de "se está por
        // aprobar" si se compara contra $record->status acá. Revalidar un approved sin cambios es
        // barato e inofensivo (se excluye a sí mismo, siempre pasa) - no hace falta la comparación.
        $approving = $data['status'] === $record::STATUS_APPROVED;

        if (! $endpointsUnchanged || $approving) {
            $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
                (int) $data['source_concept_id'],
                (int) $data['target_concept_id'],
                (string) $data['relation_type'],
                excludeId: $record->id,
            );

            if (! $validation['valid']) {
                Notification::make()
                    ->title('No se pudo actualizar la relación')
                    ->body(CreateTaxonomyConceptRelation::validationFailureMessage($validation['reason']))
                    ->danger()
                    ->send();

                throw new Halt();
            }
        }

        $record->update($data);

        return $record;
    }
}
