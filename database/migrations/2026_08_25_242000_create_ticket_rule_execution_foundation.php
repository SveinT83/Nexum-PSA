<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'ticket_rule_runs',
        'ticket_rule_events',
        'ticket_rule_executions',
        'ticket_rule_action_results',
        'ticket_rule_after_commit_results',
    ];

    /** @var array<string, list<string>> */
    private const TERMINAL_STATUSES = [
        'ticket_rule_runs' => ['succeeded', 'failed', 'no_change', 'loop_blocked'],
        'ticket_rule_events' => ['processed', 'no_change', 'loop_blocked'],
        'ticket_rule_executions' => ['unmatched', 'succeeded', 'no_change', 'failed', 'loop_blocked'],
        'ticket_rule_action_results' => ['succeeded', 'no_change', 'failed', 'not_run', 'rolled_back', 'queued'],
        'ticket_rule_after_commit_results' => ['succeeded', 'failed', 'unresolved'],
    ];

    /** @var array<string, string> */
    private const COMPLETION_COLUMNS = [
        'ticket_rule_runs' => 'completed_at',
        'ticket_rule_events' => 'processed_at',
        'ticket_rule_executions' => 'completed_at',
        'ticket_rule_action_results' => 'completed_at',
        'ticket_rule_after_commit_results' => 'completed_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ticket_rule_versions')
            || ! Schema::hasTable('ticket_rule_authority_fences')) {
            throw new RuntimeException('Ticket Rule versioning must be installed before execution evidence.');
        }

        if (! Schema::hasTable('ticket_rule_runs')) {
            Schema::create('ticket_rule_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('ticket_id');
                $table->string('root_event_key', 80);
                $table->string('source_channel', 64)->nullable();
                $table->string('source_action', 120);
                $table->string('initiator_type', 80)->nullable();
                $table->unsignedBigInteger('initiator_id')->nullable()->index();
                $table->unsignedBigInteger('automation_actor_id')->nullable()->index();
                $table->uuid('correlation_uuid')->unique();
                $table->uuid('causation_uuid')->nullable()->index();
                $table->char('root_idempotency_key', 64)->unique();
                $table->string('mode', 32)->default('runtime');
                $table->unsignedInteger('attempt_number')->default(1);
                $table->unsignedBigInteger('retry_of_run_id')->nullable()->index();
                $table->unsignedBigInteger('authority_generation');
                $table->char('authority_checksum', 64);
                $table->char('published_set_checksum', 64);
                $table->json('published_version_ids');
                $table->string('status', 32)->default('running')->index();
                $table->string('termination_reason', 80)->nullable();
                $table->json('limits_json');
                $table->json('counters_json');
                $table->json('safe_summary_json')->nullable();
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->foreign('ticket_id', 'trr_ticket_fk')
                    ->references('id')->on('tickets')->restrictOnDelete();
                $table->index(['ticket_id', 'status', 'started_at'], 'trr_ticket_status_started_ix');
            });
        }

        if (! Schema::hasTable('ticket_rule_events')) {
            Schema::create('ticket_rule_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('parent_event_id')->nullable()->index();
                $table->unsignedBigInteger('parent_action_result_id')->nullable()->index();
                $table->unsignedInteger('sequence');
                $table->string('event_key', 80)->index();
                $table->char('event_fingerprint', 64)->index();
                $table->char('idempotency_key', 64)->unique();
                $table->string('source_channel', 64)->nullable();
                $table->string('source_action', 120);
                $table->json('changed_fields_json');
                $table->json('before_json');
                $table->json('after_json');
                $table->string('initiator_type', 80)->nullable();
                $table->unsignedBigInteger('initiator_id')->nullable()->index();
                $table->unsignedBigInteger('automation_actor_id')->nullable()->index();
                $table->uuid('correlation_uuid')->index();
                $table->uuid('causation_uuid')->nullable()->index();
                $table->unsignedSmallInteger('chain_depth')->default(0);
                $table->string('status', 32)->default('queued')->index();
                $table->dateTime('occurred_at');
                $table->dateTime('processed_at')->nullable();
                $table->timestamps();

                $table->foreign('run_id', 'tre_run_fk')
                    ->references('id')->on('ticket_rule_runs')->restrictOnDelete();
                $table->foreign('ticket_id', 'tre_ticket_fk')
                    ->references('id')->on('tickets')->restrictOnDelete();
                $table->unique(['run_id', 'sequence'], 'tre_run_sequence_uq');
                $table->unique(['run_id', 'event_fingerprint'], 'tre_run_fingerprint_uq');
                $table->index(['run_id', 'status', 'sequence'], 'tre_run_status_sequence_ix');
            });
        }

        if (! Schema::hasTable('ticket_rule_executions')) {
            Schema::create('ticket_rule_executions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('ticket_rule_id');
                $table->unsignedBigInteger('rule_version_id');
                $table->unsignedInteger('order_position');
                $table->unsignedInteger('attempt_number')->default(1);
                $table->unsignedBigInteger('retry_of_id')->nullable()->index();
                $table->char('precondition_fingerprint', 64);
                $table->char('idempotency_key', 64)->unique();
                $table->char('definition_checksum', 64);
                $table->string('status', 32)->default('running')->index();
                $table->boolean('trigger_relevant')->default(false);
                $table->boolean('conditions_matched')->default(false);
                $table->string('selected_branch', 16)->nullable();
                $table->json('condition_evidence_json')->nullable();
                $table->json('change_summary_json')->nullable();
                $table->boolean('stop_requested')->default(false);
                $table->boolean('stop_applied')->default(false);
                $table->string('failure_code', 80)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->foreign('run_id', 'trx_run_fk')
                    ->references('id')->on('ticket_rule_runs')->restrictOnDelete();
                $table->foreign('event_id', 'trx_event_fk')
                    ->references('id')->on('ticket_rule_events')->restrictOnDelete();
                $table->foreign('ticket_rule_id', 'trx_rule_fk')
                    ->references('id')->on('ticket_rules')->restrictOnDelete();
                $table->foreign('rule_version_id', 'trx_version_fk')
                    ->references('id')->on('ticket_rule_versions')->restrictOnDelete();
                $table->unique(['event_id', 'rule_version_id', 'attempt_number'], 'trx_event_version_attempt_uq');
                $table->index(['run_id', 'status', 'order_position'], 'trx_run_status_order_ix');
            });
        }

        if (! Schema::hasTable('ticket_rule_action_results')) {
            Schema::create('ticket_rule_action_results', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('execution_id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('ticket_rule_id');
                $table->unsignedBigInteger('rule_version_id');
                $table->string('branch', 16);
                $table->unsignedInteger('position');
                $table->string('action_type', 80);
                $table->char('position_key', 64)->index();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->unsignedBigInteger('retry_of_id')->nullable()->index();
                $table->char('precondition_fingerprint', 64);
                $table->char('idempotency_key', 64)->unique();
                $table->json('action_snapshot_json');
                $table->string('status', 32)->default('planned')->index();
                $table->json('change_json')->nullable();
                $table->json('authorization_json')->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->foreign('run_id', 'trar_run_fk')
                    ->references('id')->on('ticket_rule_runs')->restrictOnDelete();
                $table->foreign('event_id', 'trar_event_fk')
                    ->references('id')->on('ticket_rule_events')->restrictOnDelete();
                $table->foreign('execution_id', 'trar_execution_fk')
                    ->references('id')->on('ticket_rule_executions')->restrictOnDelete();
                $table->foreign('ticket_id', 'trar_ticket_fk')
                    ->references('id')->on('tickets')->restrictOnDelete();
                $table->foreign('ticket_rule_id', 'trar_rule_fk')
                    ->references('id')->on('ticket_rules')->restrictOnDelete();
                $table->foreign('rule_version_id', 'trar_version_fk')
                    ->references('id')->on('ticket_rule_versions')->restrictOnDelete();
                $table->unique(['execution_id', 'branch', 'position', 'attempt_number'], 'trar_execution_position_attempt_uq');
                $table->index(['run_id', 'status', 'id'], 'trar_run_status_id_ix');
            });
        }

        if (! Schema::hasTable('ticket_rule_after_commit_results')) {
            Schema::create('ticket_rule_after_commit_results', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('action_result_id');
                $table->unsignedBigInteger('ticket_id');
                $table->char('delivery_key', 64)->index();
                $table->unsignedInteger('attempt_number')->default(1);
                $table->unsignedBigInteger('retry_of_id')->nullable()->index();
                $table->char('precondition_fingerprint', 64);
                $table->char('idempotency_key', 64)->unique();
                $table->string('delivery_type', 80);
                $table->string('status', 32)->default('queued')->index();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->json('safe_payload_json')->nullable();
                $table->char('external_reference_fingerprint', 64)->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->string('failure_message', 500)->nullable();
                $table->dateTime('queued_at');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('run_id', 'tracr_run_fk')
                    ->references('id')->on('ticket_rule_runs')->restrictOnDelete();
                $table->foreign('action_result_id', 'tracr_action_fk')
                    ->references('id')->on('ticket_rule_action_results')->restrictOnDelete();
                $table->foreign('ticket_id', 'tracr_ticket_fk')
                    ->references('id')->on('tickets')->restrictOnDelete();
                $table->unique(
                    ['action_result_id', 'delivery_key', 'attempt_number'],
                    'tracr_action_delivery_attempt_uq',
                );
                $table->index(['run_id', 'status', 'id'], 'tracr_run_status_id_ix');
            });
        }

        $this->addTicketEventRunLink();
        $this->addLineageForeignKeys();

        $this->addAuthorityActivationEvidence();
        $this->rebuildCompletionGuards();
    }

    public function down(): void
    {
        $this->assertNoExecutionEvidenceWouldBeDeleted();
        $this->dropCompletionGuards();

        if (Schema::hasTable('ticket_rule_events')) {
            if ($this->hasForeignKey('ticket_rule_events', ['parent_action_result_id'], 'ticket_rule_action_results')) {
                Schema::table('ticket_rule_events', function (Blueprint $table): void {
                    $table->dropForeign('tre_parent_action_fk');
                });
            }
            if ($this->hasForeignKey('ticket_rule_events', ['parent_event_id'], 'ticket_rule_events')) {
                Schema::table('ticket_rule_events', function (Blueprint $table): void {
                    $table->dropForeign('tre_parent_event_fk');
                });
            }
        }

        if (Schema::hasTable('ticket_events')
            && Schema::hasColumn('ticket_events', 'ticket_rule_run_id')) {
            if ($this->hasForeignKey('ticket_events', ['ticket_rule_run_id'], 'ticket_rule_runs')) {
                Schema::table('ticket_events', function (Blueprint $table): void {
                    $table->dropForeign('ticket_events_rule_run_fk');
                });
            }

            Schema::table('ticket_events', function (Blueprint $table): void {
                $table->dropColumn('ticket_rule_run_id');
            });
        }

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('ticket_rule_authority_fences')) {
            $columns = collect([
                'runtime_activated_at',
                'runtime_activated_by',
                'runtime_activation_checksum',
            ])->filter(fn (string $column): bool => Schema::hasColumn('ticket_rule_authority_fences', $column))->all();

            if ($columns !== []) {
                Schema::table('ticket_rule_authority_fences', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    private function addTicketEventRunLink(): void
    {
        if (! Schema::hasColumn('ticket_events', 'ticket_rule_run_id')) {
            Schema::table('ticket_events', function (Blueprint $table): void {
                $table->unsignedBigInteger('ticket_rule_run_id')->nullable();
            });
        }

        if (! $this->hasIndex('ticket_events', ['ticket_rule_run_id'])) {
            Schema::table('ticket_events', function (Blueprint $table): void {
                $table->index('ticket_rule_run_id', 'ticket_events_rule_run_ix');
            });
        }

        if (! $this->hasForeignKey('ticket_events', ['ticket_rule_run_id'], 'ticket_rule_runs')) {
            Schema::table('ticket_events', function (Blueprint $table): void {
                $table->foreign('ticket_rule_run_id', 'ticket_events_rule_run_fk')
                    ->references('id')->on('ticket_rule_runs')->restrictOnDelete();
            });
        }
    }

    private function addLineageForeignKeys(): void
    {
        $definitions = [
            ['ticket_rule_runs', 'retry_of_run_id', 'ticket_rule_runs', 'trr_retry_fk'],
            ['ticket_rule_events', 'parent_event_id', 'ticket_rule_events', 'tre_parent_event_fk'],
            ['ticket_rule_events', 'parent_action_result_id', 'ticket_rule_action_results', 'tre_parent_action_fk'],
            ['ticket_rule_executions', 'retry_of_id', 'ticket_rule_executions', 'trx_retry_fk'],
            ['ticket_rule_action_results', 'retry_of_id', 'ticket_rule_action_results', 'trar_retry_fk'],
            ['ticket_rule_after_commit_results', 'retry_of_id', 'ticket_rule_after_commit_results', 'tracr_retry_fk'],
        ];

        foreach ($definitions as [$tableName, $column, $foreignTable, $constraint]) {
            if ($this->hasForeignKey($tableName, [$column], $foreignTable)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use (
                $column,
                $foreignTable,
                $constraint,
            ): void {
                $table->foreign($column, $constraint)
                    ->references('id')->on($foreignTable)->restrictOnDelete();
            });
        }
    }

    /** @param list<string> $columns */
    private function hasForeignKey(string $table, array $columns, string $foreignTable): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === $columns
                && ($foreignKey['foreign_table'] ?? null) === $foreignTable
                && ($foreignKey['foreign_columns'] ?? []) === ['id']);
    }

    /** @param list<string> $columns */
    private function hasIndex(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'] ?? []) === $columns);
    }

    private function addAuthorityActivationEvidence(): void
    {
        $hasActivatedAt = Schema::hasColumn('ticket_rule_authority_fences', 'runtime_activated_at');
        $hasActivatedBy = Schema::hasColumn('ticket_rule_authority_fences', 'runtime_activated_by');
        $hasActivationChecksum = Schema::hasColumn('ticket_rule_authority_fences', 'runtime_activation_checksum');

        if ($hasActivatedAt && $hasActivatedBy && $hasActivationChecksum) {
            return;
        }

        Schema::table('ticket_rule_authority_fences', function (Blueprint $table) use (
            $hasActivatedAt,
            $hasActivatedBy,
            $hasActivationChecksum,
        ): void {
            if (! $hasActivatedAt) {
                $table->dateTime('runtime_activated_at')->nullable();
            }
            if (! $hasActivatedBy) {
                $table->unsignedBigInteger('runtime_activated_by')->nullable()->index();
            }
            if (! $hasActivationChecksum) {
                $table->char('runtime_activation_checksum', 64)->nullable()->index();
            }
        });
    }

    private function assertNoExecutionEvidenceWouldBeDeleted(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Ticket Rule execution after durable evidence exists.');
            }
        }

        if (Schema::hasTable('ticket_rule_authority_fences')
            && Schema::hasColumn('ticket_rule_authority_fences', 'runtime_activation_checksum')) {
            $activated = DB::table('ticket_rule_authority_fences')
                ->where(function ($query): void {
                    $query->where('runtime_authority', '<>', 'legacy')
                        ->orWhereNotNull('runtime_activated_at')
                        ->orWhereNotNull('runtime_activated_by')
                        ->orWhereNotNull('runtime_activation_checksum');
                })
                ->exists();

            if ($activated) {
                throw new RuntimeException('Cannot roll back Ticket Rule execution after runtime activation evidence exists.');
            }
        }
    }

    private function rebuildCompletionGuards(): void
    {
        $this->dropCompletionGuards();

        foreach (self::TERMINAL_STATUSES as $table => $statuses) {
            $this->createCompletionGuard($table, $statuses, 'UPDATE');
            $this->createCompletionGuard($table, $statuses, 'DELETE');
        }
    }

    private function dropCompletionGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::TERMINAL_STATUSES as $table => $_statuses) {
            foreach (['update', 'delete'] as $operation) {
                $name = $this->triggerName($table, $operation);

                if ($driver === 'mysql') {
                    DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
                } elseif ($driver === 'sqlite') {
                    DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
                } else {
                    throw new RuntimeException("Ticket Rule execution immutability is unsupported for [{$driver}].");
                }
            }
        }
    }

    /** @param list<string> $statuses */
    private function createCompletionGuard(string $table, array $statuses, string $operation): void
    {
        $driver = DB::connection()->getDriverName();
        $name = $this->triggerName($table, strtolower($operation));
        $quoted = implode(', ', array_map(fn (string $status): string => DB::getPdo()->quote($status), $statuses));
        $message = 'Completed Ticket Rule evidence is immutable';
        $completionColumn = self::COMPLETION_COLUMNS[$table];
        $condition = $operation === 'DELETE'
            ? '1 = 1'
            : "OLD.{$completionColumn} IS NOT NULL OR OLD.status IN ({$quoted})";

        if ($driver === 'mysql') {
            DB::unprepared(
                "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW "
                ."BEGIN IF {$condition} THEN "
                ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} "
                ."WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            );

            return;
        }

        throw new RuntimeException("Ticket Rule execution immutability is unsupported for [{$driver}].");
    }

    private function triggerName(string $table, string $operation): string
    {
        return match ($table) {
            'ticket_rule_runs' => "trr_terminal_{$operation}_guard",
            'ticket_rule_events' => "tre_terminal_{$operation}_guard",
            'ticket_rule_executions' => "trx_terminal_{$operation}_guard",
            'ticket_rule_action_results' => "trar_terminal_{$operation}_guard",
            'ticket_rule_after_commit_results' => "tracr_terminal_{$operation}_guard",
        };
    }
};
