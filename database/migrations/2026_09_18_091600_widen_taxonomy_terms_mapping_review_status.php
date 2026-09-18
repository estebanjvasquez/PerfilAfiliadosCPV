<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2->V3: `mapping_review_status VARCHAR(30)` (migración original de TAXV2-1) alcanzaba para los
 * valores de V2 (candidate/auto_mapped/needs_review/approved/rejected/deprecated/unmapped, todos
 * ≤13 caracteres). El JSON V3 introduce `source_verified_needs_cpv_mapping` (34 caracteres) para los
 * 52 términos nuevos descubiertos por fuente - se ensancha a VARCHAR(50) con margen para futuros
 * valores similares.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_terms ALTER COLUMN mapping_review_status TYPE VARCHAR(50)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE taxonomy_terms ALTER COLUMN mapping_review_status TYPE VARCHAR(30)'
        );
    }
};
