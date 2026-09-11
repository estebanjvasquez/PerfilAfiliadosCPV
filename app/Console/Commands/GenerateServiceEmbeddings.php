<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase MCP-4.1 (ver docs/taxonomia/plan_mcp_cira.md): genera el embedding de cada servicio del
 * catálogo (`services`, 112 filas) y lo carga en `service_embeddings` — insumo del fallback
 * semántico de `search_empresas` (tercer nivel, después de ILIKE exacto y pg_trgm difuso).
 *
 * Mismo patrón que `taxonomy:generate-embeddings` (Fase MCP-1): llama al Worker
 * `perfilafiliados-mcp` (/embed, @cf/baai/bge-m3), idempotente vía upsert por service_id.
 *
 * El texto embebido combina el nombre del servicio + el nombre de su sector - da contexto
 * semantico adicional (ej. "MATERIALES, EQUIPOS Y ACCESORIOS PARA SOLDAR | Sector: FABRICANTES")
 * para que consultas como "soldadura" o "quiero contratar quien suelde tuberias" caigan cerca
 * aunque no compartan la raiz exacta "soldar".
 */
class GenerateServiceEmbeddings extends Command
{
    protected $signature = 'empresas:generate-service-embeddings
        {--chunk=50 : Servicios por request al Worker (limite del Worker: 100)}
        {--force : Regenerar aunque el texto fuente no haya cambiado}';

    protected $description = 'Genera y carga los embeddings del catálogo de servicios en service_embeddings';

    private const MODEL_VERSION = '@cf/baai/bge-m3';

    public function handle(): int
    {
        $embedUrl = config('services.mcp.embed_url');
        $embedToken = config('services.mcp.embed_token');

        if (! $embedUrl || ! $embedToken) {
            $this->error('Falta MCP_EMBED_URL/MCP_EMBED_TOKEN en el .env (ver config/services.php "mcp").');

            return self::FAILURE;
        }

        $this->info('Leyendo catálogo de servicios (con su sector)...');

        $services = Service::query()->with('sectors')->orderBy('id')->get();

        $this->info("Total de servicios: {$services->count()}");

        $existing = DB::connection('pgsql')->table('service_embeddings')
            ->pluck('source_text', 'service_id');

        $force = (bool) $this->option('force');
        $chunkSize = max(1, min(100, (int) $this->option('chunk')));

        $pending = $services->filter(function (Service $service) use ($existing, $force) {
            if ($force) {
                return true;
            }

            return ($existing[$service->id] ?? null) !== $this->buildSourceText($service);
        })->values();

        $skipped = $services->count() - $pending->count();
        $this->info("Servicios a (re)generar: {$pending->count()} | ya al día, sin cambios: {$skipped}");

        if ($pending->isEmpty()) {
            $this->info('Nada que hacer.');

            return self::SUCCESS;
        }

        $now = now();
        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        $failures = [];

        foreach ($pending->chunk($chunkSize) as $chunk) {
            $chunk = $chunk->values();
            $texts = $chunk->map(fn (Service $s) => $this->buildSourceText($s))->all();

            $response = Http::withToken($embedToken)
                ->timeout(60)
                ->retry(3, 2000)
                ->post($embedUrl, ['texts' => array_values($texts)]);

            if (! $response->successful()) {
                $ids = $chunk->pluck('id')->implode(', ');
                $failures[] = "Lote [{$ids}]: HTTP {$response->status()} — ".$response->body();
                $bar->advance($chunk->count());

                continue;
            }

            $embeddings = $response->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== $chunk->count()) {
                $failures[] = 'Lote con id '.$chunk->first()->id.': el Worker devolvió '.count($embeddings ?? []).' vectores para '.$chunk->count().' textos.';
                $bar->advance($chunk->count());

                continue;
            }

            $rows = $chunk->values()->map(function (Service $service, int $i) use ($embeddings, $texts) {
                return [
                    'service_id' => $service->id,
                    'embedding' => '['.implode(',', $embeddings[$i]).']',
                    'source_text' => $texts[$i],
                ];
            });

            foreach ($rows as $row) {
                DB::connection('pgsql')->statement(
                    <<<'SQL'
                        INSERT INTO service_embeddings
                            (service_id, embedding, model_version, source_text, generated_at, created_at, updated_at)
                        VALUES (?, ?::vector, ?, ?, ?, ?, ?)
                        ON CONFLICT (service_id) DO UPDATE SET
                            embedding = EXCLUDED.embedding,
                            model_version = EXCLUDED.model_version,
                            source_text = EXCLUDED.source_text,
                            generated_at = EXCLUDED.generated_at,
                            updated_at = EXCLUDED.updated_at
                    SQL,
                    [
                        $row['service_id'],
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

        $total = DB::connection('pgsql')->table('service_embeddings')->count();
        $this->info("Listo. Total de embeddings en la tabla: {$total}.");

        return self::SUCCESS;
    }

    private function buildSourceText(Service $service): string
    {
        $sectorName = $service->sectors?->name;

        return $sectorName ? "{$service->name} | Sector: {$sectorName}" : $service->name;
    }
}
