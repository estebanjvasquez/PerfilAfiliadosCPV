<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-2 (ajuste V3 puntos 5/6/20): separa TÉRMINO de CONCEPTO CANÓNICO. Un regionalismo
 * (`mechurrio`, `arbolito`, `guaya fina`) puede seguir siendo `curated` indefinidamente sin
 * requerir revisión manual, siempre que esté vinculado al mismo concepto que su equivalente
 * verificado externamente (`flare`, `wellhead`, `slickline`) vía `taxonomy_term_concepts`.
 *
 * `canonical_name_en`/`canonical_name_es` son best-effort (uno de los dos puede quedar null si el
 * concepto todavía no tiene un término verificado en ese idioma) - unir EN/ES del mismo concepto
 * real requiere evidencia de traducción real (Full Discovery bilingüe, fuera de esta fase) y NO se
 * adivina por similitud de texto (español e inglés casi nunca comparten letras suficientes para
 * `pg_trgm`).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_canonical_concepts (
                id BIGSERIAL PRIMARY KEY,
                canonical_name_en VARCHAR(255) NULL,
                canonical_name_es VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_canonical_concepts_name_en_idx ON taxonomy_canonical_concepts (canonical_name_en)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_canonical_concepts_name_es_idx ON taxonomy_canonical_concepts (canonical_name_es)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_canonical_concepts');
    }
};
