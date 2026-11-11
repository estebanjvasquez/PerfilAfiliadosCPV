<?php

namespace App\Services\CategoryAnalysis\Anthropic;

use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;
use App\Services\CategoryAnalysis\Contracts\ModelCatalog;
use App\Services\CategoryAnalysis\ProviderException;
use App\Services\CategoryAnalysis\ProviderResult;

/**
 * TASK-0016, addendum: el juicio con Claude, por el SDK oficial (`anthropic-ai/sdk`).
 *
 * Una sola llamada sin herramientas: el modelo recibe los datos ya recuperados y responde con salidas estructuradas
 * (`outputConfig.format` json_schema), que el servicio vuelve a validar. Una negativa (`stop_reason: refusal`) o un
 * corte por `max_tokens` cuentan como falla, no como respuesta.
 */
final class AnthropicProvider implements AnalysisProvider, ModelCatalog
{
    public function __construct(
        private readonly AnthropicClientFactory $factory,
        private readonly ?string $apiKey,
        private readonly ?string $model,
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function enabled(): bool
    {
        return filled($this->apiKey) && filled($this->model);
    }

    public function analyze(array $prompt, int $timeoutSeconds, int $maxOutputTokens): ProviderResult
    {
        try {
            $message = $this->factory->make((string) $this->apiKey, $timeoutSeconds)->messages->create(
                maxTokens: $maxOutputTokens,
                messages: [['role' => 'user', 'content' => $prompt['user']]],
                model: (string) $this->model,
                system: $prompt['system'],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $prompt['schema']]],
            );
        } catch (\Throwable $e) {
            throw AnthropicErrors::map($e);
        }

        if ($message->stopReason === 'refusal') {
            throw new ProviderException('provider_refused', 'refusal');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new ProviderException('invalid_output', 'response cut at max_tokens');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new ProviderResult($text, $message->usage->inputTokens, $message->usage->outputTokens, $message->model);
    }

    public function listModels(string $apiKey): array
    {
        try {
            $page = $this->factory->make($apiKey, 20)->models->list(limit: 100);
        } catch (\Throwable $e) {
            throw AnthropicErrors::map($e);
        }

        $models = [];
        foreach ($page->getItems() as $info) {
            $models[$info->id] = "{$info->displayName} ({$info->id})";
        }

        return $models;
    }
}
