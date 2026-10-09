<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TASK-0010A: solicitud de REVISIÓN de categoría CPV hecha por una empresa que no encontró la
 * categoría adecuada. Nunca crea ni modifica categorías - es solo un registro auditable para que la
 * Cámara revise si una categoría existente cubre la necesidad o si hace falta una nueva.
 * Ver TaxonomyCategoryRequestService y la migración 2026_10_09_120000.
 */
class TaxonomyCategoryRequest extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'taxonomy_category_requests';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    /** Lista cerrada de justificaciones (código => etiqueta visible). */
    public const JUSTIFICACIONES = [
        'NO_ENCUENTRO_CATEGORIA' => 'No encuentro una categoría que describa lo que hacemos.',
        'DEMASIADO_GENERAL' => 'Las categorías que encuentro son demasiado generales.',
        'OTRO_NOMBRE' => 'Creo que existe, pero la conozco con otro nombre o término.',
        'NO_ESTOY_SEGURO' => 'No estoy seguro de cuál categoría corresponde.',
        'CAPACIDAD_ESPECIALIZADA' => 'Es una capacidad/producto/servicio especializado que no veo reflejado.',
        'OTRO' => 'Otro motivo.',
    ];

    public const JUSTIFICACION_OTRO = 'OTRO';

    protected $fillable = [
        'empresa_id',
        'user_id',
        'necesidad',
        'justificacion',
        'detalle',
        'terminos_probados',
        'context_snapshot',
        'request_status',
        'delivery_status',
        'delivery_error',
        'recipient_email',
        'submitted_at',
        'mail_sent_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'mail_sent_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function user()
    {
        return $this->belongsTo(UserPgsql::class, 'user_id');
    }

    public function justificacionLabel(): string
    {
        return self::JUSTIFICACIONES[$this->justificacion] ?? $this->justificacion;
    }
}
