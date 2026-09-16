<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fase MCP-5.3 (ver docs/taxonomia/plan_mcp_cira.md): aplana `experiences` (MySQL producción,
 * módulo "Experiencias" del perfil de afiliado - 95 filas, cada una un JSON con un arreglo de
 * proyectos ejecutados) en `empresa_experiencias` (una fila por proyecto, pgsql/Supabase), y genera
 * el embedding de cada descripción en `empresa_experiencia_embeddings` - mismo patrón que
 * `empresas:generate-service-embeddings`. Solo LEE de mysql, solo ESCRIBE en pgsql.
 *
 * Simplificación deliberada: a diferencia de los comandos de embeddings de servicios/taxonomía
 * (que sí evitan regenerar lo que no cambió, porque esos catálogos se editan seguido desde el
 * panel), acá se trunca y recarga TODO en cada corrida - el volumen es chico (unos cientos de
 * proyectos, no miles) y esta fuente no tiene un panel de edición propio que dispare cambios
 * frecuentes, así que no se justifica la complejidad de un diff idempotente.
 *
 * Estructura real del JSON de origen (columna `exp_year`, mal nombrada - en realidad es el arreglo
 * completo de experiencias, no solo el año): `[{"type": "...", "data": {"exp_year": "2019", ...,
 * "Descripcion": "..."}}, ...]` - se extrae `data.Descripcion` (texto libre real de cada proyecto)
 * y `data.exp_year`. El resto de los campos (magnitud, mano de obra, infra*_id) no aporta a la
 * búsqueda y se descarta.
 */
class SyncEmpresaExperiencias extends Command
{
    protected $signature = 'empresas:sync-experiencias {--chunk=50 : Descripciones por request al Worker}';

    protected $description = 'Aplana el módulo Experiencias (experiences, MySQL) hacia empresa_experiencias + sus embeddings (pgsql)';

    private const MODEL_VERSION = '@cf/baai/bge-m3';

    public function handle(): int
    {
        $embedUrl = config('services.mcp.embed_url');
        $embedToken = config('services.mcp.embed_token');

        if (! $embedUrl || ! $embedToken) {
            $this->error('Falta MCP_EMBED_URL/MCP_EMBED_TOKEN en el .env (ver config/services.php "mcp").');

            return self::FAILURE;
        }

        $this->info('Leyendo experiences de MySQL...');

        $rows = DB::connection('mysql')->table('experiences')->get();

        $projects = collect();

        foreach ($rows as $row) {
            $entries = json_decode($row->exp_year ?? '', true);

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $descripcion = trim((string) ($entry['data']['Descripcion'] ?? ''));

                if ($descripcion === '') {
                    continue;
                }

                $projects->push([
                    'empresa_id' => $row->empresa_id,
                    'descripcion' => $descripcion,
                    'exp_year' => $entry['data']['exp_year'] ?? null,
                ]);
            }
        }

        $this->info("Total de proyectos con descripción real: {$projects->count()} (de {$rows->count()} empresas con módulo Experiencias)");

        if ($projects->isEmpty()) {
            $this->info('Nada que hacer.');

            return self::SUCCESS;
        }

        $now = now();

        DB::connection('pgsql')->statement('TRUNCATE TABLE empresa_experiencia_embeddings, empresa_experiencias RESTART IDENTITY');

        $ids = [];
        foreach ($projects as $project) {
            $ids[] = DB::connection('pgsql')->table('empresa_experiencias')->insertGetId([
                'empresa_id' => $project['empresa_id'],
                'descripcion' => $project['descripcion'],
                'exp_year' => $project['exp_year'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->info('Generando embeddings...');

        $chunkSize = max(1, min(100, (int) $this->option('chunk')));
        $bar = $this->output->createProgressBar($projects->count());
        $bar->start();
        $failures = [];

        foreach ($projects->values()->chunk($chunkSize) as $chunkIndex => $chunk) {
            $chunkIds = array_slice($ids, $chunkIndex * $chunkSize, $chunk->count());
            $texts = $chunk->pluck('descripcion')->all();

            $response = Http::withToken($embedToken)
                ->timeout(60)
                ->retry(3, 2000)
                ->post($embedUrl, ['texts' => array_values($texts)]);

            if (! $response->successful()) {
                $failures[] = "Lote con ids [".implode(', ', $chunkIds)."]: HTTP {$response->status()} - ".$response->body();
                $bar->advance($chunk->count());

                continue;
            }

            $embeddings = $response->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== $chunk->count()) {
                $failures[] = 'Lote con ids ['.implode(', ', $chunkIds).']: el Worker devolvió '.count($embeddings ?? []).' vectores para '.$chunk->count().' textos.';
                $bar->advance($chunk->count());

                continue;
            }

            foreach (array_values($texts) as $i => $text) {
                DB::connection('pgsql')->statement(
                    <<<'SQL'
                        INSERT INTO empresa_experiencia_embeddings
                            (experiencia_id, embedding, model_version, source_text, generated_at, created_at, updated_at)
                        VALUES (?, ?::vector, ?, ?, ?, ?, ?)
                    SQL,
                    [
                        $chunkIds[$i],
                        '['.implode(',', $embeddings[$i]).']',
                        self::MODEL_VERSION,
                        $text,
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

        $total = DB::connection('pgsql')->table('empresa_experiencia_embeddings')->count();
        $this->info("Listo. Total de embeddings en la tabla: {$total}.");

        return self::SUCCESS;
    }
}
