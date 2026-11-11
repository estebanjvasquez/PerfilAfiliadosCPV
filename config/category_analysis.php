<?php

/*
 * TASK-0016 (Issue #2 `6791532623`): «Analizar con IA» en las solicitudes de categoría.
 * Diseño y porqué en docs/orquestador/tasks/0016-category-request-ai-analysis.md.
 *
 * INACTIVO por defecto: sin proveedor configurado no se llama a ningún servicio externo ni se gasta API. Activar un
 * proveedor real exige la autorización del propietario (proveedor, clave en el entorno y presupuesto).
 */
return [

    /* `disabled` (por defecto) no llama a nadie. Un proveedor real se registra por nombre en
     * App\Providers\AppServiceProvider::register(); las pruebas inyectan un doble en el contenedor. */
    'provider' => env('CATEGORY_ANALYSIS_PROVIDER', 'disabled'),

    /* Modelo que se registra en cada análisis; lo elige el proveedor real. */
    'model' => env('CATEGORY_ANALYSIS_MODEL'),

    /* Búsqueda de fuentes externas: `disabled` (por defecto) deja el análisis marcado como limitado. */
    'sources' => env('CATEGORY_ANALYSIS_SOURCES', 'disabled'),

    /* Versión de las instrucciones al modelo (App\Services\CategoryAnalysis\AnalysisPrompt::VERSION la fija). */

    'timeout_seconds' => (int) env('CATEGORY_ANALYSIS_TIMEOUT', 90),
    'max_output_tokens' => (int) env('CATEGORY_ANALYSIS_MAX_OUTPUT_TOKENS', 8000),

    /* Límites de la petición. */
    'max_selected_categories' => 8,
    'max_catalog_candidates' => 12,
    'max_sources' => 8,
    'max_field_chars' => 2000,

    /* Frecuencia: [análisis, minutos]. */
    'limits' => [
        'per_request' => [5, 60],
        'per_actor' => [20, 60],
    ],

    /* Tope de gasto diario en USD sumando `cost_usd` de los análisis del día; null = sin tope (solo si no hay proveedor
     * de pago). Con un proveedor real, configúrelo antes de activarlo. */
    'daily_cost_cap_usd' => env('CATEGORY_ANALYSIS_DAILY_COST_CAP_USD'),

    /* Precios por millón de tokens para estimar `cost_usd` (los del proveedor elegido); null = no se estima. */
    'price_per_mtok' => [
        'input' => env('CATEGORY_ANALYSIS_PRICE_INPUT_PER_MTOK'),
        'output' => env('CATEGORY_ANALYSIS_PRICE_OUTPUT_PER_MTOK'),
    ],

];
