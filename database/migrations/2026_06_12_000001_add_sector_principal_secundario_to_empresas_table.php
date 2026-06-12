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
        Schema::table('empresas', function (Blueprint $table) {
            $table->foreignId('sector_principal_id')->nullable()->constrained('sectors');
            $table->foreignId('sector_secundario_id')->nullable()->constrained('sectors');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign(['sector_principal_id']);
            $table->dropForeign(['sector_secundario_id']);
            $table->dropColumn(['sector_principal_id', 'sector_secundario_id']);
        });
    }
};
