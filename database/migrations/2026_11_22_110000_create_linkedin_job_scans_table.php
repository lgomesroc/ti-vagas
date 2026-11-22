<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linkedin_job_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('job_id')->unique();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('consulted_at');
            $table->timestamps();

            $table->index('consulted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linkedin_job_scans');
    }
};
