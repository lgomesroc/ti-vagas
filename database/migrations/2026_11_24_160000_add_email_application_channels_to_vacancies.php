<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->text('application_url')->nullable()->change();
            $table->string('application_email')->nullable()->after('application_url');
            $table->string('application_subject')->nullable()->after('application_email');
            $table->text('source_url')->nullable()->after('application_subject');
        });

        Schema::table('linkedin_post_candidates', function (Blueprint $table): void {
            $table->string('application_email')->nullable()->after('application_url');
            $table->string('application_subject')->nullable()->after('application_email');
        });
    }

    public function down(): void
    {
        Schema::table('linkedin_post_candidates', function (Blueprint $table): void {
            $table->dropColumn(['application_email', 'application_subject']);
        });

        DB::table('vacancies')
            ->whereNull('application_url')
            ->update([
                'application_url' => DB::raw("COALESCE(source_url, 'mailto:' || application_email)"),
            ]);

        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropColumn(['application_email', 'application_subject', 'source_url']);
            $table->text('application_url')->nullable(false)->change();
        });
    }
};
