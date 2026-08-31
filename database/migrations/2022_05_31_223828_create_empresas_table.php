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

        Schema::dropIfExists('empresas');
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('rif', 12)->unique();
            $table->string('name', 100);
            $table->year('ano_fund')->nullable();
            // Ampliado de 20 a 40: datos reales de produccion tienen hasta 22 caracteres
            // (formatos de telefono con codigo de pais/extension) - MySQL no lo bloqueaba,
            // Postgres si (encontrado al migrar los datos, ver Fase 1 del plan).
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            // Ampliado de 100 a 150: datos reales de produccion tienen hasta 104 caracteres.
            $table->string('street', 150)->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('linkedin_profile', 20)->nullable();
            $table->string('twitter_profile', 20)->nullable();
            $table->string('instagram_profile', 20)->nullable();
            $table->string('facebook_profile', 20)->nullable();
            $table->string('youtube_profile', 20)->nullable();
            $table->string('otros_profile', 20)->nullable();

            // FK a 'cities' agregada en 2026_08_31_.. add_deferred_foreign_keys_out_of_order_tables
            // (esta migracion es anterior a create_cities_table por fecha de archivo; declararla
            // aca rompe cualquier corrida desde cero contra una BD vacia, ej. Postgres/Supabase).

            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('employees_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();

            $table->json('customers_country')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
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
        Schema::dropIfExists('empresas');
    }
};
