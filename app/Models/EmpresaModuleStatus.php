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

    protected $fillable = [
        'empresa_id',
        'module',
        'no_aplica',
    ];

    protected $casts = [
        'no_aplica' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public static function setStatus(int $empresaId, string $module, bool $noAplica): void
    {
        if ($noAplica) {
            static::updateOrCreate(
                ['empresa_id' => $empresaId, 'module' => $module],
                ['no_aplica' => true]
            );
        } else {
            static::where('empresa_id', $empresaId)->where('module', $module)->delete();
        }
    }

    public static function isNoAplica(int $empresaId, string $module): bool
    {
        return static::where('empresa_id', $empresaId)
            ->where('module', $module)
            ->where('no_aplica', true)
            ->exists();
    }

    /**
     * Flags de "No Aplica" de todos los módulos para una empresa,
     * con todos los módulos presentes (false por defecto).
     */
    public static function flagsFor(int $empresaId): array
    {
        $flags = array_fill_keys(array_keys(self::MODULES), false);

        static::where('empresa_id', $empresaId)
            ->where('no_aplica', true)
            ->pluck('module')
            ->each(function ($module) use (&$flags) {
                $flags[$module] = true;
            });

        return $flags;
    }

    /**
     * Empresas marcadas "No Aplica" para un módulo (query base con join a empresas).
     */
    public static function noAplicaEmpresas(string $module)
    {
        return DB::table('empresa_module_status')
            ->join('empresas', 'empresas.id', '=', 'empresa_module_status.empresa_id')
            ->where('empresa_module_status.module', $module)
            ->where('empresa_module_status.no_aplica', true);
    }
}
