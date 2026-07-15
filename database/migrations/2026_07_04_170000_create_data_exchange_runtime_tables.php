<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exchange_delivery_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('data_exchange_profiles')->nullOnDelete();
            $table->string('name');
            $table->string('type', 30)->default('local')->index();
            $table->string('direction', 20)->default('export')->index();
            $table->string('credential_reference')->nullable();
            $table->string('filesystem_disk')->nullable();
            $table->string('remote_path')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('data_exchange_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->constrained('data_exchange_profiles')->cascadeOnDelete();
            $table->foreignId('delivery_target_id')->nullable()->constrained('data_exchange_delivery_targets')->nullOnDelete();
            $table->string('direction', 20)->default('export')->index();
            $table->boolean('active')->default(false)->index();
            $table->string('frequency', 30)->default('daily')->index();
            $table->string('run_time', 5)->nullable();
            $table->json('weekdays')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('last_run_id')->nullable()->constrained('data_exchange_runs')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('data_exchange_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained('data_exchange_schedules')->nullOnDelete();
            $table->foreignId('delivery_target_id')->nullable()->constrained('data_exchange_delivery_targets')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('data_exchange_runs')->nullOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('data_exchange_files')->nullOnDelete();
            $table->string('direction', 20)->default('export')->index();
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('attempted_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('data_exchange_import_previews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('data_exchange_profiles')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('data_exchange_runs')->nullOnDelete();
            $table->string('status', 30)->default('previewed')->index();
            $table->string('source_key')->nullable()->index();
            $table->string('format', 20)->nullable()->index();
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->json('mapping')->nullable();
            $table->json('rows')->nullable();
            $table->json('errors')->nullable();
            $table->json('summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('committed_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exchange_import_previews');
        Schema::dropIfExists('data_exchange_delivery_attempts');
        Schema::dropIfExists('data_exchange_schedules');
        Schema::dropIfExists('data_exchange_delivery_targets');
    }
};
