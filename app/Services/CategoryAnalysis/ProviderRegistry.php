<?php

namespace App\Services\CategoryAnalysis;

use App\Models\CategoryAnalysisSettings;
use App\Services\CategoryAnalysis\Anthropic\AnthropicClientFactory;
use App\Services\CategoryAnalysis\Anthropic\AnthropicProvider;
use App\Services\CategoryAnalysis\Anthropic\AnthropicWebSearchRetriever;
use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;
use App\Services\CategoryAnalysis\Contracts\ModelCatalog;
use App\Services\CategoryAnalysis\Contracts\SourceRetriever;
use App\Services\CategoryAnalysis\OpenAi\OpenAiProvider;

/**
 * TASK-0016, addendum: arma el proveedor y el buscador de fuentes con la configuración del panel
 * (CategoryAnalysisSettings). Sin una configuración completa (proveedor, clave, modelo, presupuesto y precios) devuelve
 * los desactivados: nunca se llama a un servicio de pago con una configuración a medias.
 */
final class ProviderRegistry
{
    public function __construct(private readonly AnthropicClientFactory $anthropic)
    {
    }

    public function provider(?CategoryAnalysisSettings $settings = null): AnalysisProvider
    {
        $settings ??= CategoryAnalysisSettings::current();
        if (! $settings->ready()) {
            return new DisabledAnalysisProvider;
        }

        return match ($settings->provider) {
            'anthropic' => new AnthropicProvider($this->anthropic, $settings->apiKey('anthropic'), $settings->anthropic_model),
            'openai' => new OpenAiProvider($settings->apiKey('openai'), $settings->openai_model),
            default => new DisabledAnalysisProvider,
        };
    }

    /** Fuentes externas: solo con Anthropic (búsqueda web propia) y si el administrador las activó. */
    public function sources(?CategoryAnalysisSettings $settings = null): SourceRetriever
    {
        $settings ??= CategoryAnalysisSettings::current();
        if (! $settings->ready() || $settings->provider !== 'anthropic' || ! $settings->sources_enabled) {
            return new DisabledSourceRetriever;
        }

        return new AnthropicWebSearchRetriever(
            $this->anthropic,
            $settings->apiKey('anthropic'),
            $settings->anthropic_model,
            (int) $settings->max_web_searches,
            (int) config('category_analysis.timeout_seconds'),
        );
    }

    /** Para «Consultar modelos»: lista con la clave dada, sin guardar nada. */
    public function catalog(string $provider): ModelCatalog
    {
        return match ($provider) {
            'anthropic' => new AnthropicProvider($this->anthropic, null, null),
            'openai' => new OpenAiProvider(null, null),
            default => throw new \InvalidArgumentException('Proveedor sin catálogo de modelos.'),
        };
    }
}
