<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase B.1 (sección 9 del pedido): protección mínima contra "stale dry-run" - un revisor humano
 * aprobando un candidato calculado contra un estado del grafo de conceptos que ya cambió. En vez de
 * inventar un mecanismo nuevo, reutiliza `CanonicalConceptBuilderService::tableFingerprint()`
 * (TAXV3.1, ya existente) sobre `taxonomy_canonical_concepts` + `taxonomy_term_concepts` - ver
 * `CanonicalConceptBuilderService::conceptGraphFingerprint()`.
 *
 * Columna NULLABLE a propósito: ningún proceso puebla `taxonomy_candidate_concept_links` todavía
 * (Phase C/`--apply` no existe - 0 filas en producción hoy), así que esta migración es aditiva y
 * segura sobre una tabla vacía. Queda como el "contrato mínimo" que un futuro `--apply` (Phase C)
 * debe respetar: estampar el fingerprint del grafo en el momento en que genera cada candidato. La UI
 * de revisión (Phase B.1) ya sabe leerla: si es NULL, se muestra como "no rastreado" (candidato
 * pre-Phase-C), nunca como una falsa alarma de "obsoleto".
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            "ALTER TABLE taxonomy_candidate_concept_links ADD COLUMN taxonomy_state_fingerprint VARCHAR(64) NULL"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_candidate_concept_links DROP COLUMN IF EXISTS taxonomy_state_fingerprint'
        );
    }
};
