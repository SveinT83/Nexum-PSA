<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create installation-owned profile containers, immutable versions, and protected fixtures.
     */
    public function up(): void
    {
        Schema::create('storage_purchase_order_import_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('lifecycle_state')->default('draft');
            $table->unsignedInteger('priority')->default(100);
            $table->json('matching_scope')->nullable();
            $table->json('policy_overrides')->nullable();
            $table->string('health_state')->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lifecycle_state', 'priority'], 'storage_po_import_profile_lifecycle_index');
            $table->index(['vendor_id', 'health_state'], 'storage_po_import_profile_vendor_health_index');
        });

        Schema::create('storage_purchase_order_import_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('storage_purchase_order_import_profiles', 'id', 'spo_profile_version_profile_fk')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('parent_version_id')
                ->nullable()
                ->constrained('storage_purchase_order_import_profile_versions', 'id', 'spo_profile_version_parent_fk')
                ->nullOnDelete();
            $table->string('schema_version')->default('1.0');
            $table->string('status')->default('draft');
            $table->json('definition');
            $table->char('checksum', 64);
            $table->string('source')->default('manual');
            $table->json('test_metrics')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management', 'id', 'spo_profile_version_creator_fk')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('user_management', 'id', 'spo_profile_version_activator_fk')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('activation_reason')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'version_number'], 'storage_po_import_profile_version_unique');
            $table->unique(['profile_id', 'checksum'], 'storage_po_import_profile_checksum_unique');
            $table->index(['status', 'activated_at'], 'storage_po_import_profile_version_status_index');
        });

        Schema::table('storage_purchase_order_import_profiles', function (Blueprint $table): void {
            $table->foreignId('active_version_id')
                ->nullable()
                ->after('priority')
                ->constrained('storage_purchase_order_import_profile_versions')
                ->nullOnDelete();
        });

        Schema::create('storage_purchase_order_import_profile_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('storage_purchase_order_import_profiles', 'id', 'spo_profile_fixture_profile_fk')->cascadeOnDelete();
            $table->foreignId('profile_version_id')
                ->nullable()
                ->constrained('storage_purchase_order_import_profile_versions', 'id', 'spo_profile_fixture_version_fk')
                ->nullOnDelete();
            $table->string('name');
            $table->string('fixture_type')->default('body');
            $table->boolean('is_protected')->default(true);
            $table->json('safe_source_snapshot');
            $table->json('expected_document');
            $table->char('source_checksum', 64);
            $table->char('expected_checksum', 64);
            $table->string('last_result')->nullable();
            $table->json('last_result_details')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management', 'id', 'spo_profile_fixture_creator_fk')->nullOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'source_checksum'], 'storage_po_import_fixture_source_unique');
            $table->index(['profile_id', 'last_result'], 'storage_po_import_fixture_result_index');
        });

        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->foreignId('profile_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('storage_purchase_order_import_profiles')
                ->nullOnDelete();
            $table->foreignId('profile_version_id')
                ->nullable()
                ->after('profile_id')
                ->constrained('storage_purchase_order_import_profile_versions')
                ->nullOnDelete();
            $table->index(['profile_id', 'status'], 'storage_po_import_profile_status_index');
        });
    }

    /**
     * Remove profile references before their owning tables.
     */
    public function down(): void
    {
        Schema::table('storage_purchase_order_imports', function (Blueprint $table): void {
            $table->dropForeign(['profile_version_id']);
            $table->dropForeign(['profile_id']);
            $table->dropIndex('storage_po_import_profile_status_index');
            $table->dropColumn(['profile_version_id', 'profile_id']);
        });

        Schema::dropIfExists('storage_purchase_order_import_profile_fixtures');

        Schema::table('storage_purchase_order_import_profiles', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
            $table->dropColumn('active_version_id');
        });

        Schema::dropIfExists('storage_purchase_order_import_profile_versions');
        Schema::dropIfExists('storage_purchase_order_import_profiles');
    }
};
