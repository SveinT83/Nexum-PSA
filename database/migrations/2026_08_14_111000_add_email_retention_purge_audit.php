<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_retention_purge_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('retention_months');
            $table->dateTime('cutoff_at')->index();
            $table->string('status', 40)->default('running')->index();
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('protected_count')->default(0);
            $table->unsignedInteger('purged_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('reason_counts_json')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_retention_purge_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_retention_purge_run_id')
                ->constrained('email_retention_purge_runs', indexName: 'email_retention_attempt_run_fk')
                ->cascadeOnDelete();

            // These identifiers deliberately have no foreign keys so the audit survives a successful purge.
            $table->unsignedBigInteger('email_message_id')->index();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('status', 40)->default('checking')->index();
            $table->json('reasons_json')->nullable();
            $table->boolean('had_raw_payload')->default(false);
            $table->unsignedInteger('local_attachment_file_count')->default(0);
            $table->string('failure_code', 80)->nullable();
            $table->dateTime('retry_after')->nullable()->index();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['email_retention_purge_run_id', 'email_message_id'],
                'email_retention_attempt_run_message_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_retention_purge_attempts');
        Schema::dropIfExists('email_retention_purge_runs');
    }
};
