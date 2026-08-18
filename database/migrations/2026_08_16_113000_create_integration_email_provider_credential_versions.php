<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_email_provider_credential_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('provider_integration_id');
            $table->unsignedInteger('version');
            $table->string('state', 24)->default('staged')->index();
            $table->text('imap_username_encrypted')->nullable();
            $table->text('imap_secret_encrypted')->nullable();
            $table->text('smtp_username_encrypted')->nullable();
            $table->text('smtp_secret_encrypted')->nullable();
            $table->char('credential_fingerprint', 64);
            $table->unsignedInteger('verified_configuration_version')->nullable();
            $table->string('verification_code', 80)->nullable();
            $table->unsignedBigInteger('staged_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedBigInteger('destroyed_by')->nullable();
            $table->dateTime('staged_at');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('destroyed_at')->nullable();
            $table->timestamps();

            $table->foreign('provider_integration_id', 'iep_credential_connection_fk')
                ->references('integration_id')->on('integration_email_provider_connections')->restrictOnDelete();
            $table->foreign('staged_by', 'iep_credential_stager_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('verified_by', 'iep_credential_verifier_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('activated_by', 'iep_credential_activator_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('revoked_by', 'iep_credential_revoker_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('destroyed_by', 'iep_credential_destroyer_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->unique(
                ['provider_integration_id', 'version'],
                'iep_credential_connection_version_uq',
            );
            $table->index(
                ['provider_integration_id', 'state'],
                'iep_credential_connection_state_ix',
            );
        });

        Schema::table('integration_email_provider_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_credential_version_id')
                ->nullable()
                ->after('verified_credential_version');
            $table->foreign('active_credential_version_id', 'iep_connection_active_credential_fk')
                ->references('id')->on('integration_email_provider_credential_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $hasActiveReferences = Schema::hasTable('integration_email_provider_connections')
            && Schema::hasColumn('integration_email_provider_connections', 'active_credential_version_id')
            && DB::table('integration_email_provider_connections')
                ->whereNotNull('active_credential_version_id')
                ->exists();
        $hasCredentials = Schema::hasTable('integration_email_provider_credential_versions')
            && DB::table('integration_email_provider_credential_versions')->exists();

        if ($hasActiveReferences || $hasCredentials) {
            throw new RuntimeException(
                'Email provider credential history and active references must be preserved before schema rollback.',
            );
        }

        if (Schema::hasColumn('integration_email_provider_connections', 'active_credential_version_id')) {
            Schema::table('integration_email_provider_connections', function (Blueprint $table): void {
                $table->dropForeign('iep_connection_active_credential_fk');
                $table->dropColumn('active_credential_version_id');
            });
        }

        Schema::dropIfExists('integration_email_provider_credential_versions');
    }
};
