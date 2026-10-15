<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0010C (Issue #2 comentario `6187768951`): operación de las solicitudes de revisión de
 * categoría - historial de avisos, reintento manual y transporte honesto.
 *
 * ADITIVA. No reescribe ninguna solicitud existente: ni su contenido, ni su empresa/usuario/contexto,
 * ni su estado de aviso. En staging ya existe la solicitud #1 (registrada con
 * `recipient_not_configured`) y tiene que seguir exactamente igual.
 *
 * Lo que agrega:
 *
 * 1. `delivery_status` admite un cuarto valor, `simulated`: el transporte de correo configurado no
 *    envía hacia afuera (`log`, `array`). Antes eso quedaba como `sent`, que el panel y el usuario
 *    leían como "enviado a la Cámara" sin que nada saliera del servidor. Los tres valores previos
 *    conservan su significado; `sent` pasa a leerse como "aceptado por el servidor de correo", que
 *    es lo único que un envío exitoso garantiza (no confirma la llegada al buzón).
 *
 * 2. Columnas en la solicitud:
 *    - `delivery_transport`: modo de transporte del ÚLTIMO intento (`smtp`, `log`, ...). Nunca
 *      host, usuario ni credenciales.
 *    - `delivery_claim_token` / `delivery_claimed_at`: reserva atómica del intento en curso, para
 *      que dos reintentos simultáneos no envíen dos avisos. La reserva es un UPDATE condicional de
 *      una sola sentencia; el envío por red ocurre FUERA de cualquier transacción o bloqueo. Si el
 *      proceso muere a mitad de camino, la reserva vence sola (ver el servicio) y la solicitud
 *      vuelve a poder reintentarse.
 *
 * 3. Tabla `taxonomy_category_request_delivery_attempts`: un registro por intento (inicial o
 *    reintento manual) con número, actor, destinatario, transporte, resultado, código de error y
 *    tiempos. Es la auditoría acotada que pide la instrucción.
 *
 *    Las solicitudes ya evaluadas antes de esta migración reciben UNA fila `historical` derivada de
 *    lo que ya tenían guardado (estado, código, destinatario, fechas), con `transport` NULL porque
 *    en ese momento no se registraba. Es una inserción en la tabla nueva: la solicitud en sí no se
 *    toca.
 *
 * `down()` deshace todo, y se niega si ya hay solicitudes en `simulated`, porque volver a la
 * restricción vieja exigiría reescribirlas y eso no se decide en un rollback.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $db = DB::connection('pgsql');

        $db->statement('ALTER TABLE taxonomy_category_requests ADD COLUMN IF NOT EXISTS delivery_transport VARCHAR(30) NULL');
        $db->statement('ALTER TABLE taxonomy_category_requests ADD COLUMN IF NOT EXISTS delivery_claim_token VARCHAR(36) NULL');
        $db->statement('ALTER TABLE taxonomy_category_requests ADD COLUMN IF NOT EXISTS delivery_claimed_at TIMESTAMP NULL');

        $db->statement('ALTER TABLE taxonomy_category_requests DROP CONSTRAINT IF EXISTS taxonomy_category_requests_delivery_status_check');
        $db->statement(<<<'SQL'
            ALTER TABLE taxonomy_category_requests
                ADD CONSTRAINT taxonomy_category_requests_delivery_status_check
                CHECK (delivery_status IN ('pending', 'sent', 'failed', 'simulated'))
        SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS taxonomy_category_request_delivery_attempts (
                id BIGSERIAL PRIMARY KEY,
                request_id BIGINT NOT NULL REFERENCES taxonomy_category_requests(id) ON DELETE CASCADE,
                attempt_number INTEGER NOT NULL,
                kind VARCHAR(20) NOT NULL,
                actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                recipient_email VARCHAR(255) NULL,
                transport VARCHAR(30) NULL,
                result VARCHAR(20) NOT NULL,
                error_code VARCHAR(40) NULL,
                started_at TIMESTAMP NOT NULL,
                finished_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT tcr_delivery_attempts_number_unique UNIQUE (request_id, attempt_number),
                CONSTRAINT tcr_delivery_attempts_kind_check CHECK (kind IN ('initial', 'manual_retry', 'historical')),
                CONSTRAINT tcr_delivery_attempts_result_check CHECK (result IN ('sent', 'simulated', 'failed'))
            )
        SQL);
        $db->statement('CREATE INDEX IF NOT EXISTS tcr_delivery_attempts_request_idx ON taxonomy_category_request_delivery_attempts (request_id)');

        // Historial de lo ya evaluado: SOLO inserta en la tabla nueva, nunca actualiza la solicitud.
        $db->statement(<<<'SQL'
            INSERT INTO taxonomy_category_request_delivery_attempts
                (request_id, attempt_number, kind, actor_user_id, recipient_email, transport, result,
                 error_code, started_at, finished_at, created_at, updated_at)
            SELECT r.id, 1, 'historical', r.user_id, r.recipient_email, NULL, r.delivery_status,
                   r.delivery_error, r.submitted_at, COALESCE(r.mail_sent_at, r.updated_at, r.submitted_at),
                   now(), now()
            FROM taxonomy_category_requests r
            WHERE r.delivery_status IN ('sent', 'failed')
              AND NOT EXISTS (
                  SELECT 1 FROM taxonomy_category_request_delivery_attempts a WHERE a.request_id = r.id
              )
        SQL);
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');

        $simuladas = (int) $db->selectOne("SELECT count(*) AS c FROM taxonomy_category_requests WHERE delivery_status = 'simulated'")->c;
        if ($simuladas > 0) {
            throw new RuntimeException(
                "Hay {$simuladas} solicitud(es) con aviso 'simulated'. Revertir exigiría reescribir su estado, "
                .'y eso no se decide en un rollback: revisarlas a mano antes de deshacer esta migración.'
            );
        }

        $db->statement('DROP TABLE IF EXISTS taxonomy_category_request_delivery_attempts');
        $db->statement('ALTER TABLE taxonomy_category_requests DROP CONSTRAINT IF EXISTS taxonomy_category_requests_delivery_status_check');
        $db->statement(<<<'SQL'
            ALTER TABLE taxonomy_category_requests
                ADD CONSTRAINT taxonomy_category_requests_delivery_status_check
                CHECK (delivery_status IN ('pending', 'sent', 'failed'))
        SQL);
        $db->statement('ALTER TABLE taxonomy_category_requests DROP COLUMN IF EXISTS delivery_claimed_at');
        $db->statement('ALTER TABLE taxonomy_category_requests DROP COLUMN IF EXISTS delivery_claim_token');
        $db->statement('ALTER TABLE taxonomy_category_requests DROP COLUMN IF EXISTS delivery_transport');
    }
};
