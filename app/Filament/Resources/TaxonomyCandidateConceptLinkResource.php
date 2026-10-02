<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyReviewedProposal;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\ConceptExplorerService;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Phase 3 (secciones 7/20 del pedido): cola de revisión del Canonical Concept Builder. Mismo
 * espíritu de "EXCEPTION-ONLY HUMAN REVIEW" que el pedido pide (sección 20) - un administrador no
 * debería tener que revisar miles de filas, solo la ambigüedad real que el Builder no pudo resolver
 * solo (tier REVIEW), o auditar lo que sí se auto-aceptaría antes de que exista un `--apply` real.
 *
 * Phase B.1 (extensión, no pantalla paralela - ver audit/phase3_phase_b1_review_workflow.md sección
 * 2): agrega el camino de decisión MAP_TO_EXISTING/CREATE_NEW/REJECT para candidatos
 * PROPOSE_NEW_CONCEPT (`resolveNewConcept`, antes solo podían rechazarse) y enriquece
 * approve/reject existentes con impacto predicho y estado del grafo de conceptos antes de confirmar.
 *
 * Queda en 0 filas al terminar esta entrega (ningún proceso automático la puebla todavía) - el
 * recurso existe para cuando el Builder (en una fase posterior, con `--apply` habilitado) empiece a
 * insertar candidatos reales. Las acciones están implementadas y funcionales, pero no tienen ninguna
 * fila sobre la que operar en esta entrega.
 *
 * TASK-0005 (Issue #2 comentario `5914793857`): las acciones `approve`/`resolveNewConcept` de abajo
 * (que desde TASK-0004 HIGH-2 solo mostraban "esto ya no se puede hacer desde acá") se reemplazan
 * por UNA sola acción (`freezeReview`) que llama a `ReviewedProposalService::freeze()` - el camino
 * real y funcional de revisión C2. `freeze()` NUNCA publica/aplica nada (no toca
 * `taxonomy_term_concepts` ni el `status` del candidato) - solo congela una decisión inmutable de
 * revisión (`taxonomy_reviewed_proposals`, `status=PENDING_APPLY`) pendiente de un paso de
 * `apply()` separado y explícitamente autorizado, que esta UI deliberadamente NO expone (ver
 * `TaxonomyReviewedProposalResource`, de solo lectura). `reject()` directo de
 * `CandidateConceptApprovalService` (el único camino legacy que nunca escribió una tabla protegida)
 * se retira de la UI para no tener dos caminos distintos hacia la misma decisión - REJECT ahora
 * también pasa por `freeze()`, quedando igual de auditado/inmutable que las otras 3 decisiones.
 *
 * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 2: `freezeReview` ya no se ofrece cuando el
 * candidato tiene una propuesta de revisión VIVA (`PENDING_APPLY`), y en su lugar aparece un enlace
 * de solo lectura a esa propuesta. Corrige el defecto del comentario `5947407519`: como `freeze()`
 * no toca el `status` del candidato, un candidato ya revisado seguía siendo `pending` y la grilla le
 * ofrecía un segundo camino de revisión en la misma fila que mostraba `CONGELADA_PENDIENTE`. Ver
 * `liveFrozenProposal()` para el razonamiento completo, incluido por qué `ABORTED` sí debe seguir
 * permitiendo una revisión nueva. La visibilidad de la UI NO es la salvaguarda real: el índice único
 * parcial y `ReviewedProposalService::freeze()` siguen rechazando un segundo congelamiento por
 * cualquier camino (probado por test).
 */
class TaxonomyCandidateConceptLinkResource extends Resource
{
    protected static ?string $model = TaxonomyCandidateConceptLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Candidatos de concepto (Phase 3)';

    public static ?string $label = 'Candidato de concepto';

    protected static ?string $pluralModelLabel = 'Candidatos de concepto';

    protected static ?int $navigationSort = 17;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('review_notes')->label('Notas de revisión')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term.term')->label('Término candidato')->searchable(),
                Tables\Columns\TextColumn::make('term.language')->label('Idioma')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('term.term_type')->label('Tipo de término')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('proposal_kind')
                    ->label('Tipo de propuesta')
                    ->getStateUsing(fn (TaxonomyCandidateConceptLink $record) => $record->isProposingNewConcept() ? 'CONCEPTO NUEVO' : 'MAPEAR A EXISTENTE')
                    ->colors([
                        'warning' => 'CONCEPTO NUEVO',
                        'info' => 'MAPEAR A EXISTENTE',
                    ]),
                Tables\Columns\TextColumn::make('concept.display_name')->label('Concepto sugerido')
                    ->placeholder('PROPOSE_NEW_CONCEPT')
                    ->description(fn (TaxonomyCandidateConceptLink $record) => $record->isProposingNewConcept() ? $record->suggested_new_concept_name : null),
                Tables\Columns\TextColumn::make('confidence')->numeric(4)->sortable(),
                Tables\Columns\BadgeColumn::make('tier')
                    ->colors([
                        'success' => TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT,
                        'info' => TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT_CONSERVATIVE,
                        'warning' => TaxonomyCandidateConceptLink::TIER_REVIEW,
                        'danger' => TaxonomyCandidateConceptLink::TIER_REJECT,
                    ]),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => TaxonomyCandidateConceptLink::STATUS_PENDING,
                        'success' => [TaxonomyCandidateConceptLink::STATUS_APPROVED, TaxonomyCandidateConceptLink::STATUS_PUBLISHED],
                        'danger' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
                        'warning' => TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED,
                    ]),
                // TASK-0005: freeze() NUNCA toca `status`/`reviewed_at` de esta fila - sin esta
                // columna no habría forma de ver desde la grilla que un candidato "pending" ya tiene
                // una decisión de revisión C2 congelada, esperando aplicación separada.
                Tables\Columns\BadgeColumn::make('reviewed_proposal_state')
                    ->label('Propuesta C2')
                    ->getStateUsing(function (TaxonomyCandidateConceptLink $record) {
                        $latest = $record->reviewedProposals->sortByDesc('id')->first();
                        if (! $latest) {
                            return 'SIN_REVISAR';
                        }

                        return match ($latest->status) {
                            TaxonomyReviewedProposal::STATUS_PENDING_APPLY => 'CONGELADA_PENDIENTE',
                            TaxonomyReviewedProposal::STATUS_APPLIED => 'APLICADA',
                            TaxonomyReviewedProposal::STATUS_ABORTED => 'ABORTADA',
                            default => $latest->status,
                        };
                    })
                    ->colors([
                        'gray' => 'SIN_REVISAR',
                        'info' => 'CONGELADA_PENDIENTE',
                        'success' => 'APLICADA',
                        'danger' => 'ABORTADA',
                    ]),
                Tables\Columns\TextColumn::make('reviewedBy.name')->label('Revisado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewed_at')->label('Revisado el')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tier')->options([
                    TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT => 'AUTO_ACCEPT',
                    TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT_CONSERVATIVE => 'AUTO_ACCEPT_CONSERVATIVE',
                    TaxonomyCandidateConceptLink::TIER_REVIEW => 'REVIEW',
                    TaxonomyCandidateConceptLink::TIER_REJECT => 'REJECT',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    TaxonomyCandidateConceptLink::STATUS_PENDING => 'Pendiente',
                    TaxonomyCandidateConceptLink::STATUS_APPROVED => 'Aprobado',
                    TaxonomyCandidateConceptLink::STATUS_REJECTED => 'Rechazado',
                    TaxonomyCandidateConceptLink::STATUS_PUBLISHED => 'Publicado',
                    TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED => 'Contexto requerido (sin mapeo directo)',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // TASK-0005 (Issue #2 comentario `5914793857`): ÚNICA acción de revisión para
                // candidatos pending - reemplaza `approve`/`reject`/`resolveNewConcept` (todas
                // ahora colapsadas en las 4 decisiones C2: MAP_TO_EXISTING/CREATE_NEW (esta última
                // solo si `isProposingNewConcept()`)/CONTEXT_REQUIRED/REJECT). La autorización/
                // idempotencia/transacción/fingerprint reales viven en
                // `ReviewedProposalService::freeze()` - este Action solo construye el payload
                // explícito de la decisión y muestra el resultado. `visible()` es UX (la
                // salvaguarda real de autorización está en el servicio, vía la misma policy
                // `update` que ya gobernaba `approve`/`reject`/`resolveNewConcept`).
                //
                // CRÍTICO: esto NUNCA llama a `apply()` - "Congelar revisión" congela una decisión
                // inmutable pendiente de aplicación, nunca publica. Ver `TaxonomyReviewedProposalResource`
                // (de solo lectura) para inspeccionar el estado de la propuesta congelada.
                // TASK-0006D (Issue #2 comentario `5949253156`), PARTE 2: la tercera condición es la
                // corrección del defecto reportado en el comentario `5947407519` - ver
                // `liveFrozenProposal()`.
                Tables\Actions\Action::make('freezeReview')
                    ->label('Revisar (congelar decisión C2)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('primary')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING
                        && Auth::user()?->can('update', $record)
                        && self::liveFrozenProposal($record) === null)
                    ->form(fn (TaxonomyCandidateConceptLink $record) => self::freezeReviewForm($record))
                    ->action(function (TaxonomyCandidateConceptLink $record, array $data) {
                        $decision = $data['decision'];
                        $bilingual = $decision === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                            && ($data['new_concept_identity_mode'] ?? 'monolingual') === 'bilingual';

                        // TASK-0006B, sección D: si el revisor eligió converger con otros
                        // candidatos, se congela UNA revisión AGRUPADA (una fila por candidato,
                        // unidas por `proposal_group_id`) en vez de varias independientes - es lo
                        // que impide que `apply()` cree un concepto por candidato.
                        $converge = array_values(array_filter(array_map('intval', $data['converge_candidate_ids'] ?? [])));
                        if ($bilingual && $converge !== []) {
                            self::freezeConvergedBilingualGroup($record, $converge, $data);

                            return;
                        }

                        $payload = match ($decision) {
                            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING => ['target_concept_id' => $data['target_concept_id'] ?? null],
                            TaxonomyReviewedProposal::DECISION_CREATE_NEW => $bilingual
                                ? [
                                    'canonical_name_es' => $data['canonical_name_es'] ?? null,
                                    'canonical_name_en' => $data['canonical_name_en'] ?? null,
                                ]
                                : ['new_concept_name' => $data['new_concept_name'] ?? null],
                            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED => ['context_reason' => $data['context_reason'] ?? null],
                            TaxonomyReviewedProposal::DECISION_REJECT => ['notes' => CandidateConceptApprovalService::composeReviewReason($data['reject_reason_category'], $data['notes'] ?? null)],
                            default => [],
                        };

                        $outcome = app(ReviewedProposalService::class)->freeze(
                            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                            $record->id,
                            $decision,
                            Auth::user(),
                            $payload,
                        );

                        match ($outcome['result']) {
                            ReviewedProposalService::RESULT_FROZEN => Notification::make()
                                ->title('Decisión de revisión congelada')
                                ->body('Esto NO publicó ni cambió el candidato - queda como una propuesta inmutable pendiente de un paso de aplicación separado y explícitamente autorizado.')
                                ->success()->send(),
                            ReviewedProposalService::RESULT_UNAUTHORIZED => Notification::make()
                                ->title('No tenés permiso para revisar este candidato.')->danger()->send(),
                            ReviewedProposalService::RESULT_ALREADY_PROCESSED => Notification::make()
                                ->title('Este candidato ya fue procesado (doble click o ya revisado por otro admin) - no se congeló nada nuevo.')->warning()->send(),
                            ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL => Notification::make()
                                ->title('Ya existe una propuesta de revisión congelada pendiente de aplicación para este candidato.')->warning()->send(),
                            ReviewedProposalService::RESULT_VALIDATION_FAILED => Notification::make()
                                ->title('Falta un campo explícito requerido para esta decisión (nombre nuevo/motivo de contexto no puede quedar vacío ni inferirse).')->danger()->send(),
                            ReviewedProposalService::RESULT_NOT_FOUND => Notification::make()
                                ->title('El concepto destino seleccionado ya no existe, o el candidato ya no existe.')->danger()->send(),
                            default => Notification::make()->title('No se pudo congelar la decisión.')->danger()->send(),
                        };
                    }),
                self::viewReviewedProposalAction(),
            ]);
    }

    /**
     * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 2: la propuesta de revisión VIVA de este
     * candidato, o `null` si no tiene ninguna.
     *
     * EL DEFECTO QUE CIERRA (reportado en el comentario `5947407519`, observado por el dueño durante
     * su propio paso de confirmación): `freezeReview` solo chequeaba `status === pending` + permiso de
     * update. Pero `freeze()` **deliberadamente no toca el `status` del candidato** - congelar una
     * decisión no publica ni resuelve nada -, así que un candidato con una propuesta `PENDING_APPLY`
     * sigue siendo `pending` POR DISEÑO. El chequeo confundía "sigue pending" con "sigue sin
     * revisar", y la grilla terminaba ofreciendo un segundo camino de revisión en la misma fila que ya
     * mostraba la insignia `CONGELADA_PENDIENTE`. Entrar por ahí producía un error de validación
     * ("Identidad bilingüe inválida") que parecía un problema de la propuesta congelada cuando en
     * realidad era un camino equivocado.
     *
     * La corrección se apoya en la EXISTENCIA de una propuesta viva, no en el status del candidato -
     * que es el único predicado que distingue de verdad los dos casos.
     *
     * Solo `PENDING_APPLY` cuenta como "viva", y eso es una decisión explícita, no un descuido:
     * - `ABORTED` es terminal y NO resuelve el candidato (`abort()` no toca la fila fuente), así que
     *   el candidato queda legítimamente pendiente de una revisión nueva - que es exactamente el
     *   camino de recuperación documentado para una propuesta obsoleta. Esconder el botón ahí dejaría
     *   el candidato sin ninguna forma de volver a revisarse.
     * - `APPLIED` sí resuelve el candidato (published/rejected/context_required), así que el chequeo
     *   de `status === pending` que ya existía lo cubre solo. Un candidato `pending` con una
     *   propuesta `APPLIED` no lo puede producir `apply()` por ningún camino; si apareciera, se trata
     *   con la misma regla que `ABORTED` (revisable), en vez de agregar un caso especial para un
     *   estado que la aplicación no genera.
     *
     * Y el índice único parcial `WHERE status = 'PENDING_APPLY'` hace que esta regla coincida
     * exactamente con lo que la base de datos permite: donde esta función devuelve una propuesta, un
     * `freeze()` nuevo fallaría de todos modos.
     *
     * Usa la relación ya cargada (`$record->reviewedProposals`), la misma que alimenta la columna
     * `Propuesta C2` - Eloquent la cachea por fila, así que esto NO agrega consultas a la grilla.
     */
    public static function liveFrozenProposal(TaxonomyCandidateConceptLink $record): ?TaxonomyReviewedProposal
    {
        return $record->reviewedProposals
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * TASK-0006D, PARTE 2: el texto de solo lectura que la página de detalle muestra sobre la
     * propuesta congelada. Vive acá, y no como una closure dentro del infolist, para que se pueda
     * probar sin renderizar la página: el renderizado del detalle depende de `ext-intl`
     * (`TextEntry::make('confidence')->numeric(4)` llama a `Number::format()`), que no está disponible
     * en el entorno local - una limitación de entorno preexistente, ajena a esta corrección.
     */
    public static function frozenProposalNotice(TaxonomyCandidateConceptLink $record): string
    {
        $proposal = self::liveFrozenProposal($record);

        if (! $proposal) {
            return 'Ninguna propuesta viva (PENDING_APPLY) para este candidato.';
        }

        return sprintf(
            'Propuesta #%d (%s) congelada el %s - PENDIENTE DE APLICACIÓN. La acción que corresponde a este estado vive en «Propuestas revisadas (C2)», no acá: revisar de nuevo este candidato no es el camino.',
            $proposal->id,
            $proposal->decision,
            $proposal->reviewed_at?->format('Y-m-d H:i:s') ?? '—',
        );
    }

    /**
     * TASK-0006D, PARTE 2: la alternativa SEGURA que reemplaza al botón de revisar cuando ya hay una
     * decisión congelada - "preferably expose a safe «Ver propuesta revisada» affordance/link".
     *
     * Es un enlace de solo lectura a `TaxonomyReviewedProposalResource`, que es donde vive la acción
     * correcta para este estado (`Confirmar decisión preparada`). No aplica, no publica y no congela
     * nada; esta pantalla sigue sin tener ningún botón de APPLY/Publish.
     *
     * Solo se muestra si el usuario puede ver ESA propuesta: la autorización se resuelve con la misma
     * policy que gobierna el recurso de destino (por tipo de origen, sin fuga entre candidatos y
     * relaciones), así que el enlace nunca lleva a un 403/404.
     */
    private static function viewReviewedProposalAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('viewReviewedProposal')
            ->label('Ver propuesta revisada')
            ->icon('heroicon-o-archive-box')
            ->color('gray')
            ->visible(function (TaxonomyCandidateConceptLink $record) {
                $proposal = self::liveFrozenProposal($record);

                return $proposal !== null && (Auth::user()?->can('view', $proposal) ?? false);
            })
            ->url(fn (TaxonomyCandidateConceptLink $record) => TaxonomyReviewedProposalResource::getUrl(
                'view',
                ['record' => self::liveFrozenProposal($record)?->getKey()],
            ));
    }

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección D: candidatos con los que ESTE puede
     * converger en un solo concepto - los demás `pending` que proponen concepto nuevo. Se muestra el
     * idioma de cada término (el grupo tiene que abarcar ES y EN) y se marca con "↔" los que
     * comparten `canonical_term` con este candidato, porque eso es la evidencia gobernada de que son
     * el mismo concepto en otro idioma. La marca es SUGERENCIA, no filtro: la lista no se recorta a
     * los coincidentes, así que el revisor nunca queda encerrado en lo que el dato ya sabía.
     *
     * TASK-0006D, PARTE 2: pública para que la regresión del filtro de propuestas vivas se pueda
     * probar directamente, en vez de a través de la API interna de formularios de Filament (que
     * cambia entre versiones menores y haría frágil un test de una regla de negocio estable). Es una
     * lectura pura, sin efectos.
     */
    public static function convergenceCandidateOptions(TaxonomyCandidateConceptLink $record): array
    {
        $ownCanonical = $record->term?->canonical_term;

        return TaxonomyCandidateConceptLink::query()
            ->where('status', TaxonomyCandidateConceptLink::STATUS_PENDING)
            ->whereNull('suggested_concept_id')
            ->whereKeyNot($record->getKey())
            // TASK-0006D, PARTE 2: misma corrección que en `freezeReview`, aplicada a la otra puerta
            // de entrada al mismo defecto. Un candidato con una propuesta `PENDING_APPLY` viva no
            // puede participar de un grupo nuevo: el índice único parcial lo rechazaría y
            // `freezeBilingualConceptGroup()` revertiría el grupo COMPLETO con
            // `ALREADY_HAS_PENDING_PROPOSAL`. Ofrecerlo acá solo servía para que el revisor perdiera
            // también la revisión de los demás miembros.
            ->whereDoesntHave('reviewedProposals', fn ($q) => $q->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY))
            ->with('term')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (TaxonomyCandidateConceptLink $other) use ($ownCanonical) {
                $term = $other->term;
                $sameConcept = $ownCanonical !== null && $term?->canonical_term === $ownCanonical;

                return [$other->id => sprintf(
                    '%s#%d  %s  [%s%s]  propone: %s',
                    $sameConcept ? '↔ ' : '',
                    $other->id,
                    $term?->term ?? '—',
                    $term?->language ?? '?',
                    $term?->term_type ? ', '.$term->term_type : '',
                    $other->suggested_new_concept_name ?: '—',
                )];
            })
            ->all();
    }

    /**
     * TASK-0006B, sección D: congela la revisión bilingüe AGRUPADA y traduce el resultado del
     * servicio a una notificación. La autorización/validación/transacción reales viven en
     * `ReviewedProposalService::freezeBilingualConceptGroup()` - acá solo se arma la lista de
     * candidatos y se muestra el desenlace.
     */
    private static function freezeConvergedBilingualGroup(TaxonomyCandidateConceptLink $record, array $convergeIds, array $data): void
    {
        $outcome = app(ReviewedProposalService::class)->freezeBilingualConceptGroup(
            array_merge([$record->id], $convergeIds),
            (string) ($data['canonical_name_es'] ?? ''),
            (string) ($data['canonical_name_en'] ?? ''),
            Auth::user(),
        );

        match ($outcome['result']) {
            ReviewedProposalService::RESULT_FROZEN => Notification::make()
                ->title('Revisión bilingüe agrupada congelada')
                ->body(sprintf(
                    'Se congelaron %d decisiones unidas en un solo grupo. apply() creará UN concepto (ES/EN) y adjuntará todos los términos - nunca uno por candidato. Nada se publicó todavía.',
                    count($outcome['proposals']),
                ))
                ->success()->send(),
            ReviewedProposalService::RESULT_VALIDATION_FAILED => Notification::make()
                ->title('Identidad bilingüe inválida')
                ->body('Hacen falta los dos nombres (ES y EN) explícitos, el grupo tiene que abarcar español e inglés, y todos los candidatos tienen que proponer un concepto nuevo.')
                ->danger()->send(),
            ReviewedProposalService::RESULT_UNAUTHORIZED => Notification::make()
                ->title('No tenés permiso para revisar alguno de los candidatos del grupo.')->danger()->send(),
            ReviewedProposalService::RESULT_ALREADY_PROCESSED => Notification::make()
                ->title('Alguno de los candidatos del grupo ya fue procesado - no se congeló nada (el grupo se revierte completo).')->warning()->send(),
            ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL => Notification::make()
                ->title('Alguno de los candidatos ya tiene una propuesta congelada pendiente - no se congeló nada (el grupo se revierte completo).')->warning()->send(),
            ReviewedProposalService::RESULT_NOT_FOUND => Notification::make()
                ->title('Alguno de los candidatos del grupo ya no existe.')->danger()->send(),
            default => Notification::make()->title('No se pudo congelar la revisión agrupada.')->danger()->send(),
        };
    }

    /**
     * Phase B.1 (seguimiento 2026-09-25): texto compartido de impacto predicho, con el desglose
     * confirmado/sugerido de `predictAffectedCompanies()` - reusado por el formulario de
     * `freezeReview` (TASK-0005) y la vista de detalle, para no repetir la misma lógica de formato
     * con el riesgo de que se desincronicen entre sí.
     *
     * "Confirmado" = `empresa_taxonomy_category.origen = self_declared` (la empresa lo declaró ella
     * misma). "Sugerido" = `origen = suggested` (viene de `taxonomy:homologate-empresas`, un mapeo
     * automático por similitud semántica del catálogo viejo de servicios - la empresa nunca lo
     * confirmó). Verificado en vivo (2026-09-25): 755 de 764 filas reales son `suggested` - la
     * inmensa mayoría del "impacto predicho" que ve un revisor es evidencia sugerida, no confirmada.
     */
    public static function formatImpactSummary(array $impact): string
    {
        $lines = [];

        $lines[] = sprintf(
            'Impacto predicho: %d empresas directas (%d confirmadas por la empresa, %d solo sugeridas automáticamente), %d con evidencia de crawler, %d total único.',
            $impact['direct_company_count'],
            $impact['direct_confirmed_company_count'] ?? 0,
            $impact['direct_suggested_company_count'] ?? 0,
            $impact['evidence_company_count'],
            $impact['total_unique_company_count'],
        );
        if (! empty($impact['data_gap_flags'])) {
            $lines[] = 'Brechas de datos: '.implode(', ', $impact['data_gap_flags']).' (esperado, no es una falla del motor).';
        }
        if (($impact['direct_company_count'] ?? 0) > 0 && ($impact['direct_confirmed_company_count'] ?? 0) === 0) {
            $lines[] = 'Ninguna de estas empresas confirmó esta categoría ella misma - toda la evidencia directa es sugerida automáticamente, tratarla con precaución.';
        }

        return implode("\n", $lines);
    }

    /**
     * Concepto que el panel de diagnóstico debe describir, según la decisión elegida: el destino de
     * MAP_TO_EXISTING, o el que se esté inspeccionando como evidencia en CONTEXT_REQUIRED.
     */
    private static function conceptUnderInspection(Get $get): ?TaxonomyCanonicalConcept
    {
        $conceptId = $get('decision') === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED
            ? $get('inspect_concept_id')
            : $get('target_concept_id');

        return $conceptId ? TaxonomyCanonicalConcept::find($conceptId) : null;
    }

    /**
     * TASK-0006A (Issue #2 comentario `5929287629`), sección B: render de solo lectura del
     * diagnóstico que arma `ConceptExplorerService::diagnostics()`.
     *
     * Las categorías CPV se muestran con su código, nivel (Grupo/Familia/Categoría) y breadcrumb
     * completo.
     *
     * TASK-0006A re-audit (`5930560603`), corrección 2: la lista de categorías es una PÁGINA, y el
     * render incluye la línea de estado con el total real y la posición - combinada con el filtro y
     * los botones de paginación del formulario, el revisor puede inspeccionar TODAS, no solo saber
     * que existen.
     *
     * @param  array<string, mixed>  $d
     */
    public static function formatConceptDiagnostics(array $d, ?ConceptExplorerService $explorer = null): HtmlString
    {
        $explorer ??= app(ConceptExplorerService::class);
        $levelLabel = fn (?int $level) => match ($level) {
            TaxonomyCategory::LEVEL_GROUP => 'Grupo',
            TaxonomyCategory::LEVEL_FAMILY => 'Familia',
            TaxonomyCategory::LEVEL_CATEGORY => 'Categoría',
            default => 'nivel ?',
        };

        $e = fn (?string $v) => e((string) ($v ?? '—'));

        $html = '<div class="text-sm space-y-2">';

        $html .= '<div><strong>Identidad</strong><br>'
            .'ES: '.$e($d['identity']['canonical_name_es']).'<br>'
            .'EN: '.$e($d['identity']['canonical_name_en']).'<br>'
            .'id #'.(int) $d['identity']['id']
            .' · status '.$e($d['identity']['status'])
            .' · tipo '.$e($d['identity']['concept_type'])
            .' · dominio '.$e($d['identity']['domain'])
            .'</div>';

        $terms = $d['member_terms']->take(12)
            ->map(fn ($t) => e($t->term).' <span class="text-gray-500">('.e($t->language ?? '—').', '.e($t->term_type ?? '—').')</span>')
            ->implode(', ');
        $termOverflow = max(0, $d['member_term_count'] - $d['member_terms']->take(12)->count());
        $html .= '<div><strong>Términos con identidad aprobada: '.(int) $d['member_term_count'].'</strong>'
            .($terms !== '' ? '<br>'.$terms : '')
            .($termOverflow > 0 ? ' <span class="text-gray-500">y '.$termOverflow.' más</span>' : '')
            .'</div>';

        if ($d['alias_count'] > 0) {
            $aliases = $d['aliases']->take(12)->map(fn ($a) => e($a->alias))->implode(', ');
            $aliasOverflow = max(0, $d['alias_count'] - $d['aliases']->take(12)->count());
            $html .= '<div><strong>Alias ('.(int) $d['alias_count'].')</strong><br>'.$aliases
                .($aliasOverflow > 0 ? ' <span class="text-gray-500">y '.$aliasOverflow.' más</span>' : '')
                .'</div>';
        }

        $html .= '<div><strong>Categorías CPV alcanzables por la taxonomía aprobada: '.(int) $d['category_total'].'</strong>';
        $html .= '<br><span class="text-gray-500">'.e($explorer->categoryStatusLine($d)).'</span>';
        if ($d['categories']->isNotEmpty()) {
            $html .= '<ul class="list-disc ml-5">';
            foreach ($d['categories'] as $cat) {
                $html .= '<li><code>'.e($cat->code).'</code> · '.$levelLabel($cat->level).' · '.e($cat->displayName())
                    .'<br><span class="text-gray-500">'.e($cat->breadcrumb('es')).'</span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';

        $html .= '<div><strong>Impacto predicho</strong><br>'
            .nl2br(e(self::formatImpactSummary($d['impact']))).'</div>';

        if ($d['warnings'] !== []) {
            $html .= '<div><strong>Advertencias / huecos de datos</strong><ul class="list-disc ml-5">';
            foreach ($d['warnings'] as $w) {
                $html .= '<li>'.e($w).'</li>';
            }
            $html .= '</ul></div>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * TASK-0005 (Issue #2 comentario `5914793857`), sección A: formulario único de revisión C2
     * para CUALQUIER candidato pending. Reemplaza `resolveNewConceptForm()` (solo cubría
     * PROPOSE_NEW_CONCEPT con el flujo legacy). Las 4 decisiones que soporta
     * `ReviewedProposalService::freeze()` para TERM_CONCEPT_LINK están todas acá:
     * - MAP_TO_EXISTING: siempre disponible (mapear a un concepto existente, sea o no la sugerencia
     *   original del Builder - el revisor elige explícitamente el destino, `target_concept_id`).
     * - CREATE_NEW: solo si `isProposingNewConcept()` (mismo criterio que `freeze()` exige - un
     *   candidato con concepto ya sugerido se resuelve con MAP_TO_EXISTING, nunca creando uno
     *   paralelo). El campo `new_concept_name` NUNCA se prellena con `suggested_new_concept_name` -
     *   la sugerencia del Builder se muestra en un `Placeholder` de solo evidencia/referencia (ver
     *   hallazgo de ronda 4 de TASK-0004: "no implicit fallback").
     * - CONTEXT_REQUIRED: siempre disponible (un término puede ser válido pero demasiado genérico
     *   sin importar si el Builder sugirió un concepto existente o propuso uno nuevo).
     * - REJECT: siempre disponible, mismo motivo estructurado que ya usaba el flujo legacy
     *   (`CandidateConceptApprovalService::REJECT_REASON_LABELS`/`composeReviewReason()` - motivo
     *   textual reutilizado, la ESCRITURA real ahora es 100% vía `freeze()`, nunca
     *   `CandidateConceptApprovalService::reject()`).
     */
    private static function freezeReviewForm(TaxonomyCandidateConceptLink $record): array
    {
        $service = app(CandidateConceptApprovalService::class);
        $builder = app(CanonicalConceptBuilderService::class);
        $explorer = app(ConceptExplorerService::class);

        $activeConceptCount = $explorer->activeConceptCount();

        $duplicates = $service->findPossibleDuplicateConcepts($record);
        $staleness = $service->proposalStaleness($record);

        $duplicateOptions = collect($duplicates)->mapWithKeys(fn (array $d) => [
            $d['concept_id'] => "{$d['concept_name']} (score {$d['score']}, tier {$d['tier']})",
        ])->all();
        if ($record->suggested_concept_id !== null && ! isset($duplicateOptions[$record->suggested_concept_id])) {
            $duplicateOptions[$record->suggested_concept_id] = $record->concept?->display_name ?? "#{$record->suggested_concept_id}";
        }

        $stalenessText = match (true) {
            ! $staleness['tracked'] => 'No rastreado (candidato generado antes de Phase C, sin fingerprint estampado).',
            $staleness['stale'] => 'ADVERTENCIA: el grafo de conceptos cambió desde que se generó este candidato - revisar la evidencia de nuevo.',
            default => 'Sin cambios en el grafo de conceptos desde que se generó este candidato.',
        };

        $duplicatesText = $duplicates === []
            ? 'Ninguno - ningún concepto existente corroboró lo suficiente (mismo pipeline de scoring del Builder).'
            : collect($duplicates)->map(fn (array $d) => "#{$d['concept_id']} {$d['concept_name']} — score {$d['score']}, tier {$d['tier']}")->implode(' | ');

        $decisionOptions = [
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING => 'Mapear a un concepto existente',
        ];
        if ($record->isProposingNewConcept()) {
            $decisionOptions[TaxonomyReviewedProposal::DECISION_CREATE_NEW] = 'Crear concepto nuevo';
        }
        $decisionOptions[TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED] = 'Término genérico - requiere contexto (sin mapeo directo)';
        $decisionOptions[TaxonomyReviewedProposal::DECISION_REJECT] = 'Rechazar';

        return [
            Forms\Components\Placeholder::make('term_summary')
                ->label('Término candidato')
                ->content(sprintf(
                    '%s (idioma: %s, tipo: %s, región: %s)',
                    $record->term?->term ?? '—',
                    $record->term?->language ?? '—',
                    $record->term?->term_type ?? '—',
                    is_array($record->term?->region ?? null) ? implode(', ', $record->term->region) : '—',
                )),
            Forms\Components\Placeholder::make('builder_suggestion')
                ->label('Sugerencia del Builder (solo evidencia/referencia - nunca se usa implícitamente)')
                ->content($record->isProposingNewConcept()
                    ? ('Concepto NUEVO propuesto: "'.($record->suggested_new_concept_name ?: '—').'"')
                    : ('Concepto existente sugerido: '.($record->concept?->display_name ?? "#{$record->suggested_concept_id}"))),
            Forms\Components\Placeholder::make('duplicates_summary')
                ->label('Posibles conceptos existentes (evitar duplicado)')
                ->content($duplicatesText),
            Forms\Components\Placeholder::make('staleness_banner')
                ->label('Estado del grafo de conceptos')
                ->content($stalenessText),
            // TASK-0006A, sección C: la distinción entre las dos decisiones tiene que ser explícita
            // en la UI, no implícita. NO se agrega ninguna regla automática que mande un término
            // amplio a CONTEXT_REQUIRED - la decisión final sigue siendo humana.
            Forms\Components\Placeholder::make('polysemy_guidance')
                ->label('Cómo elegir entre mapear y pedir contexto')
                ->content(new HtmlString(
                    '<div class="text-sm space-y-1">'
                    .'<p><strong>MAP_TO_EXISTING</strong>: este término tiene UN significado canónico suficientemente específico e incondicional para este uso de la taxonomía.</p>'
                    .'<p><strong>CONTEXT_REQUIRED</strong>: el término es válido pero demasiado amplio, polisémico o ambiguo para mapearlo sin contexto alrededor.</p>'
                    .'<p class="text-gray-500">Mapear vincula TÉRMINO → CONCEPTO CANÓNICO. No es "elegir todas las categorías CPV que contengan una palabra parecida": '
                    .'las categorías CPV del panel de abajo son consecuencia del concepto, mostradas como evidencia para que puedas evaluarla antes de decidir.</p>'
                    .'</div>'
                )),
            Forms\Components\Radio::make('decision')
                ->label('Decisión de revisión (C2 - solo congela, no publica)')
                ->options($decisionOptions)
                ->required()
                ->live(),
            // TASK-0006A re-audit (comentario `5930560603`), corrección 1: EXPLORADOR PAGINADO.
            //
            // La versión anterior usaba `->searchable()` + `getSearchResultsUsing()`, que devolvía
            // como máximo N opciones SIN mostrarle al revisor que existían más y sin forma de
            // alcanzarlas - el servicio informaba el overflow pero la UI lo descartaba. Ahora el
            // descubrimiento es un control explícito: campo de búsqueda + paginación + línea de
            // estado visible. El `Select` lista la PÁGINA actual, así que toda coincidencia es
            // alcanzable paginando, y no queda ningún camino con tope silencioso.
            //
            // El tamaño de página es chico y estable a propósito (no se subió al tamaño del catálogo
            // actual): el diseño tiene que seguir siendo correcto cuando el catálogo crezca.
            Forms\Components\TextInput::make('concept_search')
                ->label('Buscar en el catálogo de conceptos')
                ->helperText(sprintf(
                    'Busca sobre los %d conceptos ACTIVOS por nombre ES, nombre EN, término miembro y alias. Vacío = navegar el catálogo completo.',
                    $activeConceptCount,
                ))
                ->live(debounce: 500)
                ->afterStateUpdated(fn (Forms\Set $set) => $set('concept_page', 1))
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                ], true)),
            Forms\Components\Hidden::make('concept_page')->default(1),
            Forms\Components\Placeholder::make('concept_explorer_status')
                ->label('Cobertura de la búsqueda')
                ->content(fn (Get $get) => new HtmlString(
                    '<p class="text-sm">'.e($explorer->explorerStatusLine(
                        $explorer->searchActiveConcepts($get('concept_search'), page: (int) ($get('concept_page') ?: 1))
                    )).'</p>'
                ))
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                ], true)),
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('conceptPagePrevious')
                    ->label('Página anterior')
                    ->icon('heroicon-o-chevron-left')
                    ->link()
                    ->disabled(fn (Get $get) => ((int) ($get('concept_page') ?: 1)) <= 1)
                    ->action(fn (Forms\Set $set, Get $get) => $set('concept_page', max(1, ((int) ($get('concept_page') ?: 1)) - 1))),
                Forms\Components\Actions\Action::make('conceptPageNext')
                    ->label('Página siguiente')
                    ->icon('heroicon-o-chevron-right')
                    ->link()
                    ->disabled(fn (Get $get) => ! $explorer->searchActiveConcepts(
                        $get('concept_search'), page: (int) ($get('concept_page') ?: 1)
                    )['has_more'])
                    ->action(fn (Forms\Set $set, Get $get) => $set('concept_page', ((int) ($get('concept_page') ?: 1)) + 1)),
            ])
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                ], true)),
            Forms\Components\Select::make('target_concept_id')
                ->label('Concepto destino')
                ->helperText('Elegí explícitamente el concepto destino - no se asume la sugerencia del Builder aunque coincida. Lista la página actual del explorador; paginá arriba para alcanzar cualquier coincidencia.')
                // Las recomendaciones del Builder se mantienen SIEMPRE disponibles (evidencia), y se
                // suman a la página actual del catálogo completo.
                ->options(fn (Get $get) => $duplicateOptions + $explorer->searchOptions(
                    $get('concept_search'), page: (int) ($get('concept_page') ?: 1)
                ))
                ->getOptionLabelUsing(fn ($value) => ($c = TaxonomyCanonicalConcept::find($value)) ? $explorer->optionLabel($c) : null)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING)
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING)
                ->live(),
            // TASK-0006A, sección C: para CONTEXT_REQUIRED se permite inspeccionar conceptos como
            // EVIDENCIA, sin crear ningún mapeo. Este campo es puramente diagnóstico: alimenta el
            // panel de abajo y NUNCA entra al payload congelado (ver el `match` de la acción, que
            // para CONTEXT_REQUIRED solo toma `context_reason`).
            Forms\Components\Select::make('inspect_concept_id')
                ->label('Inspeccionar un concepto (solo evidencia - no crea ningún mapeo)')
                ->helperText('Sirve para comprobar si algún concepto existente sería suficientemente específico. Elegir acá no mapea nada. Lista la página actual del explorador.')
                ->options(fn (Get $get) => $explorer->searchOptions(
                    $get('concept_search'), page: (int) ($get('concept_page') ?: 1)
                ))
                ->getOptionLabelUsing(fn ($value) => ($c = TaxonomyCanonicalConcept::find($value)) ? $explorer->optionLabel($c) : null)
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED)
                ->live(),
            // TASK-0006B (Issue #2 comentario `5936206843`), sección C: la identidad del concepto
            // nuevo puede declararse MONOLINGÜE (como siempre) o BILINGÜE (ES + EN explícitas). La
            // capacidad existe en `freeze()`, y tiene que ser ALCANZABLE desde esta UI - si el
            // servicio la soportara y el formulario no la ofreciera, el revisor no podría usarla
            // (es exactamente el defecto que el re-audit `5930560603` bloqueó en TASK-0006A).
            Forms\Components\Radio::make('new_concept_identity_mode')
                ->label('Identidad del concepto nuevo')
                ->options([
                    'monolingual' => 'Un solo nombre (idioma del término)',
                    'bilingual' => 'Bilingüe: nombre ES y nombre EN explícitos',
                ])
                ->default('monolingual')
                ->helperText('Usá "bilingüe" cuando el mismo concepto tenga nombre propio en español y en inglés. Nunca se traduce automáticamente: las dos se escriben a mano.')
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW)
                ->live(),
            Forms\Components\TextInput::make('new_concept_name')
                ->label('Nombre del concepto nuevo (elegido explícitamente por el revisor)')
                ->helperText('Nunca se completa solo con la sugerencia del Builder de arriba - escribilo o copialo a propósito.')
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') !== 'bilingual')
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') !== 'bilingual'),
            Forms\Components\TextInput::make('canonical_name_es')
                ->label('Nombre canónico ES')
                ->helperText('El nombre español del concepto. No se deriva del inglés ni del nombre sugerido.')
                ->maxLength(255)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') === 'bilingual')
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') === 'bilingual'),
            Forms\Components\TextInput::make('canonical_name_en')
                ->label('Nombre canónico EN')
                ->helperText('El nombre inglés del concepto. No se deriva del español ni del nombre sugerido.')
                ->maxLength(255)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') === 'bilingual')
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') === 'bilingual'),
            // TASK-0006B, sección D: convergencia gobernada - varios candidatos bilingües que
            // resuelven a UN SOLO concepto. Sin esto, cada término del par recibiría su propio
            // CREATE_NEW y `apply()` crearía DOS conceptos duplicados. La lista ofrece los demás
            // candidatos `pending` que proponen concepto nuevo, con su idioma visible, y marca con
            // una señal los que comparten `canonical_term` con este (evidencia de que son el mismo
            // concepto en otro idioma) - la señal es sugerencia, no filtro: el revisor decide.
            Forms\Components\Select::make('converge_candidate_ids')
                ->label('Converger con otros candidatos en este MISMO concepto (opcional)')
                ->helperText('Elegí el/los candidatos del otro idioma que designan el mismo concepto. Se congela UNA revisión agrupada: apply() creará un solo concepto y adjuntará todos los términos, nunca uno por candidato. El grupo tiene que abarcar español e inglés.')
                ->multiple()
                ->options(fn () => self::convergenceCandidateOptions($record))
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW
                    && $get('new_concept_identity_mode') === 'bilingual')
                ->live(),
            Forms\Components\Textarea::make('context_reason')
                ->label('Motivo (por qué necesita contexto, sin mapeo directo)')
                ->rows(2)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED)
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED),
            // TASK-0006A, sección B: panel de diagnóstico de solo lectura del concepto elegido (o
            // inspeccionado). Todo sale de datos ya gobernados vía `ConceptExplorerService` - nada
            // se inventa acá. Reacciona a `target_concept_id` (MAP_TO_EXISTING) o a
            // `inspect_concept_id` (CONTEXT_REQUIRED, solo evidencia).
            Forms\Components\Placeholder::make('concept_diagnostics')
                ->label('Diagnóstico del concepto (identidad, términos, CPV alcanzable, impacto)')
                ->content(function (Get $get) use ($explorer, $activeConceptCount) {
                    $concept = self::conceptUnderInspection($get);

                    if (! $concept) {
                        return new HtmlString(sprintf(
                            '<p class="text-sm text-gray-500">Elegí un concepto para ver su diagnóstico. El catálogo tiene %d conceptos activos y el explorador de arriba los recorre todos, paginando.</p>',
                            $activeConceptCount,
                        ));
                    }

                    return self::formatConceptDiagnostics($explorer->diagnostics(
                        $concept,
                        categoryPage: (int) ($get('cpv_page') ?: 1),
                        categorySearch: $get('cpv_search'),
                    ), $explorer);
                })
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                ], true)),
            // TASK-0006A re-audit (`5930560603`), corrección 2: declarar "y N más" no alcanzaba -
            // el revisor necesita poder INSPECCIONAR las categorías omitidas para decidir si un
            // término amplio es seguro de mapear. Filtro + paginación sobre las categorías CPV.
            Forms\Components\TextInput::make('cpv_search')
                ->label('Filtrar categorías CPV del diagnóstico')
                ->helperText('Por código (ej. CPV-26) o por nombre traducido. Vacío = todas las alcanzables.')
                ->live(debounce: 500)
                ->afterStateUpdated(fn (Forms\Set $set) => $set('cpv_page', 1))
                ->visible(fn (Get $get) => self::conceptUnderInspection($get) !== null),
            Forms\Components\Hidden::make('cpv_page')->default(1),
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('cpvPagePrevious')
                    ->label('CPV: página anterior')
                    ->icon('heroicon-o-chevron-left')
                    ->link()
                    ->disabled(fn (Get $get) => ((int) ($get('cpv_page') ?: 1)) <= 1)
                    ->action(fn (Forms\Set $set, Get $get) => $set('cpv_page', max(1, ((int) ($get('cpv_page') ?: 1)) - 1))),
                Forms\Components\Actions\Action::make('cpvPageNext')
                    ->label('CPV: página siguiente')
                    ->icon('heroicon-o-chevron-right')
                    ->link()
                    ->disabled(function (Get $get) use ($explorer) {
                        $concept = self::conceptUnderInspection($get);
                        if (! $concept) {
                            return true;
                        }

                        return ! $explorer->diagnostics(
                            $concept,
                            categoryPage: (int) ($get('cpv_page') ?: 1),
                            categorySearch: $get('cpv_search'),
                        )['category_has_more'];
                    })
                    ->action(fn (Forms\Set $set, Get $get) => $set('cpv_page', ((int) ($get('cpv_page') ?: 1)) + 1)),
            ])
                ->visible(fn (Get $get) => self::conceptUnderInspection($get) !== null),
            Forms\Components\Placeholder::make('impact_preview')
                ->label('Impacto predicho (empresas)')
                ->content(function (Get $get) use ($record, $builder) {
                    if ($get('decision') === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING) {
                        $targetConceptId = $get('target_concept_id');
                        $concept = $targetConceptId ? TaxonomyCanonicalConcept::find($targetConceptId) : null;
                        if (! $concept) {
                            return 'Elegí un concepto destino para ver el impacto predicho.';
                        }
                        $impact = $builder->predictedImpactForConcept($concept);
                    } elseif ($get('decision') === TaxonomyReviewedProposal::DECISION_CREATE_NEW) {
                        $categoryIds = $builder->conceptApprovedCategoryIds([$record->suggested_term_id]);
                        $impact = $builder->predictAffectedCompanies($categoryIds);
                    } else {
                        return '—';
                    }

                    return self::formatImpactSummary($impact);
                })
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                ], true)),
            Forms\Components\Select::make('reject_reason_category')
                ->label('Motivo de rechazo')
                ->options(CandidateConceptApprovalService::REJECT_REASON_LABELS)
                ->native(false)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_REJECT)
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_REJECT),
            Forms\Components\Textarea::make('notes')
                ->label('Nota')
                ->rows(2)
                ->required(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_REJECT
                    && $get('reject_reason_category') === CandidateConceptApprovalService::REJECT_REASON_OTHER)
                ->visible(fn (Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_REJECT),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyCandidateConceptLinks::route('/'),
            'view' => Pages\ViewTaxonomyCandidateConceptLink::route('/{record}'),
            'edit' => Pages\EditTaxonomyCandidateConceptLink::route('/{record}/edit'),
        ];
    }
}
