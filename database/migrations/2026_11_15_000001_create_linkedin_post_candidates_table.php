<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linkedin_post_candidates', function (Blueprint $table): void {
            $table->id();

            $table->string('post_id')->unique();
            $table->text('post_url')->unique();

            $table->string('author_name')->nullable();
            $table->text('author_url')->nullable();
            $table->text('author_headline')->nullable();
            $table->string('author_type')->nullable();

            $table->string('matched_keyword')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->longText('post_text');
            $table->json('comments')->nullable();
            $table->json('embedded_links')->nullable();
            $table->json('emails')->nullable();
            $table->json('metadata')->nullable();

            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('work_model')->nullable();
            $table->string('employment_type')->nullable();
            $table->string('salary')->nullable();
            $table->text('application_url')->nullable();

            $table->string('status', 32)->default('pending_review')->index();
            $table->text('decision_reason')->nullable();

            $table->foreignId('duplicate_of_vacancy_id')
                ->nullable()
                ->constrained('vacancies')
                ->nullOnDelete();

            $table->foreignId('duplicate_of_candidate_id')
                ->nullable()
                ->constrained('linkedin_post_candidates')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
            $table->index(['status', 'published_at']);
            $table->index(['company', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linkedin_post_candidates');
    }
};
