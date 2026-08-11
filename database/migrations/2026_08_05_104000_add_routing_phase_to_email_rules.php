<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_rules', function (Blueprint $table): void {
            $table->string('routing_phase', 40)
                ->default('normal')
                ->after('trigger');

            $table->index(
                ['trigger', 'routing_phase', 'is_active', 'weight'],
                'email_rules_phase_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('email_rules', function (Blueprint $table): void {
            $table->dropIndex('email_rules_phase_order_index');
            $table->dropColumn('routing_phase');
        });
    }
};
