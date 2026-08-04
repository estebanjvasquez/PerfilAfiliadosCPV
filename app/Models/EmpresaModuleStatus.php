<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EmpresaModuleStatus extends Model
{
    use HasFactory;

    protected $table = 'empresa_module_status';

    public const MODULE_RECURSOS = 'recursos';
    public const MODULE_GESTION = 'gestion';
    public const MODULE_PRESENCIA = 'presencia';
    public const MODULE_EXPERIENCIAS = 'experiencias';
    public const MODULE_SOSTENIBILIDAD = 'sostenibilidad';

    public const MODULES = [
        self::MODULE_RECURSOS => 'Recursos en Venezuela',
        self::MODULE_GESTION => 'Sistemas de gestión',
        self::MODULE_PRESENCIA => 'Presencia internacional',
        self::MODULE_EXPERIENCIAS => 'Experiencia relevante',
        self::MODULE_SOSTENIBILIDAD => 'Enfoque de sostenibilidad',
    ];

    /**
     * Textos de referencia para reportes cuando el afiliado marcó "No Aplica" (módulo completo
     * o sub-tipo individual). El largo es para pantalla/Pdf; el corto es para Excel, pensado
     * para poder filtrar/ordenar por él en una hoja de cálculo.
     */
    public const NO_APLICA_LABEL_LARGO = 'Marcado no aplica por el afiliado';

    public const NO_APLICA_LABEL_CORTO = 'NA/afiliado';

    /**
     * Sentinel de sub_type que representa "módulo completo" (comportamiento histórico,
     * previo a la granularidad por tipo). No confundir con null: se usa '' porque MySQL
     * no garantiza unicidad entre múltiples NULL en una unique key compuesta.
     */
    public const SUB_TYPE_WHOLE = '';

    /**
     * Sub-tipos marcables individualmente como "No Aplica" dentro de cada módulo.
     */
    public const SUB_TYPES = [
        self::MODULE_RECURSOS => [
            'employee' => 'Recursos humanos',
            'machinery' => 'Maquinaria y equipos',
            'facility' => 'Instalaciones',
            'inventory' => 'Inventario',
        ],
        self::MODULE_GESTION => [
            'calidad' => 'Calidad',
            'ambiente' => 'Ambiente',
            'credibilidad' => 'Credibilidad y transparencia',
            'seguridad' => 'Seguridad',
            'proyectos' => 'Gestión de proyectos',
            'seguridad_info' => 'Seguridad de la información',
        ],
    ];

    protected $fillable = [
        'empresa_id',
        'module',
        'sub_type',
        'no_aplica',
    ];

    protected $casts = [
        'no_aplica' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public static function setStatus(int $empresaId, string $module, bool $noAplica, string $subType = self::SUB_TYPE_WHOLE): void
    {
        if ($noAplica) {
            static::updateOrCreate(
                ['empresa_id' => $empresaId, 'module' => $module, 'sub_type' => $subType],
                ['no_aplica' => true]
            );
        } else {
            static::where('empresa_id', $empresaId)->where('module', $module)->where('sub_type', $subType)->delete();
        }
    }

    public static function isNoAplica(int $empresaId, string $module, string $subType = self::SUB_TYPE_WHOLE): bool
    {
        return static::where('empresa_id', $empresaId)
            ->where('module', $module)
            ->where('sub_type', $subType)
            ->where('no_aplica', true)
            ->exists();
    }

    /**
     * Flags de "No Aplica" de todos los módulos (nivel módulo completo, no por sub-tipo)
     * para una empresa, con todos los módulos presentes (false por defecto).
     */
    public static function flagsFor(int $empresaId): array
    {
        $flags = array_fill_keys(array_keys(self::MODULES), false);

        static::where('empresa_id', $empresaId)
            ->where('sub_type', self::SUB_TYPE_WHOLE)
            ->where('no_aplica', true)
            ->pluck('module')
            ->each(function ($module) use (&$flags) {
                $flags[$module] = true;
            });

        return $flags;
    }

    /**
     * Flags de "No Aplica" por sub-tipo de un módulo para una empresa,
     * con todos los sub-tipos del módulo presentes (false por defecto).
     */
    public static function subTypeFlagsFor(int $empresaId, string $module): array
    {
        $subTypes = self::SUB_TYPES[$module] ?? [];
        $flags = array_fill_keys(array_keys($subTypes), false);

        static::where('empresa_id', $empresaId)
            ->where('module', $module)
            ->whereIn('sub_type', array_keys($subTypes))
            ->where('no_aplica', true)
            ->pluck('sub_type')
            ->each(function ($subType) use (&$flags) {
                $flags[$subType] = true;
            });

        return $flags;
    }

    /**
     * Empresas marcadas "No Aplica" para un módulo completo (query base con join a empresas).
     */
    public static function noAplicaEmpresas(string $module)
    {
        return DB::table('empresa_module_status')
            ->join('empresas', 'empresas.id', '=', 'empresa_module_status.empresa_id')
            ->where('empresa_module_status.module', $module)
            ->where('empresa_module_status.sub_type', self::SUB_TYPE_WHOLE)
            ->where('empresa_module_status.no_aplica', true);
    }

    /**
     * IDs de empresa que deben mostrar el marcador "No Aplica" en un reporte puntual: las que
     * marcaron el módulo COMPLETO como No Aplica, más (si se pasa un sub-tipo específico
     * distinto de SUB_TYPE_WHOLE) las que marcaron ESE sub-tipo individualmente — el módulo
     * completo siempre gana sobre los sub-tipos (misma regla que Empresa::moduleBreakdown()).
     * Pensado para reportes: cada export/página de un sub-tipo (ej. InventoryExport → 'inventory')
     * llama esto una vez para saber qué empresas marcar, sin duplicar la query en cada lugar.
     */
    public static function noAplicaIdsFor(string $module, string $subType = self::SUB_TYPE_WHOLE): array
    {
        $ids = static::where('module', $module)
            ->where('sub_type', self::SUB_TYPE_WHOLE)
            ->where('no_aplica', true)
            ->pluck('empresa_id')
            ->all();

        if ($subType !== self::SUB_TYPE_WHOLE) {
            $subTypeIds = static::where('module', $module)
                ->where('sub_type', $subType)
                ->where('no_aplica', true)
                ->pluck('empresa_id')
                ->all();

            $ids = array_values(array_unique(array_merge($ids, $subTypeIds)));
        }

        return $ids;
    }
}
