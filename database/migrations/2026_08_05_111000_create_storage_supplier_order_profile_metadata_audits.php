<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record every mutable profile-container metadata change without changing
     * the immutable parser versions owned by the profile.
     */
    public function up(): void
    {
        Schema::create('storage_purchase_order_import_profile_metadata_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained('storage_purchase_order_import_profiles', 'id', 'spo_profile_metadata_audit_profile_fk')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('user_management', 'id', 'spo_profile_metadata_audit_actor_fk')
                ->nullOnDelete();
            $table->json('changed_fields');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->string('reason', 245);
            $table->timestamps();

            $table->index(['profile_id', 'created_at'], 'spo_profile_metadata_audit_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_purchase_order_import_profile_metadata_audits');
    }
};
