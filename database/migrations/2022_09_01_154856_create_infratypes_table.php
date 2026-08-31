<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('infratypes', function (Blueprint $table) {
            $table->id();
            // FK agregada en 2026_08_31_.. add_deferred_foreign_keys_out_of_order_tables (esta
            // migracion es anterior a create_infrasectors_table por fecha de archivo).
            $table->foreignId('infrasectors_id');
            $table->string('type_name', 100);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('infratypes');
    }
};
