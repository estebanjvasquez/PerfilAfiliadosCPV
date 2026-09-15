<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 del proyecto de taxonomía (ver docs/taxonomia/analisis_taxonomia_supplyhigh.md sección 6):
 * genera el mapeo servicio-viejo -> categoría-CPV-nueva en `service_taxonomy_homologacion`, insumo
 * de `taxonomy:homologate-empresas` (que recién propaga esto a `empresa_taxonomy_category`).
 *
 * No llama a ningún modelo de IA - compara embeddings YA CALCULADOS: `service_embeddings` (112
 * filas, Fase MCP-4.2) contra `taxonomy_category_embeddings` (3.483 filas, Fase MCP-1), ambos ya
 * poblados con @cf/baai/bge-m3. Cero costo/latencia nueva.
 *
 * Nivel FAMILIA (level=1) elegido como destino del mapeo, no Grupo (48, demasiado amplio para
 * discriminar 112 servicios) ni Categoría hoja (3.103, demasiado específico para un catálogo viejo
 * plano de solo 112 entradas - forzar ahí sobreajustaría el mapeo). Solo Familias `is_active=true`
 * (excluye las 698 "DOES NOT BELONG"/"MAYBE" que Lorenzo ya marcó fuera de alcance).
 *
 * Umbral 0.45 (distancia coseno) calibrado contra 6 servicios reales antes de escribir esto (ver
 * docs/taxonomia/plan_mcp_cira.md): matches genuinos dieron 0.32-0.40 (ej. "INGENIERÍA:
 * INFRAESTRUCTURA CIVIL Y URBANISMO" -> "Ingeniería Civil para Infraestructura" a 0.321). Toma el
 * mejor match, y un 2do solo si está a menos de 0.02 del primero (empate real, no ruido de cola).
 *
 * Resultado queda con `reviewed = false` - `taxonomy:homologate-empresas` NO usa una fila hasta
 * que alguien la revise y la marque `reviewed = true` (ver ese comando). Este comando es
 * idempotente: correrlo de nuevo actualiza distancia/upsert, no duplica.
 *
 * DOS EXCLUSIONES agregadas tras la primera corrida real (12 sep 2026), no forman parte del
 * diseño original - se encontraron mirando el reporte, no se adivinaron antes:
 *
 * 1. Servicios con nombre literal "X" (8 filas) u "OTROS" (7 filas) - placeholders/catch-all del
 *    catálogo viejo, no describen una actividad real - homologarlos a CUALQUIER categoría
 *    especifica de la taxonomia nueva seria inventar informacion. Quedan sin mapear a proposito.
 *
 * 2. 2 Familias "agujero negro": `CPV-29.02` ("Servicios", nombre literalmente generico) y
 *    `CPV-12.04` ("Componentes de Construccion", umbrella demasiado amplia) atrajeron ~39 de los
 *    112 servicios en la primera corrida real - desde bancos hasta limpieza hasta marketing hasta
 *    transporte, todos con score similar (0.34-0.44) a un match realmente especifico (Obras
 *    Civiles 0.33, Mantenimiento de Planta 0.31, etc.) - subir el umbral NO las separa (se
 *    solapan en el mismo rango de distancia), asi que se excluyen como DESTINO valido del mapeo
 *    - un servicio que solo matchee contra estas 2 queda sin mapear en vez de con un match
 *    enganoso. Otras familias que tambien se repiten seguido ("Maquinas de Fabricacion",
 *    "Servicios de Apoyo para Ingenieria") NO se excluyen: a diferencia de las 2 de arriba, tienen
 *    nombre topicamente especifico (maquinaria industrial, apoyo de ingenieria) y su repeticion
 *    refleja un cluster semantico real del catalogo FABRICANTES, no un nombre vacio de contenido.
 */
class HomologateServicesTaxonomy extends Command
{
    private const NOMBRES_EXCLUIDOS = ['X', 'OTROS'];

    private const CODIGOS_FAMILIA_EXCLUIDOS = ['CPV-29.02', 'CPV-12.04'];

    protected $signature = 'taxonomy:homologate-services
        {--threshold=0.45 : Distancia coseno máxima para aceptar un match}
        {--tie-margin=0.02 : Si el 2do mejor match está a menos de esto del 1ro, se guarda también}';

    protected $description = 'Genera el mapeo servicio-viejo -> categoría CPV (Familia) en service_taxonomy_homologacion, para revisión antes de propagar a empresas';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');
        $tieMargin = (float) $this->option('tie-margin');

        $excludedUpper = array_map('mb_strtoupper', self::NOMBRES_EXCLUIDOS);
        $allServices = DB::connection('mysql')->select('select id, name from services order by id');
        $services = array_values(array_filter(
            $allServices,
            fn ($s) => ! in_array(mb_strtoupper(trim($s->name)), $excludedUpper, true)
        ));
        $excludedCount = count($allServices) - count($services);

        if (empty($services)) {
            $this->error('No hay filas en services (conexión mysql).');

            return self::FAILURE;
        }

        $this->info('Servicios a homologar: '.count($services)." (excluidos {$excludedCount} con nombre ".implode('/', self::NOMBRES_EXCLUIDOS).')');

        $now = now();
        $rows = [];
        $sinMatch = [];

        $bar = $this->output->createProgressBar(count($services));
        $bar->start();

        $blacklistPlaceholders = implode(',', array_fill(0, count(self::CODIGOS_FAMILIA_EXCLUIDOS), '?'));

        foreach ($services as $service) {
            $matches = DB::connection('pgsql')->select(<<<SQL
                select tc.id as category_id, tc.code, coalesce(tt.name, tc.code) as name,
                       (se.embedding <=> te.embedding) as distance
                from service_embeddings se
                join taxonomy_category_embeddings te on true
                join taxonomy_categories tc on tc.id = te.category_id
                left join taxonomy_category_translations tt on tt.category_id = tc.id and tt.locale = 'es'
                where se.service_id = ? and tc.level = 1 and tc.is_active = true
                    and tc.code not in ({$blacklistPlaceholders})
                order by distance asc
                limit 5
            SQL, [$service->id, ...self::CODIGOS_FAMILIA_EXCLUIDOS]);

            $accepted = array_values(array_filter($matches, fn ($m) => (float) $m->distance < $threshold));

            if (empty($accepted)) {
                $sinMatch[] = $service;
                $bar->advance();

                continue;
            }

            $toSave = [$accepted[0]];
            if (isset($accepted[1]) && ((float) $accepted[1]->distance - (float) $accepted[0]->distance) < $tieMargin) {
                $toSave[] = $accepted[1];
            }

            foreach ($toSave as $m) {
                $rows[] = [
                    'service_id' => $service->id,
                    'category_id' => $m->category_id,
                    'distance' => round((float) $m->distance, 4),
                    'method' => 'semantic_auto',
                    'created_at' => $now,
                    'updated_at' => $now,
                    '_service_name' => $service->name,
                    '_category_code' => $m->code,
                    '_category_name' => $m->name,
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach (array_chunk($rows, 100) as $chunk) {
            $insertRows = array_map(fn ($r) => [
                'service_id' => $r['service_id'],
                'category_id' => $r['category_id'],
                'distance' => $r['distance'],
                'method' => $r['method'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ], $chunk);

            DB::connection('pgsql')->table('service_taxonomy_homologacion')->upsert(
                $insertRows,
                ['service_id', 'category_id'],
                ['distance', 'method', 'updated_at']
            );
        }

        $this->info(count($rows).' fila(s) de mapeo guardadas (upsert) en service_taxonomy_homologacion.');

        if (! empty($sinMatch)) {
            $this->warn(count($sinMatch)." servicio(s) sin match por debajo del umbral ({$threshold}):");
            foreach ($sinMatch as $s) {
                $this->line("  - [{$s->id}] {$s->name}");
            }
        }

        $this->newLine();
        $this->info('=== Reporte completo para revisión (servicio -> categoría propuesta) ===');
        $this->table(
            ['service_id', 'Servicio', 'Categoría CPV (Familia)', 'Código', 'Distancia'],
            collect($rows)->map(fn ($r) => [
                $r['service_id'],
                mb_strimwidth($r['_service_name'], 0, 45, '…'),
                mb_strimwidth($r['_category_name'], 0, 40, '…'),
                $r['_category_code'],
                $r['distance'],
            ])->all()
        );

        return self::SUCCESS;
    }
}
