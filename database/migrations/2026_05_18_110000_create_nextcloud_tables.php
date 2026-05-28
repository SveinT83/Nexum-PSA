<?php

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nextcloud_connections')) {
            Schema::create('nextcloud_connections', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('scope')->default('global');
                $table->string('mode')->default('read_only');
                $table->foreignIdFor(Client::class)->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignIdFor(ClientSite::class)->nullable()->constrained('client_sites')->nullOnDelete();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('base_url');
                $table->string('admin_url')->nullable();
                $table->string('root_folder')->nullable();
                $table->string('documents_folder')->nullable();
                $table->unsignedInteger('sync_interval_minutes')->default(15);
                $table->string('service_username')->nullable();
                $table->text('service_password')->nullable();
                $table->boolean('allow_user_credentials')->default(false);
                $table->boolean('supports_managed_writes')->default(false);
                $table->string('health_status')->default('untested');
                $table->timestamp('last_health_check_at')->nullable();
                $table->timestamp('last_successful_sync_at')->nullable();
                $table->timestamp('last_sync_requested_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('capabilities')->nullable();
                $table->json('settings')->nullable();
                $table->unsignedBigInteger('talk_bot_id')->nullable();
                $table->text('talk_bot_secret')->nullable();
                $table->string('talk_default_conversation_token', 64)->nullable();
                $table->json('talk_bot_features')->nullable();
                $table->timestamps();

                $table->index(['scope', 'is_active']);
                $table->index(['client_id', 'scope']);
                $table->index(['client_site_id', 'scope']);
            });
        }

        if (! Schema::hasTable('nextcloud_user_credentials')) {
            Schema::create('nextcloud_user_credentials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->foreignIdFor(User::class)->constrained('user_management')->cascadeOnDelete();
                $table->string('remote_username');
                $table->text('app_password')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('status')->default('untested');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'user_id'], 'nextcloud_user_credentials_connection_user_unique');
            });
        }

        if (! Schema::hasTable('nextcloud_folder_mappings')) {
            Schema::create('nextcloud_folder_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->string('mappable_type');
                $table->unsignedBigInteger('mappable_id');
                $table->string('purpose')->default('client_files');
                $table->string('remote_path');
                $table->string('remote_file_id')->nullable();
                $table->boolean('auto_created')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'mappable_type', 'mappable_id', 'purpose'], 'nextcloud_folder_mappings_unique');
                $table->index(['mappable_type', 'mappable_id']);
            });
        }

        if (! Schema::hasTable('nextcloud_calendar_mappings')) {
            Schema::create('nextcloud_calendar_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->foreignId('calendar_id')->nullable()->constrained('calendars')->nullOnDelete();
                $table->foreignIdFor(User::class)->nullable()->constrained('user_management')->nullOnDelete();
                $table->string('remote_calendar_id');
                $table->string('remote_display_name')->nullable();
                $table->string('sync_direction')->default('two_way');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_synced_at')->nullable();
                $table->string('sync_token')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'remote_calendar_id'], 'nextcloud_calendar_mappings_remote_unique');
            });
        }

        if (! Schema::hasTable('nextcloud_user_mappings')) {
            Schema::create('nextcloud_user_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->foreignIdFor(User::class)->nullable()->constrained('user_management')->nullOnDelete();
                $table->string('remote_user_id');
                $table->string('remote_username')->nullable();
                $table->string('remote_email')->nullable();
                $table->string('identity_type')->default('technician');
                $table->string('identity_model_type')->nullable();
                $table->unsignedBigInteger('identity_model_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'remote_user_id'], 'nextcloud_user_mappings_remote_unique');
                $table->index(['identity_model_type', 'identity_model_id'], 'nextcloud_user_mappings_identity_idx');
            });
        }

        if (! Schema::hasTable('nextcloud_group_mappings')) {
            Schema::create('nextcloud_group_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
                $table->foreignIdFor(Client::class)->nullable()->constrained('clients')->nullOnDelete();
                $table->string('client_role')->nullable();
                $table->string('remote_group_id');
                $table->string('remote_group_name')->nullable();
                $table->string('sync_mode')->default('preview_only');
                $table->boolean('is_managed')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['connection_id', 'remote_group_id'], 'nextcloud_group_mappings_remote_unique');
            });
        }

        if (! Schema::hasTable('nextcloud_sync_logs')) {
            Schema::create('nextcloud_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->string('operation');
                $table->string('status')->default('queued');
                $table->string('credential_source')->nullable();
                $table->foreignIdFor(User::class)->nullable()->constrained('user_management')->nullOnDelete();
                $table->unsignedInteger('records_seen')->default(0);
                $table->unsignedInteger('records_changed')->default(0);
                $table->unsignedInteger('records_failed')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['connection_id', 'operation', 'status'], 'nextcloud_sync_logs_lookup_idx');
            });
        }

        if (! Schema::hasTable('nextcloud_sync_conflicts')) {
            Schema::create('nextcloud_sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('nextcloud_connections')->cascadeOnDelete();
                $table->string('conflictable_type');
                $table->unsignedBigInteger('conflictable_id')->nullable();
                $table->string('remote_object_type');
                $table->string('remote_object_id')->nullable();
                $table->string('status')->default('open');
                $table->string('resolution')->nullable();
                $table->json('local_snapshot')->nullable();
                $table->json('remote_snapshot')->nullable();
                $table->text('message')->nullable();
                $table->foreignIdFor(User::class, 'assigned_user_id')->nullable()->constrained('user_management')->nullOnDelete();
                $table->foreignIdFor(User::class, 'resolved_by')->nullable()->constrained('user_management')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['connection_id', 'status'], 'nextcloud_sync_conflicts_connection_status_idx');
                $table->index(['conflictable_type', 'conflictable_id'], 'nextcloud_sync_conflicts_local_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nextcloud_sync_conflicts');
        Schema::dropIfExists('nextcloud_sync_logs');
        Schema::dropIfExists('nextcloud_group_mappings');
        Schema::dropIfExists('nextcloud_user_mappings');
        Schema::dropIfExists('nextcloud_calendar_mappings');
        Schema::dropIfExists('nextcloud_folder_mappings');
        Schema::dropIfExists('nextcloud_user_credentials');
        Schema::dropIfExists('nextcloud_connections');
    }
};
