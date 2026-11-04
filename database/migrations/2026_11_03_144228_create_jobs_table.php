<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('company');
            $table->text('description');

            $table->string('location')->nullable();
            $table->string('work_model')->nullable();
            $table->string('employment_type')->nullable();
            $table->string('salary')->nullable();

            $table->text('application_url');

            $table->string('source');
            $table->string('external_id')->nullable();

            $table->timestamp('original_published_at');
            $table->timestamp('collected_at');

            $table->timestamps();

            $table->unique(
                ['source', 'external_id'],
                'vacancies_source_external_id_unique'
            );

            $table->index('original_published_at');
            $table->index('collected_at');
            $table->index('work_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
