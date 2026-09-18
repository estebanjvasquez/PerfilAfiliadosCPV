<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesTaxonomySettingsGroup;
use App\Support\Taxonomy\TaxonomyRankingParameters;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * TAXV2-4 (sección 4.10 del documento de instrucciones): defaults globales de contexto/ruido
 * (`context_window_words`, `minimum_supporting_terms`, `ambiguity_penalty`) - los valores POR
 * TÉRMINO viven en `taxonomy_terms` (columnas propias, editables desde TaxonomyTermResource en
 * TAXV2-6); acá solo el default que se usa al crear un término nuevo. Ver
 * ManagesTaxonomySettingsGroup para la lógica compartida con TaxonomyRankingSettingsPage.
 */
class TaxonomyNoiseSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    use ManagesTaxonomySettingsGroup;

    protected static ?string $navigationIcon = 'heroicon-o-signal-slash';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Contexto y control de ruido';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.taxonomy-settings-group';

    public function form(Form $form): Form
    {
        return $this->buildSettingsForm($form);
    }

    protected static function groups(): array
    {
        return [
            TaxonomyRankingParameters::GROUP_CONTEXT,
        ];
    }
}
