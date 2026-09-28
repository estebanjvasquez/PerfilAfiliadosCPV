<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0003, hallazgo 3 (concurrencia): `CanonicalConceptApplyService::apply()` chequeaba
 * `exists()` y después insertaba - un TOCTOU real, sin nada en el schema que lo impidiera (el
 * propio docblock de esa clase ya lo admitía: "taxonomy_candidate_concept_links NO tiene índice
 * único"). Dos corridas de `--apply` concurrentes podían pasar el chequeo las dos y duplicar la
 * cola.
 *
 * La semántica de idempotencia YA vigente en el código (antes de esta migración) es "como mucho
 * UNA fila para siempre por (term_id, concept_id), sin importar en qué estado esté" - un
 * `rejected` previo bloquea un nuevo intento a propósito (decisión humana explícita, no se repite
 * sola). Eso es exactamente lo que un índice único parcial expresa a nivel de base de datos, sin
 * borrar ni tocar ninguna fila existente:
 *
 *   - `suggested_concept_id` NOT NULL (candidato término->concepto existente): único por
 *     (suggested_term_id, suggested_concept_id).
 *   - `suggested_concept_id` NULL (propuesta de concepto NUEVO): único por suggested_term_id -
 *     Postgres no considera dos NULL iguales en un índice compuesto normal, por eso hace falta un
 *     índice parcial (`WHERE suggested_concept_id IS NULL`) en vez de una sola UNIQUE compuesta.
 *
 * Con esto, `CanonicalConceptApplyService::apply()` pasa de "exists() + create()" a
 * `insertOrIgnore()` (compila a `INSERT ... ON CONFLICT DO NOTHING` en Postgres) - la base decide
 * atómicamente, no una lectura-luego-escritura de la aplicación.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX taxonomy_candidate_concept_links_term_concept_uniq '.
            'ON taxonomy_candidate_concept_links (suggested_term_id, suggested_concept_id) '.
            'WHERE suggested_concept_id IS NOT NULL'
        );

        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX taxonomy_candidate_concept_links_new_concept_term_uniq '.
            'ON taxonomy_candidate_concept_links (suggested_term_id) '.
            'WHERE suggested_concept_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS taxonomy_candidate_concept_links_term_concept_uniq');
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS taxonomy_candidate_concept_links_new_concept_term_uniq');
    }
};
