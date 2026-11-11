<?php

namespace App\Filament\Pages;

use App\Models\CategoryAnalysisSettings;
use App\Models\TaxonomyCategoryRequestAnalysis;
use App\Services\CategoryAnalysis\ProviderException;
use App\Services\CategoryAnalysis\ProviderRegistry;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;

/**
 * TASK-0016, addendum 2026-11-11 (pedido del propietario): «Configuración del análisis con IA», en Taxonomía CPV.
 *
 * Aquí se eligen el proveedor (Anthropic u OpenAI), su clave de API, el modelo (de la lista que el proveedor devuelve
 * para esa clave), el presupuesto diario, los precios y las fuentes externas.
 *
 * La clave NO se puede volver a ver: se guarda cifrada, el formulario nunca la carga y, después de guardarla, la
 * pantalla solo dice cuándo se guardó. Para cambiarla se escribe una nueva; «Quitar la clave» la borra.
 * Solo super_admin, el mismo rol que opera las solicitudes.
 */
class CategoryAnalysisSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Configuración del análisis con IA';

    protected static ?string $title = 'Configuración del análisis con IA';

    protected static ?string $slug = 'configuracion-analisis-ia';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.taxonomy-selection-settings';

    public const PROVIDERS_WITH_KEY = ['anthropic', 'openai'];

    public ?array $data = [];

    /** @var array<string, array<string, string>> modelos que devolvió «Consultar modelos», por proveedor */
    public array $availableModels = ['anthropic' => [], 'openai' => []];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(config('filament-shield.super_admin.name')) ?? false;
    }

    public function mount(): void
    {
        abort_unless(CategoryAnalysisSettings::tableExists(), 503, 'Falta aplicar la migración de la configuración del análisis con IA.');

        // Nunca las claves: el formulario empieza con los campos de clave vacíos.
        $this->form->fill(CategoryAnalysisSettings::current()->only([
            'provider', 'anthropic_model', 'openai_model', 'daily_budget_usd', 'price_input_per_mtok',
            'price_output_per_mtok', 'price_per_web_search', 'sources_enabled', 'max_web_searches',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('estado')
                    ->label('Estado')
                    ->content(fn () => $this->statusHtml()),
                Select::make('provider')
                    ->label('Proveedor de IA')
                    ->options(CategoryAnalysisSettings::PROVIDERS)
                    ->required()
                    ->live(),
                $this->providerSection('anthropic', 'Anthropic (Claude)', 'https://console.anthropic.com/settings/keys', 'sk-ant-…'),
                $this->providerSection('openai', 'OpenAI', 'https://platform.openai.com/api-keys', 'sk-…'),
                Section::make('Presupuesto y precios')
                    ->description('El análisis no se ejecuta si el gasto estimado del día ya alcanzó el presupuesto. El gasto se estima con estos precios: cópielos de la página de precios del proveedor para el modelo elegido.')
                    ->visible(fn (Get $get) => $get('provider') !== 'disabled')
                    ->columns(3)
                    ->schema([
                        TextInput::make('daily_budget_usd')->label('Presupuesto diario (USD)')->numeric()->minValue(0.01)->required(),
                        TextInput::make('price_input_per_mtok')->label('Precio de entrada (USD por millón de tokens)')->numeric()->minValue(0)->required(),
                        TextInput::make('price_output_per_mtok')->label('Precio de salida (USD por millón de tokens)')->numeric()->minValue(0)->required(),
                    ]),
            ])
            ->statePath('data');
    }

    private function providerSection(string $provider, string $label, string $keysUrl, string $placeholder): Section
    {
        $schema = [
            Placeholder::make("{$provider}_key_status")
                ->label('Clave de API')
                ->content(function () use ($provider) {
                    $settings = CategoryAnalysisSettings::current();

                    return $settings->hasKey($provider)
                        ? 'Guardada el '.$settings->getAttribute("{$provider}_key_set_at")?->format('d/m/Y H:i').'. Por seguridad no se muestra.'
                        : 'No hay clave guardada.';
                }),
            TextInput::make("{$provider}_api_key")
                ->label('Clave nueva')
                ->password()
                ->revealable(false)
                ->autocomplete('new-password')
                ->placeholder($placeholder)
                ->maxLength(300)
                ->helperText(new HtmlString('Escríbala solo para guardar una clave o reemplazar la guardada. Se crea en <a class="underline" target="_blank" rel="noopener noreferrer" href="'.e($keysUrl).'">la consola del proveedor</a>. Use una clave propia de este sistema, con su propio límite de gasto.')),
            Actions::make([
                Action::make("list_{$provider}_models")
                    ->label('Consultar modelos')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn () => $this->loadModels($provider)),
                Action::make("remove_{$provider}_key")
                    ->label('Quitar la clave')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('La clave se borra de este sistema. El análisis con este proveedor queda desactivado hasta guardar otra.')
                    ->visible(fn () => CategoryAnalysisSettings::current()->hasKey($provider))
                    ->action(fn () => $this->removeKey($provider)),
            ]),
            Select::make("{$provider}_model")
                ->label('Modelo')
                ->options(fn () => $this->modelOptions($provider))
                ->searchable()
                ->required(fn (Get $get) => $get('provider') === $provider)
                ->helperText('Presione «Consultar modelos» para ver los modelos disponibles con la clave.'),
        ];

        if ($provider === 'anthropic') {
            $schema[] = Toggle::make('sources_enabled')
                ->label('Buscar fuentes externas con la búsqueda web de Anthropic')
                ->helperText('Sin esto el informe sale «limitado»: no se consulta nada fuera del catálogo. Cada búsqueda se cobra aparte.')
                ->live();
            $schema[] = TextInput::make('max_web_searches')->label('Búsquedas web por análisis')->numeric()->minValue(1)->maxValue(10)
                ->visible(fn (Get $get) => (bool) $get('sources_enabled'));
            $schema[] = TextInput::make('price_per_web_search')->label('Precio por búsqueda web (USD)')->numeric()->minValue(0)
                ->visible(fn (Get $get) => (bool) $get('sources_enabled'))->required(fn (Get $get) => (bool) $get('sources_enabled'));
        }

        return Section::make($label)
            ->visible(fn (Get $get) => $get('provider') === $provider)
            ->schema($schema);
    }

    /** @return array<string, string> */
    private function modelOptions(string $provider): array
    {
        $options = $this->availableModels[$provider] ?? [];
        $current = CategoryAnalysisSettings::current()->model($provider);
        if (filled($current) && ! array_key_exists($current, $options)) {
            $options = [$current => $current] + $options;
        }

        return $options;
    }

    public function loadModels(string $provider): void
    {
        abort_unless(static::canAccess() && in_array($provider, self::PROVIDERS_WITH_KEY, true), 403);

        $key = filled($this->data["{$provider}_api_key"] ?? null)
            ? trim((string) $this->data["{$provider}_api_key"])
            : CategoryAnalysisSettings::current()->apiKey($provider);
        if (blank($key)) {
            Notification::make()->warning()->title('Escriba la clave de API')->body('Hace falta una clave para consultar los modelos.')->send();

            return;
        }

        [$max, $minutes] = config('category_analysis.limits.model_listing');
        $limiter = 'category-analysis:models:'.Auth::id();
        if (RateLimiter::tooManyAttempts($limiter, $max)) {
            Notification::make()->warning()->title('Demasiadas consultas')->body('Espere un minuto e intente de nuevo.')->send();

            return;
        }
        RateLimiter::hit($limiter, $minutes * 60);

        try {
            $models = app(ProviderRegistry::class)->catalog($provider)->listModels($key);
        } catch (ProviderException $e) {
            Notification::make()->danger()->title('No se pudieron consultar los modelos')
                ->body(TaxonomyCategoryRequestAnalysis::ERRORS[$e->errorCode] ?? TaxonomyCategoryRequestAnalysis::ERRORS['provider_error'])->send();

            return;
        }

        $this->availableModels[$provider] = $models;
        Notification::make()->success()->title(count($models).' modelos disponibles')->body('Elija uno en «Modelo» y presione «Guardar».')->send();
    }

    public function removeKey(string $provider): void
    {
        abort_unless(static::canAccess() && in_array($provider, self::PROVIDERS_WITH_KEY, true), 403);

        CategoryAnalysisSettings::current()->fill(["{$provider}_api_key" => null, "{$provider}_key_set_at" => null, 'updated_by' => Auth::id()])->save();
        $this->data["{$provider}_api_key"] = null;
        $this->availableModels[$provider] = [];
        Notification::make()->success()->title('Clave quitada')->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->form->getState();
        $settings = CategoryAnalysisSettings::current();
        $provider = $state['provider'];

        $updates = collect($state)->only([
            'provider', 'anthropic_model', 'openai_model', 'daily_budget_usd', 'price_input_per_mtok',
            'price_output_per_mtok', 'price_per_web_search', 'sources_enabled', 'max_web_searches',
        ])->filter(fn ($v, $k) => array_key_exists($k, $state))->all();

        foreach (self::PROVIDERS_WITH_KEY as $p) {
            $typed = trim((string) ($state["{$p}_api_key"] ?? ''));
            if ($typed !== '') {
                $updates["{$p}_api_key"] = $typed;
                $updates["{$p}_key_set_at"] = now();
            }
        }

        if ($provider !== 'disabled' && ! isset($updates["{$provider}_api_key"]) && ! $settings->hasKey($provider)) {
            Notification::make()->danger()->title('Falta la clave de API')->body('Escriba la clave del proveedor elegido.')->send();

            return;
        }

        $settings->fill($updates + ['updated_by' => Auth::id()])->save();

        // La clave escrita sale del estado del formulario apenas se guarda.
        foreach (self::PROVIDERS_WITH_KEY as $p) {
            $this->data["{$p}_api_key"] = null;
        }

        $settings->refresh();
        Notification::make()->success()->title('Configuración guardada')
            ->body($settings->ready() ? 'El análisis con IA está activo.' : 'El análisis sigue desactivado: '.implode(' ', $settings->missing()))
            ->send();
    }

    private function statusHtml(): HtmlString
    {
        $settings = CategoryAnalysisSettings::current();
        if ($settings->ready()) {
            $spent = (float) TaxonomyCategoryRequestAnalysis::query()->where('created_at', '>=', now()->startOfDay())->sum('cost_usd');

            return new HtmlString('<span class="font-medium text-success-600">Activo</span> · '.e(CategoryAnalysisSettings::PROVIDERS[$settings->provider])
                .' · '.e((string) $settings->model($settings->provider)).' · gasto estimado hoy: USD '.number_format($spent, 4)
                .' de '.number_format((float) $settings->daily_budget_usd, 2));
        }

        return new HtmlString('<span class="font-medium text-warning-600">Desactivado</span>: '.e(implode(' ', $settings->missing())));
    }
}
