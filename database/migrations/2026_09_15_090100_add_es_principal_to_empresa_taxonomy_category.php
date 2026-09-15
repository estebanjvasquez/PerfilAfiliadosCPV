<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 del proyecto de taxonomía: distingue si una categoría vinculada a una empresa es
 * "principal" (lo que la empresa realmente hace, se destaca primero en el buscador/perfil) o
 * "secundaria" (servicio relacionado u ocasional) - campo que faltaba en `empresa_taxonomy_category`
 * (ver docs/taxonomia/analisis_taxonomia_supplyhigh.md sección 5, "Pendiente real que queda
 * abierto"). Default `false` (secundaria) - las 756 filas `suggested` que dejó Fase 3 no traen esa
 * distinción todavía, quedan como secundarias hasta que la empresa las confirme/reclasifique desde
 * el buscador nuevo.
 *
 * `ALTER TABLE` explícito en vez de `Schema::table()` con Blueprint, mismo criterio ya usado en
 * `2026_09_12_120000_create_service_taxonomy_homologacion_table.php`: la migración `CREATE TABLE`
 * original de `empresa_taxonomy_category` (antes `empresa_supplier_categories`) no existe en esta
 * rama - solo el rename (`database/migrations/pgsql/2026_09_07_090000_...`) asume que ya existe.
 * La tabla SÍ existe en el Supabase real (creada desde otra rama/sesión) - no bloquea, pero un
 * `Schema::table()` normal fallaría igual si algún día se corre esto contra una base realmente
 * vacía sin esa tabla.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE empresa_taxonomy_category ADD COLUMN es_principal BOOLEAN NOT NULL DEFAULT FALSE'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE empresa_taxonomy_category DROP COLUMN IF EXISTS es_principal'
        );
    }
};
