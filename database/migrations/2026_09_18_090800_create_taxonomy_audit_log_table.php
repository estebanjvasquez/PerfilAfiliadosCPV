<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-9 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 9 y el plan
 * de esta fase): auditoría del diccionario V2 - tabla propia, no `spatie/laravel-activitylog` (ese
 * paquete registra un diff completo de `save()`, no una fila por CAMPO con `reason`, y sumaría una
 * dependencia/patrón que el resto del proyecto no usa - ver "Diseño transversal" del plan).
 *
 * `reason` es NULLABLE: la sección 9 exige que el REGISTRO sea obligatorio para pesos/relaciones/
 * alias/códigos/fuentes/thresholds/aprobación-rechazo/config-crawler (eso se cumple siempre, en
 * cada punto de la app que llama a `TaxonomyAuditLogger::record()`), pero exigir que el
 * ADMINISTRADOR ESCRIBA un motivo en cada edición de borrador (ej. ajustar un peso mientras
 * calibra) haría impracticable la iteración que pide la sección 4.9 - por eso el motivo solo es
 * obligatorio en la transición a aprobado/rechazado de una relación (decisión ya tomada con el
 * usuario, ver plan de esta fase), reforzado en código (`required()` en el form de esa acción
 * puntual), no a nivel de columna.
 *
 * `taxonomy_version_id` nullable y sin FK todavía - lo usa TAXV2-11 (versionado) para relacionar
 * un revert con la versión a la que se vuelve; se deja la columna ya creada para no tener que
 * alterar esta tabla otra vez en esa fase.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_audit_log (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NULL,
                entity_type VARCHAR(255) NOT NULL,
                entity_id VARCHAR(50) NOT NULL,
                field VARCHAR(100) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                reason TEXT NULL,
                taxonomy_version_id BIGINT NULL,
                created_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_audit_log_entity_idx ON taxonomy_audit_log (entity_type, entity_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_audit_log_created_at_idx ON taxonomy_audit_log (created_at)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_audit_log');
    }
};
