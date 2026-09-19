<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 2/13): vocabulario GOBERNADO y GENÉRICO de marcadores de INTENCIÓN de negocio
 * (mantenimiento, alquiler, inspección, fabricación, instalación, reparación, suministro, transporte,
 * ingeniería - exactamente la lista de ejemplos GENÉRICOS de la sección 2 del pedido, ninguno
 * específico de oilfield) usado por `IntentContaminationDetector` para detectar estructuralmente
 * cuándo un término de un concepto es en realidad [INTENT + SUBJECT] en vez de una expresión pura
 * del SUBJECT.
 *
 * Importante: esto NO es "hardcoding por ejemplo" (regla del punto 12) - son las categorías de
 * intención de negocio que el propio pedido pide tratar como clase general (sección 2: "Implementa
 * una detección general de contaminación SUBJECT/INTENT... NO implementes reglas especiales para
 * estos ejemplos concretos"). El detector nunca compara contra "artificial lift" o "cabria" - compara
 * estructuralmente cualquier par de términos de un cluster contra ESTA lista de marcadores,
 * administrable sin deploy de código.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_intent_markers (
                id BIGSERIAL PRIMARY KEY,
                marker_es VARCHAR(100) NULL,
                marker_en VARCHAR(100) NULL,
                marker_type VARCHAR(50) NOT NULL DEFAULT 'operation_intent',
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (marker_es, marker_en)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_intent_markers_active_idx ON taxonomy_intent_markers (active)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_intent_markers');
    }
};
