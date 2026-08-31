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
        Schema::create('infrafacilities', function (Blueprint $table) {
            $table->id();
            // FK agregada en 2026_08_31_.. add_deferred_foreign_keys_out_of_order_tables (esta
            // migracion es anterior a create_infraregion_infrasystem_table por fecha de archivo).
            $table->foreignId('infraregion_infrasystem_id');
            //$table->foreignId('infrasystems_id')->references('infrasystem_id')->on('infraregion_infrasystem');
            //$table->foreignId('infraregions_id')->references('infraregion_id')->on('infraregion_infrasystem');
            $table->string('facility_name', 100);
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
        Schema::dropIfExists('infrafacilities');
    }
};
