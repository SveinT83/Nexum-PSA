<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_workload_profiles', function (Blueprint $table): void {
            $table->string('managed_by', 100)->nullable()->after('workload_type')->index();
            $table->unique(
                ['managed_by', 'ai_agent_id'],
                'ai_workload_profiles_managed_agent_unique'
            );
        });

        Schema::table('storage_purchase_order_automation_policies', function (Blueprint $table): void {
            $table->foreignId('ai_agent_id')
                ->nullable()
                ->after('ai_workload_profile_id')
                ->constrained('ai_agents')
                ->nullOnDelete();
        });
        $this->restoreSqliteCurrentPolicyGuard();

        DB::table('storage_purchase_order_automation_policies')
            ->whereNotNull('ai_workload_profile_id')
            ->orderBy('id')
            ->eachById(function (object $policy): void {
                $agentId = DB::table('ai_workload_profiles')
                    ->where('id', $policy->ai_workload_profile_id)
                    ->value('ai_agent_id');

                if ($agentId) {
                    DB::table('storage_purchase_order_automation_policies')
                        ->where('id', $policy->id)
                        ->update(['ai_agent_id' => $agentId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('storage_purchase_order_automation_policies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_agent_id');
        });
        $this->restoreSqliteCurrentPolicyGuard();

        Schema::table('ai_workload_profiles', function (Blueprint $table): void {
            $table->dropUnique('ai_workload_profiles_managed_agent_unique');
            $table->dropIndex(['managed_by']);
            $table->dropColumn('managed_by');
        });
    }

    /**
     * SQLite table rebuilds do not preserve the predicate on partial indexes.
     * Reassert the original one-current-policy contract after adding or dropping the FK.
     */
    private function restoreSqliteCurrentPolicyGuard(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS spo_auto_one_current_unique');
        DB::statement(
            'CREATE UNIQUE INDEX spo_auto_one_current_unique '
            .'ON storage_purchase_order_automation_policies (is_current) WHERE is_current = 1'
        );
    }
};
