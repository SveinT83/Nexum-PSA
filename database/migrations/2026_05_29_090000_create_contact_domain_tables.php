<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50)->default('person')->index();
            $table->string('status', 50)->default('active')->index();
            $table->string('display_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('organization_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('preferred_language', 10)->nullable();
            $table->string('communication_language', 10)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->boolean('do_not_call')->default(false);
            $table->boolean('do_not_email')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index('display_name');
        });

        Schema::create('contact_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('label', 50)->default('work');
            $table->string('email')->index();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->unique(['contact_id', 'email']);
        });

        Schema::create('contact_phones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('label', 50)->default('mobile');
            $table->string('phone')->index();
            $table->boolean('is_primary')->default(false);
            $table->boolean('sms_allowed')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('label', 50)->default('office');
            $table->string('address')->nullable();
            $table->string('co_address')->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('county', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('contact_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('related_type');
            $table->unsignedBigInteger('related_id');
            $table->string('relation_type', 100)->default('contact');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index(['contact_id', 'relation_type']);
        });

        Schema::create('contact_external_refs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('source', 100);
            $table->string('external_id', 255);
            $table->string('external_key', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['contact_id', 'source']);
        });

        Schema::create('contact_merge_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('target_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->json('source_snapshot')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('merged_at');
            $table->timestamps();
        });

        Schema::table('client_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('client_users', 'contact_id')) {
                $table->foreignId('contact_id')->nullable()->after('id')->constrained('contacts')->nullOnDelete();
                $table->index('contact_id');
            }
        });

        Schema::table('user_management', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_management', 'contact_id')) {
                $table->foreignId('contact_id')->nullable()->after('id')->constrained('contacts')->nullOnDelete();
                $table->index('contact_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_management', function (Blueprint $table): void {
            if (Schema::hasColumn('user_management', 'contact_id')) {
                $table->dropConstrainedForeignId('contact_id');
            }
        });

        Schema::table('client_users', function (Blueprint $table): void {
            if (Schema::hasColumn('client_users', 'contact_id')) {
                $table->dropConstrainedForeignId('contact_id');
            }
        });

        Schema::dropIfExists('contact_merge_records');
        Schema::dropIfExists('contact_external_refs');
        Schema::dropIfExists('contact_relations');
        Schema::dropIfExists('contact_addresses');
        Schema::dropIfExists('contact_phones');
        Schema::dropIfExists('contact_emails');
        Schema::dropIfExists('contacts');
    }
};
