<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase MCP-5.1 (ver docs/taxonomia/plan_mcp_cira.md): sincroniza el módulo "Gestión" del perfil de
 * afiliado (`management`, MySQL producción, 100 filas) contra Supabase (pgsql) para que
 * `search_empresas` pueda filtrar por certificación - hoy CIRA no tiene forma de responder
 * "empresas con ISO 9001". Espejo 1 a 1 de las columnas booleanas reales de `management` (no se
 * inventan certificaciones nuevas). `otras_certificaciones` concatena en texto libre las 6 columnas
 * "_otros_name" de `management` (ej. "SISTEMA DE GESTIÓN AMBIENTAL PROPIO") - 38 empresas reales
 * tienen contenido ahí, vale la pena no perderlo aunque no sea una ISO reconocida.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('empresa_certifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->unique();
            $table->boolean('iso9001')->default(false);
            $table->boolean('iso14001')->default(false);
            $table->boolean('iso45001')->default(false);
            $table->boolean('iso27001')->default(false);
            $table->boolean('iso50001')->default(false);
            $table->boolean('iso17025')->default(false);
            $table->boolean('iso37001')->default(false);
            $table->boolean('dun')->default(false);
            $table->boolean('ovid')->default(false);
            $table->boolean('pmi')->default(false);
            $table->text('otras_certificaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('empresa_certifications');
    }
};
