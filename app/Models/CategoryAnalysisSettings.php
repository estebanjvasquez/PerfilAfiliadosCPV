<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-0016, addendum 2026-11-11: configuración del análisis con IA (una fila), editable por super_admin en
 * «Configuración del análisis con IA».
 *
 * Las claves de API se cifran con APP_KEY (cast `encrypted`) y están en `$hidden`: no salen al serializar el modelo
 * (Livewire, JSON, logs). La única forma de usarlas es `apiKey()`, que las usan los adaptadores en el servidor.
 */
class CategoryAnalysisSettings extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'category_analysis_settings';

    public const PROVIDERS = [
        'disabled' => 'Desactivado',
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
    ];

    protected $fillable = [
        'provider', 'anthropic_model', 'openai_model', 'anthropic_api_key', 'openai_api_key', 'anthropic_key_set_at',
        'openai_key_set_at', 'daily_budget_usd', 'price_input_per_mtok', 'price_output_per_mtok', 'price_per_web_search',
        'sources_enabled', 'max_web_searches', 'updated_by',
    ];

    protected $hidden = ['anthropic_api_key', 'openai_api_key'];

    protected $casts = [
        'anthropic_api_key' => 'encrypted',
        'openai_api_key' => 'encrypted',
        'anthropic_key_set_at' => 'datetime',
        'openai_key_set_at' => 'datetime',
        'daily_budget_usd' => 'float',
        'price_input_per_mtok' => 'float',
        'price_output_per_mtok' => 'float',
        'price_per_web_search' => 'float',
        'sources_enabled' => 'boolean',
        'max_web_searches' => 'integer',
    ];

    public static function tableExists(): bool
    {
        return Schema::connection('pgsql')->hasTable('category_analysis_settings');
    }

    /**
     * La fila única. Si todavía no se guardó nada (o la migración no está aplicada), una instancia desactivada sin
     * guardar: leer la configuración nunca escribe en la base. Se guarda con `fill()->save()`.
     */
    public static function current(): self
    {
        $default = new self(['provider' => 'disabled', 'sources_enabled' => false, 'max_web_searches' => 3]);
        if (! self::tableExists()) {
            return $default;
        }

        return self::query()->orderBy('id')->first() ?? $default;
    }

    /** La clave del proveedor, descifrada. null si no hay o si ya no se puede descifrar (APP_KEY cambió). */
    public function apiKey(string $provider): ?string
    {
        $column = "{$provider}_api_key";
        if (! in_array($provider, ['anthropic', 'openai'], true) || blank($this->getRawOriginal($column))) {
            return null;
        }

        try {
            return $this->getAttribute($column);
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasKey(string $provider): bool
    {
        return filled($this->apiKey($provider));
    }

    public function model(string $provider): ?string
    {
        return in_array($provider, ['anthropic', 'openai'], true) ? $this->getAttribute("{$provider}_model") : null;
    }

    /**
     * Qué falta para activar el análisis (vacío = listo). El presupuesto y los precios son obligatorios: sin ellos el
     * tope diario no se podría hacer cumplir.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        if ($this->provider === 'disabled' || ! array_key_exists($this->provider, self::PROVIDERS)) {
            return ['Elija un proveedor.'];
        }
        $missing = [];
        if (! $this->hasKey($this->provider)) {
            $missing[] = 'Falta la clave de API del proveedor.';
        }
        if (blank($this->model($this->provider))) {
            $missing[] = 'Falta elegir el modelo.';
        }
        if (! ($this->daily_budget_usd > 0)) {
            $missing[] = 'Falta el presupuesto diario.';
        }
        if ($this->price_input_per_mtok === null || $this->price_output_per_mtok === null) {
            $missing[] = 'Faltan los precios por millón de tokens.';
        }

        return $missing;
    }

    public function ready(): bool
    {
        return $this->missing() === [];
    }
}
