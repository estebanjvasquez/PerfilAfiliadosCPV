<?php

namespace App\Services\CategoryAnalysis;

use App\Models\CategoryAnalysisSettings;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomyCategoryRequestAnalysis;
use App\Models\User;
use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;
use App\Services\CategoryAnalysis\Contracts\ReportsUsage;
use App\Services\CategoryAnalysis\Contracts\SourceRetriever;
use App\Services\TaxonomyCategorySearch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TASK-0016 (Issue #2 `6791532623`): «Analizar con IA» de una solicitud de categoría.
 *
 * Pasos, en este orden:
 * 1. Autoriza en el servidor (TaxonomyCategoryRequestPolicy::analyzeWithAi: solo super_admin, como el módulo).
 * 2. Valida la selección: solo categorías VIGENTES del catálogo (activas, nunca un Grupo), sin repetir y con tope.
 * 3. Recupera candidatas del catálogo con TaxonomyCategorySearch (el mismo buscador del panel) y fuentes externas
 *    con el SourceRetriever configurado. La recuperación no decide nada.
 * 4. Pide el juicio de equivalencia al AnalysisProvider configurado, con datos mínimos: lo que pidió el afiliado y el
 *    contexto sectorial, sin el nombre ni el RIF de la empresa ni datos de personas.
 * 5. Valida la respuesta (AnalysisOutputValidator) y guarda UNA fila nueva, también si falló.
 *
 * Nunca cambia la solicitud, su estado ni la taxonomía, y no crea ni vincula categorías.
 */
final class CategoryAnalysisService
{
    /** Consumo de la búsqueda de fuentes de la ejecución en curso (se suma al costo). */
    private array $sourceUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'web_searches' => 0];

    public function __construct(
        private readonly AnalysisProvider $provider,
        private readonly SourceRetriever $sources,
        private readonly TaxonomyCategorySearch $search,
        private readonly AnalysisOutputValidator $validator,
    ) {
    }

    public function enabled(): bool
    {
        return $this->provider->enabled() && TaxonomyCategoryRequestAnalysis::tableExists();
    }

    public function sourcesAvailable(): bool
    {
        return $this->sources->available();
    }

    /**
     * Buscador del catálogo vigente para el selector: nombre, sinónimo, código y diccionario; semántica solo si el
     * servicio de vectores está configurado (rotulada como sugerencia por TaxonomyCategorySearch).
     *
     * @return array<int, string> id => etiqueta
     */
    public function searchCatalog(string $query, int $limit = 15): array
    {
        return $this->search->search($query, $limit)
            ->mapWithKeys(fn (array $r) => [$r['category_id'] => TaxonomyCategorySearch::optionLabel($r)])
            ->all();
    }

    /**
     * Candidatas del catálogo para el texto de la solicitud (lo que pide y las palabras que probó).
     *
     * @return list<array<string, mixed>>
     */
    public function catalogCandidates(TaxonomyCategoryRequest $request): array
    {
        $max = (int) config('category_analysis.max_catalog_candidates');
        $ids = [];
        foreach (array_filter([$request->necesidad, $request->terminos_probados]) as $query) {
            foreach ($this->search->search(mb_substr((string) $query, 0, 200), $max) as $result) {
                $ids[$result['category_id']] = ['reasons' => collect($result['reasons'])->pluck('kind')->unique()->values()->all(), 'match_type' => $result['match_type']];
            }
        }
        $ids = array_slice($ids, 0, $max, true);

        return array_map(fn (array $snapshot) => $snapshot + ($ids[$snapshot['category_id']] ?? []), $this->snapshot(array_keys($ids)));
    }

    /**
     * Categorías vigentes por id, con su ruta, nombre y descripción. Omite las que no son vigentes.
     *
     * @param  list<int>  $ids
     * @return list<array{category_id: int, code: string, level: int, breadcrumb: string, name: string, description: ?string, tipo_oferta: ?string}>
     */
    public function snapshot(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $categories = TaxonomyCategory::query()->whereIn('id', $ids)->where('is_active', true)
            ->where('level', '!=', TaxonomyCategory::LEVEL_GROUP)->with('translations')->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            if ($category = $categories->get($id)) {
                $es = $category->translations->firstWhere('locale', 'es');
                $out[] = [
                    'category_id' => (int) $category->id,
                    'code' => $category->code,
                    'level' => (int) $category->level,
                    'breadcrumb' => $category->breadcrumb('es'),
                    'name' => $category->displayName(),
                    'description' => $es?->description ?? $category->translations->firstWhere('locale', 'en')?->description,
                    'tipo_oferta' => $category->tipo_oferta,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $categoryIds
     *
     * @throws AuthorizationException
     * @throws CategoryAnalysisException  si no corre (desactivado, selección inválida, límite de frecuencia)
     */
    public function run(TaxonomyCategoryRequest $request, User $actor, array $categoryIds): TaxonomyCategoryRequestAnalysis
    {
        if (! Gate::forUser($actor)->allows('analyzeWithAi', $request)) {
            throw new AuthorizationException;
        }
        if (! $this->enabled()) {
            throw new CategoryAnalysisException(TaxonomyCategoryRequestAnalysis::ERRORS['provider_disabled']);
        }

        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        $max = (int) config('category_analysis.max_selected_categories');
        if ($ids === []) {
            throw new CategoryAnalysisException('Elija al menos una categoría del catálogo para comparar.');
        }
        if (count($ids) > $max) {
            throw new CategoryAnalysisException("Elija como máximo {$max} categorías por análisis.");
        }
        $selected = $this->snapshot($ids);
        if (count($selected) !== count($ids)) {
            throw new CategoryAnalysisException('Una de las categorías elegidas no es vigente en el catálogo: vuelva a elegirlas.');
        }

        $this->throttle($request, $actor);
        $started = now();

        $candidates = array_values(array_filter($this->catalogCandidates($request), fn ($c) => ! in_array($c['category_id'], $ids, true)));
        $requestData = $this->requestData($request);
        // El tope se mira ANTES de cualquier llamada de pago (también la búsqueda web).
        $overBudget = $this->overBudget();
        [$sources, $limited, $sourceNote] = $overBudget ? [[], true, ''] : $this->retrieveSources($request);

        $base = [
            'request_id' => $request->getKey(),
            'actor_user_id' => $actor->getKey(),
            'limited' => $limited,
            'request_snapshot' => $requestData + ['request_id' => $request->getKey(), 'submitted_at' => $request->submitted_at?->toIso8601String()],
            'categories_snapshot' => $selected,
            'catalog_candidates' => $candidates,
            'prompt_version' => AnalysisPrompt::VERSION,
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
            'sources' => $sources,
            'started_at' => $started,
        ];

        if ($overBudget) {
            return $this->store($base + ['status' => TaxonomyCategoryRequestAnalysis::STATUS_FAILED, 'error_code' => 'budget_exceeded']);
        }

        $prompt = AnalysisPrompt::build($requestData, $selected, $candidates, array_map(fn ($s) => array_diff_key($s, ['verified' => 1, 'retrieved_at' => 1]), $sources));

        try {
            $result = $this->callProvider($prompt);
            $validated = $this->validator->validate(
                $result->json,
                $ids,
                array_column($candidates, 'category_id'),
                array_column($sources, 'source_id'),
            );
        } catch (ProviderException $e) {
            return $this->store($base + [
                'status' => TaxonomyCategoryRequestAnalysis::STATUS_FAILED,
                'error_code' => array_key_exists($e->errorCode, TaxonomyCategoryRequestAnalysis::ERRORS) ? $e->errorCode : 'provider_error',
                // Solo el detalle del validador propio (qué campo faltó). El mensaje de un proveedor puede llevar
                // cabeceras, claves o el texto enviado: no se guarda ni se registra.
                'error_message' => $e->errorCode === 'invalid_output' ? mb_substr($e->getMessage(), 0, 300) : null,
                'input_tokens' => $result->inputTokens ?? null,
                'output_tokens' => $result->outputTokens ?? null,
                'cost_usd' => $this->cost($result ?? null),
            ]);
        }

        $limitations = $validated['output']['limitations'];
        if ($limited) {
            array_unshift($limitations, $sourceNote);
        }
        $validated['output']['limitations'] = array_values(array_unique($limitations));

        return $this->store($base + [
            'status' => TaxonomyCategoryRequestAnalysis::STATUS_COMPLETED,
            'model' => $result->model ?? $this->provider->model(),
            'output' => $validated['output'],
            'validation_issues' => $validated['issues'],
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $this->cost($result),
        ]);
    }

    /** Lo que se envía al proveedor: la petición y el contexto sectorial; nunca nombre, RIF ni datos de personas. */
    private function requestData(TaxonomyCategoryRequest $request): array
    {
        $chars = (int) config('category_analysis.max_field_chars');
        $cut = fn (?string $v) => $v === null ? null : mb_substr(trim($v), 0, $chars);

        return [
            'necesidad' => $cut($request->necesidad),
            'motivo' => $request->justificacionLabel(),
            'detalle' => $cut($request->detalle),
            'terminos_probados' => $cut($request->terminos_probados),
            'contexto_sectorial' => collect($request->context_snapshot['categorias_vinculadas'] ?? [])
                ->pluck('breadcrumb')->filter()->take(15)->values()->all(),
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: bool, 2: string} fuentes con source_id, limitado, nota */
    private function retrieveSources(TaxonomyCategoryRequest $request): array
    {
        if (! $this->sources->available()) {
            return [[], true, 'Análisis limitado: la búsqueda de fuentes externas no está disponible; no se verificó nada fuera del catálogo CPV.'];
        }

        try {
            $found = $this->sources->search(mb_substr((string) $request->necesidad, 0, 200), (int) config('category_analysis.max_sources'));
            if ($this->sources instanceof ReportsUsage) {
                $this->sourceUsage = $this->sources->lastUsage();
            }
        } catch (\Throwable $e) {
            Log::warning('Category analysis: source search failed ('.class_basename($e).').');

            return [[], true, 'Análisis limitado: la búsqueda de fuentes externas falló; no se verificó nada fuera del catálogo CPV.'];
        }

        $sources = [];
        foreach (array_values($found) as $i => $source) {
            $url = (string) ($source['url'] ?? '');
            if (! self::isPublicHttpsUrl($url)) {
                continue;
            }
            $sources[] = [
                'source_id' => 'S'.($i + 1),
                'url' => $url,
                'title' => mb_substr((string) ($source['title'] ?? ''), 0, 300),
                'entity' => isset($source['entity']) ? mb_substr((string) $source['entity'], 0, 200) : null,
                'excerpt' => mb_substr((string) ($source['excerpt'] ?? ''), 0, 1500),
                'published_at' => $source['published_at'] ?? null,
                'retrieved_at' => now()->toIso8601String(),
                'verified' => $this->verifyUrl($url),
            ];
        }

        return $sources === []
            ? [[], true, 'Análisis limitado: la búsqueda de fuentes externas no devolvió resultados utilizables.']
            : [$sources, false, ''];
    }

    /** Solo https a un nombre público: nunca IP, localhost ni nombres internos (la verificación no sale a la red interna). */
    public static function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && $host !== ''
            && str_contains($host, '.')
            && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
            && ! preg_match('/(^|\.)(localhost|local|internal|lan|home|corp)$/', $host);
    }

    private function verifyUrl(string $url): ?bool
    {
        try {
            $response = Http::timeout(5)->withoutRedirecting()->head($url);

            return $response->status() < 400;
        } catch (\Throwable) {
            return null; // no se pudo comprobar
        }
    }

    /** Un reintento acotado solo si la falla es transitoria (tiempo de espera, sobrecarga). */
    private function callProvider(array $prompt): ProviderResult
    {
        $timeout = (int) config('category_analysis.timeout_seconds');
        $tokens = (int) config('category_analysis.max_output_tokens');

        try {
            return $this->provider->analyze($prompt, $timeout, $tokens);
        } catch (ProviderException $e) {
            if (! $e->retryable) {
                throw $e;
            }

            return $this->provider->analyze($prompt, $timeout, $tokens);
        }
    }

    private function throttle(TaxonomyCategoryRequest $request, User $actor): void
    {
        $limits = config('category_analysis.limits');
        $keys = [
            'category-analysis:request:'.$request->getKey() => $limits['per_request'],
            'category-analysis:actor:'.$actor->getKey() => $limits['per_actor'],
        ];
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw new CategoryAnalysisException("Se alcanzó el límite de análisis seguidos. Intente de nuevo en {$minutes} min.");
            }
        }
        foreach ($keys as $key => [, $minutes]) {
            RateLimiter::hit($key, $minutes * 60);
        }
    }

    /** Gasto del día (todas las solicitudes) contra el presupuesto diario del panel. */
    private function overBudget(): bool
    {
        $cap = CategoryAnalysisSettings::current()->daily_budget_usd;
        if ($cap === null) {
            return false;
        }

        $spent = (float) TaxonomyCategoryRequestAnalysis::query()->where('created_at', '>=', now()->startOfDay())->sum('cost_usd');

        return $spent >= (float) $cap;
    }

    /**
     * Costo estimado con los precios del panel: tokens del juicio, más los de la búsqueda de fuentes y sus búsquedas
     * web. null si faltan precios o el proveedor no informó tokens.
     */
    private function cost(?ProviderResult $result): ?float
    {
        $settings = CategoryAnalysisSettings::current();
        $in = $settings->price_input_per_mtok;
        $out = $settings->price_output_per_mtok;
        if ($in === null || $out === null) {
            return null;
        }
        $inputTokens = ($result?->inputTokens ?? 0) + $this->sourceUsage['input_tokens'];
        $outputTokens = ($result?->outputTokens ?? 0) + $this->sourceUsage['output_tokens'];
        if ($result !== null && ($result->inputTokens === null || $result->outputTokens === null)) {
            return null;
        }

        return round(($inputTokens * $in + $outputTokens * $out) / 1_000_000
            + $this->sourceUsage['web_searches'] * (float) ($settings->price_per_web_search ?? 0), 6);
    }

    private function store(array $attributes): TaxonomyCategoryRequestAnalysis
    {
        return TaxonomyCategoryRequestAnalysis::create($attributes + ['finished_at' => now()]);
    }
}
