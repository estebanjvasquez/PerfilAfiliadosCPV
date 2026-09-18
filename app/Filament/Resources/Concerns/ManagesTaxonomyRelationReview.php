<?php

namespace App\Filament\Resources\Concerns;

use App\Services\TaxonomyAuditLogger;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * TAXV2-9: acciones de aprobar/rechazar compartidas entre las 4 colas de revisión de relaciones
 * (`TaxonomyTermCpvRelationResource`, `LegacyServiceCpvRelationResource`, y los
 * `CpvRelationsRelationManager` de `TaxonomyTermResource`/`LegacyServiceResource`) - las 4
 * trabajan sobre modelos con las mismas constantes `STATUS_*` (TaxonomyTermCpvRelation y
 * LegacyServiceCpvRelation), así que la lógica es idéntica salvo el modelo concreto.
 *
 * Decisión del plan de esta fase: `reason` es obligatorio EXACTAMENTE acá (aprobar/rechazar es la
 * transición que de verdad afecta lo que ve el buscador, TAXV2-5) - no en cada edición de peso en
 * borrador (ver `ManagesTaxonomySettingsGroup`, que loggea sin pedirlo).
 *
 * TAXV2-10: "aprobar" usa el permiso `taxonomy_publish` (es literalmente la acción que publica la
 * relación al buscador en vivo) - "rechazar" usa `taxonomy_edit_relations` (una decisión editorial,
 * no de publicación). Separación de responsabilidades pedida por la sección 13 del documento.
 */
trait ManagesTaxonomyRelationReview
{
    protected static function reviewApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Aprobar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Model $record) => $record->status !== $record::STATUS_APPROVED && static::canPublishRelations())
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->helperText('Por qué se aprueba esta relación - queda registrado en la auditoría.'),
            ])
            ->action(fn (Model $record, array $data) => static::transitionStatus($record, $record::STATUS_APPROVED, $data['reason']));
    }

    protected static function reviewRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rechazar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (Model $record) => $record->status !== $record::STATUS_REJECTED && static::canReviewRelations())
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->helperText('Por qué se rechaza esta relación - queda registrado en la auditoría.'),
            ])
            ->action(fn (Model $record, array $data) => static::transitionStatus($record, $record::STATUS_REJECTED, $data['reason']));
    }

    protected static function reviewApproveBulkAction(): BulkAction
    {
        return BulkAction::make('approve_bulk')
            ->label('Aprobar seleccionadas')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn () => static::canPublishRelations())
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')->label('Motivo')->required(),
            ])
            ->action(function ($records, array $data) {
                $records->each(fn (Model $record) => static::transitionStatus($record, $record::STATUS_APPROVED, $data['reason']));
            })
            ->deselectRecordsAfterCompletion();
    }

    protected static function reviewRejectBulkAction(): BulkAction
    {
        return BulkAction::make('reject_bulk')
            ->label('Rechazar seleccionadas')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn () => static::canReviewRelations())
            ->requiresConfirmation()
            ->form([
                Textarea::make('reason')->label('Motivo')->required(),
            ])
            ->action(function ($records, array $data) {
                $records->each(fn (Model $record) => static::transitionStatus($record, $record::STATUS_REJECTED, $data['reason']));
            })
            ->deselectRecordsAfterCompletion();
    }

    /** TAXV2-10: permiso `taxonomy_edit_relations` (rechazar) de la sección 13. */
    protected static function canReviewRelations(): bool
    {
        return Auth::user()?->can('taxonomy_edit_relations') ?? false;
    }

    /** TAXV2-10: permiso `taxonomy_publish` (aprobar = publicar al buscador en vivo) de la sección 13. */
    protected static function canPublishRelations(): bool
    {
        return Auth::user()?->can('taxonomy_publish') ?? false;
    }

    private static function transitionStatus(Model $record, string $status, string $reason): void
    {
        $oldStatus = $record->status;

        $record->update([
            'status' => $status,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        TaxonomyAuditLogger::record(
            entityType: get_class($record),
            entityId: $record->getKey(),
            field: 'status',
            oldValue: $oldStatus,
            newValue: $status,
            reason: $reason,
        );

        Notification::make()
            ->success()
            ->title($status === $record::STATUS_APPROVED ? 'Relación aprobada' : 'Relación rechazada')
            ->send();
    }
}
