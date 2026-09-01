<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - vinculo empresa <-> categoria SupplHi (el "tilde" simple de categoria, sin descripcion/
 * marca/capacidad - eso vive en empresa_catalog_items, ver esa migracion). Solo se guarda el nodo
 * HOJA que la empresa elige (PDF punto 4: "La empresa queda vinculada UNICAMENTE al nodo que
 * eligio, y el sistema resuelve el resto del arbol automaticamente") - nunca sus ancestros; el
 * breadcrumb/reporte "empresas por Grupo" se resuelve en lectura via `path`/`parent_id`.
 *
 * FK REAL a `empresas.id`: posible recien en esta fase porque `empresas` ya vive en `pgsql` desde
 * la Fase 1 (antes, con empresas todavia en mysql y esta tabla en pgsql, Laravel no soporta FKs
 * entre conexiones distintas - ver Fase 3 del plan).
 *
 * `origen`: como se origino el vinculo (self_declared = la empresa lo eligio ella misma;
 * suggested = el sistema se lo sugirio pero no confirmado; validated = un admin de la Camara lo
 * revizo y aprobo) - ver punto pendiente del PDF sobre "quien en la Camara aprueba cada
 * categoria/producto antes de que quede visible en el buscador".
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('empresa_supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('supplier_categories')->cascadeOnDelete();
            $table->string('origen')->default('self_declared');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'category_id']);
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('empresa_supplier_categories');
    }
};
