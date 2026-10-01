<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyReviewedProposalResource\Pages;
use App\Models\TaxonomyReviewedProposal;
use App\Policies\TaxonomyReviewedProposalPolicy;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Forms;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

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
                // TASK-0006B, sección B: el estado de confirmación tiene que ser visible en la lista,
                // porque es la diferencia entre "una persona asumió esta decisión" y "la preparó
                // otro actor y nadie la confirmó todavía".
                Tables\Columns\BadgeColumn::make('confirmation_state')
                    ->label('Confirmación humana')
                    ->getStateUsing(fn (TaxonomyReviewedProposal $record) => match (true) {
                        $record->isHumanConfirmed() => 'Confirmada',
                        $record->awaitsHumanConfirmation() => 'Pendiente de confirmar',
                        default => 'No requiere',
                    })
                    ->colors([
                        'success' => 'Confirmada',
                        'warning' => 'Pendiente de confirmar',
                        'gray' => 'No requiere',
                    ]),
                Tables\Columns\TextColumn::make('confirmedBy.name')->label('Confirmada por')->placeholder('—'),
                Tables\Columns\TextColumn::make('confirmed_at')->label('Confirmada el')->dateTime()->placeholder('—'),
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
                self::confirmPreparedDecisionAction(),
            ]);
    }

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección B: acción SOLO-HUMANA para confirmar
     * una decisión preparada que la persona confirmadora no redactó.
     *
     * Lo que esta acción NO es, y el texto lo dice explícitamente: no es Aplicar ni Publicar. Esta
     * pantalla sigue sin tener ningún botón de apply - `ReviewedProposalService::apply()` sigue
     * siendo un paso separado con su propia autorización de ejecución, fuera de esta UI.
     *
     * Muestra la decisión original y la procedencia REAL de la preparación, sin fingir que el titular
     * de la cuenta tomó la decisión preparada por el agente - que es precisamente la contradicción
     * que el re-audit `5934324928` rechazó.
     *
     * `visible()` es UX; la autorización real vive en la policy (`confirm`) y, de forma definitiva,
     * en `ReviewedProposalService::confirm()`, que además exige que el confirmador sea el usuario
     * AUTENTICADO (no se puede confirmar en nombre de otra cuenta) y auto-captura el canal.
     */
    private static function confirmPreparedDecisionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('confirmPreparedDecision')
            ->label('Confirmar decisión preparada')
            ->modalHeading('Confirmar una decisión preparada por otro actor')
            ->modalDescription('Esto NO aplica ni publica nada. Solo registra que una persona con permiso de revisión asume explícitamente esta decisión como propia, para que pueda aplicarse en un paso posterior separado.')
            ->modalSubmitActionLabel('Confirmo esta decisión')
            ->icon('heroicon-o-hand-raised')
            ->color('warning')
            ->visible(fn (TaxonomyReviewedProposal $record) => Auth::user()?->can('confirm', $record) ?? false)
            ->form(fn (TaxonomyReviewedProposal $record) => [
                Forms\Components\Placeholder::make('decision_summary')
                    ->label('Decisión congelada que vas a confirmar')
                    ->content(fn () => new HtmlString(self::confirmationEvidenceHtml($record))),
                Forms\Components\TextInput::make('confirmation_reference')
                    ->label('Referencia de gobernanza de esta confirmación')
                    ->helperText('Dónde quedó registrada la decisión humana (ej. "Issue #2 comentario 5936206843"). Tiene que incluir al menos un dígito: es una referencia verificable, no un nombre libre.')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('confirmation_note')
                    ->label('Nota de confirmación (opcional)')
                    ->helperText('Queda guardada aparte de la decisión original, que es inmutable y no se modifica.')
                    ->rows(2),
                Forms\Components\Checkbox::make('deliberate')
                    ->label('Confirmo que revisé la decisión de arriba y la asumo como decisión de revisión propia')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (TaxonomyReviewedProposal $record, array $data) {
                $outcome = app(ReviewedProposalService::class)->confirm(
                    $record->id,
                    Auth::user(),
                    (string) ($data['confirmation_reference'] ?? ''),
                    $data['confirmation_note'] ?? null,
                );

                match ($outcome['result']) {
                    ReviewedProposalService::RESULT_CONFIRMED => Notification::make()
                        ->title('Decisión confirmada')
                        ->body('Quedó registrado quién la confirmó y cuándo. La decisión original, su payload y sus fingerprints NO se modificaron. Esto no aplicó ni publicó nada.')
                        ->success()->send(),
                    ReviewedProposalService::RESULT_ALREADY_CONFIRMED => Notification::make()
                        ->title('Esta propuesta ya estaba confirmada - no se escribió nada nuevo.')->warning()->send(),
                    ReviewedProposalService::RESULT_NOT_AWAITING_CONFIRMATION => Notification::make()
                        ->title('Esta propuesta no requiere confirmación humana.')->warning()->send(),
                    ReviewedProposalService::RESULT_ALREADY_PROCESSED => Notification::make()
                        ->title('Esta propuesta ya fue aplicada o abortada - confirmar es un paso previo a la ejecución.')->warning()->send(),
                    ReviewedProposalService::RESULT_UNAUTHORIZED => Notification::make()
                        ->title('No tenés permiso para confirmar esta decisión.')->danger()->send(),
                    ReviewedProposalService::RESULT_NOT_FOUND => Notification::make()
                        ->title('La propuesta o su fila de origen ya no existe.')->danger()->send(),
                    default => Notification::make()->title('No se pudo registrar la confirmación.')->danger()->send(),
                };
            });
    }

    /**
     * Evidencia de solo lectura que el confirmador ve ANTES de confirmar: la decisión original, su
     * payload congelado y la procedencia real de la preparación. El `prepared_by_actor_type` se
     * muestra tal cual: si la decisión la preparó el agente, lo dice, en vez de presentar al titular
     * de la cuenta como autor.
     */
    private static function confirmationEvidenceHtml(TaxonomyReviewedProposal $record): string
    {
        $rows = [
            'Propuesta' => '#'.$record->id,
            'Decisión' => $record->decision,
            'Origen' => $record->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? sprintf('candidato #%s (%s)', $record->candidate_link_id, $record->candidateLink?->term?->term ?? '—')
                : sprintf('relación #%s', $record->concept_relation_id),
            'Preparada por' => $record->isAgentPrepared()
                ? 'un AGENTE (no la persona que figura como revisor) - por eso requiere esta confirmación'
                : ($record->prepared_by_actor_type ?: '—'),
            'Canal de preparación' => $record->prepared_via ?: '—',
            'Figura como revisor' => $record->reviewer?->name ?? ('#'.$record->reviewer_id),
            'Congelada el' => $record->reviewed_at?->format('Y-m-d H:i:s') ?? '—',
            'Fingerprint del payload' => $record->payload_fingerprint,
        ];

        $html = '<dl class="text-sm space-y-1">';
        foreach ($rows as $label => $value) {
            $html .= sprintf('<div><dt class="inline font-semibold">%s:</dt> <dd class="inline">%s</dd></div>', e($label), e((string) $value));
        }
        $html .= '</dl>';

        $payload = $record->decision_payload ?? [];
        if ($payload !== []) {
            $html .= '<pre class="mt-2 text-xs whitespace-pre-wrap">'.e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre>';
        }

        return $html;
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
            // TASK-0006B (Issue #2 comentario `5936206843`), sección A/B: tercer evento del ciclo,
            // separado visualmente de la revisión Y de la ejecución - las tres cosas son distintas y
            // la pantalla no las mezcla.
            Section::make('Confirmación humana (TASK-0006B)')
                ->description('Quién PREPARÓ el contenido de la decisión no es necesariamente quién figura como revisor. Cuando la preparó un agente, una persona con permiso de revisión tiene que confirmarla explícitamente antes de que apply() la acepte. Confirmar no modifica la decisión ni sus fingerprints, y no publica nada.')
                ->schema([
                    TextEntry::make('prepared_by_actor_type')
                        ->label('Decisión preparada por')
                        ->formatStateUsing(fn (?string $state) => match ($state) {
                            TaxonomyReviewedProposal::ACTOR_AGENT => 'un AGENTE (no la persona que figura como revisor)',
                            TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER => 'el propio revisor humano',
                            default => '— (congelada antes de TASK-0006B: su procedencia de revisión original ya satisface el requisito de revisión humana)',
                        }),
                    TextEntry::make('prepared_via')->label('Canal de preparación (auto-capturado)')->placeholder('—'),
                    TextEntry::make('requires_human_confirmation')
                        ->label('¿Exige confirmación humana?')
                        ->formatStateUsing(fn ($state) => $state ? 'SÍ - apply() la rechaza hasta confirmarla' : 'No'),
                    TextEntry::make('confirmedBy.name')->label('Confirmada por')->placeholder('— (sin confirmar)'),
                    TextEntry::make('confirmed_at')->label('Confirmada el')->dateTime()->placeholder('—'),
                    TextEntry::make('confirmation_reference')->label('Referencia de gobernanza de la confirmación')->placeholder('—'),
                    TextEntry::make('confirmation_channel')->label('Canal de confirmación (auto-capturado)')->placeholder('—'),
                    TextEntry::make('confirmation_note')->label('Nota de confirmación')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('proposal_group_id')
                        ->label('Grupo bilingüe')
                        ->placeholder('— (propuesta suelta)')
                        ->helperText('Las propuestas de un mismo grupo convergen en UN solo concepto canónico: apply() las aplica juntas, en una transacción, creando un único concepto.')
                        ->fontFamily('mono'),
                ]),
            // TASK-0006C (Issue #2 `5939903005`): rastro de una confirmación cuya procedencia resultó
            // inválida y fue anulada. Se muestra solo cuando existe, para que la corrección sea
            // auditable desde la propia pantalla y no haya que leer `taxonomy_audit_log`.
            Section::make('Corrección de procedencia de confirmación (TASK-0006C)')
                ->description('Esta propuesta tuvo una confirmación cuya procedencia NO era válida y fue ANULADA. La decisión original, su payload y sus fingerprints no se modificaron. La propuesta volvió a exigir confirmación humana y apply() la sigue rechazando hasta que un revisor la confirme por esta misma pantalla.')
                ->visible(fn (TaxonomyReviewedProposal $record) => $record->hasInvalidatedConfirmation())
                ->schema([
                    TextEntry::make('confirmation_invalidated_at')->label('Anulada el')->dateTime(),
                    TextEntry::make('confirmation_invalidation_actor_type')
                        ->label('Anulada por')
                        ->formatStateUsing(fn (?string $state, TaxonomyReviewedProposal $record) => match ($state) {
                            TaxonomyReviewedProposal::ACTOR_AGENT => 'el AGENTE, por autorización explícita del dueño de la taxonomía (ninguna cuenta de persona ejecutó esta corrección)',
                            TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER => 'la persona '.($record->confirmationInvalidatedBy?->name ?? '#'.$record->confirmation_invalidated_by_id),
                            default => '—',
                        }),
                    TextEntry::make('confirmation_invalidation_channel')->label('Canal de la corrección (auto-capturado)')->placeholder('—'),
                    TextEntry::make('confirmation_invalidation_reference')->label('Referencia de gobernanza de la corrección')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('confirmation_invalidation_reason')->label('Motivo')->placeholder('—')->columnSpanFull(),
                    KeyValueEntry::make('invalidated_confirmation_snapshot')
                        ->label('Confirmación anulada (lo que decía antes)')
                        ->columnSpanFull(),
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
