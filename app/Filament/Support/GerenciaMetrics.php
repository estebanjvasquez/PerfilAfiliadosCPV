<?php

namespace App\Filament\Support;

use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use App\Models\Experience;
use App\Models\Management;
use App\Models\Presence;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fuente unica de agregados para el Tablero de Metricas Gerenciales
 * (app/Filament/Pages/GerenciaDashboard.php y sus widgets). Todo metodo
 * respeta el filtro global status_id = 1 (empresas activas) salvo que
 * include_inactive venga explicitamente en $filters.
 */
class GerenciaMetrics
{
    public static function filtersFromRequest(): array
    {
        return [
            'sector_id' => request()->query('sector_id') ?: null,
            'chamber_id' => request()->query('chamber_id') ?: null,
            'state_id' => request()->query('state_id') ?: null,
            'include_inactive' => request()->boolean('include_inactive'),
        ];
    }

    public static function baseQuery(array $filters): Builder
    {
        $query = Empresa::query();

        if (empty($filters['include_inactive'])) {
            $query->where('status_id', 1);
        }

        if ($sectorId = $filters['sector_id'] ?? null) {
            $query->where(function (Builder $q) use ($sectorId) {
                $q->where('sector_principal_id', $sectorId)
                    ->orWhere('sector_secundario_id', $sectorId);
            });
        }

        if ($chamberId = $filters['chamber_id'] ?? null) {
            $query->whereHas('chambers', fn (Builder $q) => $q->where('chambers.id', $chamberId));
        }

        if ($stateId = $filters['state_id'] ?? null) {
            $query->whereHas('city', fn (Builder $q) => $q->where('states_id', $stateId));
        }

        return $query;
    }

    /**
     * Tarjetas KPI del resumen: 1.1, 1.5, 4.2, 6.2 + total de empresas activas.
     */
    public static function resumen(array $filters): array
    {
        $empresas = static::baseQuery($filters)->get();
        $total = $empresas->count();

        $completitudPromedio = $total > 0
            ? (int) round($empresas->avg(fn (Empresa $e) => $e->completionPercentage()))
            : 0;

        $frescos = $empresas->filter(
            fn (Empresa $e) => $e->updated_at && $e->updated_at->gt(now()->subMonths(12))
        )->count();
        $frescura = $total > 0 ? (int) round(100 * $frescos / $total) : 0;

        $sedes = $empresas->filter(function (Empresa $e) {
            $asset = $e->assets()->first();

            return ! empty($asset?->facility);
        })->count();

        $proyectos = $total > 0
            ? Experience::whereIn('empresa_id', $empresas->pluck('id'))->count()
            : 0;

        return [
            'total_empresas' => $total,
            'completitud_promedio' => $completitudPromedio,
            'frescura_dato' => $frescura,
            'sedes' => $sedes,
            'proyectos' => $proyectos,
        ];
    }

    /**
     * 1.2 Segmentacion por Calidad de Perfil.
     */
    public static function calidadPerfil(array $filters): array
    {
        $buckets = ['< 50%' => 0, '50% - 89%' => 0, '90% - 100%' => 0];

        foreach (static::baseQuery($filters)->get() as $empresa) {
            $percentage = $empresa->completionPercentage();

            if ($percentage < 50) {
                $buckets['< 50%']++;
            } elseif ($percentage < 90) {
                $buckets['50% - 89%']++;
            } else {
                $buckets['90% - 100%']++;
            }
        }

        return $buckets;
    }

    /**
     * 1.3 Tasa de "No Aplica" por modulo, separando NA de modulo completo
     * (sub_type = SUB_TYPE_WHOLE) de NA parcial (al menos un sub-tipo marcado
     * sin que el modulo completo lo este) — evita el doble conteo detectado
     * en la revision de implementation_plan.md.
     */
    public static function noAplicaPorModulo(array $filters): array
    {
        $empresaIds = static::baseQuery($filters)->pluck('id');

        $rows = DB::table('empresa_module_status')
            ->whereIn('empresa_id', $empresaIds)
            ->where('no_aplica', true)
            ->get(['empresa_id', 'module', 'sub_type']);

        $labels = [];
        $completo = [];
        $parcial = [];

        foreach (EmpresaModuleStatus::MODULES as $module => $label) {
            $labels[] = $label;

            $completo[] = $rows->where('module', $module)
                ->where('sub_type', EmpresaModuleStatus::SUB_TYPE_WHOLE)
                ->pluck('empresa_id')->unique()->count();

            $parcial[] = $rows->where('module', $module)
                ->where('sub_type', '!=', EmpresaModuleStatus::SUB_TYPE_WHOLE)
                ->pluck('empresa_id')->unique()->count();
        }

        return ['labels' => $labels, 'completo' => $completo, 'parcial' => $parcial];
    }

    /**
     * 2.1 Top sectores de afiliados (principal + secundario combinados).
     */
    public static function topSectores(array $filters, int $limit = 10): array
    {
        $query = static::baseQuery($filters);

        $principal = (clone $query)->whereNotNull('sector_principal_id')
            ->select('sector_principal_id as sector_id', DB::raw('count(*) as total'))
            ->groupBy('sector_principal_id')
            ->pluck('total', 'sector_id');

        $secundario = (clone $query)->whereNotNull('sector_secundario_id')
            ->select('sector_secundario_id as sector_id', DB::raw('count(*) as total'))
            ->groupBy('sector_secundario_id')
            ->pluck('total', 'sector_id');

        $combined = [];
        foreach ([$principal, $secundario] as $counts) {
            foreach ($counts as $sectorId => $count) {
                $combined[$sectorId] = ($combined[$sectorId] ?? 0) + $count;
            }
        }

        arsort($combined);
        $top = array_slice($combined, 0, $limit, true);
        $names = Sector::whereIn('id', array_keys($top))->pluck('name', 'id');

        return [
            'labels' => collect($top)->keys()->map(fn ($id) => $names[$id] ?? "#{$id}")->all(),
            'values' => array_values($top),
        ];
    }

    /**
     * 2.2 Cobertura de servicios tecnicos (top N por cantidad de empresas).
     */
    public static function coberturaServicios(array $filters, int $limit = 10): array
    {
        $empresaIds = static::baseQuery($filters)->pluck('id');

        $rows = DB::table('empresa_sector_service')
            ->join('services', 'services.id', '=', 'empresa_sector_service.service_id')
            ->whereIn('empresa_sector_service.empresa_id', $empresaIds)
            ->select('services.name', DB::raw('count(distinct empresa_sector_service.empresa_id) as total'))
            ->groupBy('services.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return ['labels' => $rows->pluck('name')->all(), 'values' => $rows->pluck('total')->all()];
    }

    /**
     * 2.3 Indice de diversificacion: 1 sector vs 2 sectores permitidos.
     */
    public static function diversificacionSectorial(array $filters): array
    {
        $uno = 0;
        $dos = 0;

        foreach (static::baseQuery($filters)->get() as $empresa) {
            $count = count($empresa->allowedSectorIds());

            if ($count >= 2) {
                $dos++;
            } elseif ($count === 1) {
                $uno++;
            }
        }

        return ['1 Sector' => $uno, '2 Sectores' => $dos];
    }

    private const EMPLOYEES_LABELS = [
        '1' => '< 50',
        '2' => '51 - 100',
        '3' => '101 - 500',
        '4' => '> 500',
    ];

    private const BILLING_LABELS = [
        '1' => '< 100.000 USD',
        '2' => '100.001 - 1.000.000 USD',
        '3' => '1.000.001 - 10.000.000 USD',
        '4' => '> 10.000.000 USD',
    ];

    /**
     * 3.1 Generacion de empleo directo, por rango de empleados (mismas
     * opciones hardcodeadas en EmpresaResource::form()).
     */
    public static function empleoPorRango(array $filters): array
    {
        return static::countByOptionColumn($filters, 'employees_id', self::EMPLOYEES_LABELS);
    }

    /**
     * 3.2 Estratificacion financiera, por rango de facturacion.
     */
    public static function facturacionPorRango(array $filters): array
    {
        return static::countByOptionColumn($filters, 'billing_id', self::BILLING_LABELS);
    }

    private static function countByOptionColumn(array $filters, string $column, array $labels): array
    {
        $counts = static::baseQuery($filters)
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->pluck('total', $column);

        return [
            'labels' => array_values($labels),
            'values' => collect($labels)->keys()->map(fn ($key) => (int) ($counts[$key] ?? 0))->all(),
        ];
    }

    /**
     * 3.3 Composicion de capital: origen (nacional/internacional) y
     * propiedad (privado/publico).
     */
    public static function composicionCapital(array $filters): array
    {
        $query = static::baseQuery($filters);

        return [
            'origen' => [
                'Nacional' => (clone $query)->where('origin_id', 1)->count(),
                'Internacional' => (clone $query)->where('origin_id', 0)->count(),
            ],
            'propiedad' => [
                'Privado' => (clone $query)->where('property_id', 1)->count(),
                'Público' => (clone $query)->where('property_id', 0)->count(),
            ],
        ];
    }

    /**
     * 4.1 Cobertura de recursos industriales: % de empresas con datos
     * cargados en cada tipo de Asset (independiente de "No Aplica").
     */
    public static function coberturaRecursos(array $filters): array
    {
        $empresas = static::baseQuery($filters)->get();
        $total = $empresas->count();

        $subTypes = EmpresaModuleStatus::SUB_TYPES[EmpresaModuleStatus::MODULE_RECURSOS];
        $counts = array_fill_keys(array_keys($subTypes), 0);

        foreach ($empresas as $empresa) {
            $asset = $empresa->assets()->first();

            foreach ($counts as $key => $count) {
                if (! empty($asset?->{$key})) {
                    $counts[$key]++;
                }
            }
        }

        return [
            'labels' => array_values($subTypes),
            'values' => array_map(
                fn ($count) => $total > 0 ? (int) round(100 * $count / $total) : 0,
                array_values($counts)
            ),
        ];
    }

    private const CERTIFICATION_LABELS = [
        'iso9001' => 'ISO 9001',
        'iso14001' => 'ISO 14001',
        'iso45001' => 'ISO 45001',
        'iso27001' => 'ISO 27001',
        'pmi' => 'PMI',
        'ovid' => 'OVID',
        'dun' => 'DUN',
    ];

    /**
     * 5.1 + 5.2 fusionadas: % de adopcion de cada certificacion/estandar.
     */
    public static function certificaciones(array $filters): array
    {
        $empresaIds = static::baseQuery($filters)->pluck('id');
        $total = $empresaIds->count();
        $managements = Management::whereIn('empresa_id', $empresaIds)->get();

        $result = [];
        foreach (self::CERTIFICATION_LABELS as $field => $label) {
            $count = $managements->filter(fn (Management $m) => (bool) $m->{$field})->count();
            $result[$label] = $total > 0 ? (int) round(100 * $count / $total) : 0;
        }

        arsort($result);

        return $result;
    }

    /**
     * 6.1 Alcance internacional: empresas con presencia registrada
     * (oficinas o experiencia internacional) vs sin ella.
     */
    public static function alcanceInternacional(array $filters): array
    {
        $empresaIds = static::baseQuery($filters)->pluck('id');
        $total = $empresaIds->count();

        $conPresencia = Presence::whereIn('empresa_id', $empresaIds)
            ->where(fn ($q) => $q->where('has_offices', true)->orWhere('has_experience', true))
            ->count();

        return [
            'Con Presencia Internacional' => $conPresencia,
            'Sin Presencia Internacional' => max(0, $total - $conPresencia),
        ];
    }

    /**
     * 7.1 Crecimiento de afiliacion: altas por mes, ultimos $months meses.
     */
    public static function crecimientoAfiliacion(array $filters, int $months = 12): array
    {
        $desde = now()->startOfMonth()->subMonths($months - 1);

        $rows = static::baseQuery($filters)
            ->where('created_at', '>=', $desde)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as ym"), DB::raw('count(*) as total'))
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $labels = [];
        $values = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $period = now()->subMonths($i);
            $key = $period->format('Y-m');
            $labels[] = $period->translatedFormat('M Y');
            $values[] = (int) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * 7.2 Distribucion geografica por estado (top N).
     */
    public static function distribucionGeografica(array $filters, int $limit = 10): array
    {
        $rows = static::baseQuery($filters)
            ->join('cities', 'cities.id', '=', 'empresas.city_id')
            ->join('states', 'states.id', '=', 'cities.states_id')
            ->select('states.state_name', DB::raw('count(*) as total'))
            ->groupBy('states.state_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return ['labels' => $rows->pluck('state_name')->all(), 'values' => $rows->pluck('total')->all()];
    }

    /**
     * 7.3 Distribucion por camara/capitulo regional.
     */
    public static function distribucionCamaras(array $filters): array
    {
        $empresaIds = static::baseQuery($filters)->pluck('id');

        $rows = DB::table('chamber_empresa')
            ->join('chambers', 'chambers.id', '=', 'chamber_empresa.chamber_id')
            ->whereIn('chamber_empresa.empresa_id', $empresaIds)
            ->select('chambers.name', DB::raw('count(distinct chamber_empresa.empresa_id) as total'))
            ->groupBy('chambers.name')
            ->orderByDesc('total')
            ->get();

        return ['labels' => $rows->pluck('name')->all(), 'values' => $rows->pluck('total')->all()];
    }
}
