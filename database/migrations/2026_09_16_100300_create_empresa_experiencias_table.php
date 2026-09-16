<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase MCP-5.3 (ver docs/taxonomia/plan_mcp_cira.md): aplana el módulo "Experiencias" del perfil de
 * afiliado (`experiences` en MySQL, 95 filas - cada fila es un JSON con un arreglo de proyectos
 * ejecutados) en una fila por proyecto, para poder indexarla y buscarla (léxico + semántico, igual
 * que `taxonomy_categories`/`services`). El JSON de origen mezcla `exp_year` (nombre de columna) con
 * el resto de los campos del proyecto (`Descripcion`, `sectors_id`, `services_id`, etc.) - acá se
 * extrae solo lo que sirve para buscar: la descripción libre del proyecto y su año. El resto
 * (magnitud, mano de obra, infra*_id) no aporta a la búsqueda y se deja fuera para no cargar
 * complejidad sin uso real.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('empresa_experiencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id');
            $table->text('descripcion');
            $table->string('exp_year', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('empresa_experiencias');
    }
};
