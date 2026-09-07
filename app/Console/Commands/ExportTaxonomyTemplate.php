<?php

namespace App\Console\Commands;

use App\Models\TaxonomyCategory;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el archivo "matriz" para Lorenzo (pedido 7 sep 2026): un Excel de ida y vuelta, prellenado
 * con TODO lo que ya tenemos (48 Grupos + 332 Familias + 3.103 Categorías, código/jerarquía/EN ya
 * cargados), con las columnas que todavía faltan en blanco (Nombre ES, diccionario) para que Lorenzo
 * las "vacíe" ahí mismo y nos lo devuelva — evita mandarle un Excel en blanco donde tendría que
 * re-tipear 3.483 códigos que ya existen.
 *
 * 6 pestañas: Instrucciones, Grupos, Familias, Categorías, Diccionario (sinónimos),
 * "Grupo 48 - revisar" (las 12 filas con código inconsistente encontradas al construir la
 * validación de jerarquía — ver docs/taxonomia/acuerdos_pendientes_con_lorenzo.md).
 *
 * Uso: `php artisan taxonomy:export-template` — sobreescribe el archivo de salida cada vez que se
 * corre (siempre refleja el estado actual de la base), no acumula versiones.
 */
class ExportTaxonomyTemplate extends Command
{
    protected $signature = 'taxonomy:export-template
        {file=docs/taxonomia/Plantilla_Taxonomia_CPV_para_Lorenzo.xlsx : Ruta de salida, relativa a la raiz del proyecto}';

    protected $description = 'Genera el Excel matriz (prellenado) para que Lorenzo complete traducciones ES, diccionario y correcciones pendientes';

    private const HEADER_FILL = 'FF1F4E78';
    private const HEADER_FONT = 'FFFFFFFF';
    private const PENDING_FILL = 'FFFFF2CC';
    private const REVIEW_FILL = 'FFF8CBAD';
    private const LOCKED_FILL = 'FFEDEDED';

    public function handle(): int
    {
        $this->info('Leyendo taxonomía actual (Grupos/Familias/Categorías/Sinónimos)...');

        $groups = TaxonomyCategory::query()
            ->where('level', TaxonomyCategory::LEVEL_GROUP)
            ->with(['translations' => fn ($q) => $q->whereIn('locale', ['es', 'en'])])
            ->orderBy('code')
            ->get();

        $families = TaxonomyCategory::query()
            ->where('level', TaxonomyCategory::LEVEL_FAMILY)
            ->with([
                'parent',
                'translations' => fn ($q) => $q->whereIn('locale', ['es', 'en']),
            ])
            ->orderBy('code')
            ->get();

        $categories = TaxonomyCategory::query()
            ->where('level', TaxonomyCategory::LEVEL_CATEGORY)
            ->with([
                'parent',
                'translations' => fn ($q) => $q->whereIn('locale', ['es', 'en']),
            ])
            ->orderBy('code')
            ->get();

        $synonyms = \App\Models\TaxonomyCategorySynonym::with('category')->orderBy('category_id')->get();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->buildInstructionsSheet($spreadsheet);
        $this->buildGroupsSheet($spreadsheet, $groups);
        $this->buildFamiliesSheet($spreadsheet, $families);
        $reviewCodes = $this->buildCategoriesSheet($spreadsheet, $categories);
        $this->buildSynonymsSheet($spreadsheet, $synonyms);
        $this->buildReviewSheet($spreadsheet, $categories, $reviewCodes, $this->readExcelFamilyCodes());

        $spreadsheet->setActiveSheetIndex(0);

        $outputPath = base_path($this->argument('file'));
        (new Xlsx($spreadsheet))->save($outputPath);

        $this->newLine();
        $this->info("Generado: {$outputPath}");
        $this->info('Grupos: '.$groups->count().' | Familias: '.$families->count().' | Categorías: '.$categories->count().' | Sinónimos: '.$synonyms->count().' | Filas a revisar (Grupo 48): '.count($reviewCodes));

        return self::SUCCESS;
    }

    private function buildInstructionsSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Instrucciones');
        $sheet->getColumnDimension('A')->setWidth(110);
        $sheet->getDefaultRowDimension()->setRowHeight(-1);

        $lines = [
            ['Taxonomía CPV — archivo matriz para completar con Lorenzo', true, 16],
            ['', false, 11],
            ['Qué es este archivo', true, 13],
            ['Este Excel ya trae TODO lo que el sistema tiene cargado hoy: los 48 Grupos, las 332 Familias y las 3.103 Categorías, con su código, su jerarquía (qué Familia pertenece a qué Grupo, qué Categoría pertenece a qué Familia) y el nombre en inglés que ya venía en el archivo original. No hace falta re-tipear ningún código — sirve para completar lo que falta y corregir lo que haga falta, directamente sobre estas mismas filas.'],
            ['', false, 11],
            ['Qué falta completar', true, 13],
            ['1. Columna "Nombre (ES)" en las pestañas Grupos, Familias y Categorías — está en blanco en las 3.483 filas. Es la traducción al español que verá la empresa afiliada en el sistema.'],
            ['2. Pestaña "Diccionario (sinónimos)" — términos locales/coloquiales que una empresa venezolana podría escribir en vez del nombre formal (ej. a "Wellhead" en la industria local se le dice "árbol de navidad" o "arbolito" — ya hay 2 filas de ejemplo cargadas). Agregar todas las que se les ocurran, no hay límite de filas.'],
            ['3. Pestaña "Grupo 48 - revisar" — 12 categorías con un problema de código encontrado al validar la jerarquía (detalle abajo). Necesitamos que confirmen cuál es el código correcto.'],
            ['', false, 11],
            ['Qué NO tocar', true, 13],
            ['La columna "Código CPV" (fondo gris en cada pestaña) es el identificador único de cada fila en el sistema — no cambiarla ni borrar filas. Si hace falta corregir un código real (como el caso del Grupo 48), anotarlo en la pestaña "Grupo 48 - revisar" o en una columna de comentario, no borrando/re-escribiendo la fila original.'],
            ['', false, 11],
            ['Cómo se relacionan los códigos (para entender las pestañas)', true, 13],
            ['Grupo: 2 dígitos, ej. "05". Familia: código del grupo + un segmento más, ej. "05.01" (siempre dentro del grupo "05"). Categoría: código de la familia + un segmento más, y termina en "G" (bien) o "S" (servicio), ej. "05.01.01G" (siempre dentro de la familia "05.01"). El sistema ya valida esto automáticamente — una categoría no se puede guardar si su código no corresponde a la familia que se le asigna.'],
            ['', false, 11],
            ['El caso del Grupo 48', true, 13],
            ['Al construir esa validación encontramos 12 categorías cuyo código no coincide con su Familia: llevan código "48.01.13G" a "48.01.24G" (Grupo 48, Familia "01"), pero sus nombres (Shoe bottoms, Zippers and sliders, Labels, Boxes, etc.) y su Family Name real ("Accessories") corresponden claramente a la Familia "48.02", no a la "48.01" ("Raw Materials"). Lo más probable es que el código deba ser "48.02.13G" a "48.02.24G" — pedimos confirmación en la pestaña correspondiente antes de corregirlo, para no asumirlo de nuestro lado.'],
            ['', false, 11],
            ['Formato de las columnas de clasificación (ya vienen cargadas, no requieren trabajo — solo referencia)', true, 13],
            ['"Tipo": G = Bien (Goods), S = Servicio (Services). "Belongs": BELONGS / MAYBE / DOES NOT BELONG, ya viene resuelto de la clasificación de Lorenzo — las "MAYBE" y "DOES NOT BELONG" cargan inactivas en el sistema por defecto (no las ve la empresa al armar su perfil) hasta que alguien las revise y active a mano si corresponde.'],
            ['', false, 11],
            ['Cómo devolver el archivo', true, 13],
            ['Se puede ir completando de a poco — no hace falta terminar las 3.483 filas de una sola vez. Empezar por las 2.405 categorías "BELONGS" es lo más útil primero (son las que de verdad va a usar una empresa al cargar su perfil); las "MAYBE"/"DOES NOT BELONG" pueden quedar para después. Devolver el mismo archivo (mismas pestañas, mismas columnas) con lo que se haya completado.'],
        ];

        $row = 1;

        foreach ($lines as $line) {
            [$text, $bold, $size] = array_pad($line, 3, null);
            $bold ??= false;
            $cell = $sheet->setCellValue("A{$row}", $text);
            $style = $sheet->getStyle("A{$row}");
            $style->getFont()->setBold($bold)->setSize($size ?? 11);
            $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

            if ($bold && ($size ?? 11) >= 13) {
                $style->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::HEADER_FILL));
            }

            $sheet->getRowDimension($row)->setRowHeight($bold ? -1 : max(20, intdiv(strlen($text), 2)));
            $row++;
        }
    }

    private function styleHeaderRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $columns, int $row = 1): void
    {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columns);
        $range = "A{$row}:{$lastCol}{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $sheet->freezePane('A'.($row + 1));
    }

    private function markLockedColumn(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $column, int $lastRow): void
    {
        $sheet->getStyle("{$column}2:{$column}{$lastRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::LOCKED_FILL]],
        ]);
    }

    private function markPendingColumn(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $column, int $lastRow): void
    {
        $sheet->getStyle("{$column}2:{$column}{$lastRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::PENDING_FILL]],
        ]);
    }

    private function buildGroupsSheet(Spreadsheet $spreadsheet, $groups): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Grupos');

        $headers = ['Código CPV', 'Nombre (EN)', 'Nombre (ES)'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;

        foreach ($groups as $group) {
            $sheet->setCellValue("A{$row}", $group->code);
            $sheet->setCellValue("B{$row}", $group->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("C{$row}", $group->translations->firstWhere('locale', 'es')?->name);
            $row++;
        }

        $lastRow = $row - 1;
        $this->styleHeaderRow($sheet, count($headers));
        $this->markLockedColumn($sheet, 'A', $lastRow);
        $this->markPendingColumn($sheet, 'C', $lastRow);
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(45);
        $sheet->getColumnDimension('C')->setWidth(45);
        $sheet->setAutoFilter("A1:C{$lastRow}");
    }

    private function buildFamiliesSheet(Spreadsheet $spreadsheet, $families): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Familias');

        $headers = ['Código CPV', 'Grupo (código)', 'Grupo (nombre EN)', 'Nombre (EN)', 'Nombre (ES)'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;

        foreach ($families as $family) {
            $sheet->setCellValue("A{$row}", $family->code);
            $sheet->setCellValue("B{$row}", $family->parent?->code);
            $sheet->setCellValue("C{$row}", $family->parent?->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("D{$row}", $family->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("E{$row}", $family->translations->firstWhere('locale', 'es')?->name);
            $row++;
        }

        $lastRow = $row - 1;
        $this->styleHeaderRow($sheet, count($headers));
        $this->markLockedColumn($sheet, 'A', $lastRow);
        $this->markLockedColumn($sheet, 'B', $lastRow);
        $this->markPendingColumn($sheet, 'E', $lastRow);
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(45);
        $sheet->getColumnDimension('E')->setWidth(45);
        $sheet->setAutoFilter("A1:E{$lastRow}");
    }

    /** @return array<int, string> códigos de categoría marcados para revisión (Grupo 48) */
    private function buildCategoriesSheet(Spreadsheet $spreadsheet, $categories): array
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Categorías');

        $headers = [
            'Código CPV', 'Familia/Grupo padre', 'Nombre (EN)', 'Nombre (ES)',
            'Tipo (G/S)', 'Branch', 'Subbranch', 'Belongs', 'Fundamento de la clasificación', 'Revisar código',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $reviewCodes = [];
        $row = 2;

        foreach ($categories as $category) {
            $needsReview = ! TaxonomyCategory::codeBelongsToParent($category->code, $category->parent?->code ?? '');

            if ($needsReview) {
                $reviewCodes[] = $category->code;
            }

            $sheet->setCellValue("A{$row}", $category->code);
            $sheet->setCellValue("B{$row}", $category->parent?->code);
            $sheet->setCellValue("C{$row}", $category->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("D{$row}", $category->translations->firstWhere('locale', 'es')?->name);
            $sheet->setCellValue("E{$row}", $category->tipo_oferta === TaxonomyCategory::TIPO_BIEN ? 'G' : ($category->tipo_oferta === TaxonomyCategory::TIPO_SERVICIO ? 'S' : null));
            $sheet->setCellValue("F{$row}", $category->branch);
            $sheet->setCellValue("G{$row}", $category->subbranch);
            $sheet->setCellValue("H{$row}", strtoupper(str_replace('_', ' ', $category->chamber_relevance ?? '')));
            $sheet->setCellValue("I{$row}", $category->relevance_basis);
            $sheet->setCellValue("J{$row}", $needsReview ? 'SÍ — ver pestaña "Grupo 48 - revisar"' : '');

            if ($needsReview) {
                $sheet->getStyle("A{$row}:J{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::REVIEW_FILL]],
                ]);
            }

            $row++;
        }

        $lastRow = $row - 1;
        $this->styleHeaderRow($sheet, count($headers));
        $this->markLockedColumn($sheet, 'A', $lastRow);
        $this->markLockedColumn($sheet, 'B', $lastRow);
        $this->markPendingColumn($sheet, 'D', $lastRow);

        // Dropdowns para evitar errores de tipeo si alguien corrige estas columnas a mano.
        $this->addDropdown($sheet, "H2:H{$lastRow}", ['BELONGS', 'MAYBE', 'DOES NOT BELONG']);
        $this->addDropdown($sheet, "E2:E{$lastRow}", ['G', 'S']);

        foreach (['A' => 16, 'B' => 16, 'C' => 42, 'D' => 42, 'E' => 10, 'F' => 22, 'G' => 22, 'H' => 16, 'I' => 40, 'J' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->setAutoFilter("A1:J{$lastRow}");

        return $reviewCodes;
    }

    private function buildSynonymsSheet(Spreadsheet $spreadsheet, $synonyms): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Diccionario (sinónimos)');

        $headers = ['Código CPV', 'Nombre oficial (EN)', 'Término local/coloquial', 'Idioma', 'Origen'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;

        foreach ($synonyms as $synonym) {
            $sheet->setCellValue("A{$row}", $synonym->category?->code);
            $sheet->setCellValue("B{$row}", $synonym->category?->translations->firstWhere('locale', 'en')?->name ?? $synonym->category?->nameIn('en'));
            $sheet->setCellValue("C{$row}", $synonym->term);
            $sheet->setCellValue("D{$row}", $synonym->locale);
            $sheet->setCellValue("E{$row}", $synonym->origen);
            $row++;
        }

        // Filas vacías de ejemplo/plantilla para que Lorenzo agregue las suyas debajo de las
        // reales ya cargadas.
        for ($i = 0; $i < 30; $i++) {
            $row++;
        }

        $lastRow = $row - 1;
        $this->styleHeaderRow($sheet, count($headers));
        $this->markLockedColumn($sheet, 'A', $lastRow);
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(14);
        $this->addDropdown($sheet, "D2:D{$lastRow}", ['es', 'en']);
    }

    private function buildReviewSheet(Spreadsheet $spreadsheet, $categories, array $reviewCodes, array $excelFamilyCodes): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Grupo 48 - revisar');

        $sheet->setCellValue('A1', 'Estas '.count($reviewCodes).' categorías tienen un CÓDIGO que no corresponde a la Familia a la que en realidad pertenecen (el sistema ya las ubicó en la Familia correcta usando la celda "Family Code" del Excel — ver columnas C/D — pero el propio código de la categoría, columna A, quedó con el segmento de Familia equivocado). Ver pestaña "Instrucciones" para el detalle completo. Confirmar en la última columna si corresponde corregir el código (columna E trae la propuesta) o dejarlo como está.');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(75);

        $headers = ['Código actual (con el problema)', 'Nombre (EN)', 'Familia a la que pertenece (código)', 'Nombre de esa Familia (para verificar)', 'Código correcto (propuesto)', '¿Confirmado?'];
        $sheet->fromArray($headers, null, 'A2');

        $byCode = $categories->keyBy('code');
        $row = 3;

        foreach ($reviewCodes as $code) {
            $category = $byCode->get($code);
            $family = $category->parent;
            // $excelFamilyCodes esta keyeado con el codigo TAL CUAL viene en el Excel (sin
            // prefijo CPV-), $code aca ya lo tiene (es el `code` de la base) - hay que sacarselo
            // para que el lookup matchee. El parent_id de la base YA quedo bien resuelto contra la
            // celda "Family Code" del Excel (el import lo arma desde ahi, no desde el Category
            // Code) - lo que esta mal es el propio texto del codigo de la categoria.
            $correctFamilyRaw = $excelFamilyCodes[TaxonomyCategory::stripCodePrefix($code)] ?? TaxonomyCategory::stripCodePrefix($family?->code ?? '');
            $suggestedCode = null;

            if ($correctFamilyRaw !== '') {
                $categorySegments = explode('.', preg_replace('/[GS]$/i', '', TaxonomyCategory::stripCodePrefix($code)));
                $familySegments = explode('.', $correctFamilyRaw);
                $suggestedCode = TaxonomyCategory::CODE_PREFIX.implode('.', array_merge(
                    $familySegments,
                    array_slice($categorySegments, count($familySegments))
                )).substr($code, -1);
            }

            $sheet->setCellValue("A{$row}", $code);
            $sheet->setCellValue("B{$row}", $category->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("C{$row}", $family?->code);
            $sheet->setCellValue("D{$row}", $family?->translations->firstWhere('locale', 'en')?->name);
            $sheet->setCellValue("E{$row}", $suggestedCode);
            $sheet->setCellValue("F{$row}", '');
            $row++;
        }

        $lastRow = $row - 1;
        $this->styleHeaderRow($sheet, count($headers), 2);
        $sheet->getStyle("A3:C{$lastRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::LOCKED_FILL]],
        ]);
        $this->markPendingColumn($sheet, 'D', $lastRow);
        $this->markPendingColumn($sheet, 'E', $lastRow);
        $this->markPendingColumn($sheet, 'F', $lastRow);
        $this->addDropdown($sheet, "F3:F{$lastRow}", ['Sí, corregir código', 'No, dejar como está']);

        foreach (['A' => 16, 'B' => 42, 'C' => 20, 'D' => 26, 'E' => 24, 'F' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * OJO de rendimiento: `getCell($ref)->getDataValidation()` CREA una instancia nueva por celda -
     * con 3.103 filas eso son miles de objetos y el .xlsx tarda mucho / pesa de más. Se arma UNA
     * sola instancia y se reusa la MISMA referencia en cada celda (patrón documentado de
     * PhpSpreadsheet para validar un rango completo), no una copia por celda.
     */
    /**
     * Relee el Excel original de Lorenzo (mismo archivo que usa `taxonomy:import`) solo para armar
     * "Código de Categoría" -> "Family Code" tal cual venía en esa celda — es la ÚNICA fuente
     * confiable de "cuál era la familia correcta" para las filas de la pestaña "Grupo 48 -
     * revisar" (la base ya tiene el parent_id apuntando a la familia INCORRECTA para esas 12, así
     * que no sirve como fuente ahí). Devuelve un array vacío sin fallar si el archivo no está (no
     * bloquea la generación del resto del template).
     *
     * @return array<string, string> "48.01.13G" => "48.02" (sin prefijo CPV-, tal cual el Excel)
     */
    private function readExcelFamilyCodes(): array
    {
        $path = base_path('docs/taxonomia/CLASSIFIED_MASTER.xlsx');

        if (! is_file($path)) {
            return [];
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly('Database');
        }

        $sheet = $reader->load($path)->getSheetByName('Database');

        if (! $sheet) {
            return [];
        }

        $raw = $sheet->rangeToArray('A1:K'.$sheet->getHighestRow(), null, true, false);
        $header = null;

        while ($raw) {
            $candidate = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_shift($raw));

            if (in_array('Group Code', $candidate, true)) {
                $header = $candidate;

                break;
            }
        }

        if ($header === null) {
            return [];
        }

        $map = [];

        foreach ($raw as $row) {
            $r = array_combine($header, $row);
            $categoryCode = trim((string) ($r['Category Code'] ?? ''));
            $familyCode = trim((string) ($r['Family Code'] ?? ''));

            if ($categoryCode !== '' && $familyCode !== '') {
                $map[$categoryCode] = $familyCode;
            }
        }

        return $map;
    }

    private function addDropdown(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range, array $options): void
    {
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setFormula1('"'.implode(',', $options).'"');

        foreach (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::extractAllCellReferencesInRange($range) as $cellRef) {
            $sheet->getCell($cellRef)->setDataValidation(clone $validation);
        }
    }
}
