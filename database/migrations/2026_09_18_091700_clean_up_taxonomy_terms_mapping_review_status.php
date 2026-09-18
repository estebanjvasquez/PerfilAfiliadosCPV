<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-1: `ImportTaxonomyTermProvenance` (V2->V3) usó el valor crudo del JSON
 * `source_verified_needs_cpv_mapping` para los 52 términos nuevos sin ninguna relación CPV -
 * funcionalmente correcto pero no encaja con el eje limpio de 4 valores que pide esta fase
 * (`auto_mapped`/`unmapped`/`needs_review`/futuro `mapped`). Se renombra a `needs_review` -
 * exactamente lo que significa (le falta pasar por el Auto Mapper de TAXV3-3).
 *
 * NO se tocan los 1.612 términos `auto_mapped` existentes: no hay ningún bug que corregir ahí (ya
 * tienen relaciones CPV, cuyo estado de revisión vive en `taxonomy_term_cpv_relations.status`, no
 * acá) y reclasificarlos en masa recrearía la inundación de tareas que esta fase busca evitar.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            "UPDATE taxonomy_terms SET mapping_review_status = 'needs_review' WHERE mapping_review_status = 'source_verified_needs_cpv_mapping'"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            "UPDATE taxonomy_terms SET mapping_review_status = 'source_verified_needs_cpv_mapping' WHERE mapping_review_status = 'needs_review' AND term_type = 'external_source_term'"
        );
    }
};
