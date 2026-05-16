<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_workflow_transitions', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_workflow_transitions', 'manual_enabled')) {
                $table->boolean('manual_enabled')->default(true)->after('is_active');
            }

            if (! Schema::hasColumn('ticket_workflow_transitions', 'trigger_actions')) {
                $table->json('trigger_actions')->nullable()->after('manual_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_workflow_transitions', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_workflow_transitions', 'trigger_actions')) {
                $table->dropColumn('trigger_actions');
            }

            if (Schema::hasColumn('ticket_workflow_transitions', 'manual_enabled')) {
                $table->dropColumn('manual_enabled');
            }
        });
    }
};
