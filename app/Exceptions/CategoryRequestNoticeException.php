<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * TASK-0010C: un reintento de aviso que no se puede hacer, con un motivo que el panel muestra tal
 * cual al administrador. No es un error del sistema: no se registra ningún intento.
 */
class CategoryRequestNoticeException extends RuntimeException
{
    public const NOT_RETRYABLE = 'not_retryable';
    public const IN_PROGRESS = 'in_progress';
    public const RECIPIENT_MISSING = 'recipient_missing';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notRetryable(): self
    {
        return new self(self::NOT_RETRYABLE, 'El aviso de esta solicitud ya fue aceptado o simulado: no hay nada que reintentar.');
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS, 'Ya hay un intento de aviso en curso para esta solicitud. Espere a que termine y vuelva a mirar el estado.');
    }

    public static function recipientMissing(): self
    {
        return new self(self::RECIPIENT_MISSING, 'No hay correo responsable configurado. Configúrelo en «Configuración de solicitudes» antes de reintentar.');
    }
}
