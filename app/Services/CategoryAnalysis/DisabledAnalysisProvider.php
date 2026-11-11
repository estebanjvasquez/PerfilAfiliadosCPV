<?php

namespace App\Services\CategoryAnalysis;

use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;

/** TASK-0016: configuración por defecto. No llama a ningún servicio ni gasta API. */
final class DisabledAnalysisProvider implements AnalysisProvider
{
    public function name(): string
    {
        return 'disabled';
    }

    public function model(): ?string
    {
        return null;
    }

    public function enabled(): bool
    {
        return false;
    }

    public function analyze(array $prompt, int $timeoutSeconds, int $maxOutputTokens): ProviderResult
    {
        throw new ProviderException('provider_disabled');
    }
}
