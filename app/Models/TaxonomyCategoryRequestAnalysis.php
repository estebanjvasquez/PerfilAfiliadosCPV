<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * TASK-0016 (Issue #2 `6791532623`): un análisis con IA de una solicitud de categoría, tal como se ejecutó.
 *
 * Solo inserción: cada ejecución es una fila nueva y nunca se edita (la base lo impide con un trigger y el modelo lo
 * rechaza antes). La IA asesora: nada de esta fila cambia la solicitud, sus estados ni la taxonomía.
 */
class TaxonomyCategoryRequestAnalysis extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'taxonomy_category_request_analyses';

    public const UPDATED_AT = null;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_LABELS = [
        self::STATUS_COMPLETED => 'Completado',
        self::STATUS_FAILED => 'Falló',
    ];

    /** Relación de alcance entre la necesidad del afiliado y una categoría (lista cerrada). */
    public const RELATIONS = [
        'equivalent' => 'Equivalente',
        'partial' => 'Coincidencia parcial',
        'broader' => 'La categoría es más amplia',
        'narrower' => 'La categoría es más específica',
        'related' => 'Relacionada',
        'insufficient' => 'Información insuficiente',
    ];

    /** Recomendación final (lista cerrada). */
    public const RECOMMENDATIONS = [
        'reuse' => 'Reutilizar una o varias categorías existentes',
        'clarify' => 'Pedir aclaración al afiliado',
        'evaluate_new' => 'Proponer que la Cámara evalúe una categoría nueva',
    ];

    /** Códigos de error saneados (sin detalles del proveedor ni datos). */
    public const ERRORS = [
        'provider_disabled' => 'El análisis con IA no está activado en este servidor.',
        'provider_timeout' => 'El proveedor de IA no respondió a tiempo.',
        'provider_error' => 'El proveedor de IA devolvió un error.',
        'provider_auth' => 'El proveedor de IA rechazó la clave de API: revísela en «Configuración del análisis con IA».',
        'provider_refused' => 'El proveedor de IA declinó responder.',
        'invalid_output' => 'La respuesta de la IA no cumplió el formato esperado y se descartó.',
        'budget_exceeded' => 'Se alcanzó el tope de gasto diario configurado.',
    ];

    protected $fillable = [
        'request_id', 'actor_user_id', 'status', 'limited', 'request_snapshot', 'categories_snapshot',
        'catalog_candidates', 'prompt_version', 'provider', 'model', 'output', 'sources', 'validation_issues',
        'error_code', 'error_message', 'input_tokens', 'output_tokens', 'cost_usd', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'limited' => 'boolean',
        'request_snapshot' => 'array',
        'categories_snapshot' => 'array',
        'catalog_candidates' => 'array',
        'output' => 'array',
        'sources' => 'array',
        'validation_issues' => 'array',
        'cost_usd' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * La tabla existe en esta base. Si el código se despliega antes que la migración, la página de la solicitud sigue
     * funcionando y el análisis queda desactivado, en vez de fallar.
     */
    public static function tableExists(): bool
    {
        static $exists = null;

        return $exists ??= \Illuminate\Support\Facades\Schema::connection('pgsql')->hasTable('taxonomy_category_request_analyses');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un análisis guardado no se modifica: ejecute uno nuevo.'));
        static::deleting(fn () => throw new LogicException('Los análisis no se borran desde la aplicación.'));
    }

    public function request()
    {
        return $this->belongsTo(TaxonomyCategoryRequest::class, 'request_id');
    }

    public function actor()
    {
        return $this->belongsTo(UserPgsql::class, 'actor_user_id');
    }

    public function errorLabel(): ?string
    {
        return $this->error_code === null ? null : (self::ERRORS[$this->error_code] ?? $this->error_code);
    }
}
