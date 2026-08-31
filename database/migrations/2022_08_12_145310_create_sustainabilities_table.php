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
        Schema::create('sustainabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained();
            // FK agregada en 2026_08_31_.. add_deferred_foreign_keys_out_of_order_tables (esta
            // migracion es anterior a create_areas_table por fecha de archivo).
            $table->foreignId('areas_id');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->enum('sust_status', ['0', '1', '2'])->default('0');
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
        Schema::dropIfExists('sustainabilities');
    }
};
