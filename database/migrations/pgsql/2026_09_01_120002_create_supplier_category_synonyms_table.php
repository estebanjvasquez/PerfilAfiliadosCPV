<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - sinonimos/terminos alternativos por categoria (PDF punto 3: "incluyendo terminos
 * comerciales venezolanos que no son ni traduccion ni sinonimo formal de SupplHi"). Alimenta el
 * buscador hibrido de Fase 5 (lexico + semantico) - un termino local ("tubo" para "Pipe") permite
 * que el buscador matchee aunque el termino no aparezca en ninguna traduccion oficial.
 *
 * `locale` nullable: un sinonimo puede ser especifico de un idioma, o generico/no dependiente del
 * idioma (ej. una sigla o codigo comercial usado igual en ambos).
 * `origen`: de donde vino el sinonimo (ej. 'supplhi', 'local_venezuela', 'manual') - para poder
 * auditar/filtrar mas adelante sin perder de donde salio cada termino.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('supplier_category_synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('supplier_categories')->cascadeOnDelete();
            $table->string('locale', 5)->nullable();
            $table->string('term');
            $table->string('origen');
            $table->timestamps();

            $table->index('category_id');
            $table->index('term');
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('supplier_category_synonyms');
    }
};
