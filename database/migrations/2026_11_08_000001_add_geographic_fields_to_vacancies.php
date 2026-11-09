<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->string('city', 150)->nullable();
            $table->string('state', 100)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('ibge_municipality_code', 7)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'state',
                'state_code',
                'country_code',
                'ibge_municipality_code',
                'latitude',
                'longitude',
            ]);
        });
    }
};
