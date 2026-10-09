<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0010A (Issue #2 comentario `6079967780`): solicitud gobernada de revisión de categoría CPV.
 *
 * Cuando una empresa no encuentra la categoría adecuada en "Buscar y agregar categoría"
 * (TaxonomyCategoriesRelationManager), registra una SOLICITUD DE REVISIÓN para la Cámara - nunca
 * crea una categoría. Esta tabla es el registro auditable de esas solicitudes; el correo al
 * responsable es solo una notificación encima de este registro (si el correo falla, la fila queda
 * igual, con `delivery_status = failed`).
 *
 * Además agrega a `taxonomy_selection_settings` (singleton existente) el correo del responsable de
 * la Cámara, configurable por Super Admin en TaxonomySelectionSettingsPage - nunca hardcodeado.
 *
 * Migración ADITIVA y reversible: `IF NOT EXISTS` en el `up()` (idempotente - los tests la aplican
 * dentro de su propia transacción sin depender de que ya esté corrida) y `down()` revierte las dos
 * piezas por completo. No toca ningún dato existente.
 *
 * `empresa_id` ON DELETE CASCADE: mismo criterio que `empresa_module_status` - si no,
 * `Empresa::deleteWithDependencies()` fallaría por FK al borrar una empresa con solicitudes.
 * `user_id` ON DELETE SET NULL: la solicitud (y su snapshot de contexto) sobrevive al usuario.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $db = DB::connection('pgsql');

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS taxonomy_category_requests (
                id BIGSERIAL PRIMARY KEY,
                empresa_id BIGINT NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
                user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                necesidad TEXT NOT NULL,
                justificacion VARCHAR(40) NOT NULL,
                detalle TEXT NOT NULL,
                terminos_probados TEXT NULL,
                context_snapshot JSONB NOT NULL DEFAULT '{}',
                request_status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
                delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                delivery_error VARCHAR(255) NULL,
                recipient_email VARCHAR(255) NULL,
                submitted_at TIMESTAMP NOT NULL,
                mail_sent_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT taxonomy_category_requests_justificacion_check CHECK (justificacion IN (
                    'NO_ENCUENTRO_CATEGORIA', 'DEMASIADO_GENERAL', 'OTRO_NOMBRE',
                    'NO_ESTOY_SEGURO', 'CAPACIDAD_ESPECIALIZADA', 'OTRO'
                )),
                CONSTRAINT taxonomy_category_requests_delivery_status_check CHECK (
                    delivery_status IN ('pending', 'sent', 'failed')
                )
            )
        SQL);

        $db->statement('CREATE INDEX IF NOT EXISTS taxonomy_category_requests_empresa_idx ON taxonomy_category_requests (empresa_id)');
        $db->statement('CREATE INDEX IF NOT EXISTS taxonomy_category_requests_status_idx ON taxonomy_category_requests (request_status)');

        $db->statement('ALTER TABLE taxonomy_selection_settings ADD COLUMN IF NOT EXISTS category_request_recipient_email VARCHAR(255) NULL');
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');

        $db->statement('ALTER TABLE taxonomy_selection_settings DROP COLUMN IF EXISTS category_request_recipient_email');
        $db->statement('DROP TABLE IF EXISTS taxonomy_category_requests');
    }
};
