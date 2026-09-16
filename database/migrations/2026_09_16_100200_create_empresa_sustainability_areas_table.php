<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase MCP-5.2 (ver docs/taxonomia/plan_mcp_cira.md): pivote empresa<->área de sostenibilidad,
 * espejo de `sustainabilities` en MySQL (283 filas / 97 empresas distintas). */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('empresa_sustainability_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('area_id');
            $table->timestamps();
            $table->unique(['empresa_id', 'area_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('empresa_sustainability_areas');
    }
};
