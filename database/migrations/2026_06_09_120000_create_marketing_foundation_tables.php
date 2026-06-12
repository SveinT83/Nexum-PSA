<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_consent_categories')) {
            Schema::create('marketing_consent_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_interest_tags')) {
            Schema::create('marketing_interest_tags', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_lists')) {
            Schema::create('marketing_lists', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status')->default('active')->index();
                $table->string('audience_type')->default('all_business_contacts')->index();
                $table->foreignId('consent_category_id')->nullable()->constrained('marketing_consent_categories')->nullOnDelete();
                $table->json('segment_criteria')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamp('last_resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('marketing_list_members')) {
            Schema::create('marketing_list_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketing_list_id')->constrained('marketing_lists')->cascadeOnDelete();
                $table->string('source_type', 50);
                $table->unsignedBigInteger('source_id');
                $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->string('email');
                $table->string('name')->nullable();
                $table->string('status')->default('eligible')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['marketing_list_id', 'source_type', 'source_id'], 'marketing_list_members_source_unique');
                $table->index(['marketing_list_id', 'status']);
                $table->index('email');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_list_members');
        Schema::dropIfExists('marketing_lists');
        Schema::dropIfExists('marketing_interest_tags');
        Schema::dropIfExists('marketing_consent_categories');
    }
};
