<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exchange_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->string('direction', 20)->default('export')->index();
            $table->string('format', 20)->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('data_exchange_profile_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('data_exchange_profiles')->cascadeOnDelete();
            $table->string('source_key')->index();
            $table->string('alias')->nullable();
            $table->string('relationship_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('data_exchange_profile_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('data_exchange_profiles')->cascadeOnDelete();
            $table->foreignId('profile_source_id')->nullable()->constrained('data_exchange_profile_sources')->nullOnDelete();
            $table->string('source_key')->index();
            $table->string('field_key');
            $table->string('output_key')->nullable();
            $table->string('label')->nullable();
            $table->json('transform')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['profile_id', 'sort_order'], 'data_exchange_profile_fields_order_idx');
        });

        Schema::create('data_exchange_profile_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('data_exchange_profiles')->cascadeOnDelete();
            $table->foreignId('profile_source_id')->nullable()->constrained('data_exchange_profile_sources')->nullOnDelete();
            $table->string('field_key');
            $table->string('operator', 40);
            $table->json('value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('data_exchange_profile_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('data_exchange_profiles')->cascadeOnDelete();
            $table->string('output_format', 20)->index();
            $table->string('mapping_key');
            $table->text('source_expression')->nullable();
            $table->text('default_value')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->unique(['profile_id', 'output_format', 'mapping_key'], 'data_exchange_profile_mapping_unique');
        });

        Schema::create('data_exchange_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('data_exchange_profiles')->nullOnDelete();
            $table->string('direction', 20)->index();
            $table->string('status', 30)->default('queued')->index();
            $table->string('trigger_type', 30)->default('manual')->index();
            $table->foreignId('triggered_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status'], 'data_exchange_runs_profile_status_idx');
        });

        Schema::create('data_exchange_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('data_exchange_runs')->nullOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('data_exchange_profiles')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->string('format', 20)->nullable()->index();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum')->nullable();
            $table->timestamp('retention_until')->nullable()->index();
            $table->foreignId('generated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_exchange_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('data_exchange_profiles')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('data_exchange_runs')->nullOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('data_exchange_files')->nullOnDelete();
            $table->string('event_type', 80)->index();
            $table->string('outcome', 40)->default('recorded')->index();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exchange_audit_events');
        Schema::dropIfExists('data_exchange_files');
        Schema::dropIfExists('data_exchange_runs');
        Schema::dropIfExists('data_exchange_profile_mappings');
        Schema::dropIfExists('data_exchange_profile_filters');
        Schema::dropIfExists('data_exchange_profile_fields');
        Schema::dropIfExists('data_exchange_profile_sources');
        Schema::dropIfExists('data_exchange_profiles');
    }
};
