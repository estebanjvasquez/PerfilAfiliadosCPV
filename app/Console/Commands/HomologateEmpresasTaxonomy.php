<?php

namespace App\Console\Commands;

use App\Models\EmpresaTaxonomyCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Paso 4 (final) de la Fase 3 del proyecto de taxonomía (ver docs/taxonomia/plan_mcp_cira.md y
 * docs/taxonomia/analisis_taxonomia_supplyhigh.md sección 6): propaga el mapeo ya revisado de
 * `service_taxonomy_homologacion` (`HomologateServicesTaxonomy`, paso 2) a `empresa_taxonomy_category`
 * - una fila por cada combinación real (empresa, categoría CPV) que se puede inferir de los
 * servicios que la empresa YA tiene cargados en el catálogo viejo (`empresa_sector_service`).
 *
 * Solo usa filas `reviewed = true` de `service_taxonomy_homologacion` (pasa `--incluir-no-revisados`
 * para saltarse ese filtro, no debería hacer falta - las 131 filas activas al escribir esto ya están
 * todas revisadas a mano, ver plan_mcp_cira.md sección "Fase 3 pausada..." y su continuación).
 *
 * `origen = EmpresaTaxonomyCategory::ORIGEN_SUGGESTED` ("el sistema lo sugirió, la empresa no lo
 * confirmó todavía") - decisión ya tomada con el usuario, ver plan_mcp_cira.md. `ON CONFLICT
 * (empresa_id, category_id) DO NOTHING`: nunca pisa una fila que ya exista (por ejemplo si una
 * empresa ya se autohomologó por otra vía) ni degrada una fila `validated` de vuelta a `suggested`.
 *
 * 100% aditivo: NO toca `sectors`/`services`/`empresa_sector_service` (catálogo viejo, sigue siendo
 * la fuente de verdad de los reportes actuales - restricción explícita del usuario) ni borra nada de
 * `empresa_taxonomy_category` que ya exista.
 */
class HomologateEmpresasTaxonomy extends Command
{
    protected $signature = 'taxonomy:homologate-empresas
        {--incluir-no-revisados : Usar también filas de service_taxonomy_homologacion con reviewed=false}
        {--dry-run : Solo mostrar cuántas filas se insertarían, sin escribir nada}';

    protected $description = 'Propaga service_taxonomy_homologacion (servicio -> categoría CPV) a empresa_taxonomy_category (empresa -> categoría CPV)';

    public function handle(): int
    {
        $incluirNoRevisados = (bool) $this->option('incluir-no-revisados');
        $dryRun = (bool) $this->option('dry-run');

        $homologacionQuery = DB::connection('pgsql')->table('service_taxonomy_homologacion');
        if (! $incluirNoRevisados) {
            $homologacionQuery->where('reviewed', true);
        }

        $serviceToCategories = $homologacionQuery->get()
            ->groupBy('service_id')
            ->map(fn ($rows) => $rows->pluck('category_id')->unique()->values()->all());

        if ($serviceToCategories->isEmpty()) {
            $this->error('service_taxonomy_homologacion no tiene filas revisadas. Corré primero taxonomy:homologate-services y revisá el resultado.');

            return self::FAILURE;
        }

        $this->info('Servicios homologados disponibles: '.$serviceToCategories->count());

        $empresaServicios = DB::connection('mysql')->table('empresa_sector_service')
            ->select('empresa_id', 'service_id')
            ->get()
            ->groupBy('empresa_id');

        $this->info('Empresas con al menos un servicio real: '.$empresaServicios->count());

        $now = now();
        $rows = [];
        $empresasSinCategoria = [];

        foreach ($empresaServicios as $empresaId => $servicios) {
            $categoryIds = $servicios->pluck('service_id')
                ->flatMap(fn ($serviceId) => $serviceToCategories->get($serviceId, []))
                ->unique()
                ->values();

            if ($categoryIds->isEmpty()) {
                $empresasSinCategoria[] = $empresaId;

                continue;
            }

            foreach ($categoryIds as $categoryId) {
                $rows[] = [
                    'empresa_id' => $empresaId,
                    'category_id' => $categoryId,
                    'origen' => EmpresaTaxonomyCategory::ORIGEN_SUGGESTED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->info(count($rows).' fila(s) empresa-categoría a insertar (upsert, no duplica).');

        if ($empresasSinCategoria) {
            $this->warn(count($empresasSinCategoria).' empresa(s) con servicios reales pero NINGUNO homologado todavía (sus servicios están fuera de service_taxonomy_homologacion - servicio excluido o sin revisar):');
            $this->line('  IDs: '.implode(', ', $empresasSinCategoria));
        }

        if ($dryRun) {
            $this->info('--dry-run: no se escribió nada.');

            return self::SUCCESS;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            // OJO: Query\Builder::upsert() con $update = [] hace un INSERT liso (sin ON CONFLICT),
            // no un "no-op" - encontrado leyendo el propio código de Laravel antes de correr esto
            // (habría roto la idempotencia: correr el comando 2 veces habría violado la unique
            // constraint (empresa_id, category_id) en la 2da corrida). Pasar ['updated_at'] fuerza
            // el ON CONFLICT DO UPDATE real, pero solo toca `updated_at` - nunca pisa `origen` ni
            // `approved_by` de una fila que ya exista (ej. una ya escalada a 'validated' a mano).
            DB::connection('pgsql')->table('empresa_taxonomy_category')->upsert(
                $chunk,
                ['empresa_id', 'category_id'],
                ['updated_at']
            );
            $inserted += count($chunk);
        }

        $totalFinal = DB::connection('pgsql')->table('empresa_taxonomy_category')->count();
        $this->info("Listo. Filas procesadas: {$inserted}. Total en empresa_taxonomy_category: {$totalFinal}.");

        return self::SUCCESS;
    }
}
