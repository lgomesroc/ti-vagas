<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->string('availability_status', 20)
                ->default('active')
                ->index();

            $table->timestamp('availability_checked_at')->nullable();
            $table->unsignedSmallInteger('availability_http_status')->nullable();
            $table->unsignedSmallInteger('availability_consecutive_not_found')->default(0);
            $table->text('availability_last_error')->nullable();
        });

        Schema::create('vacancy_availability_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vacancy_id')
                ->constrained('vacancies')
                ->cascadeOnDelete();

            $table->string('check_type', 20);
            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('checked_url')->nullable();
            $table->text('final_url')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['vacancy_id', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_availability_checks');

        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropIndex(['availability_status']);
            $table->dropColumn([
                'availability_status',
                'availability_checked_at',
                'availability_http_status',
                'availability_consecutive_not_found',
                'availability_last_error',
            ]);
        });
    }
};
