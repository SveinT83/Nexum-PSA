<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_email_provider_connections', function (Blueprint $table): void {
            $table->uuid('integration_id')->primary();
            $table->string('driver', 40)->default('imap_smtp');
            $table->string('status', 24)->default('staged')->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->unsignedInteger('verified_configuration_version')->nullable();
            $table->unsignedInteger('verified_credential_version')->nullable();
            $table->string('imap_host', 253);
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_transport', 24);
            $table->string('imap_endpoint_policy_id', 96);
            $table->string('imap_auth_type', 32)->default('password');
            $table->string('smtp_host', 253);
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_transport', 24);
            $table->string('smtp_endpoint_policy_id', 96);
            $table->string('smtp_auth_type', 32)->default('password');
            $table->string('trust_mode', 24)->default('public');
            $table->string('trusted_cidr_name', 120)->nullable();
            $table->text('private_endpoint_reason')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('last_verification_code', 80)->nullable();
            $table->dateTime('last_verified_at')->nullable();
            $table->uuid('verification_claim_token')->nullable();
            $table->unsignedInteger('verification_claim_configuration_version')->nullable();
            $table->unsignedInteger('verification_claim_credential_version')->nullable();
            $table->dateTime('verification_claim_expires_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('integration_id', 'iep_connection_integration_fk')
                ->references('id')->on('integrations')->restrictOnDelete();
            $table->foreign('created_by', 'iep_connection_creator_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('updated_by', 'iep_connection_updater_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['status', 'trust_mode', 'updated_at'],
                'iep_connection_status_trust_ix',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_email_provider_connections')
            && DB::table('integration_email_provider_connections')->exists()) {
            throw new RuntimeException(
                'Email provider connections must be preserved or explicitly removed before schema rollback.',
            );
        }

        Schema::dropIfExists('integration_email_provider_connections');
    }
};
