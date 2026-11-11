<?php

namespace App\Services\CategoryAnalysis;

use App\Services\CategoryAnalysis\Contracts\SourceRetriever;

/** TASK-0016: configuración por defecto. Sin búsqueda externa, el análisis queda marcado como limitado. */
final class DisabledSourceRetriever implements SourceRetriever
{
    public function name(): string
    {
        return 'disabled';
    }

    public function available(): bool
    {
        return false;
    }

    public function search(string $query, int $max): array
    {
        return [];
    }
}
