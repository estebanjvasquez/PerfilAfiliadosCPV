<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-3 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.7 y el plan
 * de esta fase): espejo en pgsql del catálogo legacy `services` (mysql, 112 filas, producción real,
 * de solo lectura desde el commit 8352cad) - necesario porque `services` no puede tener relaciones
 * Eloquent reales hacia tablas nuevas de pgsql (Postgres no valida FKs cross-connection).
 *
 * `id` se inserta IGUAL al `id` de `services` en mysql (no un id propio autoincremental) - mismo
 * criterio ya usado en este proyecto para `EmpresaPgsql`/`UserPgsql`: mismo id entre conexiones,
 * evita una columna `service_id` redundante y hace trivial cualquier join/razonamiento cross-
 * connection futuro. Se llena desde el JOIN vivo `services`+`sectors` de mysql (fuente de verdad),
 * NO desde `legacy_services[]` del JSON V2 (que coincide 1:1 pero es una foto vieja, no la fuente
 * viva) - ver `taxonomy:import-legacy-service-relations`.
 *
 * `status` (`legacy_active`/`deprecated`/`disabled`, sección 4.7) es la única columna
 * administrable desde el panel (TAXV2-7) - el importador nunca la pisa en una re-corrida (ver
 * comando: el upsert excluye `status` de las columnas actualizadas).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE legacy_services (
                id BIGINT PRIMARY KEY,
                sectors_id BIGINT NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'legacy_active',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS legacy_services');
    }
};
