<?php

namespace App\Services\CategoryAnalysis\OpenAi;

use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;
use App\Services\CategoryAnalysis\Contracts\ModelCatalog;
use App\Services\CategoryAnalysis\ProviderException;
use App\Services\CategoryAnalysis\ProviderResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TASK-0016, addendum: el juicio con OpenAI, por su API HTTP con el cliente de Laravel (no hay SDK oficial de OpenAI
 * para PHP; el paquete conocido es comunitario y no se agrega).
 *
 * Chat Completions con `response_format` json_schema estricto: el esquema de AnalysisPrompt ya cumple sus reglas (todas
 * las propiedades obligatorias, sin propiedades adicionales). OpenAI no tiene aquí búsqueda externa: con este proveedor
 * el análisis sale limitado.
 */
final class OpenAiProvider implements AnalysisProvider, ModelCatalog
{
    private const BASE = 'https://api.openai.com/v1';

    /** Familias de modelos de texto que sirven para este análisis (se descartan audio, imagen, embeddings, etc.). */
    private const TEXT_MODEL = '/^(gpt-|o\d|chatgpt-)/';

    private const NOT_TEXT = '/(audio|realtime|transcribe|tts|image|embedding|moderation|search|whisper|dall-e|instruct)/';

    public function __construct(private readonly ?string $apiKey, private readonly ?string $model)
    {
    }

    public function name(): string
    {
        return 'openai';
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
        $response = $this->send(fn () => Http::withToken((string) $this->apiKey)->acceptJson()->timeout($timeoutSeconds)
            ->post(self::BASE.'/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'cpv_category_analysis', 'strict' => true, 'schema' => $prompt['schema'],
                ]],
                'max_completion_tokens' => $maxOutputTokens,
            ]));

        $choice = $response->json('choices.0');
        if (filled($choice['message']['refusal'] ?? null)) {
            throw new ProviderException('provider_refused', 'refusal');
        }
        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new ProviderException('invalid_output', 'response cut at max tokens');
        }

        return new ProviderResult(
            (string) ($choice['message']['content'] ?? ''),
            $response->json('usage.prompt_tokens'),
            $response->json('usage.completion_tokens'),
            $response->json('model'),
        );
    }

    public function listModels(string $apiKey): array
    {
        $response = $this->send(fn () => Http::withToken($apiKey)->acceptJson()->timeout(20)->get(self::BASE.'/models'));

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && preg_match(self::TEXT_MODEL, $id) && ! preg_match(self::NOT_TEXT, $id))
            ->sort()
            ->mapWithKeys(fn ($id) => [$id => $id])
            ->all();
    }

    /** Errores a códigos saneados; el cuerpo de la respuesta no se copia (puede repetir datos enviados). */
    private function send(\Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new ProviderException('provider_timeout', 'openai connection', retryable: true);
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => throw new ProviderException('provider_auth', 'openai auth'),
            $response->status() === 429, $response->serverError() => throw new ProviderException('provider_error', 'openai busy', retryable: true),
            default => throw new ProviderException('provider_error', 'openai http '.$response->status()),
        };
    }
}
