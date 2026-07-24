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
        Schema::table('empresa_module_status', function (Blueprint $table) {
            $table->string('sub_type', 50)->default('')->after('module');
        });

        Schema::table('empresa_module_status', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'module']);
            $table->unique(['empresa_id', 'module', 'sub_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('empresa_module_status', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'module', 'sub_type']);
            $table->unique(['empresa_id', 'module']);
        });

        Schema::table('empresa_module_status', function (Blueprint $table) {
            $table->dropColumn('sub_type');
        });
    }
};
