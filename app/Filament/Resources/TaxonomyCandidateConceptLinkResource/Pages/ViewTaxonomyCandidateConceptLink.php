<?php

namespace App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource;
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
            TextEntry::make('concept.display_name')->label('Concepto sugerido')->placeholder('PROPOSE_NEW_CONCEPT'),
            TextEntry::make('suggested_new_concept_name')->label('Nombre de concepto propuesto')->visible(fn ($record) => $record->isProposingNewConcept()),
            TextEntry::make('confidence')->numeric(4),
            TextEntry::make('tier'),
            TextEntry::make('status'),
            KeyValueEntry::make('signals')->label('Señales (trazabilidad completa del Builder)'),
            TextEntry::make('review_notes')->label('Notas de revisión')->placeholder('—'),
        ]);
    }
}
