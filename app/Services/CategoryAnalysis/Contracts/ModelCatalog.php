<?php

namespace App\Services\CategoryAnalysis\Contracts;

/** TASK-0016, addendum: modelos disponibles para una clave de API, para el selector del panel. */
interface ModelCatalog
{
    /**
     * @return array<string, string> id del modelo => nombre visible
     *
     * @throws \App\Services\CategoryAnalysis\ProviderException
     */
    public function listModels(string $apiKey): array;
}
