<?php

namespace App\Services\Taxonomy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TAXV3-3: extraído de `TaxonomyCategorySearch::embed()` (Fase 4) para poder reusarlo desde
 * `TaxonomyAutoMapper` sin acoplar el Auto Mapper al motor de búsqueda interactiva - mismo
 * contrato exacto (`POST {texts:[...]} -> {embeddings:[[...]]}`, mismo `GenerateTaxonomyEmbeddings.php`/
 * `HomologateServicesTaxonomy.php` del lado servidor), comportamiento sin cambios.
 */
class EmbeddingClient
{
    /** @return ?string Literal pgvector `'[0.1,0.2,...]'` o null si el servicio no está configurado/falla. */
    public function embed(string $text): ?string
    {
        $embedUrl = config('services.mcp.embed_url');
        $embedToken = config('services.mcp.embed_token');

        if (! $embedUrl || ! $embedToken) {
            Log::warning('EmbeddingClient: falta MCP_EMBED_URL/MCP_EMBED_TOKEN, se omite el nivel semántico.');

            return null;
        }

        $response = Http::withToken($embedToken)->timeout(15)->post($embedUrl, ['texts' => [$text]]);

        if (! $response->successful()) {
            Log::warning('EmbeddingClient: fallo el request a /embed', ['status' => $response->status()]);

            return null;
        }

        $embeddings = $response->json('embeddings');
        $vector = $embeddings[0] ?? null;

        return is_array($vector) ? '['.implode(',', $vector).']' : null;
    }
}
