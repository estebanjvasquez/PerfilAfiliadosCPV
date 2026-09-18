<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-7 (Fase A, ver docs/taxonomia/plan_mcp_cira.md): documento unificado por empresa para el
 * buscador híbrido - concatena TODO lo que ya existe repartido en 6 niveles (nombre, sector,
 * servicios, taxonomía CPV, certificaciones, sostenibilidad, experiencias) en un solo texto por
 * empresa, con su `tsvector` para full-text nativo de Postgres (stemming real en español, sin
 * depender de que un LLM adivine la raíz de una palabra - la causa de las colisiones represas/
 * repres de Fases MCP-4.8/5.4/6.1).
 *
 * NO se modifica la tabla `empresas` (pertenece al dominio de Filament/Laravel) - esta tabla es
 * puramente derivada, se recalcula por completo con el comando `empresas:build-search-documents`
 * cada vez que cambie algo relevante (mismo criterio que `service_embeddings`/`taxonomy_category_
 * embeddings`: dato derivado, no fuente de verdad).
 *
 * `search_vector` se calcula EXPLÍCITAMENTE en el comando (`to_tsvector('spanish', unaccent(...))`),
 * no como columna `GENERATED ALWAYS AS` - `unaccent()` es STABLE, no IMMUTABLE, y Postgres exige
 * inmutabilidad en la expresión de una columna generada (el workaround típico es una función wrapper
 * marcada IMMUTABLE, pero es más simple calcularlo a mano en el mismo comando que ya escribe
 * `document`, igual que ya se hace con las columnas de embedding de este proyecto).
 *
 * `IF NOT EXISTS` (TAXV3-7, incidente 18 sep 2026): esta migración se había corrido una vez desde un
 * entorno con `DB_CONNECTION=mysql` por default - la tabla quedó creada en el pgsql compartido, pero
 * el registro de "ya corrió" se guardó en la tabla `migrations` de MYSQL (bookkeeping sigue la
 * conexión default, no el `$connection` de la migración - mismo gotcha ya documentado para
 * Spatie Permission). Al desplegar en el servidor (`DB_CONNECTION=pgsql` ahí) `artisan migrate` no
 * la veía como aplicada e intentó crear la tabla de nuevo, sin la app de por medio, se planto en
 * mantenimiento (`artisan down` sin el `up` posterior). Idempotente de acá en más para que cualquier
 * entorno que la corra por primera vez la registre sin fallar aunque la tabla ya exista.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS empresa_search_documents (
                empresa_id BIGINT PRIMARY KEY REFERENCES empresas(id) ON DELETE CASCADE,
                document TEXT NOT NULL,
                search_vector TSVECTOR NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX IF NOT EXISTS empresa_search_documents_gin ON empresa_search_documents USING gin (search_vector)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS empresa_search_documents');
    }
};
