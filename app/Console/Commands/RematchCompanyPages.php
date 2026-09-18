<?php

namespace App\Console\Commands;

use App\Models\CompanyPage;
use App\Models\CompanyTermMatch;
use App\Services\Taxonomy\PageTermMatcher;
use Illuminate\Console\Command;

/**
 * TAXV3-6 (ajuste V3 punto 15: "reprocesar contenido ya crawleado"). Cuando el diccionario gana
 * términos nuevos (Auto Mapper, `taxonomy:import-term-provenance`, o eventualmente Fase B), este
 * comando busca coincidencias NUEVAS contra el `visible_text` YA guardado de `company_pages` - sin
 * pedirle nada de nuevo a la web de la empresa. Solo se recrawlea de verdad
 * (`taxonomy:crawl-company-website --fresh`) cuando el contenido no existe, está vencido o cambió
 * su hash - eso ya lo decide ese comando, este acá nunca hace una petición HTTP.
 *
 * Idempotente: `CompanyTermMatch::recordFromMatch()` no duplica una fila para el mismo
 * página+término si ya existe.
 */
class RematchCompanyPages extends Command
{
    protected $signature = 'taxonomy:rematch-company-pages {--empresa_id= : limitar a una empresa} {--limit= : máximo de páginas a reprocesar}';

    protected $description = 'TAXV3-6: re-compara el texto YA guardado de company_pages contra el diccionario actual, sin recrawlear.';

    public function handle(PageTermMatcher $matcher): int
    {
        $query = CompanyPage::query()->whereNotNull('visible_text');

        if ($empresaId = $this->option('empresa_id')) {
            $query->where('empresa_id', (int) $empresaId);
        }
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $pages = $query->get();

        if ($pages->isEmpty()) {
            $this->info('No hay páginas con texto almacenado para reprocesar.');

            return self::SUCCESS;
        }

        $this->info("Reprocesando {$pages->count()} página(s) contra el diccionario actual...");

        $candidateTerms = $matcher->defaultCandidateTerms();
        $newMatches = 0;

        $bar = $this->output->createProgressBar($pages->count());
        $bar->start();

        foreach ($pages as $page) {
            foreach ($matcher->match($page->visible_text, $candidateTerms) as $found) {
                if (CompanyTermMatch::recordFromMatch($page, $page->empresa_id, $found)) {
                    $newMatches++;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Listo. Matches nuevos encontrados: {$newMatches}.");

        return self::SUCCESS;
    }
}
