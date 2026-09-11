<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase MCP-1 (ver docs/taxonomia/plan_mcp_cira.md): genera el embedding bilingue (ES/EN) de cada
 * nodo de la taxonomia CPV (Grupo/Familia/Categoria, 3.483 en total) y lo carga en
 * `taxonomy_category_embeddings` — insumo del componente semantico del buscador hibrido
 * (`search_taxonomy`, todavia sin construir).
 *
 * El texto que se embebe combina Nombre (ES) + Nombre (EN) + sinonimos/terminos locales (cuando
 * existen) - un termino como "arbolito" (sinonimo venezolano de "API 6A Wellhead Valves") debe
 * poder matchear semanticamente aunque el nombre oficial de la categoria no lo mencione.
 *
 * Llama al Worker `perfilafiliados-mcp` (repo aparte, endpoint /embed, modelo @cf/baai/bge-m3 -
 * multilingue, por eso un solo vector por nodo alcanza para ES y EN a la vez, no hace falta uno
 * por idioma). Es un job de CARGA POR LOTE, no en tiempo real - se corre ahora que la traduccion
 * ES ya esta completa (ver acuerdos_pendientes_con_lorenzo.md), y de nuevo solo cuando cambie el
 * texto de una categoria (no en cada busqueda de un usuario).
 *
 * Idempotente: upsert por category_id (UNIQUE en la tabla) - correr de nuevo no duplica, solo
 * actualiza si el texto fuente cambio (o fuerza con --force para regenerar todo igual).
 */
class GenerateTaxonomyEmbeddings extends Command
{
    protected $signature = 'taxonomy:generate-embeddings
        {--chunk=50 : Nodos por request al Worker (limite del Worker: 100)}
        {--force : Regenerar aunque el texto fuente no haya cambiado}';

    protected $description = 'Genera y carga los embeddings bilingues (ES/EN) de la taxonomia CPV en taxonomy_category_embeddings';

    private const MODEL_VERSION = '@cf/baai/bge-m3';

    public function handle(): int
    {
        $embedUrl = config('services.mcp.embed_url');
        $embedToken = config('services.mcp.embed_token');

        if (! $embedUrl || ! $embedToken) {
            $this->error('Falta MCP_EMBED_URL/MCP_EMBED_TOKEN en el .env (ver config/services.php "mcp").');

            return self::FAILURE;
        }

        $this->info('Leyendo taxonomía (Grupos + Familias + Categorías) con traducciones y sinónimos...');

        $categories = TaxonomyCategory::query()
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', ['es', 'en']),
                'synonyms',
            ])
            ->orderBy('id')
            ->get();

        $this->info("Total de nodos: {$categories->count()}");

        $existing = DB::connection('pgsql')->table('taxonomy_category_embeddings')
            ->pluck('source_text', 'category_id');

        $force = (bool) $this->option('force');
        $chunkSize = max(1, min(100, (int) $this->option('chunk')));

        $pending = $categories->filter(function (TaxonomyCategory $category) use ($existing, $force) {
            if ($force) {
                return true;
            }

            $currentText = $this->buildSourceText($category);

            return ($existing[$category->id] ?? null) !== $currentText;
        })->values();

        $skipped = $categories->count() - $pending->count();
        $this->info("Nodos a (re)generar: {$pending->count()} | ya al día, sin cambios: {$skipped}");

        if ($pending->isEmpty()) {
            $this->info('Nada que hacer.');

            return self::SUCCESS;
        }

        $now = now();
        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        $failures = [];

        foreach ($pending->chunk($chunkSize) as $chunk) {
            // ->values() ANTES de mapear: Collection::chunk() conserva las claves originales del
            // padre (ej. el 2do lote arranca en la clave 50, no 0) - sin este reset, $texts[$i]/
            // $embeddings[$i] mas abajo (que sí son 0-based, porque $embeddings viene de un JSON
            // de la API y $chunk se re-indexa aparte para el bucle de filas) quedan desalineados
            // a partir del 2do lote (bug real, encontrado al correr esto contra los 3.483 nodos).
            $chunk = $chunk->values();
            $texts = $chunk->map(fn (TaxonomyCategory $c) => $this->buildSourceText($c))->all();

            $response = Http::withToken($embedToken)
                ->timeout(60)
                ->retry(3, 2000)
                ->post($embedUrl, ['texts' => array_values($texts)]);

            if (! $response->successful()) {
                $codes = $chunk->pluck('code')->implode(', ');
                $failures[] = "Lote [{$codes}]: HTTP {$response->status()} — ".$response->body();
                $bar->advance($chunk->count());

                continue;
            }

            $embeddings = $response->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== $chunk->count()) {
                $failures[] = 'Lote con código '.$chunk->first()->code.': el Worker devolvió '.count($embeddings ?? []).' vectores para '.$chunk->count().' textos.';
                $bar->advance($chunk->count());

                continue;
            }

            $rows = $chunk->values()->map(function (TaxonomyCategory $category, int $i) use ($embeddings, $texts, $now) {
                return [
                    'category_id' => $category->id,
                    'embedding' => '['.implode(',', $embeddings[$i]).']',
                    'source_text' => $texts[$i],
                ];
            });

            foreach ($rows as $row) {
                DB::connection('pgsql')->statement(
                    <<<'SQL'
                        INSERT INTO taxonomy_category_embeddings
                            (category_id, embedding, model_version, source_text, generated_at, created_at, updated_at)
                        VALUES (?, ?::vector, ?, ?, ?, ?, ?)
                        ON CONFLICT (category_id) DO UPDATE SET
                            embedding = EXCLUDED.embedding,
                            model_version = EXCLUDED.model_version,
                            source_text = EXCLUDED.source_text,
                            generated_at = EXCLUDED.generated_at,
                            updated_at = EXCLUDED.updated_at
                    SQL,
                    [
                        $row['category_id'],
                        $row['embedding'],
                        self::MODEL_VERSION,
                        $row['source_text'],
                        $now,
                        $now,
                        $now,
                    ]
                );
            }

            $bar->advance($chunk->count());
        }

        $bar->finish();
        $this->newLine(2);

        if ($failures) {
            $this->error('Lotes con error ('.count($failures).'):');
            foreach ($failures as $f) {
                $this->line("  - {$f}");
            }

            return self::FAILURE;
        }

        $total = DB::connection('pgsql')->table('taxonomy_category_embeddings')->count();
        $this->info("Listo. Total de embeddings en la tabla: {$total}.");

        return self::SUCCESS;
    }

    private function buildSourceText(TaxonomyCategory $category): string
    {
        $es = $category->translations->firstWhere('locale', 'es')?->name;
        $en = $category->translations->firstWhere('locale', 'en')?->name;
        $synonyms = $category->synonyms->pluck('term')->unique()->implode(', ');

        $parts = array_filter([$es, $en, $synonyms !== '' ? "Sinónimos: {$synonyms}" : null]);

        return implode(' | ', $parts) ?: $category->code;
    }
}
