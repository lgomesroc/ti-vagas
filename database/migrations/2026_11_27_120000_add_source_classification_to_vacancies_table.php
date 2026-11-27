<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->string('source_category')->nullable()->after('salary');
            $table->boolean('is_technology')->nullable()->after('source_category');
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropColumn(['source_category', 'is_technology']);
        });
    }
};
