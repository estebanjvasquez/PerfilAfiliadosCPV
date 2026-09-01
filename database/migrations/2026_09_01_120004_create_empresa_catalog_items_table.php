<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - producto/servicio concreto que una empresa publica en el catalogo (PDF punto 4, paso 4:
 * "Descripcion, marcas, capacidades, ubicacion y palabras clave complementarias" - analogia
 * explicita del documento con un marketplace: se listan PRODUCTOS, no solo se tildan categorias).
 * Una empresa puede publicar varios items distintos, y cada item puede etiquetarse con 1 o mas
 * categorias SupplHi (ver empresa_catalog_item_category, pivote muchos-a-muchos) - separado de
 * empresa_supplier_categories (que sigue siendo el "tilde" simple, sin estos detalles).
 *
 * `keywords` es texto libre separado por comas (no un array/jsonb) a proposito: son palabras clave
 * COMPLEMENTARIAS de busqueda en lenguaje libre (PDF punto 4), no una lista estructurada con
 * validacion - el buscador hibrido de Fase 5 las indexa igual que la descripcion (tsvector), no
 * hace falta la rigidez de un array tipado para eso.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('empresa_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('capacity')->nullable();
            $table->string('location')->nullable();
            $table->text('keywords')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('empresa_catalog_items');
    }
};
