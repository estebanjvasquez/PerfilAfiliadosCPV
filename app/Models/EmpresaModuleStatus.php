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
        self::MODULE_GESTION => 'Sistemas de Gestión',
        self::MODULE_PRESENCIA => 'Presencia Internacional',
        self::MODULE_EXPERIENCIAS => 'Experiencia Relevante',
        self::MODULE_SOSTENIBILIDAD => 'Enfoque de Sostenibilidad',
    ];

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
            'employee' => 'Recursos Humanos',
            'machinery' => 'Maquinaria y Equipos',
            'facility' => 'Instalaciones',
            'inventory' => 'Inventario',
        ],
        self::MODULE_GESTION => [
            'calidad' => 'Calidad',
            'ambiente' => 'Ambiente',
            'credibilidad' => 'Credibilidad y Transparencia',
            'seguridad' => 'Seguridad',
            'proyectos' => 'Gestión de Proyectos',
            'seguridad_info' => 'Seguridad de la Información',
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
}
