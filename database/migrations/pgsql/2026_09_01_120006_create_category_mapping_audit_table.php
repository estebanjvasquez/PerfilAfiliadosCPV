<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - bitacora de cada corrida del importador de la taxonomia SupplHi (PDF punto 1: "proceso
 * de importacion repetible... asi se puede volver a correr cuando SupplHi actualice su
 * clasificacion, sin duplicar ni perder categorias ya vinculadas a empresas"). Registra que paso
 * con CADA fila del archivo fuente en cada corrida, no solo el resultado final.
 *
 * `category_id` nullable a proposito: si una fila del archivo fuente tiene un error (codigo
 * duplicado, parent_id que no matchea ningun codigo importado, etc.) nunca llega a crear/actualizar
 * una categoria - se registra igual el intento fallido (con `supplhi_code` crudo del archivo, que
 * SI se conserva siempre) para poder diagnosticar el archivo fuente sin perder el rastro de qué
 * fila fallo y por que.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('category_mapping_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('supplier_categories')->nullOnDelete();
            $table->string('supplhi_code');
            $table->string('version');
            $table->string('action');
            $table->text('details')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->index('supplhi_code');
            $table->index('version');
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('category_mapping_audit');
    }
};
