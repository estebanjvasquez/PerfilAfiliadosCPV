<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase B.1 (seguimiento 2026-09-25): `formatImpactSummary()` es una función pura (sin DB, sin
 * dependencias de Filament runtime) - se testea aislada del resto del panel.
 */
class TaxonomyCandidateConceptLinkResourceTest extends TestCase
{
    #[Test]
    public function format_impact_summary_shows_the_confirmed_vs_suggested_breakdown(): void
    {
        $text = TaxonomyCandidateConceptLinkResource::formatImpactSummary([
            'direct_company_count' => 5,
            'direct_confirmed_company_count' => 2,
            'direct_suggested_company_count' => 3,
            'evidence_company_count' => 0,
            'expanded_company_count' => 0,
            'total_unique_company_count' => 5,
            'data_gap_flags' => [],
        ]);

        $this->assertStringContainsString('5 empresas directas', $text);
        $this->assertStringContainsString('2 confirmadas por la empresa', $text);
        $this->assertStringContainsString('3 solo sugeridas automáticamente', $text);
    }

    #[Test]
    public function format_impact_summary_warns_when_all_direct_evidence_is_suggested(): void
    {
        $text = TaxonomyCandidateConceptLinkResource::formatImpactSummary([
            'direct_company_count' => 3,
            'direct_confirmed_company_count' => 0,
            'direct_suggested_company_count' => 3,
            'evidence_company_count' => 0,
            'expanded_company_count' => 0,
            'total_unique_company_count' => 3,
            'data_gap_flags' => [],
        ]);

        $this->assertStringContainsString('Ninguna de estas empresas confirmó', $text);
    }

    #[Test]
    public function format_impact_summary_does_not_warn_when_there_is_confirmed_evidence(): void
    {
        $text = TaxonomyCandidateConceptLinkResource::formatImpactSummary([
            'direct_company_count' => 3,
            'direct_confirmed_company_count' => 1,
            'direct_suggested_company_count' => 2,
            'evidence_company_count' => 0,
            'expanded_company_count' => 0,
            'total_unique_company_count' => 3,
            'data_gap_flags' => [],
        ]);

        $this->assertStringNotContainsString('Ninguna de estas empresas confirmó', $text);
    }

    #[Test]
    public function format_impact_summary_includes_data_gap_flags(): void
    {
        $text = TaxonomyCandidateConceptLinkResource::formatImpactSummary([
            'direct_company_count' => 0,
            'direct_confirmed_company_count' => 0,
            'direct_suggested_company_count' => 0,
            'evidence_company_count' => 0,
            'expanded_company_count' => 0,
            'total_unique_company_count' => 0,
            'data_gap_flags' => ['CPV_WITHOUT_COMPANIES'],
        ]);

        $this->assertStringContainsString('CPV_WITHOUT_COMPANIES', $text);
    }
}
