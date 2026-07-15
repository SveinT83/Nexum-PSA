<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('success_message')->nullable();
            $table->string('target_type')->default('review_only')->index();
            $table->boolean('auto_create_client')->default(false);
            $table->boolean('auto_create_contact')->default(true);
            $table->foreignId('owner_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('spam_honeypot_field')->default('intake_website');
            $table->unsignedTinyInteger('max_files')->default(5);
            $table->unsignedInteger('max_file_size_kb')->default(20480);
            $table->json('allowed_mime_types')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('intake_form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_form_id')->constrained('intake_forms')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('field_type')->default('text');
            $table->string('maps_to')->nullable()->index();
            $table->text('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('max_files')->nullable();
            $table->unsignedInteger('max_file_size_kb')->nullable();
            $table->json('allowed_mime_types')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['intake_form_id', 'key']);
            $table->index(['intake_form_id', 'is_active', 'sort_order']);
        });

        Schema::create('intake_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_form_id')->nullable()->constrained('intake_forms')->nullOnDelete();
            $table->string('status')->default('new')->index();
            $table->text('source_url')->nullable();
            $table->text('referrer')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('honeypot_value')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->foreignId('matched_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matched_site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignId('matched_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('matched_client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->string('target_type')->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('routing_result')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('intake_submission_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_submission_id')->constrained('intake_submissions')->cascadeOnDelete();
            $table->foreignId('intake_form_field_id')->nullable()->constrained('intake_form_fields')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path', 1024);
            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha1', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['intake_submission_id', 'intake_form_field_id'], 'intake_attach_submission_field_idx');
        });

        Schema::create('intake_submission_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('intake_submission_id')->constrained('intake_submissions')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('type')->index();
            $table->text('message')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_submission_events');
        Schema::dropIfExists('intake_submission_attachments');
        Schema::dropIfExists('intake_submissions');
        Schema::dropIfExists('intake_form_fields');
        Schema::dropIfExists('intake_forms');
    }
};
