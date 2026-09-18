<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-6 (ajuste V3 punto 15): `company_pages` guardaba `content_hash` pero no el texto en sí -
 * suficiente para DETECTAR que algo cambió, pero no para volver a compararlo contra términos
 * nuevos sin recrawlear. `visible_text` guarda el mismo texto que `PageTermMatcher` ya usa (post
 * `HtmlPageExtractor::extractVisibleText()`, no el HTML crudo - más chico, ya es exactamente el
 * insumo que hace falta).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE company_pages ADD COLUMN visible_text TEXT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE company_pages DROP COLUMN IF EXISTS visible_text'
        );
    }
};
