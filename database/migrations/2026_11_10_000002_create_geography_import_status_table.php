<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geography_import_status', function (Blueprint $table): void {
            $table->string('source', 80)->primary();
            $table->unsignedInteger('records_count')->default(0);
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geography_import_status');
    }
};
