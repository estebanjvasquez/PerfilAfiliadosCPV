<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TASK-0010C: un intento de aviso por correo de una solicitud de revisión de categoría.
 *
 * Es la auditoría acotada de los avisos: quién lo pidió, a quién iba, con qué modo de transporte
 * y qué resultó. Solo la escribe TaxonomyCategoryRequestService; nunca se edita ni se borra desde
 * el panel. Ver la migración 2026_10_15_120000.
 */
class TaxonomyCategoryRequestDeliveryAttempt extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'taxonomy_category_request_delivery_attempts';

    public const KIND_INITIAL = 'initial';
    public const KIND_MANUAL_RETRY = 'manual_retry';
    /** Derivado por la migración de lo que la solicitud ya tenía guardado antes de esta versión. */
    public const KIND_HISTORICAL = 'historical';

    public const KIND_LABELS = [
        self::KIND_INITIAL => 'Aviso inicial',
        self::KIND_MANUAL_RETRY => 'Reintento manual',
        self::KIND_HISTORICAL => 'Registrado antes del historial',
    ];

    protected $fillable = [
        'request_id',
        'attempt_number',
        'kind',
        'actor_user_id',
        'recipient_email',
        'transport',
        'result',
        'error_code',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(TaxonomyCategoryRequest::class, 'request_id');
    }

    public function actor()
    {
        return $this->belongsTo(UserPgsql::class, 'actor_user_id');
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }
}
