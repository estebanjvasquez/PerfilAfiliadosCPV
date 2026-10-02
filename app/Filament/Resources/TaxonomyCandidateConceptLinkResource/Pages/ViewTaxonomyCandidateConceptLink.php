<?php

namespace App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource as Resource;
use App\Models\TaxonomyCandidateConceptLink;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewTaxonomyCandidateConceptLink extends ViewRecord
{
    protected static string $resource = Resource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('term.term')->label('Término candidato'),
            TextEntry::make('term.language')->label('Idioma'),
            TextEntry::make('term.region')->label('Región')->formatStateUsing(fn ($state) => is_array($state) && $state !== [] ? implode(', ', $state) : null)->placeholder('—'),
            TextEntry::make('term.term_type')->label('Tipo de término'),
            TextEntry::make('concept.display_name')->label('Concepto sugerido')->placeholder('PROPOSE_NEW_CONCEPT'),
            TextEntry::make('suggested_new_concept_name')->label('Nombre de concepto propuesto')->visible(fn (TaxonomyCandidateConceptLink $record) => $record->isProposingNewConcept()),
            TextEntry::make('confidence')->numeric(4),
            TextEntry::make('tier'),
            TextEntry::make('status'),
            // Incidente 503/500 (TASK-0002): `signals` no siempre es plano string=>string.
            // `CanonicalConceptApplyService::apply()` encola candidatos PROPOSE_NEW_CONCEPT con
            // `signals = ['possible_existing_concepts' => [...]]` (array anidado, no escalar).
            // `KeyValueEntry` llama `htmlspecialchars()` sobre cada valor tal cual viene del modelo;
            // en PHP 8 eso es un TypeError en cuanto el valor no es string (incluso un array vacío).
            // Se normaliza acá, en la capa de presentación, sin tocar la forma real de `signals` en
            // la base de datos - otros consumidores (auditoría, futuros scorers) siguen viendo la
            // estructura anidada original.
            KeyValueEntry::make('signals')
                ->label('Señales (trazabilidad completa del Builder)')
                ->state(fn (TaxonomyCandidateConceptLink $record) => collect($record->signals ?? [])
                    ->mapWithKeys(fn ($value, $key) => [
                        $key => is_scalar($value) || $value === null
                            ? (string) $value
                            : json_encode($value, JSON_UNESCAPED_UNICODE),
                    ])
                    ->all()),
            // Phase B.1 (secciones 4/10/11 del pedido): impacto predicho inspeccionable en la vista
            // de detalle, no solo en el modal de confirmación - reusa la misma fuente
            // (`predictAffectedCompanies`/`predictedImpactForConcept`), nunca una segunda semántica
            // de alcanzabilidad.
            TextEntry::make('predicted_impact')
                ->label('Impacto predicho (empresas)')
                ->state(function (TaxonomyCandidateConceptLink $record) {
                    $builder = app(CanonicalConceptBuilderService::class);

                    if ($record->isProposingNewConcept()) {
                        $categoryIds = $builder->conceptApprovedCategoryIds([$record->suggested_term_id]);
                        $impact = $builder->predictAffectedCompanies($categoryIds);
                    } elseif ($record->concept) {
                        $impact = $builder->predictedImpactForConcept($record->concept);
                    } else {
                        return '—';
                    }

                    return Resource::formatImpactSummary($impact);
                }),
            TextEntry::make('possible_duplicate_concepts')
                ->label('Posibles conceptos duplicados')
                ->visible(fn (TaxonomyCandidateConceptLink $record) => $record->isProposingNewConcept())
                ->state(fn (TaxonomyCandidateConceptLink $record) => collect(app(CandidateConceptApprovalService::class)->findPossibleDuplicateConcepts($record))
                    ->map(fn (array $d) => "#{$d['concept_id']} {$d['concept_name']} (score {$d['score']}, {$d['tier']})")
                    ->implode(' | ') ?: 'Ninguno - ningún concepto existente corroboró lo suficiente.'),
            // Phase B.1 (sección 9 del pedido): protección contra stale dry-run visible en la vista
            // de detalle, no solo dentro del formulario de decisión.
            TextEntry::make('taxonomy_graph_state')
                ->label('Estado del grafo de conceptos')
                ->state(function (TaxonomyCandidateConceptLink $record) {
                    $staleness = app(CandidateConceptApprovalService::class)->proposalStaleness($record);

                    return match (true) {
                        ! $staleness['tracked'] => 'No rastreado (candidato generado antes de Phase C, sin fingerprint estampado).',
                        $staleness['stale'] => 'ADVERTENCIA: cambió desde que se generó este candidato.',
                        default => 'Sin cambios desde que se generó este candidato.',
                    };
                }),
            // TASK-0006D (Issue #2 comentario `5949253156`), PARTE 2: la vista de detalle es donde un
            // revisor aterriza ANTES de decidir, así que tiene que decir sin ambigüedad que ya hay
            // una decisión congelada - si no, el candidato se ve "pending" (lo está, por diseño:
            // `freeze()` no toca su status) y parece sin revisar. Solo lectura, sin ninguna acción.
            TextEntry::make('frozen_reviewed_proposal')
                ->label('Propuesta C2 congelada')
                ->state(fn (TaxonomyCandidateConceptLink $record) => Resource::frozenProposalNotice($record))
                ->columnSpanFull(),
            TextEntry::make('review_notes')->label('Notas de revisión')->placeholder('—'),
            TextEntry::make('reviewedBy.name')->label('Revisado por')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Revisado el')->dateTime()->placeholder('—'),
        ]);
    }
}
