<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-4 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.9/4.10 y
 * el plan de esta fase): una sola tabla clave-valor para los ~22 parámetros de
 * `admin_defaults.scoring` del JSON V2, en vez de ~20 columnas fijas o una tabla por sección -
 * decisión explícita del plan para no volverse un monstruo de columnas `_default`/`_min`/`_max`
 * por parámetro. La metadata estática (label/descripción/default/min/max/ejemplo) vive en código,
 * en `App\Support\Taxonomy\TaxonomyRankingParameters::DEFINITIONS` - acá solo se guarda el valor
 * ACTUAL de cada parámetro.
 *
 * `key` usa la misma ruta con puntos que el JSON (`declared_profile.category`,
 * `context.ambiguity_penalty`, etc.) para que sea trivial rastrear cada fila hasta la sección
 * exacta de `admin_defaults.scoring` que la originó.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_settings (
                key VARCHAR(100) PRIMARY KEY,
                value NUMERIC(6, 4) NOT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_settings');
    }
};
