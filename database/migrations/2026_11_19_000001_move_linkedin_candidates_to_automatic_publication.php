<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('linkedin_post_candidates')
            ->where('status', 'pending_review')
            ->update([
                'status' => 'pending_publication',
                'decision_reason' => 'Migrado para a fila de publicação automática.',
            ]);

        Schema::table('linkedin_post_candidates', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending_publication')->change();
        });
    }

    public function down(): void
    {
        DB::table('linkedin_post_candidates')
            ->where('status', 'pending_publication')
            ->update(['status' => 'pending_review']);

        Schema::table('linkedin_post_candidates', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending_review')->change();
        });
    }
};
