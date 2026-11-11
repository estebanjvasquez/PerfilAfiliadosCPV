<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CategoryAnalysisSettingsPage;
use App\Models\CategoryAnalysisSettings;
use App\Models\User;
use App\Services\CategoryAnalysis\Anthropic\AnthropicClientFactory;
use App\Services\CategoryAnalysis\Anthropic\AnthropicProvider;
use App\Services\CategoryAnalysis\Anthropic\AnthropicWebSearchRetriever;
use App\Services\CategoryAnalysis\AnalysisPrompt;
use App\Services\CategoryAnalysis\Contracts\AnalysisProvider;
use App\Services\CategoryAnalysis\DisabledAnalysisProvider;
use App\Services\CategoryAnalysis\OpenAi\OpenAiProvider;
use App\Services\CategoryAnalysis\ProviderException;
use App\Services\CategoryAnalysis\ProviderRegistry;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TASK-0016, addendum 2026-11-11: «Configuración del análisis con IA» y los adaptadores reales.
 *
 * Reglas (pedido del propietario): proveedor, clave y modelo se configuran en el panel; con la clave se listan los
 * modelos del proveedor; la clave no se vuelve a ver; hay presupuesto diario. Más: solo super_admin; clave cifrada en la
 * base; sin configuración completa no se llama a nadie; el costo incluye las búsquedas web.
 *
 * Dobles (declarados): Anthropic responde por un transporte PSR-18 de Guzzle con MockHandler (el SDK oficial arma la
 * petición real; nada sale a la red); OpenAI por Http::fake(). Las claves son textos de prueba.
 */
class CategoryAnalysisSettingsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const ANTHROPIC_KEY = 'sk-ant-prueba-0123456789-SECRETA';

    private const OPENAI_KEY = 'sk-prueba-openai-9876543210-SECRETA';

    private MockHandler $mock;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_11_130000_create_category_analysis_settings_table.php'))->up();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->mock = new MockHandler;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $this->app->instance(AnthropicClientFactory::class, new AnthropicClientFactory(new \GuzzleHttp\Client(['handler' => $stack])));
        Http::preventStrayRequests();
    }

    private function role(string $name): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($name, 'web'));

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        return $this->role(config('filament-shield.super_admin.name'));
    }

    private function anthropicJson(array $body, int $status = 200): PsrResponse
    {
        return new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function modelsPage(): array
    {
        return ['data' => [
            ['type' => 'model', 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-09-01T00:00:00Z', 'lifecycle' => 'active'],
            ['type' => 'model', 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5', 'created_at' => '2026-09-01T00:00:00Z', 'lifecycle' => 'active'],
        ], 'has_more' => false, 'first_id' => 'claude-opus-5-5', 'last_id' => 'claude-sonnet-5-5'];
    }

    private function message(array $content, array $usage = ['input_tokens' => 1000, 'output_tokens' => 500], string $stop = 'end_turn'): array
    {
        return ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => $usage];
    }

    private function answerJson(): string
    {
        return json_encode(['summary' => 's', 'interpretation' => 'i', 'categories' => [], 'suggested_category_ids' => [],
            'recommendation' => ['type' => 'clarify', 'rationale' => 'r', 'category_ids' => []], 'clarification_questions' => [], 'limitations' => []]);
    }

    private function configure(array $overrides = []): CategoryAnalysisSettings
    {
        $settings = CategoryAnalysisSettings::current();
        $settings->fill(array_replace([
            'provider' => 'anthropic', 'anthropic_api_key' => self::ANTHROPIC_KEY, 'anthropic_key_set_at' => now(), 'anthropic_model' => 'claude-opus-5-5',
            'daily_budget_usd' => 5, 'price_input_per_mtok' => 4, 'price_output_per_mtok' => 20,
        ], $overrides))->save();

        return $settings->refresh();
    }

    // ------------------------------------------------------------------ acceso y menú

    #[Test]
    public function only_super_admin_opens_the_settings_page_in_the_cpv_taxonomy_menu(): void
    {
        $this->assertSame('Taxonomía CPV', CategoryAnalysisSettingsPage::getNavigationGroup());
        $this->actingAs($this->superAdmin())->get(CategoryAnalysisSettingsPage::getUrl())->assertOk()->assertSee('Configuración del análisis con IA');
        foreach (['afiliados', 'lectura', 'reportes'] as $role) {
            $this->actingAs($this->role($role))->get(CategoryAnalysisSettingsPage::getUrl())->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ la clave no se puede filtrar

    #[Test]
    public function a_saved_key_is_encrypted_never_shown_again_and_kept_when_left_blank(): void
    {
        $admin = $this->superAdmin();
        $page = Livewire::actingAs($admin)->test(CategoryAnalysisSettingsPage::class)
            ->fillForm(['provider' => 'anthropic', 'anthropic_api_key' => self::ANTHROPIC_KEY, 'anthropic_model' => null,
                'daily_budget_usd' => 3, 'price_input_per_mtok' => 4, 'price_output_per_mtok' => 20])
            ->set('availableModels.anthropic', ['claude-opus-5-5' => 'Claude Opus 5.5'])
            ->fillForm(['anthropic_model' => 'claude-opus-5-5'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Configuración guardada');

        // Fuera del estado de Livewire y de la pantalla, en este y en cualquier render posterior.
        $this->assertNull($page->get('data.anthropic_api_key'));
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $page->html());
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, json_encode($page->get("data")));
        $fresh = Livewire::actingAs($admin)->test(CategoryAnalysisSettingsPage::class)->assertSee('Por seguridad no se muestra');
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $fresh->html().json_encode($fresh->get("data")));
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $this->actingAs($admin)->get(CategoryAnalysisSettingsPage::getUrl())->getContent());

        // En la base, cifrada; el modelo no la serializa.
        $raw = DB::connection('pgsql')->table('category_analysis_settings')->value('anthropic_api_key');
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $raw);
        $settings = CategoryAnalysisSettings::current();
        $this->assertSame(self::ANTHROPIC_KEY, $settings->apiKey('anthropic'));
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $settings->toJson());
        $this->assertTrue($settings->ready());

        // Guardar con el campo vacío conserva la clave; quitarla la borra.
        Livewire::actingAs($admin)->test(CategoryAnalysisSettingsPage::class)->fillForm(['daily_budget_usd' => 4])->call('save');
        $this->assertSame(self::ANTHROPIC_KEY, CategoryAnalysisSettings::current()->apiKey('anthropic'));
        $this->assertSame(4.0, CategoryAnalysisSettings::current()->daily_budget_usd);
        Livewire::actingAs($admin)->test(CategoryAnalysisSettingsPage::class)->call('removeKey', 'anthropic');
        $this->assertFalse(CategoryAnalysisSettings::current()->hasKey('anthropic'));
        $this->assertFalse(CategoryAnalysisSettings::current()->ready());
    }

    #[Test]
    public function the_analysis_stays_off_until_provider_key_model_budget_and_prices_are_all_set(): void
    {
        $this->assertInstanceOf(DisabledAnalysisProvider::class, app(AnalysisProvider::class));

        foreach (['anthropic_model' => null, 'daily_budget_usd' => null, 'price_output_per_mtok' => null, 'anthropic_api_key' => null] as $field => $value) {
            $this->configure([$field => $value]);
            $this->assertInstanceOf(DisabledAnalysisProvider::class, app(ProviderRegistry::class)->provider(), "sin {$field}");
            $this->assertNotEmpty(CategoryAnalysisSettings::current()->missing());
        }

        $this->configure();
        $this->assertInstanceOf(AnthropicProvider::class, app(AnalysisProvider::class));
        $this->configure(['provider' => 'openai', 'openai_api_key' => self::OPENAI_KEY, 'openai_key_set_at' => now(), 'openai_model' => 'gpt-5']);
        $this->assertInstanceOf(OpenAiProvider::class, app(AnalysisProvider::class));
        $this->assertFalse(app(ProviderRegistry::class)->sources()->available(), 'con OpenAI no hay búsqueda externa');
    }

    // ------------------------------------------------------------------ modelos disponibles

    #[Test]
    public function with_a_typed_anthropic_key_the_page_lists_the_available_models(): void
    {
        $this->mock->append($this->anthropicJson($this->modelsPage()));

        $page = Livewire::actingAs($this->superAdmin())->test(CategoryAnalysisSettingsPage::class)
            ->fillForm(['provider' => 'anthropic', 'anthropic_api_key' => self::ANTHROPIC_KEY])
            ->call('loadModels', 'anthropic')
            ->assertNotified('2 modelos disponibles');

        $this->assertSame(['claude-opus-5-5' => 'Claude Opus 5.5 (claude-opus-5-5)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (claude-sonnet-5-5)'], $page->get('availableModels.anthropic'));
        $request = $this->history[0]['request'];
        $this->assertStringEndsWith('/v1/models', $request->getUri()->getPath());
        $this->assertSame(self::ANTHROPIC_KEY, $request->getHeaderLine('x-api-key'));
        $this->assertFalse(CategoryAnalysisSettings::current()->exists, 'consultar modelos no guarda nada');
    }

    #[Test]
    public function with_an_openai_key_the_page_lists_only_text_models_and_reports_a_rejected_key(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::sequence()
                ->push(['data' => [['id' => 'gpt-5'], ['id' => 'gpt-5-mini'], ['id' => 'o4-mini'], ['id' => 'text-embedding-3-large'], ['id' => 'gpt-4o-realtime-preview'], ['id' => 'dall-e-3'], ['id' => 'whisper-1']]])
                ->push(['error' => ['message' => 'Incorrect API key provided: sk-prueba-openai-9876543210-SECRETA']], 401),
        ]);
        $admin = $this->superAdmin();

        $page = Livewire::actingAs($admin)->test(CategoryAnalysisSettingsPage::class)
            ->fillForm(['provider' => 'openai', 'openai_api_key' => self::OPENAI_KEY])
            ->call('loadModels', 'openai');
        $this->assertSame(['gpt-5', 'gpt-5-mini', 'o4-mini'], array_keys($page->get('availableModels.openai')));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::OPENAI_KEY));

        Log::spy();
        $page->call('loadModels', 'openai')->assertNotified('No se pudieron consultar los modelos');
        $this->assertStringNotContainsString(self::OPENAI_KEY, $page->html());
        Log::shouldNotHaveReceived('error');
    }

    // ------------------------------------------------------------------ adaptadores reales (transporte simulado)

    #[Test]
    public function the_anthropic_adapter_sends_structured_output_and_maps_refusals_and_auth_errors(): void
    {
        $this->configure();
        $provider = app(ProviderRegistry::class)->provider();
        $prompt = AnalysisPrompt::build(['necesidad' => 'x'], [], [], []);

        $this->mock->append(
            $this->anthropicJson($this->message([['type' => 'text', 'text' => $this->answerJson(), 'citations' => null]])),
            $this->anthropicJson($this->message([], stop: 'refusal')),
            $this->anthropicJson(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
        );

        $result = $provider->analyze($prompt, 30, 4000);
        $this->assertSame($this->answerJson(), $result->json);
        $this->assertSame([1000, 500], [$result->inputTokens, $result->outputTokens]);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame(AnalysisPrompt::outputSchema(), $body['output_config']['format']['schema']);
        $this->assertSame(self::ANTHROPIC_KEY, $this->history[0]['request']->getHeaderLine('x-api-key'));
        $this->assertArrayNotHasKey('tools', $body, 'el juicio no recibe herramientas');

        foreach (['provider_refused', 'provider_auth'] as $code) {
            try {
                $provider->analyze($prompt, 30, 4000);
                $this->fail("sin {$code}");
            } catch (ProviderException $e) {
                $this->assertSame($code, $e->errorCode);
                $this->assertStringNotContainsString('x-api-key', $e->getMessage());
            }
        }
    }

    #[Test]
    public function anthropic_web_search_sources_come_only_from_search_result_blocks_and_their_cost_counts(): void
    {
        $this->configure(['sources_enabled' => true, 'max_web_searches' => 2, 'price_per_web_search' => 0.01]);
        $retriever = app(ProviderRegistry::class)->sources();
        $this->assertInstanceOf(AnthropicWebSearchRetriever::class, $retriever);

        $this->mock->append($this->anthropicJson($this->message([
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'ingeniería de costos']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                ['type' => 'web_search_result', 'url' => 'https://web.aacei.org/resources/tcm', 'title' => 'Total Cost Management Framework', 'encrypted_content' => 'x', 'page_age' => 'March 2024'],
                ['type' => 'web_search_result', 'url' => 'https://www.pmi.org/costos', 'title' => 'Cost Management', 'encrypted_content' => 'y', 'page_age' => null],
            ]],
            ['type' => 'text', 'text' => 'Según https://inventada.example.com la ingeniería de costos…', 'citations' => [
                ['type' => 'web_search_result_location', 'url' => 'https://web.aacei.org/resources/tcm', 'title' => 'Total Cost Management Framework', 'encrypted_index' => 'e', 'cited_text' => 'Cost engineering covers estimating and cost control.'],
            ]],
        ], ['input_tokens' => 2000, 'output_tokens' => 300, 'server_tool_use' => ['web_search_requests' => 2, 'web_fetch_requests' => 0]])));

        $sources = $retriever->search('Ingeniería de costos', 8);

        $this->assertSame(['https://web.aacei.org/resources/tcm', 'https://www.pmi.org/costos'], array_column($sources, 'url'));
        $this->assertSame('Cost engineering covers estimating and cost control.', $sources[0]['excerpt']);
        $this->assertSame('web.aacei.org', $sources[0]['entity']);
        $this->assertNotContains('https://inventada.example.com', array_column($sources, 'url'), 'el texto del modelo no aporta URL');
        $this->assertSame(['input_tokens' => 2000, 'output_tokens' => 300, 'web_searches' => 2], $retriever->lastUsage());
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame([['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 2]], $body['tools']);
    }

    #[Test]
    public function the_openai_adapter_uses_a_strict_json_schema_and_maps_errors(): void
    {
        $this->configure(['provider' => 'openai', 'openai_api_key' => self::OPENAI_KEY, 'openai_key_set_at' => now(), 'openai_model' => 'gpt-5']);
        Http::fake(['api.openai.com/v1/chat/completions' => Http::sequence()
            ->push(['model' => 'gpt-5', 'choices' => [['message' => ['content' => $this->answerJson(), 'refusal' => null], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 400]])
            ->push(['choices' => [['message' => ['content' => null, 'refusal' => 'No puedo'], 'finish_reason' => 'stop']]])
            ->push(['error' => ['message' => 'busy']], 429)]);
        $provider = app(ProviderRegistry::class)->provider();
        $prompt = AnalysisPrompt::build(['necesidad' => 'x'], [], [], []);

        $result = $provider->analyze($prompt, 30, 4000);
        $this->assertSame([900, 400, 'gpt-5'], [$result->inputTokens, $result->outputTokens, $result->model]);
        Http::assertSent(fn (Request $r) => $r['response_format']['type'] === 'json_schema'
            && $r['response_format']['json_schema']['strict'] === true
            && $r['response_format']['json_schema']['schema'] === AnalysisPrompt::outputSchema()
            && $r['messages'][0]['role'] === 'system');

        try {
            $provider->analyze($prompt, 30, 4000);
            $this->fail('sin negativa');
        } catch (ProviderException $e) {
            $this->assertSame('provider_refused', $e->errorCode);
        }
        try {
            $provider->analyze($prompt, 30, 4000);
            $this->fail('sin 429');
        } catch (ProviderException $e) {
            $this->assertSame(['provider_error', true], [$e->errorCode, $e->retryable]);
        }
    }
}
