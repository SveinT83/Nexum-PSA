<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_purchase_order_lines', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->nullable()->after('item_id')->constrained('tickets')->nullOnDelete();
            $table->foreignId('ticket_planned_line_id')->nullable()->unique()->after('ticket_id')->constrained('ticket_planned_lines')->nullOnDelete();
            $table->json('metadata')->nullable()->after('expected_at');
        });
    }

    public function down(): void
    {
        Schema::table('storage_purchase_order_lines', function (Blueprint $table): void {
            $table->dropUnique(['ticket_planned_line_id']);
            $table->dropConstrainedForeignId('ticket_planned_line_id');
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropColumn('metadata');
        });
    }
};
