<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_rules', function (Blueprint $table): void {
            $table->boolean('stop_processing')->default(false)->after('priority')->index();
        });

        Schema::table('signal_rule_executions', function (Blueprint $table): void {
            $table->foreignId('retry_of_execution_id')
                ->nullable()
                ->after('signal_rule_id')
                ->constrained('signal_rule_executions')
                ->nullOnDelete();
            $table->unsignedInteger('attempt')->default(1)->after('retry_of_execution_id');
        });
    }

    public function down(): void
    {
        Schema::table('signal_rule_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retry_of_execution_id');
            $table->dropColumn('attempt');
        });

        Schema::table('signal_rules', function (Blueprint $table): void {
            $table->dropColumn('stop_processing');
        });
    }
};
