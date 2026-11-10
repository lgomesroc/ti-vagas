<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();

            // Código IBGE de 7 dígitos.
            $table->char('ibge_code', 7)->unique();
            $table->string('name', 150);
            $table->string('normalized_name', 150);
            $table->char('state_code', 2);

            // Centroide do município.
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);

            // Divisão regional do IBGE (2017).
            $table->string('intermediate_region_code', 8)->nullable();
            $table->string('intermediate_region_name', 150)->nullable();
            $table->string('immediate_region_code', 8)->nullable();
            $table->string('immediate_region_name', 150)->nullable();

            // Região Metropolitana (RM) ou RIDE.
            $table->string('region_type', 8)->nullable();
            $table->string('region_code', 40)->nullable();
            $table->string('region_name', 150)->nullable();

            $table->index(['state_code', 'normalized_name']);
            $table->index('normalized_name');
            $table->index('intermediate_region_code');
            $table->index('region_code');
        });

        Schema::table('vacancies', function (Blueprint $table) {
            $table->index('ibge_municipality_code');
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropIndex(['ibge_municipality_code']);
        });

        Schema::dropIfExists('municipalities');
    }
};
