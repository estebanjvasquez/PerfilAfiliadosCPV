<?php

namespace App\Filament\Pages;

use App\Filament\Pages\TaxonomyExceptions\TaxonomyExceptionsWidget;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-4 (ajuste V3 puntos 9/10): cola de excepciones unificada - el admin ve un puñado de casos
 * reales que necesitan una decisión humana, no los 1.776 términos pending/curated (eso es
 * cobertura, `TaxonomyDashboardPage`) ni el backlog histórico de 9.288 relaciones `needs_review` de
 * TAXV2-2 (anterior a esta fase, deliberadamente no se toca ni se resurface).
 */
class TaxonomyExceptionsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Revisión requerida';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.taxonomy-exceptions';

    /** TAXV3-1: mismo permiso que el Dashboard - es una vista de solo lectura sobre datos ya visibles en otros lados. */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('taxonomy_view') ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TaxonomyExceptionsWidget::class,
        ];
    }

    /**
     * @return array<int, array{a: string, b: string, similarity: float}>
     */
    public function getDuplicateConceptPairs(): array
    {
        $rows = DB::connection('pgsql')->select(<<<'SQL'
            SELECT
                coalesce(a.canonical_name_en, a.canonical_name_es) AS a_name,
                coalesce(b.canonical_name_en, b.canonical_name_es) AS b_name,
                GREATEST(
                    similarity(coalesce(a.canonical_name_en,''), coalesce(b.canonical_name_en,'')),
                    similarity(coalesce(a.canonical_name_es,''), coalesce(b.canonical_name_es,''))
                ) AS sim
            FROM taxonomy_canonical_concepts a
            JOIN taxonomy_canonical_concepts b ON b.id > a.id
            WHERE similarity(coalesce(a.canonical_name_en,''), coalesce(b.canonical_name_en,'')) > 0.5
               OR similarity(coalesce(a.canonical_name_es,''), coalesce(b.canonical_name_es,'')) > 0.5
            ORDER BY sim DESC
            LIMIT 30
        SQL);

        return array_map(fn ($r) => [
            'a' => $r->a_name,
            'b' => $r->b_name,
            'similarity' => round((float) $r->sim, 2),
        ], $rows);
    }
}
