<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rmm_alert_occurrences', 'processing_token')) {
            return;
        }

        Schema::table('rmm_alert_occurrences', function (Blueprint $table): void {
            $table->uuid('processing_token')
                ->nullable()
                ->after('processing_started_at');
            $table->index('processing_token', 'rmm_occurrence_processing_token');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rmm_alert_occurrences', 'processing_token')) {
            return;
        }
        if (DB::table('rmm_alert_occurrences')->whereNotNull('processing_token')->exists()) {
            throw new RuntimeException('Refusing to drop active RMM processing lease tokens.');
        }

        Schema::table('rmm_alert_occurrences', function (Blueprint $table): void {
            $table->dropIndex('rmm_occurrence_processing_token');
            $table->dropColumn('processing_token');
        });
    }
};
