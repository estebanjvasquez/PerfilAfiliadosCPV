<?php

namespace App\Services\CategoryAnalysis\Contracts;

use App\Services\CategoryAnalysis\ProviderResult;

/**
 * TASK-0016: el juicio de equivalencia, desacoplado del proveedor. Recibe datos ya recuperados (solicitud, categorías
 * reales del catálogo, fuentes externas reales) y devuelve un JSON que CategoryAnalysisService valida contra el
 * esquema de AnalysisPrompt::outputSchema(). No recibe herramientas: no puede publicar, vincular ni cambiar nada.
 */
interface AnalysisProvider
{
    /** Nombre corto que se guarda en el análisis (`disabled`, `fake`, ...). */
    public function name(): string;

    public function model(): ?string;

    /** false: no se llama (configuración inactiva). */
    public function enabled(): bool;

    /**
     * @param  array{system: string, input: array<string, mixed>, schema: array<string, mixed>}  $prompt
     *
     * @throws \App\Services\CategoryAnalysis\ProviderException
     */
    public function analyze(array $prompt, int $timeoutSeconds, int $maxOutputTokens): ProviderResult;
}
