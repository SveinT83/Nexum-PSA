<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_time_entries', function (Blueprint $table): void {
            $table->foreignId('task_id')
                ->nullable()
                ->after('ticket_id')
                ->constrained('tasks')
                ->nullOnDelete();

            $table->index(['task_id', 'ticket_id'], 'ticket_time_entries_task_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_time_entries', function (Blueprint $table): void {
            $table->dropIndex('ticket_time_entries_task_ticket_idx');
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
