<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;
use App\Models\TaxonomyCandidateConceptLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (secciones 7/20 del pedido): cola de revisión del Canonical Concept Builder. Mismo
 * espíritu de "EXCEPTION-ONLY HUMAN REVIEW" que el pedido pide (sección 20) - un administrador no
 * debería tener que revisar miles de filas, solo la ambigüedad real que el Builder no pudo resolver
 * solo (tier REVIEW), o auditar lo que sí se auto-aceptaría antes de que exista un `--apply` real.
 *
 * Queda en 0 filas al terminar esta entrega (ningún proceso automático la puebla todavía) - el
 * recurso existe para cuando el Builder (en una fase posterior, con `--apply` habilitado) empiece a
 * insertar candidatos reales. Las acciones Aprobar/Rechazar están implementadas y funcionales, pero
 * no tienen ninguna fila sobre la que operar en esta entrega.
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
                Tables\Actions\Action::make('approve')
                    ->label('Aprobar y publicar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING && ! $record->isProposingNewConcept())
                    ->requiresConfirmation()
                    ->action(function (TaxonomyCandidateConceptLink $record) {
                        $termConceptId = DB::connection('pgsql')->table('taxonomy_term_concepts')->insertGetId([
                            'term_id' => $record->suggested_term_id,
                            'concept_id' => $record->suggested_concept_id,
                            'created_at' => now(),
                        ]);

                        $record->update([
                            'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                            'reviewed_by' => Auth::id(),
                            'reviewed_at' => now(),
                            'published_term_concept_id' => $termConceptId,
                        ]);

                        Notification::make()->title('Candidato aprobado y publicado en taxonomy_term_concepts')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->status === TaxonomyCandidateConceptLink::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(fn (TaxonomyCandidateConceptLink $record) => $record->update([
                        'status' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
                        'reviewed_by' => Auth::id(),
                        'reviewed_at' => now(),
                    ])),
            ]);
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
