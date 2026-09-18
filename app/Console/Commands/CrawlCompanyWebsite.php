<?php

namespace App\Console\Commands;

use App\Models\CompanyCrawlRun;
use App\Models\CompanyPage;
use App\Models\CompanyTermMatch;
use App\Services\Taxonomy\HtmlPageExtractor;
use App\Services\Taxonomy\PageTermMatcher;
use App\Services\Taxonomy\RobotsTxtChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * TAXV2-13 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 6 y el plan
 * de esta fase - la parte menos especificada del documento, mayor riesgo operativo/legal por
 * apuntar a sitios reales de terceros). Crawlea la web de UNA empresa (o un lote chico vía
 * `--batch`) por invocación, sembrado desde `empresas.website` (mysql), buscando coincidencias
 * contra el diccionario real (`PageTermMatcher`) como EVIDENCIA - nunca asigna
 * `empresa_taxonomy_category` automáticamente (ver docblock de `company_term_matches`).
 *
 * Mismo patrón de checkpoint que `taxonomy:crawl-source` (TAXV2-12): `pending_urls`/`visited_urls`
 * en `company_crawl_runs` permiten retomar sin repetir páginas ya visitadas.
 */
class CrawlCompanyWebsite extends Command
{
    protected $signature = 'taxonomy:crawl-company-website {empresa_id? : id de empresas (mysql); si se omite, se eligen automáticamente hasta --batch}
        {--limit=5 : máximo de URLs a procesar POR empresa en esta invocación}
        {--batch=1 : cuántas empresas procesar en esta invocación cuando no se da empresa_id}
        {--dry-run : solo valida robots.txt/estado, no hace peticiones de contenido ni escribe nada}
        {--fresh : ignora la corrida existente de la empresa y empieza una nueva desde su website}';

    protected $description = 'Crawlea la(s) web(s) de empresa(s) buscando evidencia de términos del diccionario (company_term_matches), para revisión manual.';

    private const USER_AGENT = 'PerfilAfiliadosCPV-CompanyBot/1.0 (+revision humana antes de confirmar evidencia)';

    private const MAX_QUEUE_SIZE = 200;

    private const PAGE_TYPE_KEYWORDS = ['about', 'services', 'capabilities', 'solutions', 'products', 'industries', 'equipment', 'projects'];

    public function handle(RobotsTxtChecker $robots, HtmlPageExtractor $extractor, PageTermMatcher $matcher): int
    {
        $empresaIdArg = $this->argument('empresa_id');
        $limit = max(1, (int) $this->option('limit'));
        $batch = max(1, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $fresh = (bool) $this->option('fresh');

        $empresaIds = $empresaIdArg !== null ? [(int) $empresaIdArg] : $this->pickNextEmpresas($batch);

        if ($empresaIds === []) {
            $this->info('No hay empresas pendientes de crawlear (sin website, o todas ya cubiertas este ciclo).');

            return self::SUCCESS;
        }

        $candidateTerms = $matcher->defaultCandidateTerms();

        foreach ($empresaIds as $empresaId) {
            $this->crawlOne((int) $empresaId, $limit, $dryRun, $fresh, $robots, $extractor, $matcher, $candidateTerms);
        }

        return self::SUCCESS;
    }

    private function crawlOne(
        int $empresaId,
        int $limit,
        bool $dryRun,
        bool $fresh,
        RobotsTxtChecker $robots,
        HtmlPageExtractor $extractor,
        PageTermMatcher $matcher,
        Collection $candidateTerms
    ): void {
        $empresa = DB::connection('mysql')->table('empresas')->where('id', $empresaId)->first();
        if (! $empresa) {
            $this->error("No existe la empresa #{$empresaId}.");

            return;
        }

        if (empty($empresa->website)) {
            $this->error("La empresa #{$empresaId} no tiene website.");

            return;
        }

        $seedUrl = $this->normalizeUrl($empresa->website, $extractor);
        if (! $seedUrl) {
            $this->error("Website inválido para la empresa #{$empresaId}: {$empresa->website}");

            return;
        }

        if (! $robots->isAllowed($seedUrl, self::USER_AGENT)) {
            $this->error("robots.txt de la empresa #{$empresaId} prohíbe crawlear {$seedUrl}.");
            if (! $dryRun) {
                $this->blockRun($empresaId, "robots.txt prohíbe {$seedUrl}");
            }

            return;
        }

        if ($dryRun) {
            $this->info("[dry-run] empresa #{$empresaId}: robots.txt permite crawlear desde {$seedUrl}. No se hizo ninguna petición de contenido ni escritura.");

            return;
        }

        $run = $this->activeOrFreshRun($empresaId, $seedUrl, $fresh);
        if ($run->status === CompanyCrawlRun::STATUS_COMPLETED) {
            $this->info("Empresa #{$empresaId} ya completó su última corrida (#{$run->id}) el {$run->finished_at}. Usa --fresh para reiniciar.");

            return;
        }

        $delaySeconds = $robots->crawlDelaySeconds($seedUrl, self::USER_AGENT) ?? 3.0;

        $pending = $run->pending_urls;
        $visited = $run->visited_urls;
        $processed = 0;
        $matchesFound = 0;

        while ($pending !== [] && $processed < $limit) {
            $url = array_shift($pending);

            if (in_array($url, $visited, true)) {
                continue;
            }

            if (! $robots->isAllowed($url, self::USER_AGENT)) {
                $visited[] = $url;

                continue;
            }

            if ($processed > 0) {
                usleep((int) ($delaySeconds * 1_000_000));
            }

            try {
                $response = Http::timeout(15)->withUserAgent(self::USER_AGENT)->get($url);
            } catch (\Throwable $e) {
                $this->warn("Error al pedir {$url}: {$e->getMessage()}");
                $visited[] = $url;
                $processed++;

                continue;
            }

            $visited[] = $url;
            $processed++;

            if (! $response->successful()) {
                continue;
            }

            $contentType = (string) $response->header('Content-Type');
            if ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'text')) {
                continue;
            }

            $html = $response->body();
            $title = $extractor->extractTitle($html);
            $text = $extractor->extractVisibleText($html);

            $page = CompanyPage::query()->updateOrCreate(
                ['crawl_run_id' => $run->id, 'url' => $url],
                [
                    'empresa_id' => $empresaId,
                    'page_type' => $this->classifyPageType($url),
                    'http_status' => $response->status(),
                    'title' => $title ? mb_substr($title, 0, 500) : null,
                    'content_hash' => hash('sha256', $text),
                    'fetched_at' => now(),
                ]
            );

            foreach ($matcher->match($text, $candidateTerms) as $found) {
                if ($this->storeMatch($page, $empresaId, $found)) {
                    $matchesFound++;
                }
            }

            $newLinks = collect($extractor->extractSameHostLinks($html, $url))
                ->reject(fn ($l) => in_array($l, $visited, true) || in_array($l, $pending, true))
                ->sortBy(fn ($l) => $this->pagePriority($l))
                ->values();

            foreach ($newLinks as $link) {
                if (count($pending) + count($visited) >= self::MAX_QUEUE_SIZE) {
                    break;
                }
                $pending[] = $link;
            }
        }

        $run->pending_urls = $pending;
        $run->visited_urls = $visited;
        $run->pages_processed += $processed;
        $run->matches_found += $matchesFound;
        $run->status = $pending === [] ? CompanyCrawlRun::STATUS_COMPLETED : CompanyCrawlRun::STATUS_RUNNING;
        if ($run->status === CompanyCrawlRun::STATUS_COMPLETED) {
            $run->finished_at = now();
        }
        $run->save();

        $this->info("Empresa #{$empresaId}: {$processed} página(s) procesada(s), {$matchesFound} match(es) nuevo(s). Cola restante: ".count($pending).". Estado: {$run->status}.");
    }

    /**
     * @return int[]
     */
    private function pickNextEmpresas(int $batch): array
    {
        $crawledIds = CompanyCrawlRun::query()->pluck('empresa_id')->unique()->all();

        $neverCrawled = DB::connection('mysql')->table('empresas')
            ->whereNotNull('website')->where('website', '!=', '')
            ->when($crawledIds !== [], fn ($q) => $q->whereNotIn('id', $crawledIds))
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->all();

        if (count($neverCrawled) >= $batch) {
            return $neverCrawled;
        }

        $remaining = $batch - count($neverCrawled);
        $recycled = CompanyCrawlRun::query()
            ->where('status', CompanyCrawlRun::STATUS_COMPLETED)
            ->whereNotIn('empresa_id', $neverCrawled)
            ->orderBy('finished_at')
            ->limit($remaining)
            ->pluck('empresa_id')
            ->unique()
            ->all();

        return array_values(array_unique(array_merge($neverCrawled, $recycled)));
    }

    private function normalizeUrl(string $url, HtmlPageExtractor $extractor): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $extractor->normalizeUrl($url);
    }

    private function classifyPageType(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return 'home';
        }
        foreach (self::PAGE_TYPE_KEYWORDS as $keyword) {
            if (str_contains($path, $keyword)) {
                return $keyword;
            }
        }

        return 'other';
    }

    private function pagePriority(string $url): int
    {
        $type = $this->classifyPageType($url);
        if ($type === 'home') {
            return -1;
        }
        $idx = array_search($type, self::PAGE_TYPE_KEYWORDS, true);

        return $idx === false ? 99 : $idx;
    }

    private function activeOrFreshRun(int $empresaId, string $seedUrl, bool $fresh): CompanyCrawlRun
    {
        if (! $fresh) {
            $active = CompanyCrawlRun::query()
                ->where('empresa_id', $empresaId)
                ->where('status', CompanyCrawlRun::STATUS_RUNNING)
                ->latest('id')
                ->first();
            if ($active) {
                return $active;
            }

            $last = CompanyCrawlRun::query()->where('empresa_id', $empresaId)->latest('id')->first();
            if ($last && $last->status === CompanyCrawlRun::STATUS_COMPLETED) {
                return $last;
            }
        }

        return CompanyCrawlRun::query()->create([
            'empresa_id' => $empresaId,
            'status' => CompanyCrawlRun::STATUS_RUNNING,
            'pending_urls' => [$seedUrl],
            'visited_urls' => [],
            'pages_processed' => 0,
            'matches_found' => 0,
            'started_at' => now(),
        ]);
    }

    private function blockRun(int $empresaId, string $message): void
    {
        $run = CompanyCrawlRun::query()
            ->where('empresa_id', $empresaId)
            ->where('status', CompanyCrawlRun::STATUS_RUNNING)
            ->latest('id')
            ->first() ?? new CompanyCrawlRun([
                'empresa_id' => $empresaId,
                'pending_urls' => [],
                'visited_urls' => [],
                'started_at' => now(),
            ]);

        $run->status = CompanyCrawlRun::STATUS_BLOCKED;
        $run->error_message = $message;
        $run->finished_at = now();
        $run->save();
    }

    /**
     * @param  array{term: \App\Models\TaxonomyTerm, matched_text: string, context: string}  $found
     */
    private function storeMatch(CompanyPage $page, int $empresaId, array $found): bool
    {
        $term = $found['term'];

        $exists = CompanyTermMatch::query()
            ->where('page_id', $page->id)
            ->where('taxonomy_term_id', $term->id)
            ->exists();
        if ($exists) {
            return false;
        }

        $bestRelation = $term->cpvRelations->sortByDesc('weight')->first();
        $evidenceScore = round(($bestRelation->weight ?? 0.5) * ($term->oil_gas_exclusivity ?? 1.0), 4);

        CompanyTermMatch::query()->create([
            'page_id' => $page->id,
            'empresa_id' => $empresaId,
            'taxonomy_term_id' => $term->id,
            'matched_text' => $found['matched_text'],
            'canonical_term' => $term->canonical_term ?: $term->term,
            'match_type' => 'exact_page_text',
            'context' => $found['context'],
            'cpv_code' => $bestRelation->cpv_code ?? null,
            'relation_weight' => $bestRelation->weight ?? null,
            'evidence_score' => $evidenceScore,
            'status' => CompanyTermMatch::STATUS_PENDING_REVIEW,
            'crawled_at' => now(),
        ]);

        return true;
    }
}
