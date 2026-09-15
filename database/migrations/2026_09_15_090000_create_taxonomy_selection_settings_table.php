<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 del proyecto de taxonomía (ver docs/taxonomia/plan_mcp_cira.md): los 2 números que el
 * diseño original (docs/taxonomia/Respuesta_Ampliacion_SupplHi_Bilingue.pdf, sección 4) dejaba
 * pendientes de definir - límite de categorías principales/secundarias por empresa en el buscador
 * de autocarga - el usuario pidió explícitamente que NO sean un número fijo en código, sino
 * configurable por los administradores desde el panel (ver TaxonomySelectionSettingsPage).
 *
 * Tabla de una sola fila (patrón "singleton row", no un settings genérico de key/value - son
 * solo 2 números, traer un paquete de settings para esto sería sobre-ingeniería). `null` en
 * cualquiera de las 2 columnas significa "sin tope" - el valor pedido explícitamente al arrancar
 * esta fase.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_selection_settings (
                id BIGSERIAL PRIMARY KEY,
                max_categorias_principales SMALLINT NULL,
                max_categorias_secundarias SMALLINT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->table('taxonomy_selection_settings')->insert([
            'max_categorias_principales' => null,
            'max_categorias_secundarias' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_selection_settings');
    }
};
