<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-1: alias planos de cada término (`records[].aliases[]` del JSON V2 - strings sueltos, sin
 * peso propio, ej. "oil" -> ["black gold"], "drill bit" -> [...]). Tabla separada en vez de un
 * array en `taxonomy_terms` (pedido de la sección 11), a diferencia de `region`/`negative_context`
 * que sí quedan como array nativo en `taxonomy_terms` - la diferencia es que un alias es una
 * entidad buscable por sí sola (se filtra/edita individualmente desde el admin, sección 4.5), no
 * solo metadata del término.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_aliases (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                alias VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (term_id, alias)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_aliases_term_id_idx ON taxonomy_term_aliases (term_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_aliases');
    }
};
