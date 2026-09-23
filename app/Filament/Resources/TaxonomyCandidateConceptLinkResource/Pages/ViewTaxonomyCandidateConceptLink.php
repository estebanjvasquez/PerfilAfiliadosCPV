<?php

namespace App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource;
use App\Models\TaxonomyCandidateConceptLink;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewTaxonomyCandidateConceptLink extends ViewRecord
{
    protected static string $resource = TaxonomyCandidateConceptLinkResource::class;

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
            KeyValueEntry::make('signals')->label('Señales (trazabilidad completa del Builder)'),
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

                    return sprintf(
                        'Directas: %d | Con evidencia de crawler: %d | Expandidas: %d | Total único: %d%s',
                        $impact['direct_company_count'],
                        $impact['evidence_company_count'],
                        $impact['expanded_company_count'],
                        $impact['total_unique_company_count'],
                        empty($impact['data_gap_flags']) ? '' : ' — brechas de datos: '.implode(', ', $impact['data_gap_flags']).' (brecha de datos esperada, no falla de motor)',
                    );
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
                    $staleness = app(CandidateConceptApprovalService::class)->conceptGraphStaleness($record);

                    return match (true) {
                        ! $staleness['tracked'] => 'No rastreado (candidato generado antes de Phase C, sin fingerprint estampado).',
                        $staleness['stale'] => 'ADVERTENCIA: cambió desde que se generó este candidato.',
                        default => 'Sin cambios desde que se generó este candidato.',
                    };
                }),
            TextEntry::make('review_notes')->label('Notas de revisión')->placeholder('—'),
            TextEntry::make('reviewedBy.name')->label('Revisado por')->placeholder('—'),
            TextEntry::make('reviewed_at')->label('Revisado el')->dateTime()->placeholder('—'),
        ]);
    }
}
