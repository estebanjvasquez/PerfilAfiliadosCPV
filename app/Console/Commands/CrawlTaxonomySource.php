<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCandidateTerm;
use App\Models\TaxonomyCrawlRun;
use App\Models\TaxonomySource;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\Taxonomy\HtmlPageExtractor;
use App\Services\Taxonomy\RobotsTxtChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * TAXV2-12 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 5 y el plan
 * de esta fase): crawlea un lote acotado de URLs de UNA fuente terminológica por invocación,
 * respetando robots.txt y el `rate_limit_rpm` configurado, y guarda lo encontrado únicamente en
 * `taxonomy_candidate_terms` - nunca en `taxonomy_terms` directo (regla 8 de la sección 5).
 *
 * Reanudable por checkpoint: el estado (`pending_urls`/`visited_urls`) vive en la fila `running` de
 * `taxonomy_crawl_runs` de esa fuente, no en memoria - correr este comando repetidas veces (vía
 * cron) avanza la cola sin reprocesar lo ya visitado, en vez de una sola corrida larga que agote el
 * pool PHP-FPM del hosting compartido (docs/plan_despliegue_taxonomia_cira.md).
 *
 * Sin `source_id`, elige la fuente habilitada+crawleable que más lo necesita (con una corrida activa,
 * o la que lleva más tiempo sin crawlear) - así un cron sin argumentos (`taxonomy:crawl-source`)
 * ya reparte el trabajo entre las fuentes habilitadas.
 */
class CrawlTaxonomySource extends Command
{
    protected $signature = 'taxonomy:crawl-source {source_id? : source_id de taxonomy_sources (ej. SLB); si se omite, se elige automáticamente}
        {--limit=5 : máximo de URLs a procesar en esta invocación}
        {--dry-run : solo valida robots.txt/estado, no hace peticiones de contenido ni escribe nada}
        {--fresh : ignora la corrida existente y empieza una nueva desde la URL semilla}';

    protected $description = 'Crawlea un lote acotado de una fuente terminológica (SLB/OSHA/IADC) hacia taxonomy_candidate_terms, para revisión manual.';

    private const USER_AGENT = 'PerfilAfiliadosCPV-TaxonomyBot/1.0 (+revision humana antes de publicar cualquier termino)';

    private const MAX_QUEUE_SIZE = 500;

    public function handle(RobotsTxtChecker $robots, HtmlPageExtractor $extractor): int
    {
        $sourceId = $this->argument('source_id');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $source = $sourceId
            ? TaxonomySource::query()->where('source_id', $sourceId)->first()
            : $this->pickNextSource();

        if (! $source) {
            $this->error($sourceId
                ? "No existe la fuente '{$sourceId}'."
                : 'No hay ninguna fuente habilitada y crawleable (actívala en Taxonomía CPV > Fuentes).');

            return self::FAILURE;
        }

        if (! $source->crawlable) {
            $this->error("La fuente '{$source->source_id}' no es crawleable (ej. CURATED).");

            return self::FAILURE;
        }

        if (! $source->enabled) {
            $this->error("La fuente '{$source->source_id}' está deshabilitada. Actívala desde Taxonomía CPV > Fuentes antes de crawlear.");

            return self::FAILURE;
        }

        $seedUrl = $source->discovery_url ?: $source->base_url;
        if (! $seedUrl) {
            $this->error("La fuente '{$source->source_id}' no tiene base_url/discovery_url configurada.");

            return self::FAILURE;
        }
        $seedUrl = $extractor->normalizeUrl($seedUrl);
        if (! $seedUrl) {
            $this->error("base_url/discovery_url inválida para la fuente '{$source->source_id}'.");

            return self::FAILURE;
        }

        if ($source->respect_robots_txt && ! $robots->isAllowed($seedUrl, self::USER_AGENT)) {
            $this->error("robots.txt de '{$source->source_id}' prohíbe crawlear {$seedUrl}.");
            if (! $dryRun) {
                $this->blockRun($source->source_id, "robots.txt prohíbe {$seedUrl}");
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info("[dry-run] robots.txt permite crawlear '{$source->source_id}' desde {$seedUrl}. rate_limit_rpm=".
                ($source->rate_limit_rpm ?: 'sin configurar').'. No se hizo ninguna petición de contenido ni escritura.');

            return self::SUCCESS;
        }

        $run = $this->activeOrFreshRun($source, $seedUrl, (bool) $this->option('fresh'));

        if ($run->status === TaxonomyCrawlRun::STATUS_COMPLETED) {
            $this->info("La fuente '{$source->source_id}' ya completó su última corrida (#{$run->id}) el {$run->finished_at}. Usa --fresh para iniciar una nueva.");

            return self::SUCCESS;
        }

        $delaySeconds = $source->rate_limit_rpm ? (60 / $source->rate_limit_rpm) : 10.0;
        if ($source->respect_robots_txt) {
            $delaySeconds = max($delaySeconds, $robots->crawlDelaySeconds($seedUrl, self::USER_AGENT) ?? 0.0);
        }

        $pending = $run->pending_urls;
        $visited = $run->visited_urls;
        $processed = 0;
        $newCandidates = 0;

        while ($pending !== [] && $processed < $limit) {
            $url = array_shift($pending);

            if (in_array($url, $visited, true)) {
                continue;
            }

            if ($source->respect_robots_txt && ! $robots->isAllowed($url, self::USER_AGENT)) {
                $this->warn("Omitida (robots.txt): {$url}");
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
                $this->warn("HTTP {$response->status()} en {$url}");

                continue;
            }

            $contentType = (string) $response->header('Content-Type');
            if ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'text')) {
                continue;
            }

            $html = $response->body();

            foreach ($extractor->extractGlossaryTerms($html) as $found) {
                if ($this->storeCandidate($source, $run, $url, $found['term'], $found['definition'])) {
                    $newCandidates++;
                }
            }

            foreach ($extractor->extractSameHostLinks($html, $url) as $link) {
                if (count($pending) + count($visited) >= self::MAX_QUEUE_SIZE) {
                    break;
                }
                if (! in_array($link, $visited, true) && ! in_array($link, $pending, true)) {
                    $pending[] = $link;
                }
            }
        }

        $run->pending_urls = $pending;
        $run->visited_urls = $visited;
        $run->pages_processed += $processed;
        $run->candidates_found += $newCandidates;
        $run->status = $pending === [] ? TaxonomyCrawlRun::STATUS_COMPLETED : TaxonomyCrawlRun::STATUS_RUNNING;
        if ($run->status === TaxonomyCrawlRun::STATUS_COMPLETED) {
            $run->finished_at = now();
        }
        $run->save();

        $this->info("Fuente '{$source->source_id}': {$processed} página(s) procesada(s), {$newCandidates} candidato(s) nuevo(s). Cola restante: ".count($pending).". Estado: {$run->status}.");

        return self::SUCCESS;
    }

    private function pickNextSource(): ?TaxonomySource
    {
        return TaxonomySource::query()
            ->where('enabled', true)
            ->where('crawlable', true)
            ->get()
            ->sortBy(function (TaxonomySource $s) {
                $hasActive = TaxonomyCrawlRun::query()
                    ->where('source_id', $s->source_id)
                    ->where('status', TaxonomyCrawlRun::STATUS_RUNNING)
                    ->exists();

                if ($hasActive) {
                    return -1;
                }

                $lastFinished = TaxonomyCrawlRun::query()
                    ->where('source_id', $s->source_id)
                    ->latest('id')
                    ->value('finished_at');

                return $lastFinished ? strtotime((string) $lastFinished) : 0;
            })
            ->first();
    }

    private function activeOrFreshRun(TaxonomySource $source, string $seedUrl, bool $fresh): TaxonomyCrawlRun
    {
        if (! $fresh) {
            $active = TaxonomyCrawlRun::query()
                ->where('source_id', $source->source_id)
                ->where('status', TaxonomyCrawlRun::STATUS_RUNNING)
                ->latest('id')
                ->first();
            if ($active) {
                return $active;
            }

            $last = TaxonomyCrawlRun::query()->where('source_id', $source->source_id)->latest('id')->first();
            if ($last && $last->status === TaxonomyCrawlRun::STATUS_COMPLETED) {
                return $last;
            }
        }

        return TaxonomyCrawlRun::query()->create([
            'source_id' => $source->source_id,
            'status' => TaxonomyCrawlRun::STATUS_RUNNING,
            'pending_urls' => [$seedUrl],
            'visited_urls' => [],
            'pages_processed' => 0,
            'candidates_found' => 0,
            'started_at' => now(),
        ]);
    }

    private function blockRun(string $sourceId, string $message): void
    {
        $run = TaxonomyCrawlRun::query()
            ->where('source_id', $sourceId)
            ->where('status', TaxonomyCrawlRun::STATUS_RUNNING)
            ->latest('id')
            ->first() ?? new TaxonomyCrawlRun([
                'source_id' => $sourceId,
                'pending_urls' => [],
                'visited_urls' => [],
                'started_at' => now(),
            ]);

        $run->status = TaxonomyCrawlRun::STATUS_BLOCKED;
        $run->error_message = $message;
        $run->finished_at = now();
        $run->save();
    }

    /**
     * V2->V3 ("crawler_rule" de docs/taxonomia/MIGRACION_TAXONOMIA_CPV_V2_A_V3.md sección 2): si el
     * término crawleado YA es un término real del diccionario (coincidencia exacta, no difusa), no
     * se trata como candidato nuevo - se verifica su binding de fuente directamente. Nunca toca
     * relaciones/pesos CPV (regla explícita del documento).
     */
    private function storeCandidate(TaxonomySource $source, TaxonomyCrawlRun $run, string $url, string $term, string $definition): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $existing = DB::connection('pgsql')->selectOne(
            'SELECT id FROM taxonomy_terms WHERE LOWER(canonical_term) = LOWER(?) OR LOWER(term) = LOWER(?) LIMIT 1',
            [$term, $term]
        );

        if ($existing) {
            $this->verifyExistingTermBinding((int) $existing->id, $source, $url, $term);

            return false;
        }

        $hash = hash('sha256', $term.'|'.$definition);
        $now = now();

        $existing = TaxonomyCandidateTerm::query()
            ->where('source_id', $source->source_id)
            ->whereRaw('LOWER(term) = ?', [mb_strtolower($term)])
            ->first();

        if ($existing) {
            $existing->update([
                'crawl_run_id' => $run->id,
                'source_url' => $url,
                'context_snippet' => mb_substr($definition, 0, 500),
                'content_hash' => $hash,
                'change_status' => $existing->content_hash === $hash
                    ? TaxonomyCandidateTerm::CHANGE_UNCHANGED
                    : TaxonomyCandidateTerm::CHANGE_CHANGED,
                'last_seen_at' => $now,
            ]);

            return false;
        }

        [$confidence, $suggestedCpv, $canonical] = $this->suggestCpvMatch($term);

        TaxonomyCandidateTerm::query()->create([
            'crawl_run_id' => $run->id,
            'source_id' => $source->source_id,
            'term' => mb_substr($term, 0, 255),
            'language' => 'en',
            'source_url' => $url,
            'context_snippet' => mb_substr($definition, 0, 500),
            'content_hash' => $hash,
            'change_status' => TaxonomyCandidateTerm::CHANGE_NEW,
            'suggested_canonical_term' => $canonical,
            'suggested_aliases' => [],
            'suggested_cpv_relations' => $suggestedCpv,
            'confidence' => $confidence,
            'status' => TaxonomyCandidateTerm::STATUS_CANDIDATE,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);

        return true;
    }

    /**
     * V2->V3: crea/actualiza un binding VERIFICADO (el crawler acaba de ver este término exacto en
     * esta URL de esta fuente) y, si el término todavía no tenía ninguna procedencia externa
     * verificada, lo promueve a `origin_type=external_verified` - nunca toca sus relaciones/pesos
     * CPV (regla explícita de la sección 2 del documento de migración).
     */
    private function verifyExistingTermBinding(int $termId, TaxonomySource $source, string $url, string $sourceTerm): void
    {
        $now = now();

        DB::connection('pgsql')->statement(
            'INSERT INTO taxonomy_term_source_bindings (
                term_id, source_id, source_term, source_url, binding_type,
                verification_status, sync_enabled, first_seen_at, last_seen_at, last_verified_at,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (term_id, source_id) DO UPDATE SET
                source_term = excluded.source_term,
                source_url = excluded.source_url,
                verification_status = excluded.verification_status,
                sync_enabled = excluded.sync_enabled,
                first_seen_at = COALESCE(taxonomy_term_source_bindings.first_seen_at, excluded.first_seen_at),
                last_seen_at = excluded.last_seen_at,
                last_verified_at = excluded.last_verified_at,
                updated_at = excluded.updated_at',
            [
                $termId, $source->source_id, $sourceTerm, $url, 'exact_source_term',
                'verified', true, $now, $now, $now, $now, $now,
            ]
        );

        DB::connection('pgsql')->update(
            'UPDATE taxonomy_terms SET
                origin_type = ?,
                display_source = ?,
                primary_source_id = COALESCE(primary_source_id, ?),
                updated_at = ?
             WHERE id = ? AND origin_type IS DISTINCT FROM ?',
            [
                TaxonomyTerm::ORIGIN_EXTERNAL_VERIFIED,
                "{$source->name} verified",
                $source->source_id,
                $now,
                $termId,
                TaxonomyTerm::ORIGIN_EXTERNAL_VERIFIED,
            ]
        );
    }

    /**
     * Mapping automático de candidatos (sección 5): compara por similitud de trigramas (`pg_trgm`,
     * ya habilitado en TAXV2-1) contra los términos existentes y, si hay uno suficientemente
     * parecido, propone sus relaciones CPV `approved` como sugerencia - nunca las aplica solo.
     *
     * @return array{0: ?float, 1: ?array, 2: ?string}
     */
    private function suggestCpvMatch(string $term): array
    {
        $match = DB::connection('pgsql')->selectOne(
            'SELECT t.id, t.canonical_term, t.term, similarity(t.canonical_term, ?) AS sim
             FROM taxonomy_terms t
             WHERE t.canonical_term IS NOT NULL AND t.canonical_term % ?
             ORDER BY sim DESC LIMIT 1',
            [$term, $term]
        );

        if (! $match || (float) $match->sim < 0.5) {
            return [null, null, null];
        }

        $relations = TaxonomyTermCpvRelation::query()
            ->where('term_id', $match->id)
            ->where('status', 'approved')
            ->orderByDesc('weight')
            ->get(['cpv_code', 'weight'])
            ->map(fn ($r) => ['cpv_code' => $r->cpv_code, 'weight' => (float) $r->weight])
            ->values()
            ->all();

        return [round((float) $match->sim, 4), $relations ?: null, $match->canonical_term ?? $match->term];
    }
}
