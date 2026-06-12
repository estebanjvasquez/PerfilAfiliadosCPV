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
        Schema::create('management', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();

            $table->boolean('iso9001')->default(0);
            $table->boolean('iso17025')->default(0);
            $table->boolean('quality_otros')->default(0);
            $table->json('quality_data')->nullable();

            $table->boolean('iso14001')->default(0);
            $table->boolean('iso50001')->default(0);
            $table->boolean('environment_otros')->default(0);
            $table->json('environment_data')->nullable();

            $table->boolean('dun')->default(0);
            $table->boolean('iso37001')->default(0);
            $table->boolean('credibility_otros')->default(0);
            $table->json('credibility_data')->nullable();

            $table->boolean('iso45001')->default(0);
            $table->boolean('ovid')->default(0);
            $table->boolean('security_otros')->default(0);
            $table->json('security_data')->nullable();

            $table->boolean('pmi')->default(0);
            $table->boolean('pmi_otros')->default(0);
            $table->json('pmi_data')->nullable();

            $table->boolean('iso27001')->default(0);
            $table->boolean('info_otros')->default(0);
            $table->json('info_data')->nullable();

            $table->timestamps();
            $table->unique(['empresa_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('management');
    }
};
