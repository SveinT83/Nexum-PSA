<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexum_relationships', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('direction', 40)->index();
            $table->string('relationship_type', 60)->default('customer_provider');
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('remote_base_url')->nullable();
            $table->string('remote_instance_id')->nullable()->index();
            $table->string('remote_organization_name')->nullable();
            $table->string('remote_organization_identifier')->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->string('health_status', 40)->default('unknown')->index();
            $table->json('capabilities')->nullable();
            $table->json('ticket_policy')->nullable();
            $table->json('documentation_policy')->nullable();
            $table->json('attachment_policy')->nullable();
            $table->json('status_mapping')->nullable();
            $table->json('service_areas')->nullable();
            $table->text('outbound_token_encrypted')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->string('inbound_token_hash')->nullable();
            $table->timestamp('token_rotated_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->text('failure_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['direction', 'status'], 'nexum_relationships_direction_status_idx');
            $table->index(['relationship_type', 'status'], 'nexum_relationships_type_status_idx');
        });

        Schema::create('nexum_sync_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('relationship_id')->constrained('nexum_relationships')->cascadeOnDelete();
            $table->string('domain', 60)->index();
            $table->string('local_type')->nullable();
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('remote_type')->nullable();
            $table->string('remote_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->string('remote_version')->nullable();
            $table->string('remote_checksum')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->string('direction', 40)->index();
            $table->string('sync_status', 40)->default('pending')->index();
            $table->string('conflict_status', 40)->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['relationship_id', 'domain', 'local_type', 'local_id'], 'nexum_sync_links_local_idx');
            $table->index(['relationship_id', 'domain', 'remote_type', 'remote_id'], 'nexum_sync_links_remote_idx');
        });

        Schema::create('nexum_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('relationship_id')->constrained('nexum_relationships')->cascadeOnDelete();
            $table->foreignId('sync_link_id')->nullable()->constrained('nexum_sync_links')->nullOnDelete();
            $table->string('direction', 40)->index();
            $table->string('capability', 80)->nullable()->index();
            $table->string('local_type')->nullable();
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('remote_type')->nullable();
            $table->string('remote_id')->nullable();
            $table->string('event_type', 80)->index();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('machine_identity')->nullable();
            $table->string('payload_checksum')->nullable();
            $table->string('outcome', 40)->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['relationship_id', 'event_type', 'outcome'], 'nexum_sync_events_relationship_outcome_idx');
            $table->index(['local_type', 'local_id'], 'nexum_sync_events_local_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexum_sync_events');
        Schema::dropIfExists('nexum_sync_links');
        Schema::dropIfExists('nexum_relationships');
    }
};
