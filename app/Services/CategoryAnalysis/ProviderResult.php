<?php

namespace App\Services\CategoryAnalysis;

/** TASK-0016: lo que devolvió el proveedor, antes de validar. */
final class ProviderResult
{
    public function __construct(
        public readonly string $json,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $model = null,
    ) {
    }
}
