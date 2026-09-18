<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesTaxonomySettingsGroup;
use App\Support\Taxonomy\TaxonomyRankingParameters;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * TAXV2-4 (sección 4.9 del documento de instrucciones): pesos de perfil declarado, evidencia web,
 * servicios legacy, matching y ranking - ver ManagesTaxonomySettingsGroup para la lógica compartida
 * con TaxonomyNoiseSettingsPage (sección 4.10).
 */
class TaxonomyRankingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;
    use ManagesTaxonomySettingsGroup;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Pesos y ranking';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.taxonomy-settings-group';

    public function form(Form $form): Form
    {
        return $this->buildSettingsForm($form);
    }

    protected static function groups(): array
    {
        return [
            TaxonomyRankingParameters::GROUP_DECLARED_PROFILE,
            TaxonomyRankingParameters::GROUP_WEBSITE_EVIDENCE,
            TaxonomyRankingParameters::GROUP_LEGACY_SERVICES,
            TaxonomyRankingParameters::GROUP_RELATION_THRESHOLDS,
            TaxonomyRankingParameters::GROUP_MATCHING,
            TaxonomyRankingParameters::GROUP_RANKING,
            TaxonomyRankingParameters::GROUP_AUTO_MAPPING,
        ];
    }
}
