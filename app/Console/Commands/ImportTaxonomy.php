<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa la taxonomía CPV desde el Excel que entrega Lorenzo (hoja "Database", la tabla plana con
 * las 3.103 filas — las hojas "01".."48" son el mismo recorte repetido por grupo, solo para lectura
 * humana). Ver docs/taxonomia/acuerdos_pendientes_con_lorenzo.md para el porqué de cada decisión
 * de mapeo de acá abajo — todas ya cerradas con el cliente el 7 sep 2026, salvo lo anotado.
 *
 * Solo 3 niveles del archivo se modelan como nodos reales del árbol (Grupo/Familia/Categoría) — el
 * código de cada uno es la clave de idempotencia (upsert por `code` único), así que correr este
 * comando 2 veces con el mismo Excel no duplica nada, solo actualiza. Branch/Subbranch NO son
 * nodos: Lorenzo no les da código propio y el mismo texto se repite en familias distintas
 * (verificado — "Accessories" aparece en 3 familias), así que quedan como 2 columnas de texto en
 * la fila de la Categoría (punto 2 del acuerdo).
 *
 * La traducción a español NO la hace este comando — la trae Lorenzo con sus propias herramientas
 * (punto 5 del acuerdo) y se carga aparte. Este import solo carga la traducción `en` que ya trae
 * el Excel de origen, para no dejar el catálogo sin ningún nombre mientras tanto.
 *
 * Implementado con upserts masivos por lote (`DB::table()->upsert()`), no Eloquent fila por fila:
 * las 3.103 categorias (+332 familias +48 grupos +3.103 traducciones) contra Supabase remoto vía
 * Eloquent uno por uno tardaba >5 minutos sin terminar (cada `save()` dispara el observer, que a
 * su vez hace un round-trip de red extra para buscar el padre) - con upsert por lote baja a
 * segundos. level/path se calculan acá mismo (mismo algoritmo de TaxonomyCategoryObserver, pero
 * en memoria) porque un import masivo por definición no puede tener ciclos (el árbol se arma desde
 * cero a partir del Excel) - el observer sigue activo y protegiendo ediciones manuales futuras
 * desde el panel, solo se evita acá por ser la ruta caliente de este comando puntual.
 */
class ImportTaxonomy extends Command
{
    protected $signature = 'taxonomy:import
        {file=docs/taxonomia/CLASSIFIED_MASTER.xlsx : Ruta del Excel, relativa a la raiz del proyecto}
        {--sheet=Database : Hoja a leer (la tabla plana unificada, no las hojas por grupo)}
        {--source-version= : Etiqueta de esta carga, por defecto la fecha de hoy}
        {--chunk=500 : Filas por lote en los upserts de Categoria/Traduccion}';

    protected $description = 'Importa/actualiza la taxonomia CPV (Grupo > Familia > Categoria) desde el Excel de Lorenzo';

    private const PREFIX = 'CPV-';

    private const REQUIRED_COLUMNS = [
        'Group Code', 'Group Name', 'Family Code', 'Family Name',
        'Branch', 'Subbranch', 'Category Code', 'Category Name',
        'Category Type', 'Classification', 'Classification Basis',
    ];

    /** Category Type -> tipo_oferta (acuerdo punto 1: binario, no hay un 3er valor real en el archivo). */
    private const TIPO_MAP = [
        'G' => TaxonomyCategory::TIPO_BIEN,
        'S' => TaxonomyCategory::TIPO_SERVICIO,
    ];

    /** Classification -> chamber_relevance (ya viene resuelta en el archivo, ver presentacion-fase-2.html). */
    private const RELEVANCE_MAP = [
        'BELONGS' => TaxonomyCategory::RELEVANCE_BELONGS,
        'MAYBE' => TaxonomyCategory::RELEVANCE_MAYBE,
        'DOES NOT BELONG' => TaxonomyCategory::RELEVANCE_DOES_NOT_BELONG,
    ];

    public function handle(): int
    {
        $path = base_path($this->argument('file'));

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $sourceVersion = $this->option('source-version') ?: now()->toDateString();
        $chunkSize = (int) $this->option('chunk');
        $now = now();

        $this->info('Leyendo Excel...');
        $rows = $this->readSheetRows($path, $this->option('sheet'));

        if ($rows === null) {
            return self::FAILURE;
        }

        // --- Pasada 1: agrupar filas por Grupo/Familia/Categoria en memoria (sin tocar la BD) ---
        $groupNames = [];   // "CPV-01" => "Heat Transfer..."
        $familyNames = [];  // "CPV-01.01" => ["name" => ..., "group_code" => "CPV-01"]
        $categories = [];   // "CPV-01.01.01G" => [...datos de la categoria...]
        $skipped = 0;

        foreach ($rows as $row) {
            $groupCode = trim((string) ($row['Group Code'] ?? ''));
            $categoryCode = trim((string) ($row['Category Code'] ?? ''));

            if ($groupCode === '' || $categoryCode === '') {
                $skipped++;

                continue;
            }

            $groupCpv = self::PREFIX.$groupCode;
            $groupNames[$groupCpv] ??= trim((string) ($row['Group Name'] ?? ''));

            $familyCodeRaw = trim((string) ($row['Family Code'] ?? ''));
            $familyCpv = $familyCodeRaw !== '' ? self::PREFIX.$familyCodeRaw : null;

            if ($familyCpv !== null) {
                $familyNames[$familyCpv] ??= [
                    'name' => trim((string) ($row['Family Name'] ?? '')),
                    'group_code' => $groupCpv,
                ];
            }

            $categoryType = strtoupper(trim((string) ($row['Category Type'] ?? '')));
            $classification = strtoupper(trim((string) ($row['Classification'] ?? '')));

            $categories[self::PREFIX.$categoryCode] = [
                'name' => trim((string) ($row['Category Name'] ?? '')),
                'parent_code' => $familyCpv ?? $groupCpv,
                'tipo_oferta' => self::TIPO_MAP[$categoryType] ?? null,
                'branch' => trim((string) ($row['Branch'] ?? '')) ?: null,
                'subbranch' => trim((string) ($row['Subbranch'] ?? '')) ?: null,
                'chamber_relevance' => self::RELEVANCE_MAP[$classification] ?? null,
                'relevance_basis' => trim((string) ($row['Classification Basis'] ?? '')) ?: null,
                // Default mientras se confirma con Lorenzo el punto 4 del acuerdo (que hacer con
                // las "MAYBE"): lo unico que se apaga solo es lo que el archivo ya marca fuera de
                // alcance. Administrable despues a mano desde el panel, sin re-importar.
                'is_active' => $classification !== 'DOES NOT BELONG',
            ];
        }

        // Instancia NUEVA en cada consulta (no reusar un mismo builder entre varios ->whereIn(),
        // que acumula condiciones en vez de reemplazarlas - bug real encontrado en esta sesion: un
        // $table compartido entre el whereIn de grupos y el de familias terminaba armando
        // "WHERE code IN (grupos) AND code IN (familias)", que nunca matchea nada).
        $categoriesTable = fn () => DB::connection('pgsql')->table('taxonomy_categories');
        $translationsTable = fn () => DB::connection('pgsql')->table('taxonomy_category_translations');

        // --- Pasada 2: Grupos (nivel 0, path = su propio code) ---
        $groupRows = [];
        foreach ($groupNames as $code => $name) {
            $groupRows[] = [
                'code' => $code, 'parent_id' => null, 'level' => 0, 'path' => $code,
                'source_version' => $sourceVersion, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $categoriesTable()->upsert($groupRows, ['code'], ['level', 'path', 'source_version', 'updated_at']);
        $groupIds = $categoriesTable()->whereIn('code', array_keys($groupNames))->pluck('id', 'code');

        // --- Pasada 3: Familias (nivel 1, path = grupo.path/family.code = grupo.code/family.code) ---
        $familyRows = [];
        foreach ($familyNames as $code => $data) {
            $familyRows[] = [
                'code' => $code,
                'parent_id' => $groupIds[$data['group_code']] ?? null,
                'level' => 1,
                'path' => $data['group_code'].'/'.$code,
                'source_version' => $sourceVersion, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        collect($familyRows)->chunk($chunkSize)->each(
            fn ($chunk) => $categoriesTable()->upsert($chunk->all(), ['code'], ['parent_id', 'level', 'path', 'source_version', 'updated_at'])
        );
        $familyIds = $familyNames ? $categoriesTable()->whereIn('code', array_keys($familyNames))->pluck('id', 'code') : collect();

        // --- Pasada 4: Categorias (nivel 2, path = padre.path/category.code) ---
        $parentPaths = $groupNames === [] ? collect() : collect($groupNames)->keys()->mapWithKeys(fn ($c) => [$c => $c])
            ->merge(collect($familyNames)->map(fn ($d, $code) => $d['group_code'].'/'.$code));

        $categoryRows = [];
        foreach ($categories as $code => $data) {
            $parentCode = $data['parent_code'];
            $parentId = $familyIds[$parentCode] ?? $groupIds[$parentCode] ?? null;
            $parentPath = $parentPaths[$parentCode] ?? $parentCode;

            $categoryRows[] = [
                'code' => $code,
                'parent_id' => $parentId,
                'level' => 2,
                'path' => $parentPath.'/'.$code,
                'tipo_oferta' => $data['tipo_oferta'],
                'branch' => $data['branch'],
                'subbranch' => $data['subbranch'],
                'chamber_relevance' => $data['chamber_relevance'],
                'relevance_basis' => $data['relevance_basis'],
                'is_active' => $data['is_active'],
                'source_version' => $sourceVersion, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $this->info('Guardando '.count($categoryRows).' categorias...');
        collect($categoryRows)->chunk($chunkSize)->each(function ($chunk) use ($categoriesTable) {
            $categoriesTable()->upsert($chunk->all(), ['code'], [
                'parent_id', 'level', 'path', 'tipo_oferta', 'branch', 'subbranch',
                'chamber_relevance', 'relevance_basis', 'is_active', 'source_version', 'updated_at',
            ]);
        });
        $categoryIds = collect($categories)->keys()->chunk($chunkSize)
            ->flatMap(fn ($chunk) => $categoriesTable()->whereIn('code', $chunk->all())->pluck('id', 'code'));

        // --- Pasada 5: traducciones EN (Grupo/Familia/Categoria, todas al mismo tiempo) ---
        $translationRows = [];
        foreach ($groupNames as $code => $name) {
            if ($name !== '') {
                $translationRows[] = ['category_id' => $groupIds[$code], 'locale' => 'en', 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($familyNames as $code => $data) {
            if ($data['name'] !== '') {
                $translationRows[] = ['category_id' => $familyIds[$code], 'locale' => 'en', 'name' => $data['name'], 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($categories as $code => $data) {
            if ($data['name'] !== '') {
                $translationRows[] = ['category_id' => $categoryIds[$code], 'locale' => 'en', 'name' => $data['name'], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $this->info('Guardando '.count($translationRows).' traducciones (en)...');
        collect($translationRows)->chunk($chunkSize)->each(
            fn ($chunk) => $translationsTable()->upsert($chunk->all(), ['category_id', 'locale'], ['name', 'updated_at'])
        );

        $this->newLine();
        $this->info('Grupos: '.count($groupNames).' | Familias: '.count($familyNames).' | Categorias: '.count($categories)." | Filas omitidas (sin codigo): {$skipped}");

        $cycles = TaxonomyCategory::detectCycles();

        if ($cycles->isNotEmpty()) {
            $this->error('Se detectaron ciclos en los ids: '.$cycles->implode(', '));

            return self::FAILURE;
        }

        $this->info('0 ciclos detectados. Importacion completa.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function readSheetRows(string $path, string $sheetName): ?array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly($sheetName);
        }

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet) {
            $this->error("No existe la hoja '{$sheetName}'.");

            return null;
        }

        $raw = $sheet->rangeToArray('A1:K'.$sheet->getHighestRow(), null, true, false);

        // La hoja "Database" trae una fila de link ("Back to Index") antes del header real -
        // buscamos la primera fila que de verdad tenga "Group Code", en vez de asumir que es la 1.
        $header = null;

        while ($raw) {
            $candidate = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_shift($raw));

            if (in_array('Group Code', $candidate, true)) {
                $header = $candidate;

                break;
            }
        }

        if ($header === null) {
            $this->error('No se encontro la fila de encabezados ("Group Code") en la hoja.');

            return null;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $header, true)) {
                $this->error("Falta la columna esperada '{$column}' en la hoja.");

                return null;
            }
        }

        return array_map(fn ($row) => array_combine($header, $row), $raw);
    }
}
