<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - traducciones por idioma de cada categoria SupplHi (PDF punto 3: "una sola estructura
 * canonica identificada por el codigo de SupplHi, con las traducciones asociadas por idioma").
 * Unico (category_id, locale): una traduccion por idioma por categoria.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('supplier_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('supplier_categories')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('supplier_category_translations');
    }
};
