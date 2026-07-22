<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ticket_workflow_transitions', 'customer_notification')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->json('customer_notification')->nullable()->after('requirements');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ticket_workflow_transitions', 'customer_notification')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->dropColumn('customer_notification');
            });
        }
    }
};
