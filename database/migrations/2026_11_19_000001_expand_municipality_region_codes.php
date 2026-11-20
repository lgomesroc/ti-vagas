<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('intermediate_region_code', 20)->nullable()->change();
            $table->string('immediate_region_code', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('intermediate_region_code', 8)->nullable()->change();
            $table->string('immediate_region_code', 8)->nullable()->change();
        });
    }
};
