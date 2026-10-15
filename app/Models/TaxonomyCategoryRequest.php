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
    /**
     * TASK-0010C: el transporte de correo ACEPTÓ el mensaje para enviarlo. No confirma que haya
     * llegado al buzón: ningún transporte lo garantiza.
     */
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';
    /**
     * TASK-0010C: el transporte configurado no envía hacia afuera (`log`, `array`): el mensaje quedó
     * en el log del servidor o en memoria. La solicitud está registrada, pero la Cámara NO recibió
     * ningún correo. Antes esto quedaba como `sent`.
     */
    public const DELIVERY_SIMULATED = 'simulated';

    /** Etiquetas del panel de administración (las del afiliado viven en el RelationManager). */
    public const DELIVERY_LABELS = [
        self::DELIVERY_PENDING => 'Pendiente',
        self::DELIVERY_SENT => 'Aceptado por el servidor de correo',
        self::DELIVERY_SIMULATED => 'Simulado: registrado en el servidor, no se envió',
        self::DELIVERY_FAILED => 'Falló',
    ];

    /** Códigos de falla guardados en `delivery_error` y su explicación para el administrador. */
    public const ERROR_SIN_DESTINATARIO = 'recipient_not_configured';
    public const ERROR_ENVIO = 'mail_send_failed';

    public const ERROR_LABELS = [
        self::ERROR_SIN_DESTINATARIO => 'No hay correo responsable configurado.',
        self::ERROR_ENVIO => 'El servidor de correo rechazó el envío o no respondió.',
    ];

    public const REQUEST_STATUS_LABELS = [
        self::STATUS_PENDING_REVIEW => 'Pendiente de revisión',
    ];

    /** Estados desde los que se puede pedir un reintento manual del aviso. */
    public const RETRYABLE_DELIVERY = [self::DELIVERY_FAILED, self::DELIVERY_PENDING];

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
        'delivery_transport',
        'delivery_claim_token',
        'delivery_claimed_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'mail_sent_at' => 'datetime',
        'delivery_claimed_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function user()
    {
        return $this->belongsTo(UserPgsql::class, 'user_id');
    }

    public function deliveryAttempts()
    {
        return $this->hasMany(TaxonomyCategoryRequestDeliveryAttempt::class, 'request_id')->orderBy('attempt_number');
    }

    public function justificacionLabel(): string
    {
        return self::JUSTIFICACIONES[$this->justificacion] ?? $this->justificacion;
    }

    public function deliveryLabel(): string
    {
        return self::DELIVERY_LABELS[$this->delivery_status] ?? (string) $this->delivery_status;
    }

    public function deliveryErrorLabel(): ?string
    {
        if (blank($this->delivery_error)) {
            return null;
        }

        return self::ERROR_LABELS[$this->delivery_error] ?? $this->delivery_error;
    }

    public function requestStatusLabel(): string
    {
        return self::REQUEST_STATUS_LABELS[$this->request_status] ?? (string) $this->request_status;
    }

    public function isRetryable(): bool
    {
        return in_array($this->delivery_status, self::RETRYABLE_DELIVERY, true);
    }
}
