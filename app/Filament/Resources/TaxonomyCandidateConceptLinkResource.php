<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Phase 3.1 (sección 9 del pedido): la lógica real (autorización, re-chequeo de
                // status con lock, transacción, idempotencia, audit log) vive en
                // CandidateConceptApprovalService - este Action solo llama al servicio e informa el
                // resultado. `visible()` incluye el chequeo de permiso (Filament no lo aplica solo
                // automáticamente a Actions custom) - es UX, no la salvaguarda real: la salvaguarda
                // real está en el servicio, que se re-verifica sin importar qué mostró la UI.
                //
                // Phase B.1: solo para candidatos con concepto sugerido por el algoritmo (no
                // PROPOSE_NEW_CONCEPT - esos van por `resolveNewConcept` abajo). Antes de confirmar
                // muestra impacto predicho + estado del grafo (secciones 5/11 del pedido).
                Tables\Actions\Action::make('approve')
                    ->label('Aprobar y publicar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING
                        && ! $record->isProposingNewConcept()
                        && Auth::user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (TaxonomyCandidateConceptLink $record) => self::impactAndStalenessSummary($record))
                    ->action(function (TaxonomyCandidateConceptLink $record) {
                        $outcome = app(CandidateConceptApprovalService::class)->approve($record->id, Auth::user());

                        match ($outcome['result']) {
                            CandidateConceptApprovalService::RESULT_APPROVED => Notification::make()
                                ->title("Candidato aprobado y publicado en taxonomy_term_concepts#{$outcome['term_concept_id']}")->success()->send(),
                            CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED => Notification::make()
                                ->title('Este candidato ya fue procesado (doble click o ya revisado por otro admin) - no se creó ningún link nuevo.')->warning()->send(),
                            CandidateConceptApprovalService::RESULT_NOT_SUPPORTED => Notification::make()
                                ->title('PROPOSE_NEW_CONCEPT no se puede aprobar desde acá todavía.')->danger()->send(),
                            CandidateConceptApprovalService::RESULT_UNAUTHORIZED => Notification::make()
                                ->title('No tenés permiso para aprobar candidatos.')->danger()->send(),
                            default => Notification::make()->title('El candidato ya no existe.')->danger()->send(),
                        };
                    }),
                // Phase B.1 (sección 7 del pedido): rechazo simple, ahora con motivo estructurado
                // obligatorio (antes no pedía ningún motivo). Solo para candidatos NO
                // PROPOSE_NEW_CONCEPT - los de concepto nuevo se rechazan vía `resolveNewConcept`
                // (decisión REJECT), un solo camino por tipo de candidato, sin dos botones que hagan
                // lo mismo con distinta UI.
                Tables\Actions\Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING
                        && ! $record->isProposingNewConcept()
                        && Auth::user()?->can('update', $record))
                    ->form([
                        Forms\Components\Select::make('reject_reason_category')
                            ->label('Motivo de rechazo')
                            ->options(CandidateConceptApprovalService::REJECT_REASON_LABELS)
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Nota (opcional salvo motivo "Otro")')
                            ->rows(2)
                            ->required(fn (Get $get) => $get('reject_reason_category') === CandidateConceptApprovalService::REJECT_REASON_OTHER),
                    ])
                    ->action(function (TaxonomyCandidateConceptLink $record, array $data) {
                        $notes = CandidateConceptApprovalService::composeReviewReason($data['reject_reason_category'], $data['notes'] ?? null);
                        $outcome = app(CandidateConceptApprovalService::class)->reject($record->id, Auth::user(), $notes);

                        match ($outcome['result']) {
                            CandidateConceptApprovalService::RESULT_REJECTED => Notification::make()->title('Candidato rechazado')->success()->send(),
                            CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED => Notification::make()
                                ->title('Este candidato ya fue procesado (doble click o ya revisado por otro admin).')->warning()->send(),
                            CandidateConceptApprovalService::RESULT_UNAUTHORIZED => Notification::make()
                                ->title('No tenés permiso para rechazar candidatos.')->danger()->send(),
                            default => Notification::make()->title('El candidato ya no existe.')->danger()->send(),
                        };
                    }),
                // Phase B.1 (objetivo principal de esta entrega): resuelve candidatos
                // PROPOSE_NEW_CONCEPT (`suggested_concept_id === null`) con las 3 decisiones que
                // `CandidateConceptApprovalService::resolveNewConceptProposal()` ya soporta desde
                // Phase B, pero que hasta ahora ningún botón invocaba. Igual que arriba: la
                // autorización/idempotencia/transacción reales viven en el servicio, este Action
                // solo las invoca e informa el resultado.
                Tables\Actions\Action::make('resolveNewConcept')
                    ->label('Resolver propuesta de concepto nuevo')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING
                        && $record->isProposingNewConcept()
                        && Auth::user()?->can('update', $record))
                    ->form(fn (TaxonomyCandidateConceptLink $record) => self::resolveNewConceptForm($record))
                    ->action(function (TaxonomyCandidateConceptLink $record, array $data) {
                        $notes = $data['decision'] === CandidateConceptApprovalService::DECISION_REJECT
                            ? CandidateConceptApprovalService::composeReviewReason($data['reject_reason_category'], $data['notes'] ?? null)
                            : ($data['notes'] ?? null);

                        $outcome = app(CandidateConceptApprovalService::class)->resolveNewConceptProposal(
                            $record->id,
                            Auth::user(),
                            $data['decision'],
                            $data['target_concept_id'] ?? null,
                            $notes,
                        );

                        match ($outcome['result']) {
                            CandidateConceptApprovalService::RESULT_MAPPED_TO_EXISTING => Notification::make()
                                ->title("Mapeado a concepto existente #{$outcome['concept_id']} -> taxonomy_term_concepts#{$outcome['term_concept_id']}")->success()->send(),
                            CandidateConceptApprovalService::RESULT_CREATED_NEW_CONCEPT => Notification::make()
                                ->title("Concepto nuevo creado #{$outcome['concept_id']} -> taxonomy_term_concepts#{$outcome['term_concept_id']}")->success()->send(),
                            CandidateConceptApprovalService::RESULT_REJECTED => Notification::make()->title('Candidato rechazado')->success()->send(),
                            CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED => Notification::make()
                                ->title('Este candidato ya fue procesado (doble click o ya revisado por otro admin) - no se hizo ningún cambio.')->warning()->send(),
                            CandidateConceptApprovalService::RESULT_UNAUTHORIZED => Notification::make()
                                ->title('No tenés permiso para resolver este candidato.')->danger()->send(),
                            CandidateConceptApprovalService::RESULT_NOT_FOUND => Notification::make()
                                ->title('El concepto destino seleccionado ya no existe, o el candidato ya no existe.')->danger()->send(),
                            CandidateConceptApprovalService::RESULT_NOT_APPLICABLE => Notification::make()
                                ->title('Este candidato ya no es una propuesta de concepto nuevo (refrescá la página).')->danger()->send(),
                            default => Notification::make()->title('No se pudo procesar la decisión.')->danger()->send(),
                        };
                    }),
            ]);
    }

    /**
     * Phase B.1 (seguimiento 2026-09-25): texto compartido de impacto predicho, con el desglose
     * confirmado/sugerido de `predictAffectedCompanies()` - reusado por el modal de `approve`, el
     * formulario de `resolveNewConcept` y la vista de detalle, para no repetir 3 veces la misma
     * lógica de formato con el riesgo de que se desincronicen entre sí.
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

    /** Phase B.1 (secciones 5/11 del pedido): texto de impacto + estado del grafo mostrado antes de confirmar `approve`. */
    private static function impactAndStalenessSummary(TaxonomyCandidateConceptLink $record): string
    {
        $lines = [];

        if ($record->concept) {
            $impact = app(CanonicalConceptBuilderService::class)->predictedImpactForConcept($record->concept);
            $lines[] = self::formatImpactSummary($impact);
        }

        $staleness = app(CandidateConceptApprovalService::class)->conceptGraphStaleness($record);
        $lines[] = match (true) {
            ! $staleness['tracked'] => 'Estado del grafo de conceptos: no rastreado (candidato generado antes de Phase C).',
            $staleness['stale'] => 'ADVERTENCIA: el grafo de conceptos cambió desde que se generó este candidato - revisar de nuevo antes de aprobar.',
            default => 'Estado del grafo de conceptos: sin cambios desde que se generó este candidato.',
        };

        return implode("\n", $lines);
    }

    /** Phase B.1 (secciones 5/6/7/10/11 del pedido): formulario de decisión para candidatos PROPOSE_NEW_CONCEPT. */
    private static function resolveNewConceptForm(TaxonomyCandidateConceptLink $record): array
    {
        $service = app(CandidateConceptApprovalService::class);
        $builder = app(CanonicalConceptBuilderService::class);

        $duplicates = $service->findPossibleDuplicateConcepts($record);
        $staleness = $service->conceptGraphStaleness($record);

        $duplicateOptions = collect($duplicates)->mapWithKeys(fn (array $d) => [
            $d['concept_id'] => "{$d['concept_name']} (score {$d['score']}, tier {$d['tier']})",
        ])->all();

        $stalenessText = match (true) {
            ! $staleness['tracked'] => 'No rastreado (candidato generado antes de Phase C, sin fingerprint estampado).',
            $staleness['stale'] => 'ADVERTENCIA: el grafo de conceptos cambió desde que se generó este candidato - revisar la evidencia de nuevo.',
            default => 'Sin cambios en el grafo de conceptos desde que se generó este candidato.',
        };

        $duplicatesText = $duplicates === []
            ? 'Ninguno - ningún concepto existente corroboró lo suficiente (mismo pipeline de scoring del Builder).'
            : collect($duplicates)->map(fn (array $d) => "#{$d['concept_id']} {$d['concept_name']} — score {$d['score']}, tier {$d['tier']}")->implode(' | ');

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
            Forms\Components\Placeholder::make('proposed_name')
                ->label('Nombre de concepto propuesto')
                ->content($record->suggested_new_concept_name ?: ($record->term?->canonical_term ?? '—')),
            Forms\Components\Placeholder::make('duplicates_summary')
                ->label('Posibles conceptos existentes (evitar duplicado)')
                ->content($duplicatesText),
            Forms\Components\Placeholder::make('staleness_banner')
                ->label('Estado del grafo de conceptos')
                ->content($stalenessText),
            Forms\Components\Radio::make('decision')
                ->label('Decisión')
                ->options([
                    CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING => 'Mapear a un concepto existente',
                    CandidateConceptApprovalService::DECISION_CREATE_NEW => 'Crear concepto nuevo',
                    CandidateConceptApprovalService::DECISION_REJECT => 'Rechazar',
                ])
                ->required()
                ->live(),
            Forms\Components\Select::make('target_concept_id')
                ->label('Concepto destino')
                ->options($duplicateOptions)
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => TaxonomyCanonicalConcept::query()
                    ->where(fn ($q) => $q->where('canonical_name_en', 'ilike', "%{$search}%")->orWhere('canonical_name_es', 'ilike', "%{$search}%"))
                    ->limit(20)->get()->mapWithKeys(fn (TaxonomyCanonicalConcept $c) => [$c->id => $c->display_name])->all())
                ->getOptionLabelUsing(fn ($value) => TaxonomyCanonicalConcept::find($value)?->display_name)
                ->required(fn (Get $get) => $get('decision') === CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING)
                ->visible(fn (Get $get) => $get('decision') === CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING)
                ->live(),
            Forms\Components\Placeholder::make('impact_preview')
                ->label('Impacto predicho (empresas)')
                ->content(function (Get $get) use ($record, $builder) {
                    if ($get('decision') === CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING) {
                        $targetConceptId = $get('target_concept_id');
                        $concept = $targetConceptId ? TaxonomyCanonicalConcept::find($targetConceptId) : null;
                        if (! $concept) {
                            return 'Elegí un concepto destino para ver el impacto predicho.';
                        }
                        $impact = $builder->predictedImpactForConcept($concept);
                    } elseif ($get('decision') === CandidateConceptApprovalService::DECISION_CREATE_NEW) {
                        $categoryIds = $builder->conceptApprovedCategoryIds([$record->suggested_term_id]);
                        $impact = $builder->predictAffectedCompanies($categoryIds);
                    } else {
                        return '—';
                    }

                    return self::formatImpactSummary($impact);
                })
                ->visible(fn (Get $get) => in_array($get('decision'), [
                    CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING,
                    CandidateConceptApprovalService::DECISION_CREATE_NEW,
                ], true)),
            Forms\Components\Select::make('reject_reason_category')
                ->label('Motivo de rechazo')
                ->options(CandidateConceptApprovalService::REJECT_REASON_LABELS)
                ->native(false)
                ->required(fn (Get $get) => $get('decision') === CandidateConceptApprovalService::DECISION_REJECT)
                ->visible(fn (Get $get) => $get('decision') === CandidateConceptApprovalService::DECISION_REJECT),
            Forms\Components\Textarea::make('notes')
                ->label('Nota')
                ->rows(2)
                ->required(fn (Get $get) => $get('decision') === CandidateConceptApprovalService::DECISION_REJECT
                    && $get('reject_reason_category') === CandidateConceptApprovalService::REJECT_REASON_OTHER),
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
