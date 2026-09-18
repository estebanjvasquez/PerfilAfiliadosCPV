<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-1 (requisito 17 del ajuste V3: distinguir decisiones automáticas de humanas en la
 * auditoría). `actor_type` default `'user'` NOT NULL - todas las 5+ fases previas que ya escriben
 * en `taxonomy_audit_log` (TaxonomyAuditLogger, ManagesTaxonomyRelationReview, LogsAuditOnSave, etc.)
 * siguen escribiendo sin cambios y quedan correctamente clasificadas como `user` sin tocar una sola
 * línea de código existente. `algorithm_version` nullable - solo lo llenan las fases nuevas
 * (TAXV3-3 Auto Mapper, TAXV3-5 Source Sync) que SÍ generan decisiones automáticas.
 *
 * Se hace en TAXV3-1 (primera sub-fase), no al final: el Auto Mapper y el Source Sync necesitan
 * poder escribir auditoría etiquetada desde su primer commit - agregarlo después hubiera forzado un
 * backfill de historial ya escrito sin esta distinción.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_audit_log
                ADD COLUMN actor_type VARCHAR(10) NOT NULL DEFAULT 'user',
                ADD COLUMN algorithm_version VARCHAR(50) NULL
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_audit_log_actor_type_idx ON taxonomy_audit_log (actor_type)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_audit_log
                DROP COLUMN IF EXISTS actor_type,
                DROP COLUMN IF EXISTS algorithm_version
        SQL);
    }
};
