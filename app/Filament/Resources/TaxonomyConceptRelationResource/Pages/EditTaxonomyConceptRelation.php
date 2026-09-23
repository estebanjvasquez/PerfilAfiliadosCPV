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
        $unchanged = (int) $record->source_concept_id === (int) $data['source_concept_id']
            && (int) $record->target_concept_id === (int) $data['target_concept_id']
            && $record->relation_type === $data['relation_type'];

        if (! $unchanged) {
            $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
                (int) $data['source_concept_id'],
                (int) $data['target_concept_id'],
                (string) $data['relation_type'],
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
