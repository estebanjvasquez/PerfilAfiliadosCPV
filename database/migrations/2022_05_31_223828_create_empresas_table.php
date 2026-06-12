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
            $table->string('phone', 20)->nullable();
            $table->string('website')->nullable();
            $table->string('street', 100)->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('linkedin_profile', 20)->nullable();
            $table->string('twitter_profile', 20)->nullable();
            $table->string('instagram_profile', 20)->nullable();
            $table->string('facebook_profile', 20)->nullable();
            $table->string('youtube_profile', 20)->nullable();
            $table->string('otros_profile', 20)->nullable();

            $table->foreign('city_id')->references('id')->on('cities');

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
