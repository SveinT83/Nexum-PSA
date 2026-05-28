<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('merged_into_ticket_id')->nullable()->after('closed_at')->constrained('tickets')->nullOnDelete();
            $table->unsignedBigInteger('merged_by')->nullable()->after('merged_into_ticket_id')->index();
            $table->timestamp('merged_at')->nullable()->after('merged_by');

            $table->index(['merged_into_ticket_id', 'merged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['merged_into_ticket_id', 'merged_at']);
            $table->dropConstrainedForeignId('merged_into_ticket_id');
            $table->dropColumn(['merged_by', 'merged_at']);
        });
    }
};
