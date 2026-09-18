<?php

namespace Tests\Unit\Taxonomy;

use App\Services\Taxonomy\EmbeddingClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAXV3-3: extraído de TaxonomyCategorySearch::embed() - mismo comportamiento, ahora reusable
 * desde TaxonomyAutoMapper. Se prueba con Http::fake(), sin llamar al servicio real.
 */
class EmbeddingClientTest extends TestCase
{
    #[Test]
    public function returns_a_pgvector_literal_on_success(): void
    {
        Http::fake([
            '*' => Http::response(['embeddings' => [[0.1, 0.2, 0.3]]], 200),
        ]);

        config(['services.mcp.embed_url' => 'https://example.test/embed', 'services.mcp.embed_token' => 'token']);

        $vector = (new EmbeddingClient())->embed('wellhead');

        $this->assertSame('[0.1,0.2,0.3]', $vector);
    }

    #[Test]
    public function returns_null_when_service_is_not_configured(): void
    {
        config(['services.mcp.embed_url' => null, 'services.mcp.embed_token' => null]);

        $vector = (new EmbeddingClient())->embed('wellhead');

        $this->assertNull($vector);
    }

    #[Test]
    public function returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        config(['services.mcp.embed_url' => 'https://example.test/embed', 'services.mcp.embed_token' => 'token']);

        $vector = (new EmbeddingClient())->embed('wellhead');

        $this->assertNull($vector);
    }
}
