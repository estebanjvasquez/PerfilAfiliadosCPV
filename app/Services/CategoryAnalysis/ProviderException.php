<?php

namespace App\Services\CategoryAnalysis;

use RuntimeException;

/**
 * TASK-0016: falla del proveedor con un código saneado (ver TaxonomyCategoryRequestAnalysis::ERRORS). El mensaje
 * nunca lleva cabeceras, claves, el texto enviado ni la respuesta cruda. `retryable`: falla transitoria (tiempo de
 * espera, sobrecarga) que admite un único reintento.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '', public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
