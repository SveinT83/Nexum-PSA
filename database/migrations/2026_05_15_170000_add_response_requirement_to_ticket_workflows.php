<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_workflow_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_workflow_states', 'requires_response')) {
                $table->boolean('requires_response')->default(false)->after('requires_note');
            }
        });

        Schema::table('ticket_workflow_transitions', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_workflow_transitions', 'requires_response')) {
                $table->boolean('requires_response')->default(false)->after('requires_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_workflow_transitions', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_workflow_transitions', 'requires_response')) {
                $table->dropColumn('requires_response');
            }
        });

        Schema::table('ticket_workflow_states', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_workflow_states', 'requires_response')) {
                $table->dropColumn('requires_response');
            }
        });
    }
};
