<?php

/*
 * TASK-0016 (Issue #2 `6791532623`): «Analizar con IA» en las solicitudes de categoría.
 * Diseño y porqué en docs/orquestador/tasks/0016-category-request-ai-analysis.md.
 *
 * El proveedor, la clave de API, el modelo, el presupuesto diario, los precios y las fuentes externas se configuran en
 * el panel (Taxonomía CPV > Configuración del análisis con IA, tabla `category_analysis_settings`, claves cifradas).
 * Aquí quedan solo los límites técnicos. Sin configuración completa en el panel no se llama a ningún proveedor.
 */
return [

    'timeout_seconds' => (int) env('CATEGORY_ANALYSIS_TIMEOUT', 90),
    'max_output_tokens' => (int) env('CATEGORY_ANALYSIS_MAX_OUTPUT_TOKENS', 8000),

    /* Límites de la petición. */
    'max_selected_categories' => 8,
    'max_catalog_candidates' => 12,
    'max_sources' => 8,
    'max_field_chars' => 2000,

    /* Frecuencia: [análisis, minutos]. «Consultar modelos» tiene su propio límite. */
    'limits' => [
        'per_request' => [5, 60],
        'per_actor' => [20, 60],
        'model_listing' => [5, 1],
    ],

];
