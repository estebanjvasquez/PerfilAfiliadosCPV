<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0016, addendum 2026-11-11: configuración del análisis con IA, editable en el panel (Taxonomía CPV >
 * Configuración del análisis con IA). Una sola fila.
 *
 * Las claves de API se guardan CIFRADAS por la aplicación (cast `encrypted`, con APP_KEY): la columna nunca tiene la
 * clave en claro y la base sola no alcanza para leerla. RLS explícito, como las demás tablas nuevas.
 *
 * ADITIVA y reversible: `IF NOT EXISTS` y `down()` la quita entera. No toca ninguna otra tabla.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $db = DB::connection('pgsql');

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS category_analysis_settings (
                id BIGSERIAL PRIMARY KEY,
                provider VARCHAR(20) NOT NULL DEFAULT 'disabled',
                anthropic_model VARCHAR(100) NULL,
                openai_model VARCHAR(100) NULL,
                anthropic_api_key TEXT NULL,
                openai_api_key TEXT NULL,
                anthropic_key_set_at TIMESTAMP NULL,
                openai_key_set_at TIMESTAMP NULL,
                daily_budget_usd NUMERIC(10, 2) NULL,
                price_input_per_mtok NUMERIC(10, 4) NULL,
                price_output_per_mtok NUMERIC(10, 4) NULL,
                price_per_web_search NUMERIC(10, 4) NULL,
                sources_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                max_web_searches SMALLINT NOT NULL DEFAULT 3,
                updated_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT category_analysis_settings_provider_check CHECK (provider IN ('disabled', 'anthropic', 'openai')),
                CONSTRAINT category_analysis_settings_budget_check CHECK (daily_budget_usd IS NULL OR daily_budget_usd >= 0),
                CONSTRAINT category_analysis_settings_searches_check CHECK (max_web_searches BETWEEN 0 AND 10)
            )
        SQL);

        $db->statement('ALTER TABLE category_analysis_settings ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS category_analysis_settings');
    }
};
