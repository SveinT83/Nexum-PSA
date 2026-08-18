<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->unsignedInteger('expected_placement_sync_version')->nullable()->after('request_json');
            $table->unsignedBigInteger('expected_provider_uid')->nullable()->after('expected_placement_sync_version');
            $table->unsignedBigInteger('expected_uid_validity')->nullable()->after('expected_provider_uid');
            $table->timestamp('expected_folder_updated_at')->nullable()->after('expected_uid_validity');
            $table->unsignedInteger('max_attempts')->default(5)->after('attempts');
            $table->string('failure_classification', 40)->nullable()->after('error_message');
            $table->string('status_reason_code', 100)->nullable()->after('failure_classification');
            $table->text('status_reason_message')->nullable()->after('status_reason_code');
            $table->timestamp('last_attempt_at')->nullable()->after('next_attempt_at');
            $table->timestamp('reconciliation_required_at')->nullable()->after('last_attempt_at');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_required_at');

            $table->index(
                ['status', 'failure_classification', 'next_attempt_at'],
                'email_remote_ops_due_recovery_index',
            );
            $table->index('failure_classification', 'email_remote_ops_failure_class_index');
        });

        Schema::create('email_remote_operation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_remote_operation_id');
            $table->foreign(
                'email_remote_operation_id',
                'email_remote_attempt_operation_fk',
            )->references('id')->on('email_remote_operations')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('attempt_kind', 30)->default('mutation');
            $table->string('trigger', 30)->default('initial');
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->foreign('triggered_by', 'email_remote_attempt_triggered_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->string('status', 30)->default('running');
            $table->string('outcome', 50)->nullable();
            $table->string('failure_classification', 40)->nullable();
            $table->string('reason_code', 100)->nullable();
            $table->text('reason_message')->nullable();
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->json('error_json')->nullable();
            // MariaDB strict mode rejects required TIMESTAMP columns without
            // explicit defaults. DATETIME keeps these application-owned audit
            // instants portable across Dev and the SQLite test database.
            $table->dateTime('started_at');
            $table->dateTime('provider_started_at')->nullable();
            $table->dateTime('provider_finished_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['email_remote_operation_id', 'attempt_number'],
                'email_remote_attempt_number_unique',
            );
            $table->index(
                ['email_remote_operation_id', 'started_at'],
                'email_remote_attempt_operation_started_index',
            );
            $table->index('attempt_kind', 'email_remote_attempt_kind_index');
            $table->index('trigger', 'email_remote_attempt_trigger_index');
            $table->index('status', 'email_remote_attempt_status_index');
            $table->index('outcome', 'email_remote_attempt_outcome_index');
            $table->index('failure_classification', 'email_remote_attempt_failure_index');
        });

        $this->backfillExpectedEvidence();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_remote_operation_attempts');

        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->dropIndex('email_remote_ops_due_recovery_index');
            $table->dropIndex('email_remote_ops_failure_class_index');
            $table->dropColumn([
                'expected_placement_sync_version',
                'expected_provider_uid',
                'expected_uid_validity',
                'expected_folder_updated_at',
                'max_attempts',
                'failure_classification',
                'status_reason_code',
                'status_reason_message',
                'last_attempt_at',
                'reconciliation_required_at',
                'reconciled_at',
            ]);
        });
    }

    private function backfillExpectedEvidence(): void
    {
        DB::table('email_remote_operations')
            ->select(['id', 'email_mailbox_placement_id', 'email_folder_id'])
            ->orderBy('id')
            ->chunkById(250, function ($operations): void {
                foreach ($operations as $operation) {
                    $values = [];

                    if ($operation->email_mailbox_placement_id) {
                        $placement = DB::table('email_mailbox_placements')
                            ->where('id', $operation->email_mailbox_placement_id)
                            ->first(['sync_version', 'imap_uid', 'imap_uid_validity']);

                        if ($placement) {
                            $values['expected_placement_sync_version'] = (int) $placement->sync_version;
                            $values['expected_provider_uid'] = (int) $placement->imap_uid;
                            $values['expected_uid_validity'] = (int) $placement->imap_uid_validity;
                        }
                    }

                    if ($operation->email_folder_id) {
                        $folder = DB::table('email_folders')
                            ->where('id', $operation->email_folder_id)
                            ->first(['updated_at']);

                        if ($folder) {
                            $values['expected_folder_updated_at'] = $folder->updated_at;
                        }
                    }

                    if ($values !== []) {
                        DB::table('email_remote_operations')
                            ->where('id', $operation->id)
                            ->update($values);
                    }
                }
            });
    }
};
