<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_email_provider_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('operation', 24)->index();
            $table->string('status', 32)->default('previewed')->index();
            $table->char('scope_fingerprint', 64);
            $table->unsignedInteger('account_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->unsignedBigInteger('rollback_of_run_id')->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->dateTime('preview_expires_at');
            $table->dateTime('rollback_deadline_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by', 'iep_migration_run_creator_fk')
                ->references('id')->on('user_management')->restrictOnDelete();
            $table->foreign('applied_by', 'iep_migration_run_applier_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('rolled_back_by', 'iep_migration_run_rollback_actor_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('rollback_of_run_id', 'iep_migration_run_parent_fk')
                ->references('id')->on('integration_email_provider_migration_runs')->restrictOnDelete();
            $table->foreign('source_run_id', 'iep_migration_run_source_fk')
                ->references('id')->on('integration_email_provider_migration_runs')->restrictOnDelete();
            $table->index(
                ['operation', 'status', 'created_at'],
                'iep_migration_run_operation_status_ix',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_email_provider_migration_runs')
            && DB::table('integration_email_provider_migration_runs')->exists()) {
            throw new RuntimeException(
                'Email provider migration run history must be preserved before schema rollback.',
            );
        }

        Schema::dropIfExists('integration_email_provider_migration_runs');
    }
};
