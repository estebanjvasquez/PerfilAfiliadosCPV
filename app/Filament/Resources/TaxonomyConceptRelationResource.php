<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyConceptRelationResource\Pages;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyConceptRelationType;
use App\Models\TaxonomyReviewedProposal;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 3 (secciones 5/6/20 del pedido): CRUD administrativo de relaciones semánticas TIPADAS entre
 * `taxonomy_canonical_concepts`. Distinto de `TermsRelationManager` (que vincula TÉRMINO->CONCEPTO,
 * identidad) - acá se vincula CONCEPTO->CONCEPTO (ej. un futuro PART_OF).
 *
 * Esta tabla queda en 0 filas al terminar esta entrega (el Builder no la puebla todavía) - el
 * recurso existe como infraestructura administrable desde ya, sin esperar a `--apply`.
 *
 * TASK-0005 (Issue #2 comentario `5914793857`), sección B: agrega `freezeReview`, el camino de
 * revisión C2 real para relaciones candidatas - llama a `ReviewedProposalService::freeze()`
 * (PUBLISH_RELATION/REJECT), nunca publica/aprueba.
 *
 * TASK-0005 re-audit (comentario `5917275454`, corrección 2): antes este recurso exponía
 * `EditAction` + `DeleteAction` sobre CUALQUIER fila, incluidas las candidatas en revisión C2.
 * La publicación-vía-edit ya estaba bloqueada (TASK-0004 HIGH-2), pero un revisor común todavía
 * podía mutar o BORRAR una relación candidata al margen del ciclo inmutable de revisión - y borrar
 * la fila fuente destruye la evidencia/estado de la revisión en lugar de producir un REJECT
 * congelado.
 *
 * El CRUD administrativo genérico se conserva, pero SEPARADO por estado: `canEdit()`/`canDelete()`
 * devuelven false para toda fila con `isUnderC2Review()` (status=candidate, o con una propuesta
 * `PENDING_APPLY`). Eso cierra a la vez la acción de tabla y la ruta `/{record}/edit`, porque
 * `EditRecord::authorizeAccess()` consulta `canEdit()` del recurso. El borrado además está bloqueado
 * a nivel de modelo (`TaxonomyConceptRelation::booted()`), así que tampoco pasa por tinker/API.
 *
 * Lo que SÍ sigue disponible, deliberadamente y de forma acotada: crear relaciones nuevas, y
 * editar/borrar relaciones cuyo ciclo de revisión ya terminó (`approved`/`rejected` sin propuesta
 * pendiente). Crear no puede rodear ninguna revisión - una fila nueva en `candidate` queda
 * inmediatamente gobernada por C2, y la transición directa a `approved` sigue bloqueada por el guard
 * de publicación del modelo.
 */
class TaxonomyConceptRelationResource extends Resource
{
    protected static ?string $model = TaxonomyConceptRelation::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Relaciones entre conceptos (Phase 3)';

    public static ?string $label = 'Relación entre conceptos';

    protected static ?string $pluralModelLabel = 'Relaciones entre conceptos';

    protected static ?int $navigationSort = 16;

    /**
     * TASK-0005 re-audit (comentario `5917275454`, corrección 2): una relación en revisión C2 no se
     * edita ni se borra por el CRUD genérico. Filament consulta estos dos métodos tanto para las
     * acciones de tabla como para autorizar la ruta de la página de edición
     * (`EditRecord::authorizeAccess()`), así que cierran la UI y la ruta de una sola vez. El permiso
     * normal del modelo de permisos del proyecto sigue siendo condición necesaria - esto solo agrega
     * la restricción de ciclo de vida encima, nunca la reemplaza.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && ! $record->isUnderC2Review();
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! $record->isUnderC2Review();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('source_concept_id')
                ->label('Concepto origen')
                ->options(fn () => TaxonomyCanonicalConcept::query()->orderBy('canonical_name_en')->get()->pluck('display_name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('target_concept_id')
                ->label('Concepto destino')
                ->options(fn () => TaxonomyCanonicalConcept::query()->orderBy('canonical_name_en')->get()->pluck('display_name', 'id'))
                ->searchable()
                ->required()
                ->different('source_concept_id'),
            Forms\Components\Select::make('relation_type')
                ->label('Tipo de relación')
                ->helperText('Vocabulario gobernado - ver "Tipos de relación entre conceptos". No es un mapping_relation_type de CPV (esa es otra dimensión, ver sección 11 del pedido).')
                ->options(fn () => TaxonomyConceptRelationType::activeOptions())
                ->native(false)
                ->required(),
            Forms\Components\TextInput::make('weight')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->default(0.5)->required(),
            Forms\Components\TextInput::make('confidence')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->default(0.5)->required(),
            Forms\Components\Select::make('status')
                ->options([
                    TaxonomyConceptRelation::STATUS_CANDIDATE => 'Candidata',
                    TaxonomyConceptRelation::STATUS_APPROVED => 'Aprobada',
                    TaxonomyConceptRelation::STATUS_REJECTED => 'Rechazada',
                ])
                ->native(false)
                ->required()
                ->default(TaxonomyConceptRelation::STATUS_CANDIDATE),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sourceConcept.display_name')->label('Origen')->searchable(),
                Tables\Columns\TextColumn::make('relation_type')->label('Relación')->badge(),
                Tables\Columns\TextColumn::make('targetConcept.display_name')->label('Destino')->searchable(),
                Tables\Columns\TextColumn::make('weight')->numeric(4)->sortable(),
                Tables\Columns\TextColumn::make('confidence')->numeric(4)->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => TaxonomyConceptRelation::STATUS_CANDIDATE,
                        'success' => TaxonomyConceptRelation::STATUS_APPROVED,
                        'danger' => TaxonomyConceptRelation::STATUS_REJECTED,
                    ]),
                // TASK-0005: freeze() NUNCA toca `status`/`reviewed_at` de esta fila - mismo
                // criterio que la columna equivalente en TaxonomyCandidateConceptLinkResource.
                Tables\Columns\BadgeColumn::make('reviewed_proposal_state')
                    ->label('Propuesta C2')
                    ->getStateUsing(function (TaxonomyConceptRelation $record) {
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
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('relation_type')->options(fn () => TaxonomyConceptRelationType::activeOptions()),
                Tables\Filters\SelectFilter::make('status')->options([
                    TaxonomyConceptRelation::STATUS_CANDIDATE => 'Candidata',
                    TaxonomyConceptRelation::STATUS_APPROVED => 'Aprobada',
                    TaxonomyConceptRelation::STATUS_REJECTED => 'Rechazada',
                ]),
            ])
            ->actions([
                // TASK-0005, sección B: revisión C2 real - el humano revisa explícitamente
                // origen/destino/tipo (mostrados como evidencia de solo lectura en el formulario,
                // ver `freezeReviewForm()`) y congela PUBLISH_RELATION o REJECT. Nunca aprueba/
                // publica desde acá - `visible()` es UX, la autorización real vive en
                // `ReviewedProposalService::freeze()` (misma policy `update` que ya gobernaba
                // `EditAction`).
                Tables\Actions\Action::make('freezeReview')
                    ->label('Revisar (congelar decisión C2)')
                    ->icon('heroicon-o-lock-closed')
                    ->color('primary')
                    ->visible(fn (TaxonomyConceptRelation $record) => $record->status === TaxonomyConceptRelation::STATUS_CANDIDATE
                        && Auth::user()?->can('update', $record))
                    ->form(fn (TaxonomyConceptRelation $record) => self::freezeReviewForm($record))
                    ->action(function (TaxonomyConceptRelation $record, array $data) {
                        $decision = $data['decision'];
                        $payload = $decision === TaxonomyReviewedProposal::DECISION_REJECT
                            ? ['notes' => $data['notes'] ?? null]
                            : [];

                        $outcome = app(ReviewedProposalService::class)->freeze(
                            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
                            $record->id,
                            $decision,
                            Auth::user(),
                            $payload,
                        );

                        match ($outcome['result']) {
                            ReviewedProposalService::RESULT_FROZEN => Notification::make()
                                ->title('Decisión de revisión congelada')
                                ->body('Esto NO aprobó ni publicó la relación - queda como una propuesta inmutable pendiente de un paso de aplicación separado y explícitamente autorizado.')
                                ->success()->send(),
                            ReviewedProposalService::RESULT_UNAUTHORIZED => Notification::make()
                                ->title('No tenés permiso para revisar esta relación.')->danger()->send(),
                            ReviewedProposalService::RESULT_ALREADY_PROCESSED => Notification::make()
                                ->title('Esta relación ya fue procesada (doble click o ya revisada por otro admin) - no se congeló nada nuevo.')->warning()->send(),
                            ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL => Notification::make()
                                ->title('Ya existe una propuesta de revisión congelada pendiente de aplicación para esta relación.')->warning()->send(),
                            ReviewedProposalService::RESULT_NOT_FOUND => Notification::make()
                                ->title('La relación ya no existe.')->danger()->send(),
                            default => Notification::make()->title('No se pudo congelar la decisión.')->danger()->send(),
                        };
                    }),
                // TASK-0005 re-audit, corrección 2: explícitas y redundantes a propósito respecto de
                // `canEdit()`/`canDelete()` - no dependen de qué hook interno de Filament consulte
                // cada versión, y dejan el CRUD administrativo visible SOLO para filas cuyo ciclo de
                // revisión ya terminó.
                Tables\Actions\EditAction::make()
                    ->visible(fn (TaxonomyConceptRelation $record) => self::canEdit($record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (TaxonomyConceptRelation $record) => self::canDelete($record)),
            ]);
    }

    /** TASK-0005, sección B: formulario de revisión C2 para relaciones candidatas. */
    private static function freezeReviewForm(TaxonomyConceptRelation $record): array
    {
        return [
            Forms\Components\Placeholder::make('relation_summary')
                ->label('Relación propuesta (revisar explícitamente antes de decidir)')
                ->content(sprintf(
                    '%s  —[ %s ]→  %s (peso %.4f, confianza %.4f)',
                    $record->sourceConcept?->display_name ?? "#{$record->source_concept_id}",
                    $record->relation_type,
                    $record->targetConcept?->display_name ?? "#{$record->target_concept_id}",
                    $record->weight,
                    $record->confidence,
                )),
            Forms\Components\Radio::make('decision')
                ->label('Decisión de revisión (C2 - solo congela, no publica)')
                ->options([
                    TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION => 'Publicar esta relación',
                    TaxonomyReviewedProposal::DECISION_REJECT => 'Rechazar',
                ])
                ->required()
                ->live(),
            Forms\Components\Textarea::make('notes')
                ->label('Nota (opcional)')
                ->rows(2)
                ->visible(fn (Forms\Get $get) => $get('decision') === TaxonomyReviewedProposal::DECISION_REJECT),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyConceptRelations::route('/'),
            'create' => Pages\CreateTaxonomyConceptRelation::route('/create'),
            'edit' => Pages\EditTaxonomyConceptRelation::route('/{record}/edit'),
        ];
    }
}
