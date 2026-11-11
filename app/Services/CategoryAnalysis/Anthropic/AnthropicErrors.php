<?php

namespace App\Services\CategoryAnalysis\Anthropic;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Services\CategoryAnalysis\ProviderException;

/**
 * TASK-0016, addendum: traduce las excepciones del SDK a códigos saneados, de la más específica a la más general. El
 * mensaje del SDK no se copia: puede llevar la respuesta del servidor.
 */
final class AnthropicErrors
{
    public static function map(\Throwable $e): ProviderException
    {
        return match (true) {
            $e instanceof APITimeoutException => new ProviderException('provider_timeout', 'anthropic timeout', retryable: true),
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => new ProviderException('provider_auth', 'anthropic auth'),
            $e instanceof RateLimitException, $e instanceof InternalServerException => new ProviderException('provider_error', 'anthropic busy', retryable: true),
            $e instanceof APIConnectionException => new ProviderException('provider_error', 'anthropic connection', retryable: true),
            $e instanceof APIException => new ProviderException('provider_error', 'anthropic api'),
            default => new ProviderException('provider_error', 'anthropic '.class_basename($e)),
        };
    }
}
