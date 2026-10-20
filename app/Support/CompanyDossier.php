<?php

namespace App\Support;

use App\Filament\Pages\JoinViews;
use App\Models\Country;
use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\Experience;
use App\Models\Management;
use App\Models\Presence;
use App\Models\Sector;
use Illuminate\Support\Facades\DB;

/**
 * CPV-COMPLETE-COMPANY-PDF (Issue #2 `6270558322`): el «perfil completo» de UNA empresa en PDF (`resources/views/pdf.blade.php`).
 *
 * Es el mismo reporte de siempre, con sus secciones y su diseño. Esta clase:
 * - junta sus datos una sola vez;
 * - pone valores vacíos seguros, para que un perfil nuevo o incompleto no dé un 500;
 * - valida la selección opcional de secciones.
 *
 * Las secciones que ya salían de las vistas de reporte (`capacityView`, `ClientsView`, `ResourceView`, …) siguen saliendo
 * de ellas, mediante JoinViews. Se leen de los modelos, en cambio:
 * - **Contactos:** el texto de la vista ocultaba la lista cuando había UN solo contacto.
 * - **Gestión:** booleanos y nombres de «Otros» sin el artefacto `["…"]` de la vista.
 * - **Presencia internacional:** la vista empareja oficinas y experiencias por posición. Sin oficinas, la experiencia
 *   internacional desaparecía del reporte.
 * - **Experiencia relevante:** `ExperienceView` no lee las entradas con clave UUID que guarda el formulario
 *   (ver experiences()).
 * - **Categorías CPV:** sección nueva del perfil, que el reporte no tenía.
 *
 * No hay puntajes, calificaciones ni datos externos: todo es lo que la empresa registró en el sistema.
 */
final class CompanyDossier
{
    /** Secciones del perfil completo, en el orden del reporte. Las claves son la lista blanca del parámetro `secciones`. */
    public const SECTIONS = [
        'generales' => 'Datos generales y contactos',
        'operacion' => 'Operación en Venezuela',
        'clientes' => 'Clientes',
        'camaras' => 'Cámaras',
        'sectores' => 'Sectores de actividad económica y servicios',
        'categorias' => 'Categorías CPV',
        'sostenibilidad' => 'Enfoque de sostenibilidad',
        'recursos' => 'Recursos en Venezuela',
        'experiencia' => 'Experiencia relevante',
        'presencia' => 'Presencia internacional',
        'gestion' => 'Sistemas de gestión',
    ];

    /**
     * Qué trae cada sección, para el selector (EmpresaResource::profileSectionsAction) y el manual (#descargar-perfil).
     * CPV-PDF-OPERATION-SECTION (`6286644396`): «Operación en Venezuela» es una sección propia, y las secciones que agrupan
     * varios bloques (Recursos, Presencia, Gestión) los nombran.
     */
    public const SECTION_DESCRIPTIONS = [
        'generales' => 'Nombre, RIF, dirección, ciudad, web, teléfono, redes sociales, fundación, capital y origen, sectores principal y secundario; contactos.',
        'operacion' => 'Facturación anual, empleados (rango declarado y total en Recursos humanos) y estatus actual.',
        'clientes' => 'Nombre y país de cada cliente.',
        'camaras' => 'Cámaras en las que participa la empresa.',
        'sectores' => 'Sectores de actividad económica y los servicios de cada uno.',
        'categorias' => 'Categorías CPV declaradas o confirmadas, la principal y su origen.',
        'sostenibilidad' => 'Áreas de sostenibilidad y su estado.',
        'recursos' => 'Cuatro bloques: recursos humanos, maquinarias y equipos, instalaciones e inventario.',
        'experiencia' => 'Trabajos relevantes: año, clasificación de la infraestructura, monto del contrato, esfuerzo H-H, sector y servicios, descripción.',
        'presencia' => 'Dos bloques: oficinas en otros países y proyectos en otros países. No incluye la Experiencia relevante.',
        'gestion' => 'Seis bloques: calidad, ambiente, credibilidad y transparencia, seguridad (con OVID), gestión de proyectos y seguridad de la información, cada uno con «Otros».',
    ];

    /**
     * Un parámetro de más de esto no es una selección legítima: se ignora y sale el perfil completo. Sale de las claves
     * (tres veces la lista completa, por espacios o repeticiones), no de un número fijo de secciones.
     */
    private static function maxParamLength(): int
    {
        return 3 * strlen(implode(',', array_keys(self::SECTIONS)));
    }

    private const ROLES = ['1' => 'Subcontratista', '2' => 'Contratista principal', '3' => 'Ambos'];

    private const MONTOS = ['1' => '< 100.000 USD', '2' => '100.001 - 1.000.000 USD', '3' => '1.000.001 - 10.000.000 USD', '4' => '> 10.000.001 USD'];

    private const EMPLEADOS = ['1' => '< 50', '2' => '51 - 100', '3' => '101 - 500', '4' => '> 500'];

    /**
     * La selección pedida, validada contra SECTIONS y en el orden del reporte.
     *
     * Acepta `secciones=generales,recursos` o `secciones[]=generales&secciones[]=recursos`.
     * Las claves desconocidas se IGNORAN. Si no queda ninguna válida (o no se pidió nada, o el valor es demasiado largo o de
     * otro tipo), el resultado son TODAS las secciones: el perfil completo es siempre el valor por defecto. Este parámetro
     * elige solo secciones: no puede cambiar la empresa ni la autorización, que se resuelven antes.
     *
     * @return list<string>
     */
    public static function sectionsFrom(mixed $raw): array
    {
        $requested = match (true) {
            is_string($raw) && strlen($raw) <= self::maxParamLength() => explode(',', $raw),
            is_array($raw) && count($raw) <= count(self::SECTIONS) => array_filter($raw, 'is_string'),
            default => [],
        };
        $requested = array_map(fn (string $key) => strtolower(trim($key)), $requested);
        $valid = array_values(array_filter(array_keys(self::SECTIONS), fn (string $key) => in_array($key, $requested, true)));

        return $valid === [] ? array_keys(self::SECTIONS) : $valid;
    }

    /** @param list<string> $sections */
    public static function isComplete(array $sections): bool
    {
        return count($sections) === count(self::SECTIONS);
    }

    /**
     * Todo lo que necesita la vista `pdf`. Solo se llama DESPUÉS de autorizar (EmpresaExportController).
     *
     * @param  list<string>  $sections
     */
    public static function data(Empresa $empresa, array $sections): array
    {
        $id = (int) $empresa->getKey();
        $want = fn (string $section) => in_array($section, $sections, true);

        // Una sola fila de datos generales (la vista tiene una fila por cliente). Si no hay ninguna, todos los campos en null.
        // La misma fila trae los campos de Operación y los clientes; cada sección imprime solo los suyos (pdf.blade.php).
        $datos = $want('generales') || $want('operacion') || $want('clientes') ? JoinViews::index($id)->data : collect();
        $g = (object) array_merge(array_fill_keys(self::GENERAL_FIELDS, null), (array) ($datos->first()?->getAttributes() ?? []));

        // JoinViews devuelve objetos View. Se sacan sus datos: un View dentro de los datos de otra vista se RENDERIZA al
        // armarla (View::gatherData), y join-views.blade.php no se puede renderizar fuera de su página.
        $recursos = null;
        if ($want('recursos')) {
            $view = JoinViews::index_recursos($id);
            $recursos = (object) collect(['value_emp', 'value_maq', 'value_fac', 'value_inv', 'data_rec', 'data_maq', 'data_ins', 'data_inv'])
                ->mapWithKeys(fn (string $key) => [$key => $view->getData()[$key] ?? []])->all();
        }
        $statuses = EmpresaModuleStatus::where('empresa_id', $id)->get();

        return [
            'record' => $empresa,
            'sections' => $sections,
            'complete' => self::isComplete($sections),
            'omitted' => array_values(array_diff_key(self::SECTIONS, array_flip($sections))),
            'generatedAt' => now()->timezone('America/Caracas'),
            'g' => $g,
            'contactos' => $want('generales') ? $empresa->contacts()->orderByDesc('contact_empresa.is_principal')->orderBy('contacts.name')->get() : collect(),
            'redes' => array_filter([
                'LinkedIn' => $empresa->linkedin_profile,
                'Twitter / X' => $empresa->twitter_profile,
                'Instagram' => $empresa->instagram_profile,
                'Facebook' => $empresa->facebook_profile,
                'YouTube' => $empresa->youtube_profile,
                'Otros' => $empresa->otros_profile,
            ], 'filled'),
            'sectorPrincipal' => $empresa->sector_principal_id ? Sector::find($empresa->sector_principal_id)?->name : null,
            'sectorSecundario' => $empresa->sector_secundario_id ? Sector::find($empresa->sector_secundario_id)?->name : null,
            'empleadosRango' => self::EMPLEADOS[(string) $empresa->employees_id] ?? null,
            'clientes' => $datos->filter(fn ($row) => filled($row->clientes))
                ->map(fn ($row) => ['nombre' => $row->clientes, 'pais' => $row->cli_pais])
                ->unique(fn ($c) => $c['nombre'].'|'.$c['pais'])->values(),
            'camaras' => $want('camaras') ? JoinViews::index_chambers($id)->data_chambers : collect(),
            'secSer' => $want('sectores') ? JoinViews::index_sec_ser($id)->data_sec_ser : collect(),
            'categorias' => $want('categorias') ? self::categories($id) : collect(),
            'sust' => $want('sostenibilidad') ? JoinViews::index_sust($id)->data_sust : collect(),
            'rec' => $recursos,
            'exp' => $want('experiencia') ? self::experiences($id) : collect(),
            'presencia' => $want('presencia') ? self::presence($id) : null,
            'gestion' => $want('gestion') ? self::management($id) : null,
            'na' => EmpresaModuleStatus::flagsFromCollection($statuses),
            'naRecursos' => EmpresaModuleStatus::subTypeFlagsFromCollection($statuses, EmpresaModuleStatus::MODULE_RECURSOS),
            'naGestion' => EmpresaModuleStatus::subTypeFlagsFromCollection($statuses, EmpresaModuleStatus::MODULE_GESTION),
            'naLabel' => EmpresaModuleStatus::NO_APLICA_LABEL_LARGO,
        ];
    }

    /** Campos de `JoinViews::index()` que usan las secciones de datos generales, operación y clientes. */
    private const GENERAL_FIELDS = [
        'nombre', 'rif', 'direccion', 'ciudad', 'website', 'telefono', 'contactos', 'fundacion', 'capital', 'origen',
        'facturacion_anual', 'estado_actual', 'empleados', 'clientes', 'cli_pais',
    ];

    /** Categorías declaradas o confirmadas. Las sugerencias sin confirmar no son datos de la empresa: no se incluyen. */
    private static function categories(int $id)
    {
        return EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $id)
            ->whereIn('origen', [EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, EmpresaTaxonomyCategory::ORIGEN_VALIDATED])
            ->with('category.translations')
            ->orderByDesc('es_principal')
            ->orderBy('id')
            ->get()
            ->map(fn (EmpresaTaxonomyCategory $link) => [
                'nombre' => $link->category?->breadcrumb('es') ?? '—',
                'principal' => (bool) $link->es_principal,
                'origen' => $link->origen === EmpresaTaxonomyCategory::ORIGEN_VALIDATED ? 'Confirmada' : 'Declarada por la empresa',
            ]);
    }

    /**
     * Experiencia relevante, leída del modelo. El formulario (Builder, y hoy ExperiencesRelationManager) guarda `exp_year`
     * con claves UUID: `{"<uuid>": {"type": …, "data": {…}}}`, un OBJETO JSON. `ExperienceView` solo recorre arreglos, y un
     * objeto lo envuelve como UNA fila con todos los campos en null. Por eso esas experiencias salían vacías en el reporte.
     * Aquí se aceptan las dos formas, y las etiquetas salen de los mismos catálogos que usa la vista.
     */
    private static function experiences(int $id)
    {
        $entries = Experience::where('empresa_id', $id)->orderBy('id')->get()
            ->flatMap(fn (Experience $e) => array_values(is_array($e->exp_year) ? $e->exp_year : []))
            ->map(fn ($entry) => is_array($entry) ? ($entry['data'] ?? $entry) : null)
            ->filter(fn ($data) => is_array($data) && $data !== [])
            ->values();

        $names = function (string $table, string $column, string $key) use ($entries) {
            $ids = $entries->pluck($key)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();

            return $ids->isEmpty() ? collect() : DB::table($table)->whereIn('id', $ids->all())->pluck($column, 'id');
        };
        $lookups = [
            'infrasectors_id' => $names('infrasectors', 'sector_name', 'infrasectors_id'),
            'infratypes_id' => $names('infratypes', 'type_name', 'infratypes_id'),
            'infrasystems_id' => $names('infrasystems', 'system_name', 'infrasystems_id'),
            'infraregions_id' => $names('infraregions', 'region_name', 'infraregions_id'),
            'infrafacilities_id' => $names('infrafacilities', 'facility_name', 'infrafacilities_id'),
            'sectors_id' => $names('sectors', 'name', 'sectors_id'),
        ];
        $serviceIds = $entries->flatMap(fn ($d) => is_array($d['services_id'] ?? null) ? $d['services_id'] : [])->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique();
        $services = $serviceIds->isEmpty() ? collect() : DB::table('services')->whereIn('id', $serviceIds->all())->orderBy('id')->pluck('name', 'id');
        $label = fn (array $d, string $key) => is_numeric($d[$key] ?? null) ? ($lookups[$key][(int) $d[$key]] ?? '') : '';
        $text = fn ($value) => is_scalar($value) && (string) $value !== 'null' ? trim((string) $value) : '';

        return $entries->map(fn (array $d) => (object) [
            'ano' => $text($d['exp_year'] ?? null),
            'sectorind' => $label($d, 'infrasectors_id'),
            'tipoind' => $label($d, 'infratypes_id'),
            'systemind' => $label($d, 'infrasystems_id'),
            'regionind' => $label($d, 'infraregions_id'),
            'facilityind' => $label($d, 'infrafacilities_id'),
            'magnitud' => self::MONTOS[(string) ($d['magnitud'] ?? '')] ?? '',
            'prof_tech' => $text($d['prof_tech'] ?? null),
            'manpower' => $text($d['manpower'] ?? null),
            'exp_sector' => $label($d, 'sectors_id'),
            'exp_service' => collect(is_array($d['services_id'] ?? null) ? $d['services_id'] : [])
                ->map(fn ($s) => $services[(int) $s] ?? null)->filter()->unique()->implode(', '),
            'descripcion' => $text($d['Descripcion'] ?? null),
        ])->sortBy('ano')->values();
    }

    /** @return array{registrada: bool, oficinas: list<array>, experiencias: list<array>, has_offices: ?bool, has_experience: ?bool} */
    private static function presence(int $id): array
    {
        $presence = Presence::where('empresa_id', $id)->orderBy('id')->first();
        $offices = $presence?->has_offices ? array_values((array) $presence->office_data) : [];
        $experiences = $presence?->has_experience ? array_values((array) $presence->experience_data) : [];
        $countryIds = collect($offices)->pluck('country_id')->merge(collect($experiences)->pluck('expcountry_id'))->filter()->unique();
        $countries = $countryIds->isEmpty() ? collect() : Country::whereIn('id', $countryIds->all())->pluck('country_name', 'id');
        $text = fn ($value) => blank($value) || $value === 'null' ? '' : (string) $value;

        return [
            'registrada' => $presence !== null,
            'has_offices' => $presence?->has_offices,
            'has_experience' => $presence?->has_experience,
            'oficinas' => array_map(fn ($o) => [
                'pais' => $countries[$o['country_id'] ?? null] ?? '',
                'mts' => $text($o['offices_surf'] ?? null),
                'empleados' => $text($o['employees_q'] ?? null),
                'activa' => filter_var($o['status'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No',
            ], array_filter($offices, 'is_array')),
            'experiencias' => array_map(fn ($e) => [
                'pais' => $countries[$e['expcountry_id'] ?? null] ?? '',
                'proyectos' => $text($e['projects_q'] ?? null),
                'rol' => self::ROLES[(string) ($e['role'] ?? '')] ?? '',
                'monto' => self::MONTOS[(string) ($e['executed_q'] ?? '')] ?? '',
                'empleados' => $text($e['expemployees_q'] ?? null),
                'clientes' => $text($e['main_clients'] ?? null),
            ], array_filter($experiences, 'is_array')),
        ];
    }

    /**
     * Sistemas de gestión: cada certificación como booleano, y los nombres de «Otros» por sección (solo si «Otros» está
     * marcado). Las etiquetas las pone la vista. `null` si la empresa no registró nada.
     *
     * @return ?array{flags: array<string, bool>, otros: array<string, list<string>>}
     */
    private static function management(int $id): ?array
    {
        $m = Management::where('empresa_id', $id)->orderBy('id')->first();
        if (! $m) {
            return null;
        }
        $otros = fn (string $flag, string $data, string $field) => $m->{$flag} ? collect($m->{$data})->pluck($field)->filter()->values()->all() : [];

        return [
            'flags' => collect(['iso9001', 'iso17025', 'iso14001', 'iso50001', 'dun', 'iso37001', 'iso45001', 'ovid', 'pmi', 'iso27001'])
                ->mapWithKeys(fn (string $field) => [$field => (bool) $m->{$field}])->all(),
            'otros' => [
                'calidad' => $otros('quality_otros', 'quality_data', 'quality_otros_name'),
                'ambiente' => $otros('environment_otros', 'environment_data', 'environment_otros_name'),
                'credibilidad' => $otros('credibility_otros', 'credibility_data', 'credibility_otros_name'),
                'seguridad' => $otros('security_otros', 'security_data', 'security_otros_name'),
                'proyectos' => $otros('pmi_otros', 'pmi_data', 'pmi_otros_name'),
                'seguridad_info' => $otros('info_otros', 'info_data', 'info_otros_name'),
            ],
        ];
    }
}
