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
            $table->foreignId('infraregion_infrasystem_id')->references('id')->on('infraregion_infrasystem');
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
