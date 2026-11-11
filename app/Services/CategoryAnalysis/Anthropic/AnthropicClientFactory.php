<?php

namespace App\Services\CategoryAnalysis\Anthropic;

use Anthropic\Client;
use Psr\Http\Client\ClientInterface;

/**
 * TASK-0016, addendum: crea el cliente del SDK oficial de Anthropic con la clave guardada en el panel.
 *
 * Los reintentos del SDK se apagan (`maxRetries` 0): el servicio hace un único reintento propio y así el tiempo total
 * queda acotado. Las pruebas registran un transporte PSR-18 simulado; en producción el SDK descubre Guzzle.
 */
class AnthropicClientFactory
{
    public function __construct(private readonly ?ClientInterface $transporter = null)
    {
    }

    public function make(string $apiKey, float $timeoutSeconds): Client
    {
        return new Client(apiKey: $apiKey, requestOptions: array_filter([
            'timeout' => $timeoutSeconds,
            'maxRetries' => 0,
            'transporter' => $this->transporter,
        ], fn ($v) => $v !== null));
    }
}
