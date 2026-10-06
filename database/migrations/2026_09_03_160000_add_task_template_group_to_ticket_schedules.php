<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ticket_schedules', 'task_template_group_id')) {
            return;
        }

        Schema::table('ticket_schedules', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_template_group_id')
                ->nullable()
                ->after('ticket_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ticket_schedules', 'task_template_group_id')) {
            return;
        }

        Schema::table('ticket_schedules', function (Blueprint $table): void {
            $table->dropColumn('task_template_group_id');
        });
    }
};
