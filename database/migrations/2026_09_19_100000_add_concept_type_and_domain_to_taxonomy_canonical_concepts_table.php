<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 3 del pedido): extiende `taxonomy_canonical_concepts` con una clasificación
 * OPCIONAL. Aditiva, sin backfill - los 79 conceptos existentes de TAXV3-2 quedan con ambas
 * columnas en NULL después de esta migración. Inferir `concept_type`/`domain` retroactivamente es
 * una decisión de builder/admin explícita, nunca un efecto secundario de correr esta migración.
 *
 * `concept_type` referencia (a nivel de aplicación, no FK - ver docblock de
 * `create_taxonomy_concept_types_table`) el vocabulario gobernado de `taxonomy_concept_types`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_canonical_concepts ADD COLUMN concept_type VARCHAR(50) NULL'
        );
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_canonical_concepts ADD COLUMN domain VARCHAR(50) NULL'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_canonical_concepts_concept_type_idx ON taxonomy_canonical_concepts (concept_type)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_canonical_concepts DROP COLUMN IF EXISTS concept_type'
        );
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_canonical_concepts DROP COLUMN IF EXISTS domain'
        );
    }
};
