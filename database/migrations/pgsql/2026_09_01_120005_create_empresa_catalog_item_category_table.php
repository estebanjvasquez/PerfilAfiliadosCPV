<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - pivote muchos-a-muchos entre empresa_catalog_items y supplier_categories. FKs de
 * pivote explicitas en ambos lados (evita el bug ya documentado de Sector::empresas(), que usa
 * belongsToMany sin FKs explicitas sobre una tabla pivote que ademas no tiene la columna
 * esperada - ver Empresa.php/Sector.php).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('empresa_catalog_item_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('empresa_catalog_items')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('supplier_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['catalog_item_id', 'category_id']);
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('empresa_catalog_item_category');
    }
};
