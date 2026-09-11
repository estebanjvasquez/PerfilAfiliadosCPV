<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-1 (ver docs/taxonomia/plan_mcp_cira.md): embeddings de la taxonomia CPV para el
 * componente semantico del buscador hibrido, separados de la tabla canonica (permite
 * regenerar/cambiar de modelo sin tocar los datos maestros - mismo diseno ya previsto en
 * analisis_taxonomia_supplyhigh.md seccion 3).
 *
 * Se pone en el nivel TOP de database/migrations/ (no en database/migrations/pgsql/) a
 * proposito, con `$connection = 'pgsql'` en la clase: asi la recoge el `php artisan migrate
 * --force` normal del pipeline de deploy (Contabo) sin flags extra. El subdirectorio pgsql/ de
 * este repo son migraciones YA corridas una vez con un path explicito, archivadas ahi para no
 * volver a intentarse solas - no aplica a migraciones nuevas que sí deben correr en el deploy
 * normal.
 *
 * Dimension del vector (1024): verificada empiricamente contra el modelo real
 * (@cf/baai/bge-m3 en el Worker perfilafiliados-mcp), no asumida de la documentacion de
 * Cloudflare (que no la especifica) - ver README de ese repo.
 *
 * Gotcha real encontrado al correr esta migracion (11 sep 2026): `$connection = 'pgsql'` hace
 * que el `up()` de ESTA clase corra contra pgsql, pero el BOOKKEEPING de "esta migracion ya se
 * corrio" lo escribe el Migrator en la tabla `migrations` de la conexion POR DEFECTO del comando
 * (`mysql` en este proyecto, no pgsql) - dos tablas `migrations` fisicamente distintas. Si se
 * corre `php artisan migrate` local (default mysql) contra el mismo Supabase que usa Contabo, la
 * fila de bookkeeping queda en la `migrations` de mysql, NO en la de pgsql - el siguiente
 * `migrate --force` de un entorno con `DB_CONNECTION=pgsql` (Contabo) no la ve como corrida e
 * intenta crear la tabla de nuevo -> `SQLSTATE[42P07] Duplicate table` (pasó en vivo en el
 * deploy de este mismo commit). Fix aplicado a mano una vez: insertar la fila correspondiente
 * en `DB::connection('pgsql')->table('migrations')` (mismo patron que
 * `SkipMysqlOnlyViewMigrationsOnPgsql`). Para la PROXIMA migracion con `$connection = 'pgsql'`:
 * correrla ya sea con `--database=pgsql`, o replicar ese insert manual una vez contra pgsql
 * despues de correrla localmente.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        // pgvector ya esta habilitado en este proyecto de Supabase (confirmado 9 sep 2026,
        // `select extname from pg_extension where extname = 'vector'`) - CREATE EXTENSION IF NOT
        // EXISTS de todos modos, para que esta migracion sea autosuficiente si algun dia corre
        // contra un proyecto de Supabase nuevo (ej. produccion, al momento del corte final).
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_category_embeddings (
                id BIGSERIAL PRIMARY KEY,
                category_id BIGINT NOT NULL REFERENCES taxonomy_categories(id) ON DELETE CASCADE,
                embedding vector(1024) NOT NULL,
                model_version VARCHAR(255) NOT NULL,
                source_text TEXT NOT NULL,
                generated_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (category_id)
            )
        SQL);

        // Indice HNSW para similaridad coseno - el dataset es chico (3.483 nodos), pero esto es
        // exactamente el mismo patron que va a usar despues el buscador semantico de empresas
        // (Fase 4/6 del plan de taxonomia), asi que se deja bien armado desde ahora.
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_category_embeddings_hnsw ON taxonomy_category_embeddings USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_category_embeddings');
    }
};
