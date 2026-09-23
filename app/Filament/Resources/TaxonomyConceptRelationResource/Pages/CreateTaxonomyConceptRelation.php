<?php

namespace App\Filament\Resources\TaxonomyConceptRelationResource\Pages;

use App\Filament\Resources\TaxonomyConceptRelationResource;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase B.1 (sección 12 del pedido): cuando un humano propone una relación concepto<->concepto a
 * mano (incluidos los 4 tipos direccionales/jerárquicos que el Builder nunca auto-propone - ver
 * `CanonicalConceptBuilderService::proposeConceptRelations()`), pasa por la MISMA validación
 * (`validateConceptRelationProposal()`: auto-relación, tipo inactivo/desconocido, duplicado exacto,
 * duplicado simétrico, duplicado vía inverso, ciclo) que ya protege las propuestas auto-generadas.
 * Antes de esta entrega, `CreateRecord` guardaba directo sin pasar por esta validación - un humano
 * podía crear un ciclo o un duplicado que el propio Builder ya sabía prevenir para sus propuestas.
 */
class CreateTaxonomyConceptRelation extends CreateRecord
{
    protected static string $resource = TaxonomyConceptRelationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
            (int) $data['source_concept_id'],
            (int) $data['target_concept_id'],
            (string) $data['relation_type'],
        );

        if (! $validation['valid']) {
            Notification::make()
                ->title('No se pudo crear la relación')
                ->body(self::validationFailureMessage($validation['reason']))
                ->danger()
                ->send();

            throw new Halt();
        }

        return static::getModel()::create($data);
    }

    public static function validationFailureMessage(?string $reason): string
    {
        return match ($reason) {
            'SELF_RELATION_NOT_ALLOWED' => 'Un concepto no puede relacionarse consigo mismo.',
            'UNKNOWN_OR_INACTIVE_RELATION_TYPE' => 'El tipo de relación no existe o no está activo en el catálogo de gobernanza.',
            'DUPLICATE' => 'Ya existe exactamente esta relación (mismo origen, destino y tipo).',
            'DUPLICATE_VIA_SYMMETRY' => 'Ya existe esta relación en sentido inverso - al ser un tipo no direccional, es la misma relación.',
            'DUPLICATE_VIA_INVERSE' => 'Ya existe la relación inversa equivalente (ej. HAS_PART en sentido contrario) - crear esta sería una duplicación semántica.',
            'CYCLE_DETECTED' => 'Esta relación cerraría un ciclo en la jerarquía de conceptos - no se permite.',
            default => 'La relación no pasó la validación de seguridad de Phase 3.',
        };
    }
}
