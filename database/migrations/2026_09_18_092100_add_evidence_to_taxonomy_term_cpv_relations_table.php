<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-3 (ajuste V3 punto 16: explicabilidad de cada mapping automático). `evidence` (jsonb,
 * nullable) guarda qué señales del Auto Mapper contribuyeron y con qué peso - visible en modo
 * lectura en `TaxonomyTermCpvRelationResource` para CUALQUIER relación que el Auto Mapper cree, no
 * solo las que caigan en la Cola de Excepciones (TAXV3-4). Las 9.727 relaciones existentes (todas
 * de `taxonomy:import-term-cpv-relations`, TAXV2-2) quedan con `evidence = NULL` - no se les
 * inventa evidencia retroactiva que no existió al importarlas.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_term_cpv_relations ADD COLUMN evidence JSONB NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_term_cpv_relations DROP COLUMN IF EXISTS evidence'
        );
    }
};
