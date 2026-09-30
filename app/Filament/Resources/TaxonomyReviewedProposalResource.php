<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyReviewedProposalResource\Pages;
use App\Models\TaxonomyReviewedProposal;
use App\Policies\TaxonomyReviewedProposalPolicy;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0005 (Issue #2 comentario `5914793857`), sección C: visibilidad admin de las propuestas
 * congeladas de Phase C2 (`taxonomy_reviewed_proposals`) - la pieza que faltaba para que un humano
 * pueda inspeccionar QUÉ se congeló, QUIÉN lo revisó, y en qué estado de ejecución quedó, sin tener
 * que leer la tabla a mano.
 *
 * DELIBERADAMENTE de solo lectura: sin `create`/`edit`/`delete`. `freeze()`/`apply()` viven en
 * `ReviewedProposalService`, nunca en un formulario de Filament que escriba esta tabla directamente
 * - ver docblock de `TaxonomyReviewedProposal` ("clase intencionalmente delgada"). Esta UI
 * DELIBERADAMENTE no ofrece ningún botón de "Aplicar/Publicar" (sección D del comentario que abrió
 * esta tarea: la autorización de ejecución sigue siendo un paso separado y explícito, fuera del
 * alcance de esta UI de revisión).
 *
 * La autorización vive en `TaxonomyReviewedProposalPolicy`, que reusa los permisos `view_any`/`view`
 * YA sembrados de `TaxonomyCandidateConceptLinkPolicy`/`TaxonomyConceptRelationPolicy` en vez de
 * requerir un permiso Shield nuevo sin sembrar.
 *
 * TASK-0005 re-audit (comentario `5917275454`, corrección 1): el listado era GLOBAL, así que un
 * usuario con permiso para UN solo tipo de origen veía filas de AMBOS tipos. `getEloquentQuery()`
 * ahora restringe las filas a los tipos que el usuario puede ver. Como `ViewRecord` resuelve el
 * registro contra ese mismo query, esto también cierra el acceso por URL directa al detalle (da 404
 * en lugar de filtrar la fila), además del 403 que devuelve la policy `view()` por tipo.
 */
class TaxonomyReviewedProposalResource extends Resource
{
    protected static ?string $model = TaxonomyReviewedProposal::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Propuestas revisadas (C2)';

    public static ?string $label = 'Propuesta revisada';

    protected static ?string $pluralModelLabel = 'Propuestas revisadas';

    protected static ?int $navigationSort = 18;

    public static function canAccess(): bool
    {
        return TaxonomyReviewedProposalPolicy::visibleProposalTypes(Auth::user()) !== [];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * TASK-0005 re-audit (comentario `5917275454`, corrección 1): filtra las filas por tipo de
     * origen según lo que el usuario puede ver. Un usuario con permiso solo de candidatos no ve
     * propuestas de relación y viceversa; con ambos permisos ve todo. Sin permisos la lista de tipos
     * queda vacía y `whereIn` compila a `0 = 1` (cero filas).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn(
            'proposal_type',
            TaxonomyReviewedProposalPolicy::visibleProposalTypes(Auth::user()),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\BadgeColumn::make('proposal_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => $state === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK ? 'Candidato término→concepto' : 'Relación concepto→concepto'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Origen')
                    ->getStateUsing(function (TaxonomyReviewedProposal $record) {
                        if ($record->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK) {
                            return $record->candidateLink?->term?->term ?? "candidato #{$record->candidate_link_id}";
                        }

                        return $record->conceptRelation
                            ? sprintf('%s → %s', $record->conceptRelation->sourceConcept?->display_name, $record->conceptRelation->targetConcept?->display_name)
                            : "relación #{$record->concept_relation_id}";
                    }),
                Tables\Columns\BadgeColumn::make('decision')->label('Decisión'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado de ejecución')
                    ->colors([
                        'info' => TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
                        'success' => TaxonomyReviewedProposal::STATUS_APPLIED,
                        'danger' => TaxonomyReviewedProposal::STATUS_ABORTED,
                    ]),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Revisor')->placeholder('—'),
                Tables\Columns\TextColumn::make('reviewed_at')->label('Congelada el')->dateTime(),
                Tables\Columns\TextColumn::make('authorization_reference')->label('Referencia de autorización')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('target_environment')->label('Entorno')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('applied_at')->label('Aplicada el')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('proposal_type')->options([
                    TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK => 'Candidato término→concepto',
                    TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION => 'Relación concepto→concepto',
                ]),
                Tables\Filters\SelectFilter::make('decision')->options([
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING => 'MAP_TO_EXISTING',
                    TaxonomyReviewedProposal::DECISION_CREATE_NEW => 'CREATE_NEW',
                    TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED => 'CONTEXT_REQUIRED',
                    TaxonomyReviewedProposal::DECISION_REJECT => 'REJECT',
                    TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION => 'PUBLISH_RELATION',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    TaxonomyReviewedProposal::STATUS_PENDING_APPLY => 'Pendiente de aplicación',
                    TaxonomyReviewedProposal::STATUS_APPLIED => 'Aplicada',
                    TaxonomyReviewedProposal::STATUS_ABORTED => 'Abortada',
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Identidad de la propuesta')
                ->schema([
                    TextEntry::make('proposal_type')->label('Tipo'),
                    TextEntry::make('decision')->label('Decisión'),
                    TextEntry::make('candidateLink.term.term')->label('Término candidato')->placeholder('—'),
                    TextEntry::make('conceptRelation.id')
                        ->label('Relación')
                        ->formatStateUsing(fn ($state, TaxonomyReviewedProposal $record) => $record->conceptRelation
                            ? sprintf('%s → %s (%s)', $record->conceptRelation->sourceConcept?->display_name, $record->conceptRelation->targetConcept?->display_name, $record->conceptRelation->relation_type)
                            : '—')
                        ->visible(fn (TaxonomyReviewedProposal $record) => $record->proposal_type === TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION),
                ]),
            Section::make('Revisión (congelamiento)')
                ->schema([
                    TextEntry::make('reviewer.name')->label('Revisor')->placeholder('—'),
                    TextEntry::make('reviewed_at')->label('Congelada el')->dateTime(),
                    TextEntry::make('payload_version')->label('Versión del contrato'),
                    // Representación diagnóstica segura: el fingerprint es un hash SHA-256, nunca
                    // un secreto ni datos sensibles crudos - existe precisamente para poder
                    // mostrarse/compararse sin exponer nada.
                    TextEntry::make('payload_fingerprint')->label('Fingerprint del payload (tamper-detection)')->fontFamily('mono'),
                    TextEntry::make('taxonomy_state_fingerprint')->label('Fingerprint del estado de taxonomía (staleness)')->fontFamily('mono'),
                    KeyValueEntry::make('decision_payload')->label('Payload de decisión (congelado)'),
                ]),
            Section::make('Ejecución (apply) — fuera del alcance de esta UI')
                ->description('Esta pantalla es de solo lectura. Aplicar/publicar esta propuesta requiere una autorización de ejecución explícita y separada, fuera de esta UI de revisión (ver ReviewedProposalService::apply()).')
                ->schema([
                    TextEntry::make('status')->label('Estado'),
                    TextEntry::make('authorization_reference')->label('Referencia de autorización')->placeholder('— (todavía no aplicada)'),
                    TextEntry::make('target_environment')->label('Entorno de ejecución')->placeholder('—'),
                    TextEntry::make('applied_at')->label('Aplicada el')->dateTime()->placeholder('—'),
                    KeyValueEntry::make('application_result')->label('Resultado de la aplicación/aborto')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyReviewedProposals::route('/'),
            'view' => Pages\ViewTaxonomyReviewedProposal::route('/{record}'),
        ];
    }
}
