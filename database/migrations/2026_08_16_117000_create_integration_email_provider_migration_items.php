<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_email_provider_migration_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('migration_run_id');
            $table->unsignedBigInteger('email_account_id');
            $table->uuid('provider_integration_id')->nullable();
            $table->unsignedBigInteger('credential_version_id')->nullable();
            $table->string('status', 32)->default('previewed')->index();
            $table->string('block_code', 80)->nullable();
            $table->char('legacy_fingerprint', 64);
            $table->char('binding_fingerprint', 64)->nullable();
            $table->string('previous_source', 24)->default('legacy');
            $table->uuid('previous_provider_integration_id')->nullable();
            $table->unsignedInteger('previous_binding_version');
            $table->unsignedInteger('staged_configuration_version')->nullable();
            $table->unsignedInteger('staged_credential_version')->nullable();
            $table->dateTime('staged_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('cutover_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->timestamps();

            $table->foreign('migration_run_id', 'iep_migration_item_run_fk')
                ->references('id')->on('integration_email_provider_migration_runs')->restrictOnDelete();
            $table->foreign('email_account_id', 'iep_migration_item_account_fk')
                ->references('id')->on('email_accounts')->restrictOnDelete();
            $table->foreign('provider_integration_id', 'iep_migration_item_connection_fk')
                ->references('integration_id')->on('integration_email_provider_connections')->restrictOnDelete();
            $table->foreign('credential_version_id', 'iep_migration_item_credential_fk')
                ->references('id')->on('integration_email_provider_credential_versions')->restrictOnDelete();
            $table->foreign('previous_provider_integration_id', 'iep_migration_item_previous_connection_fk')
                ->references('integration_id')->on('integration_email_provider_connections')->restrictOnDelete();
            $table->unique(
                ['migration_run_id', 'email_account_id'],
                'iep_migration_item_run_account_uq',
            );
            $table->index(
                ['email_account_id', 'status'],
                'iep_migration_item_account_status_ix',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_email_provider_migration_items')
            && DB::table('integration_email_provider_migration_items')->exists()) {
            throw new RuntimeException(
                'Email provider migration item history must be preserved before schema rollback.',
            );
        }

        Schema::dropIfExists('integration_email_provider_migration_items');
    }
};
