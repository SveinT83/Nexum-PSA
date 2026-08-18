<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIGRATION_NAME = '2026_08_16_118500_add_durable_inbound_notification_fanout';

    private const FANOUT_TABLE = 'notification_inbound_email_fanouts';

    private const REPAIR_TABLE = 'notification_inbound_ticket_message_repairs';

    public function up(): void
    {
        $this->addSettingCursorIndex();
        $this->dropSettingIdentityGuard();
        $this->addSettingIdentityGuard();
        $this->addTicketMessagePointer();
        $this->dropTicketMessageEvidenceDeleteGuards();
        $this->addTicketMessageEvidenceDeleteGuards();
        $this->addRemoteOperationIndex();
        $this->addRemoteOperationStatusCursorIndex();
        $this->addRemoteOperationFolderIndex();
        $this->addReconciliationRecoveryIndexes();
        $this->addExternalDeliveryRecoveryIndex();
        $this->addExternalDeliveryAttestationColumns();
        $this->createRepairCursor();
        $this->attestFirstSealRepairProvenance();
        $this->attestTableContract(
            self::REPAIR_TABLE,
            $this->repairContract('', DB::connection()->getDriverName()),
            'Inbound notification Ticket-message repair evidence is malformed.',
        );
        $this->dropRepairMonotonicGuard();
        $this->dropRepairGuard();
        $this->addRepairGuard();
        $this->addRepairMonotonicGuard();
        $this->dropRepairDeleteGuard();
        $this->addRepairDeleteGuard();
        $this->createFanouts();
        $this->ensureFanoutPageWitnessColumns();
        $this->attestFirstSealFanoutProvenance();
        $this->attestTableContract(
            self::FANOUT_TABLE,
            $this->fanoutContract('', DB::connection()->getDriverName()),
            'Inbound notification fanout evidence is malformed.',
        );
        $this->dropFanoutMonotonicGuard();
        $this->dropFanoutInitialGuard();
        $this->dropFanoutGuard();
        $this->addFanoutGuard();
        $this->addFanoutInitialGuard();
        $this->addFanoutMonotonicGuard();
        $this->dropFanoutDeleteGuard();
        $this->addFanoutDeleteGuard();
        // SQLite refreshes foreign keys by rebuilding the table. Remove every
        // trigger that names the live table before that rebuild, then reinstall
        // the exact authority and state machine from the current definition.
        $this->attestFirstSealExternalDeliveryProvenance();
        $this->attestTableContract(
            'notification_inbound_external_deliveries',
            $this->externalDeliveryContract('', DB::connection()->getDriverName()),
            'Inbound notification external-delivery evidence is malformed.',
        );
        $this->dropExternalDeliveryDeleteGuard();
        $this->dropExternalDeliveryMonotonicGuard();
        $this->dropExternalDeliveryInitialGuard();
        $this->dropExternalDeliveryGuard();
        $this->dropExternalDeliveryFanoutAuthority();
        $this->addExternalDeliveryFanoutAuthority();
        $this->addExternalDeliveryGuard();
        $this->addExternalDeliveryInitialGuard();
        $this->addExternalDeliveryMonotonicGuard();
        $this->addExternalDeliveryDeleteGuard();

        // These one-time deploy checks deliberately run only after every
        // current guard has been reinstalled. MariaDB cannot put a CHECK on a
        // column participating in ON DELETE SET NULL, so the trigger state
        // machines protect new writes while this quiesced full-table
        // attestation validates any legacy or interrupted-migration rows.
        $this->attestFirstSealProvenance();
        $this->attestCurrentContractRows();
    }

    public function down(): void
    {
        if (Schema::hasTable('email_provider_reconciliation_items')
            && DB::table('email_provider_reconciliation_items')
                ->where('automation_status', 'awaiting_notification_fanout')
                ->exists()) {
            throw new RuntimeException(
                'Awaiting reconciliation notification fanouts must be settled before schema rollback.',
            );
        }
        if (Schema::hasTable('notification_inbound_external_deliveries')
            && DB::table('notification_inbound_external_deliveries')->exists()) {
            throw new RuntimeException(
                'Inbound notification external-delivery evidence must be preserved before schema rollback.',
            );
        }
        if (Schema::hasTable(self::FANOUT_TABLE) && DB::table(self::FANOUT_TABLE)->exists()) {
            throw new RuntimeException(
                'Inbound notification fanout evidence must be preserved before schema rollback.',
            );
        }
        if (Schema::hasTable(self::REPAIR_TABLE)
            && DB::table(self::REPAIR_TABLE)
                ->where(function ($repair): void {
                    $repair->where('status', 'failed')->orWhereNotNull('error_code');
                })
                ->exists()) {
            throw new RuntimeException(
                'Failed inbound Ticket-message repair evidence must be resolved before schema rollback.',
            );
        }
        if (Schema::hasColumn('ticket_messages', 'source_inbound_email_message_id')
            && DB::table('ticket_messages')->whereNotNull('source_inbound_email_message_id')->exists()) {
            throw new RuntimeException(
                'Inbound Ticket-message links must be repaired before schema rollback.',
            );
        }

        $this->dropExternalDeliveryDeleteGuard();
        $this->dropExternalDeliveryMonotonicGuard();
        $this->dropExternalDeliveryInitialGuard();
        $this->dropExternalDeliveryGuard();
        $this->dropExternalDeliveryFanoutAuthority();
        foreach (['inbound_notification_fanout_id', 'canonical_payload_hash'] as $column) {
            if (! Schema::hasColumn('notification_inbound_external_deliveries', $column)) {
                continue;
            }
            Schema::table('notification_inbound_external_deliveries', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
        $this->dropFanoutDeleteGuard();
        $this->dropFanoutMonotonicGuard();
        $this->dropFanoutInitialGuard();
        $this->dropFanoutGuard();
        Schema::dropIfExists(self::FANOUT_TABLE);
        $this->dropRepairDeleteGuard();
        $this->dropRepairMonotonicGuard();
        $this->dropRepairGuard();
        Schema::dropIfExists(self::REPAIR_TABLE);
        $this->dropTicketMessageMetadataGuard();
        $this->dropTicketMessagePointerMonotonicGuard();
        $this->dropTicketMessagePointerInitialGuard();
        $this->dropTicketMessagePointerGuard();
        $this->dropTicketMessageEvidenceDeleteGuards();

        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_unresolved_placement_ix')) {
            Schema::table('email_remote_operations', function (Blueprint $table): void {
                $table->dropIndex('email_remote_ops_unresolved_placement_ix');
            });
        }
        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_placement_status_cursor_ix')) {
            Schema::table('email_remote_operations', function (Blueprint $table): void {
                $table->dropIndex('email_remote_ops_placement_status_cursor_ix');
            });
        }
        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_unresolved_folder_ix')) {
            Schema::table('email_remote_operations', function (Blueprint $table): void {
                $table->dropIndex('email_remote_ops_unresolved_folder_ix');
            });
        }
        $this->dropReconciliationRecoveryIndexes();
        if ($this->hasIndex('notification_inbound_external_deliveries', 'notif_inbound_ext_status_cursor_ix')) {
            Schema::table('notification_inbound_external_deliveries', function (Blueprint $table): void {
                $table->dropIndex('notif_inbound_ext_status_cursor_ix');
            });
        }
        if (Schema::hasColumn('ticket_messages', 'inbound_email_message_id')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                if ($this->hasForeign('ticket_messages', 'ticket_messages_inbound_email_fk')) {
                    $table->dropForeign('ticket_messages_inbound_email_fk');
                }
                $table->dropColumn('inbound_email_message_id');
            });
        }
        if (Schema::hasColumn('ticket_messages', 'source_inbound_email_message_id')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                if ($this->hasIndex('ticket_messages', 'ticket_messages_ticket_source_inbound_ix')) {
                    $table->dropIndex('ticket_messages_ticket_source_inbound_ix');
                }
                if ($this->hasIndex('ticket_messages', 'ticket_messages_source_inbound_email_uq')) {
                    $table->dropUnique('ticket_messages_source_inbound_email_uq');
                }
                $table->dropColumn('source_inbound_email_message_id');
            });
        }
        if ($this->hasIndex('notification_settings', 'notification_settings_type_cursor_ix')) {
            Schema::table('notification_settings', function (Blueprint $table): void {
                $table->dropIndex('notification_settings_type_cursor_ix');
            });
        }
        $this->dropSettingIdentityGuard();
    }

    private function addSettingCursorIndex(): void
    {
        if ($this->hasIndex('notification_settings', 'notification_settings_type_cursor_ix')) {
            return;
        }

        Schema::table('notification_settings', function (Blueprint $table): void {
            $table->index(
                ['notification_type', 'id'],
                'notification_settings_type_cursor_ix',
            );
        });
    }

    /** Freeze the identity used by a fanout's settings high-water. */
    private function addSettingIdentityGuard(): void
    {
        $table = 'notification_settings';
        $trigger = 'notification_settings_identity_immutable';
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $same = in_array($driver, ['mysql', 'mariadb'], true)
            ? 'NEW.id <=> OLD.id and NEW.user_id <=> OLD.user_id'
                .' and NEW.notification_type <=> OLD.notification_type'
            : 'NEW.id is OLD.id and NEW.user_id is OLD.user_id'
                .' and NEW.notification_type is OLD.notification_type';

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if coalesce(({$same}), 0) = 0 then signal sqlstate '45000'"
                ." set message_text = 'notification_setting_identity_immutable'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}`"
                ." when coalesce(({$same}), 0) = 0 begin"
                ." select raise(abort, 'notification_setting_identity_immutable'); end",
            );
        }
    }

    private function dropSettingIdentityGuard(): void
    {
        DB::unprepared('drop trigger if exists `notification_settings_identity_immutable`');
    }

    private function addTicketMessagePointer(): void
    {
        if (! Schema::hasColumn('ticket_messages', 'source_inbound_email_message_id')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_inbound_email_message_id')->nullable()->after('ticket_id');
            });
        }
        if (! Schema::hasColumn('ticket_messages', 'inbound_email_message_id')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->unsignedBigInteger('inbound_email_message_id')
                    ->nullable()
                    ->after('source_inbound_email_message_id');
            });
        }
        if ($this->hasIndex('ticket_messages', 'ticket_messages_inbound_email_uq')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->dropUnique('ticket_messages_inbound_email_uq');
            });
        }
        if (! $this->hasIndex('ticket_messages', 'ticket_messages_source_inbound_email_uq')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->unique(
                    'source_inbound_email_message_id',
                    'ticket_messages_source_inbound_email_uq',
                );
            });
        }
        if (! $this->hasIndex('ticket_messages', 'ticket_messages_ticket_source_inbound_ix')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->index(
                    ['ticket_id', 'source_inbound_email_message_id', 'id'],
                    'ticket_messages_ticket_source_inbound_ix',
                );
            });
        }
        $this->attestFirstSealTicketMessagePointerProvenance();
        $this->attestTableContract(
            'ticket_messages',
            $this->ticketMessagePointerContract(''),
            'Inbound Ticket-message pointer evidence is malformed.',
        );
        // This migration is additive but MySQL/MariaDB DDL auto-commits. A
        // retry must replace an older same-named definition rather than infer
        // correctness from its name after new columns have been added. Drop a
        // stale CHECK before installing the SET NULL foreign key: MariaDB
        // refuses that FK while a CHECK still references its live column.
        $this->dropTicketMessageMetadataGuard();
        $this->dropTicketMessagePointerMonotonicGuard();
        $this->dropTicketMessagePointerInitialGuard();
        $this->dropTicketMessagePointerGuard();
        if (! $this->hasForeign('ticket_messages', 'ticket_messages_inbound_email_fk')) {
            Schema::table('ticket_messages', function (Blueprint $table): void {
                $table->foreign('inbound_email_message_id', 'ticket_messages_inbound_email_fk')
                    ->references('id')->on('email_messages')->nullOnDelete();
            });
        }
        $this->addTicketMessagePointerGuard();
        $this->addTicketMessagePointerInitialGuard();
        $this->addTicketMessagePointerMonotonicGuard();
        $this->addTicketMessageMetadataGuard();
    }

    private function addTicketMessagePointerGuard(): void
    {
        $table = 'ticket_messages';
        $constraint = 'ticket_messages_inbound_pointer_ck';
        $driver = DB::connection()->getDriverName();
        $valid = $this->ticketMessagePointerContract($driver === 'sqlite' ? 'NEW.' : '');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MariaDB rejects CHECK constraints which reference a column used
            // by an ON DELETE SET NULL foreign key. The strict INSERT and
            // UPDATE triggers below enforce this same expression instead.
            return;
        }

        $this->ensureSqliteContractTrigger(
            "{$constraint}_insert",
            "before insert on `{$table}`",
            $valid,
            'ticket_message_inbound_pointer_invalid',
        );
        $this->ensureSqliteContractTrigger(
            "{$constraint}_update",
            "before update on `{$table}`",
            $valid,
            'ticket_message_inbound_pointer_invalid',
        );
    }

    private function dropTicketMessagePointerGuard(): void
    {
        $table = 'ticket_messages';
        $constraint = 'ticket_messages_inbound_pointer_ck';
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)
            && Schema::hasTable($table)
            && $this->hasConstraint($table, $constraint)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            $this->dropSqliteGuardPair($constraint);
        }
    }

    private function addTicketMessagePointerInitialGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $metadataType = in_array($driver, ['mysql', 'mariadb'], true)
            ? "json_type(json_extract(NEW.metadata, '$.email_message_id'))"
            : "json_type(NEW.metadata, '$.email_message_id')";
        $metadataValue = in_array($driver, ['mysql', 'mariadb'], true)
            ? "cast(json_unquote(json_extract(NEW.metadata, '$.email_message_id')) as decimal(20,0))"
            : "cast(json_extract(NEW.metadata, '$.email_message_id') as integer)";
        $integerType = in_array($driver, ['mysql', 'mariadb'], true) ? 'INTEGER' : 'integer';
        $nullType = in_array($driver, ['mysql', 'mariadb'], true) ? 'NULL' : 'null';
        $metadataAbsent = "(NEW.metadata is null or {$metadataType} is null or {$metadataType} = '{$nullType}')";
        $metadataExact = "({$metadataType} = '{$integerType}'"
            .' and NEW.source_inbound_email_message_id is not null'
            ." and {$metadataValue} >= 1"
            .' and '.$metadataValue.' = NEW.source_inbound_email_message_id)';
        $valid = '((NEW.source_inbound_email_message_id is null'
            .' and NEW.inbound_email_message_id is null)'
            .' or (NEW.source_inbound_email_message_id is not null'
            .' and NEW.source_inbound_email_message_id >= 1'
            .' and NEW.inbound_email_message_id is not null'
            .' and NEW.inbound_email_message_id = NEW.source_inbound_email_message_id))'
            .' and ((NEW.source_inbound_email_message_id is null and '.$metadataAbsent.')'
            .' or (NEW.source_inbound_email_message_id is not null'
            .' and ('.$metadataAbsent.' or '.$metadataExact.')))';
        $this->addInsertGuard(
            'ticket_messages',
            'ticket_messages_inbound_pointer_initial',
            $valid,
            'ticket_message_inbound_pointer_initial_invalid',
        );
    }

    private function dropTicketMessagePointerInitialGuard(): void
    {
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_pointer_initial`');
    }

    private function addTicketMessagePointerMonotonicGuard(): void
    {
        $table = 'ticket_messages';
        $trigger = 'ticket_messages_inbound_pointer_monotonic';
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $same = in_array($driver, ['mysql', 'mariadb'], true) ? '<=>' : 'is';
        $unchangedOrDetached = "NEW.source_inbound_email_message_id {$same} OLD.source_inbound_email_message_id"
            ." and (NEW.inbound_email_message_id {$same} OLD.inbound_email_message_id"
            .' or (OLD.inbound_email_message_id is not null and NEW.inbound_email_message_id is null))';
        $initialLink = 'OLD.source_inbound_email_message_id is null'
            .' and OLD.inbound_email_message_id is null'
            .' and NEW.source_inbound_email_message_id is not null'
            .' and NEW.source_inbound_email_message_id >= 1'
            .' and NEW.inbound_email_message_id is not null'
            .' and NEW.inbound_email_message_id = NEW.source_inbound_email_message_id';
        $contract = $this->ticketMessagePointerContract('NEW.');
        $allowed = "({$unchangedOrDetached}) or ({$initialLink})";
        $invalid = "coalesce((({$contract}) and ({$allowed})), 0) = 0";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if {$invalid} then signal sqlstate '45000'"
                ." set message_text = 'ticket_message_inbound_pointer_immutable'; end if; end",
            );

            return;
        }

        DB::unprepared(
            "create trigger `{$trigger}` before update on `{$table}`"
            ." when {$invalid} begin"
            ." select raise(abort, 'ticket_message_inbound_pointer_immutable'); end",
        );
    }

    private function dropTicketMessagePointerMonotonicGuard(): void
    {
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_pointer_monotonic`');
    }

    /**
     * Stop old/raw writers from adding legacy JSON evidence beyond the frozen
     * repair high-water. Existing pre-trigger rows may remain temporarily
     * inconsistent until the bounded repair updates them to an exact pointer.
     */
    private function addTicketMessageMetadataGuard(): void
    {
        $table = 'ticket_messages';
        $trigger = 'ticket_messages_inbound_metadata_consistent';
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        $metadataType = in_array($driver, ['mysql', 'mariadb'], true)
            ? "json_type(json_extract(NEW.metadata, '$.email_message_id'))"
            : "json_type(NEW.metadata, '$.email_message_id')";
        $metadataValue = in_array($driver, ['mysql', 'mariadb'], true)
            ? "cast(json_unquote(json_extract(NEW.metadata, '$.email_message_id')) as decimal(20,0))"
            : "cast(json_extract(NEW.metadata, '$.email_message_id') as integer)";
        $integerType = in_array($driver, ['mysql', 'mariadb'], true) ? 'INTEGER' : 'integer';
        $nullType = in_array($driver, ['mysql', 'mariadb'], true) ? 'NULL' : 'null';
        $metadataAbsent = "(NEW.metadata is null or {$metadataType} is null"
            ." or {$metadataType} = '{$nullType}')";
        $metadataExact = "({$metadataType} = '{$integerType}'"
            ." and {$metadataValue} >= 1"
            .' and '.$metadataValue.' = NEW.source_inbound_email_message_id)';
        $valid = '((NEW.source_inbound_email_message_id is null and '.$metadataAbsent.')'
            .' or (NEW.source_inbound_email_message_id is not null'
            .' and ('.$metadataAbsent.' or '.$metadataExact.')))';
        $invalid = "coalesce(({$valid}), 0) = 0";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if {$invalid} then signal sqlstate '45000'"
                ." set message_text = 'ticket_message_inbound_metadata_invalid'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}`"
                ." when {$invalid} begin"
                ." select raise(abort, 'ticket_message_inbound_metadata_invalid'); end",
            );
        }
    }

    private function dropTicketMessageMetadataGuard(): void
    {
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_metadata_consistent`');
    }

    /**
     * Preserve the frozen inbound-event identity across both direct child
     * deletion and the Ticket table's database-level cascade. Soft deletion
     * remains available because it is an ordinary guarded UPDATE.
     */
    private function addTicketMessageEvidenceDeleteGuards(): void
    {
        $childTrigger = 'ticket_messages_inbound_evidence_no_delete';
        $parentTrigger = 'tickets_inbound_evidence_no_cascade';
        $driver = DB::connection()->getDriverName();

        if (! $this->hasTrigger($childTrigger)) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared(
                    "create trigger `{$childTrigger}` before delete on `ticket_messages`"
                    .' for each row begin if OLD.source_inbound_email_message_id is not null then'
                    ." signal sqlstate '45000' set message_text = 'ticket_message_inbound_evidence_delete_forbidden';"
                    .' end if; end',
                );
            } elseif ($driver === 'sqlite') {
                DB::unprepared(
                    "create trigger `{$childTrigger}` before delete on `ticket_messages`"
                    .' when OLD.source_inbound_email_message_id is not null begin'
                    ." select raise(abort, 'ticket_message_inbound_evidence_delete_forbidden'); end",
                );
            }
        }

        if ($this->hasTrigger($parentTrigger)) {
            return;
        }

        $linkedChildExists = 'exists (select 1 from ticket_messages'
            .' where ticket_id = OLD.id and source_inbound_email_message_id is not null)';
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$parentTrigger}` before delete on `tickets` for each row begin"
                ." if {$linkedChildExists} then signal sqlstate '45000'"
                ." set message_text = 'ticket_inbound_evidence_cascade_forbidden'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$parentTrigger}` before delete on `tickets`"
                ." when {$linkedChildExists} begin"
                ." select raise(abort, 'ticket_inbound_evidence_cascade_forbidden'); end",
            );
        }
    }

    private function dropTicketMessageEvidenceDeleteGuards(): void
    {
        DB::unprepared('drop trigger if exists `tickets_inbound_evidence_no_cascade`');
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_evidence_no_delete`');
    }

    private function addRemoteOperationIndex(): void
    {
        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_unresolved_placement_ix')) {
            return;
        }

        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->index(
                [
                    'email_mailbox_placement_id',
                    'status',
                    'reconciled_at',
                    'failure_classification',
                    'id',
                ],
                'email_remote_ops_unresolved_placement_ix',
            );
        });
    }

    private function addRemoteOperationStatusCursorIndex(): void
    {
        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_placement_status_cursor_ix')) {
            return;
        }

        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->index(
                ['email_mailbox_placement_id', 'status', 'id'],
                'email_remote_ops_placement_status_cursor_ix',
            );
        });
    }

    private function addRemoteOperationFolderIndex(): void
    {
        if ($this->hasIndex('email_remote_operations', 'email_remote_ops_unresolved_folder_ix')) {
            return;
        }

        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->index(
                [
                    'email_folder_id',
                    'status',
                    'reconciled_at',
                    'failure_classification',
                    'id',
                ],
                'email_remote_ops_unresolved_folder_ix',
            );
        });
    }

    private function addExternalDeliveryRecoveryIndex(): void
    {
        if ($this->hasIndex(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_status_cursor_ix',
        )) {
            return;
        }

        Schema::table('notification_inbound_external_deliveries', function (Blueprint $table): void {
            $table->index(['status', 'id'], 'notif_inbound_ext_status_cursor_ix');
        });
    }

    /** Keep each exact recovery branch seekable inside one reconciliation run. */
    private function addReconciliationRecoveryIndexes(): void
    {
        $indexes = [
            'em_recon_items_import_recovery_due_ix' => [
                'email_provider_reconciliation_run_id',
                'kind',
                'status',
                'last_attempt_at',
                'id',
            ],
            'em_recon_items_automation_recovery_due_ix' => [
                'email_provider_reconciliation_run_id',
                'kind',
                'automation_required',
                'automation_status',
                'automation_last_attempt_at',
                'id',
            ],
            'em_recon_items_baseline_recovery_due_ix' => [
                'email_provider_reconciliation_run_id',
                'kind',
                'historical_baseline_required',
                'historical_baseline_status',
                'historical_baseline_last_attempt_at',
                'id',
            ],
        ];

        foreach ($indexes as $name => $columns) {
            if ($this->hasIndex('email_provider_reconciliation_items', $name)) {
                continue;
            }
            Schema::table('email_provider_reconciliation_items', function (Blueprint $table) use (
                $columns,
                $name,
            ): void {
                $table->index($columns, $name);
            });
        }
    }

    private function dropReconciliationRecoveryIndexes(): void
    {
        foreach ([
            'em_recon_items_import_recovery_due_ix',
            'em_recon_items_automation_recovery_due_ix',
            'em_recon_items_baseline_recovery_due_ix',
        ] as $name) {
            if (! $this->hasIndex('email_provider_reconciliation_items', $name)) {
                continue;
            }
            Schema::table('email_provider_reconciliation_items', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }

    /** Attest the exact canonical row bytes consumed by an external worker. */
    private function addExternalDeliveryAttestationColumns(): void
    {
        if (! Schema::hasColumn(
            'notification_inbound_external_deliveries',
            'inbound_notification_fanout_id',
        )) {
            Schema::table('notification_inbound_external_deliveries', function (Blueprint $table): void {
                // Nullable only for legacy rows. The initial-state guard makes
                // the exact durable fanout source mandatory for new writes.
                $table->unsignedBigInteger('inbound_notification_fanout_id')
                    ->nullable()
                    ->after('user_id');
            });
        }

        if (! Schema::hasColumn(
            'notification_inbound_external_deliveries',
            'canonical_payload_hash',
        )) {
            Schema::table('notification_inbound_external_deliveries', function (Blueprint $table): void {
                // Nullable only for rows written before this additive guard lands.
                // Every new row is required to carry a lowercase SHA-256 value.
                $table->char('canonical_payload_hash', 64)
                    ->nullable()
                    ->after('inbound_notification_fanout_id');
            });
        }
    }

    private function addExternalDeliveryFanoutAuthority(): void
    {
        $table = 'notification_inbound_external_deliveries';
        if (! $this->hasIndex($table, 'notif_inbound_ext_fanout_status_ix')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->index(
                    ['inbound_notification_fanout_id', 'status', 'id'],
                    'notif_inbound_ext_fanout_status_ix',
                );
            });
        }
        if (! $this->hasForeign($table, 'notif_inbound_ext_fanout_fk')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign(
                    'inbound_notification_fanout_id',
                    'notif_inbound_ext_fanout_fk',
                )->references('id')->on(self::FANOUT_TABLE)->restrictOnDelete();
            });
        }
    }

    private function dropExternalDeliveryFanoutAuthority(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $driver = DB::connection()->getDriverName();
        $foreignKeys = Schema::hasTable($table)
            ? collect(Schema::getForeignKeys($table))
                ->filter(fn (array $foreign): bool => array_values($foreign['columns'] ?? [])
                    === ['inbound_notification_fanout_id'])
                ->values()
            : collect();

        if ($driver === 'sqlite' && $foreignKeys->isNotEmpty()) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['inbound_notification_fanout_id']);
            });
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($foreignKeys as $foreign) {
                $name = $foreign['name'] ?? null;
                if (! is_string($name) || $name === '') {
                    throw new RuntimeException(
                        'Inbound notification external-delivery fanout authority is malformed.',
                    );
                }
                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropForeign($name);
                });
            }
        }
        if ($this->hasIndex($table, 'notif_inbound_ext_fanout_status_ix')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex('notif_inbound_ext_fanout_status_ix');
            });
        }
    }

    /** Close nullable SQL branches and attest the full durable outbox state. */
    private function addExternalDeliveryGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_state_ck';
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // notification_id and user_id both use ON DELETE SET NULL. MariaDB
            // refuses a CHECK which references either live FK, so the strict
            // INSERT and UPDATE triggers enforce the full contract instead.
            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->externalDeliveryContract('NEW.', $driver);
            $this->ensureSqliteContractTrigger(
                "{$constraint}_insert",
                "before insert on `{$table}`",
                $valid,
                'notification_external_state_invalid',
            );
            $this->ensureSqliteContractTrigger(
                "{$constraint}_update",
                "before update on `{$table}`",
                $valid,
                'notification_external_state_invalid',
            );
        }
    }

    private function dropExternalDeliveryGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_state_ck';
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)
            && Schema::hasTable($table)
            && $this->hasConstraint($table, $constraint)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            $this->dropSqliteGuardPair($constraint);
        }
    }

    private function addExternalDeliveryInitialGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $valid = $this->externalDeliveryContract('NEW.', $driver)
            .' and NEW.notification_id is not null and NEW.user_id is not null'
            .' and NEW.inbound_notification_fanout_id is not null'
            .' and NEW.canonical_payload_hash is not null'
            ." and NEW.status = 'pending'";
        $this->addInsertGuard(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_initial',
            $valid,
            'notification_external_initial_state_invalid',
        );
    }

    private function dropExternalDeliveryInitialGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ext_initial`');
    }

    /** Freeze recipient/channel authority and allow only the worker state machine. */
    private function addExternalDeliveryMonotonicGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $trigger = 'notif_inbound_ext_monotonic';
        $driver = DB::connection()->getDriverName();
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $same = function (string $column) use ($driver): string {
            return in_array($driver, ['mysql', 'mariadb'], true)
                ? "NEW.{$column} <=> OLD.{$column}"
                : "NEW.{$column} is OLD.{$column}";
        };
        $allSame = static fn (array $columns): string => implode(
            ' and ',
            array_map($same, $columns),
        );
        $sameOrDetached = fn (string $column): string => '('.$same($column)
            ." or (OLD.{$column} is not null and NEW.{$column} is null))";
        $nonForeign = [
            'id',
            'inbound_notification_fanout_id',
            'canonical_payload_hash',
            'requested_mail',
            'requested_web_push',
            'requested_nextcloud_talk',
            'mail_scope',
            'mail_account_id',
            'mail_provider_binding_version',
            'mail_snapshot_failure_code',
            'status',
            'claim_token',
            'attempt_count',
            'last_attempt_at',
            'completed_at',
            'error_code',
            'created_at',
            'updated_at',
        ];
        $detach = '(OLD.notification_id is not null and NEW.notification_id is null'
            .' or OLD.user_id is not null and NEW.user_id is null)'
            .' and '.$sameOrDetached('notification_id')
            .' and '.$sameOrDetached('user_id')
            .' and '.$allSame($nonForeign);
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and '.$same('notification_id').' and '.$same('user_id')
            .' and NEW.attempt_count = OLD.attempt_count + 1'
            .' and NEW.last_attempt_at is not null'
            .' and '.$allSame(array_values(array_diff($nonForeign, [
                'status',
                'claim_token',
                'attempt_count',
                'last_attempt_at',
                'updated_at',
            ])));
        $finish = "OLD.status = 'running'"
            ." and NEW.status in ('completed','suppressed','unresolved')"
            .' and '.$same('notification_id').' and '.$same('user_id')
            .' and '.$allSame(array_values(array_diff($nonForeign, [
                'status',
                'claim_token',
                'completed_at',
                'error_code',
                'updated_at',
            ])));
        $contract = $this->externalDeliveryContract('NEW.', $driver);
        $allowed = "({$detach}) or ({$claim}) or ({$finish})";
        $invalid = "coalesce((({$contract}) and ({$allowed})), 0) = 0";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if {$invalid} then signal sqlstate '45000'"
                ." set message_text = 'notification_external_delivery_is_monotonic'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}`"
                ." when {$invalid} begin"
                ." select raise(abort, 'notification_external_delivery_is_monotonic'); end",
            );
        }
    }

    private function dropExternalDeliveryMonotonicGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ext_monotonic`');
    }

    private function addExternalDeliveryDeleteGuard(): void
    {
        $this->addDeleteGuard(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_no_delete',
            'notification_external_delivery_delete_forbidden',
        );
    }

    private function dropExternalDeliveryDeleteGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ext_no_delete`');
    }

    private function createRepairCursor(): void
    {
        $sealed = $this->migrationRepositoryIsSealed();
        if ($sealed && ! Schema::hasTable(self::REPAIR_TABLE)) {
            throw new RuntimeException(
                'Sealed inbound Ticket-message repair evidence is missing.',
            );
        }

        if (! Schema::hasTable(self::REPAIR_TABLE)) {
            Schema::create(self::REPAIR_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('through_id')->default(0);
                $table->unsignedBigInteger('cursor_id')->default(0);
                $table->char('claim_token', 64)->nullable();
                $table->unsignedBigInteger('page_through_id')->nullable();
                $table->unsignedSmallInteger('page_row_count')->nullable();
                $table->unsignedInteger('page_count')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->timestamps();
            });
        }
        $this->ensureRepairCursorColumns();

        if (! DB::table(self::REPAIR_TABLE)->where('id', 1)->exists()) {
            if ($sealed) {
                throw new RuntimeException(
                    'Sealed inbound Ticket-message repair evidence is missing.',
                );
            }
            $throughId = (int) (DB::table('ticket_messages')->max('id') ?? 0);
            DB::table(self::REPAIR_TABLE)->insert([
                'id' => 1,
                'status' => $throughId === 0 ? 'completed' : 'pending',
                'through_id' => $throughId,
                'cursor_id' => 0,
                'last_attempt_at' => null,
                'completed_at' => $throughId === 0 ? now() : null,
                'error_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Repair interrupted MySQL/MariaDB DDL one additive column at a time. */
    private function ensureRepairCursorColumns(): void
    {
        $columns = [
            'claim_token' => fn (Blueprint $table) => $table->char('claim_token', 64)->nullable(),
            'page_through_id' => fn (Blueprint $table) => $table->unsignedBigInteger('page_through_id')->nullable(),
            'page_row_count' => fn (Blueprint $table) => $table->unsignedSmallInteger('page_row_count')->nullable(),
            'page_count' => fn (Blueprint $table) => $table->unsignedInteger('page_count')->default(0),
        ];

        foreach ($columns as $column => $define) {
            if (Schema::hasColumn(self::REPAIR_TABLE, $column)) {
                continue;
            }
            Schema::table(self::REPAIR_TABLE, function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }
    }

    private function createFanouts(): void
    {
        if (Schema::hasTable(self::FANOUT_TABLE)) {
            return;
        }

        Schema::create(self::FANOUT_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')->nullable();
            $table->unsignedBigInteger('source_email_message_id');
            $table->unsignedBigInteger('email_account_id');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('ticket_queue_id')->nullable();
            $table->unsignedBigInteger('ticket_owner_user_id')->nullable();
            $table->unsignedBigInteger('ticket_message_id')->nullable();
            $table->foreignId('email_provider_reconciliation_item_id')->nullable();
            $table->char('automation_claim_token', 64)->nullable();
            $table->unsignedBigInteger('notification_setting_through_id')->default(0);
            $table->unsignedBigInteger('notification_setting_cursor_id')->default(0);
            $table->boolean('owner_candidate_processed')->default(false);
            $table->boolean('owner_priority_reserved')->default(false);
            $table->string('status', 24)->default('pending');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedBigInteger('page_setting_through_id')->nullable();
            $table->unsignedSmallInteger('page_setting_row_count')->nullable();
            $table->boolean('page_owner_pending')->nullable();
            $table->boolean('page_owner_candidate_included')->nullable();
            $table->unsignedSmallInteger('page_attempt_count')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->foreign('email_message_id', 'notif_inbound_fanout_email_fk')
                ->references('id')->on('email_messages')->nullOnDelete();
            $table->unique('email_message_id', 'notif_inbound_fanout_email_uq');
            $table->unique('source_email_message_id', 'notif_inbound_fanout_source_email_uq');
            // An active reconciliation item is the durable completion barrier.
            // Explicit restriction preserves the item/token all-or-none fact.
            $table->foreign(
                'email_provider_reconciliation_item_id',
                'notif_inbound_fanout_recon_item_fk',
            )->references('id')->on('email_provider_reconciliation_items')->restrictOnDelete();
            $table->unique(
                'email_provider_reconciliation_item_id',
                'notif_inbound_fanout_recon_item_uq',
            );
            $table->index(
                ['status', 'last_attempt_at', 'id'],
                'notif_inbound_fanout_due_ix',
            );
            $table->index(['status', 'id'], 'notif_inbound_fanout_status_cursor_ix');
        });
    }

    /** Repair interrupted MySQL/MariaDB DDL before installing page guards. */
    private function ensureFanoutPageWitnessColumns(): void
    {
        $columns = [
            'page_setting_through_id' => fn (Blueprint $table) => $table
                ->unsignedBigInteger('page_setting_through_id')->nullable(),
            'page_setting_row_count' => fn (Blueprint $table) => $table
                ->unsignedSmallInteger('page_setting_row_count')->nullable(),
            'page_owner_pending' => fn (Blueprint $table) => $table
                ->boolean('page_owner_pending')->nullable(),
            'page_owner_candidate_included' => fn (Blueprint $table) => $table
                ->boolean('page_owner_candidate_included')->nullable(),
        ];

        foreach ($columns as $column => $define) {
            if (Schema::hasColumn(self::FANOUT_TABLE, $column)) {
                continue;
            }
            Schema::table(self::FANOUT_TABLE, function (Blueprint $table) use ($define): void {
                $define($table);
            });
        }
    }

    private function addRepairGuard(): void
    {
        $table = self::REPAIR_TABLE;
        $constraint = 'notif_inbound_ticket_repair_ck';
        $driver = DB::connection()->getDriverName();
        $initial = $this->repairInitialContract('NEW.');
        $valid = $this->repairContract('NEW.', $driver);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MariaDB also forbids a CHECK which references an AUTO_INCREMENT
            // key. The singleton/full-state contract is enforced at INSERT by
            // this trigger and at UPDATE by the monotonic trigger below.
            $this->addInsertGuard(
                $table,
                "{$constraint}_insert",
                $initial,
                'notification_ticket_repair_contract_invalid',
            );

            return;
        }

        $this->ensureSqliteContractTrigger(
            "{$constraint}_insert",
            "before insert on `{$table}`",
            $initial,
            'notification_ticket_repair_contract_invalid',
        );
        $this->ensureSqliteContractTrigger(
            "{$constraint}_update",
            "before update on `{$table}`",
            $valid,
            'notification_ticket_repair_contract_invalid',
        );
    }

    private function dropRepairGuard(): void
    {
        $table = self::REPAIR_TABLE;
        $constraint = 'notif_inbound_ticket_repair_ck';
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)
            && Schema::hasTable($table)
            && $this->hasConstraint($table, $constraint)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        }
        $this->dropSqliteGuardPair($constraint);
    }

    private function addRepairMonotonicGuard(): void
    {
        $table = self::REPAIR_TABLE;
        $trigger = 'notif_inbound_ticket_repair_monotonic';
        $driver = DB::connection()->getDriverName();
        if ($this->hasTrigger($trigger)) {
            return;
        }
        $same = function (string $column) use ($driver): string {
            return in_array($driver, ['mysql', 'mariadb'], true)
                ? "NEW.{$column} <=> OLD.{$column}"
                : "NEW.{$column} is OLD.{$column}";
        };
        $allSame = static fn (array $columns): string => implode(
            ' and ',
            array_map($same, $columns),
        );
        $columns = [
            'id',
            'status',
            'through_id',
            'cursor_id',
            'claim_token',
            'page_through_id',
            'page_row_count',
            'page_count',
            'last_attempt_at',
            'completed_at',
            'error_code',
            'created_at',
            'updated_at',
        ];
        $except = static fn (array $excluded): array => array_values(array_diff($columns, $excluded));
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and '.$allSame($except([
                'status',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'last_attempt_at',
                'updated_at',
            ]));
        $reclaim = "OLD.status = 'running' and NEW.status = 'running'"
            .' and NEW.last_attempt_at >= OLD.last_attempt_at'
            .' and '.$allSame($except(['claim_token', 'last_attempt_at', 'updated_at']));
        $page = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and OLD.page_through_id < OLD.through_id'
            .' and NEW.cursor_id = OLD.page_through_id'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and '.$allSame($except([
                'status',
                'cursor_id',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'page_count',
                'updated_at',
            ]));
        $complete = "OLD.status = 'running' and NEW.status = 'completed'"
            .' and OLD.page_through_id = OLD.through_id'
            .' and NEW.cursor_id = OLD.page_through_id'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and '.$allSame($except([
                'status',
                'cursor_id',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'page_count',
                'completed_at',
                'updated_at',
            ]));
        $fail = "OLD.status in ('pending','running') and NEW.status = 'failed'"
            .' and '.$allSame($except([
                'status',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
                'updated_at',
            ]));
        $contract = $this->repairContract('NEW.', $driver);
        $allowed = "({$claim}) or ({$reclaim}) or ({$page}) or ({$complete}) or ({$fail})";
        $invalid = "coalesce((({$contract}) and ({$allowed})), 0) = 0";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if {$invalid} then signal sqlstate '45000'"
                ." set message_text = 'notification_ticket_repair_is_monotonic'; end if; end",
            );

            return;
        }

        DB::unprepared(
            "create trigger `{$trigger}` before update on `{$table}`"
            ." when {$invalid} begin"
            ." select raise(abort, 'notification_ticket_repair_is_monotonic'); end",
        );
    }

    private function dropRepairMonotonicGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ticket_repair_monotonic`');
    }

    /** The singleton is durable deployment evidence and may only disappear in a guarded down. */
    private function addRepairDeleteGuard(): void
    {
        $this->addDeleteGuard(
            self::REPAIR_TABLE,
            'notif_inbound_ticket_repair_no_delete',
            'notification_ticket_repair_delete_forbidden',
        );
    }

    private function dropRepairDeleteGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ticket_repair_no_delete`');
    }

    /** Reject malformed durable claims and incomplete terminal attestations. */
    private function addFanoutGuard(): void
    {
        $table = self::FANOUT_TABLE;
        $constraint = 'notif_inbound_fanout_contract_ck';
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // email_message_id uses ON DELETE SET NULL. MariaDB cannot attach
            // the full row CHECK, so the strict INSERT and UPDATE triggers are
            // the portable contract authority for this table.
            return;
        }

        if ($driver === 'sqlite') {
            $valid = $this->fanoutContract('NEW.', $driver);
            $this->ensureSqliteContractTrigger(
                "{$constraint}_insert",
                "before insert on `{$table}`",
                $valid,
                'notification_fanout_contract_invalid',
            );
            $this->ensureSqliteContractTrigger(
                "{$constraint}_update",
                "before update on `{$table}`",
                $valid,
                'notification_fanout_contract_invalid',
            );
        }
    }

    private function addFanoutInitialGuard(): void
    {
        $driver = DB::connection()->getDriverName();
        $valid = $this->fanoutContract('NEW.', $driver)
            .' and NEW.email_message_id is not null'
            .' and NEW.email_provider_reconciliation_item_id is null'
            .' and NEW.automation_claim_token is null'
            ." and NEW.status = 'pending' and NEW.claim_token is null"
            .' and NEW.notification_setting_cursor_id = 0'
            .' and NEW.owner_candidate_processed = 0'
            .' and NEW.owner_priority_reserved = 0'
            .' and NEW.page_setting_through_id is null'
            .' and NEW.page_setting_row_count is null'
            .' and NEW.page_owner_pending is null'
            .' and NEW.page_owner_candidate_included is null'
            .' and NEW.page_attempt_count = 0 and NEW.page_count = 0'
            .' and NEW.last_attempt_at is null and NEW.completed_at is null'
            .' and NEW.error_code is null';
        $this->addInsertGuard(
            self::FANOUT_TABLE,
            'notif_inbound_fanout_initial',
            $valid,
            'notification_fanout_initial_state_invalid',
        );
    }

    private function dropFanoutInitialGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_fanout_initial`');
    }

    private function dropFanoutGuard(): void
    {
        $table = self::FANOUT_TABLE;
        $constraint = 'notif_inbound_fanout_contract_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)
            && Schema::hasTable($table)
            && $this->hasConstraint($table, $constraint)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /** Freeze identity/high-water facts and make every terminal row immutable. */
    private function addFanoutMonotonicGuard(): void
    {
        $table = self::FANOUT_TABLE;
        $trigger = 'notif_inbound_fanout_monotonic';
        $driver = DB::connection()->getDriverName();
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $same = function (string $column) use ($driver): string {
            return in_array($driver, ['mysql', 'mariadb'], true)
                ? "NEW.{$column} <=> OLD.{$column}"
                : "NEW.{$column} is OLD.{$column}";
        };
        $allSame = static fn (array $columns): string => implode(
            ' and ',
            array_map($same, $columns),
        );
        $columns = [
            'id',
            'email_message_id',
            'source_email_message_id',
            'email_account_id',
            'ticket_id',
            'ticket_queue_id',
            'ticket_owner_user_id',
            'ticket_message_id',
            'email_provider_reconciliation_item_id',
            'automation_claim_token',
            'notification_setting_through_id',
            'notification_setting_cursor_id',
            'owner_candidate_processed',
            'owner_priority_reserved',
            'status',
            'claim_token',
            'page_setting_through_id',
            'page_setting_row_count',
            'page_owner_pending',
            'page_owner_candidate_included',
            'page_attempt_count',
            'page_count',
            'last_attempt_at',
            'completed_at',
            'error_code',
            'created_at',
            'updated_at',
        ];
        $except = static fn (array $excluded): array => array_values(array_diff($columns, $excluded));
        $detach = 'OLD.email_message_id is not null and NEW.email_message_id is null and '
            .$allSame($except(['email_message_id']));
        $attach = "OLD.status = 'pending'"
            .' and OLD.email_provider_reconciliation_item_id is null'
            .' and OLD.automation_claim_token is null'
            .' and NEW.email_provider_reconciliation_item_id is not null'
            .' and NEW.automation_claim_token is not null'
            .' and '.$allSame($except([
                'email_provider_reconciliation_item_id',
                'automation_claim_token',
                'updated_at',
            ]));
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and NEW.page_attempt_count = OLD.page_attempt_count + 1'
            .' and NEW.last_attempt_at is not null'
            .' and (OLD.last_attempt_at is null or NEW.last_attempt_at >= OLD.last_attempt_at)'
            .' and '.$allSame($except([
                'status',
                'claim_token',
                'page_setting_through_id',
                'page_setting_row_count',
                'page_owner_pending',
                'page_owner_candidate_included',
                'page_attempt_count',
                'last_attempt_at',
                'updated_at',
            ]));
        $reclaim = "OLD.status = 'running' and NEW.status = 'running'"
            .' and NEW.page_attempt_count = OLD.page_attempt_count + 1'
            .' and NEW.last_attempt_at is not null'
            .' and NEW.last_attempt_at >= OLD.last_attempt_at'
            .' and '.$allSame($except([
                'claim_token',
                'page_attempt_count',
                'last_attempt_at',
                'updated_at',
            ]));
        $release = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.claim_token is null'
            .' and NEW.page_setting_through_id is null'
            .' and NEW.page_setting_row_count is null'
            .' and NEW.page_owner_pending is null'
            .' and NEW.page_owner_candidate_included is null'
            .' and '.$allSame($except([
                'status',
                'claim_token',
                'page_setting_through_id',
                'page_setting_row_count',
                'page_owner_pending',
                'page_owner_candidate_included',
                'updated_at',
            ]));
        $pageCommit = "OLD.status = 'running' and NEW.status in ('pending','completed')"
            .' and NEW.claim_token is null and NEW.page_attempt_count = 0'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and NEW.notification_setting_cursor_id >= OLD.notification_setting_cursor_id'
            .' and NEW.notification_setting_cursor_id <= OLD.page_setting_through_id'
            .' and (NEW.status = \'pending\' or ('
            .'OLD.page_setting_through_id = OLD.notification_setting_through_id'
            .' and NEW.notification_setting_cursor_id = OLD.page_setting_through_id))'
            .' and ((OLD.page_owner_pending = 1 and NEW.owner_candidate_processed = 1)'
            .' or (OLD.page_owner_pending = 0'
            .' and NEW.owner_candidate_processed = OLD.owner_candidate_processed))'
            .' and (NEW.owner_priority_reserved = OLD.owner_priority_reserved'
            .' or (OLD.page_owner_candidate_included = 1'
            .' and OLD.owner_priority_reserved = 0 and NEW.owner_priority_reserved = 1))'
            .' and NEW.page_setting_through_id is null'
            .' and NEW.page_setting_row_count is null'
            .' and NEW.page_owner_pending is null'
            .' and NEW.page_owner_candidate_included is null'
            .' and '.$allSame($except([
                'notification_setting_cursor_id',
                'owner_candidate_processed',
                'owner_priority_reserved',
                'status',
                'claim_token',
                'page_setting_through_id',
                'page_setting_row_count',
                'page_owner_pending',
                'page_owner_candidate_included',
                'page_attempt_count',
                'page_count',
                'completed_at',
                'updated_at',
            ]));
        $fail = "OLD.status in ('pending','running') and NEW.status = 'failed'"
            .' and NEW.claim_token is null'
            .' and NEW.page_setting_through_id is null'
            .' and NEW.page_setting_row_count is null'
            .' and NEW.page_owner_pending is null'
            .' and NEW.page_owner_candidate_included is null'
            ." and ((NEW.error_code = 'inbound_notification_fanout_attempts_exhausted'"
            .' and OLD.page_attempt_count >= 3)'
            ." or (NEW.error_code = 'inbound_notification_fanout_source_missing'"
            ." and OLD.status = 'running')"
            ." or (NEW.error_code = 'inbound_notification_fanout_item_scope_stale'"
            ." and OLD.status = 'running'))"
            .' and '.$allSame($except([
                'status',
                'claim_token',
                'page_setting_through_id',
                'page_setting_row_count',
                'page_owner_pending',
                'page_owner_candidate_included',
                'completed_at',
                'error_code',
                'updated_at',
            ]));
        $allowed = "({$detach}) or ({$attach}) or ({$claim}) or ({$reclaim})"
            ." or ({$release}) or ({$pageCommit}) or ({$fail})";
        $contract = $this->fanoutContract('NEW.', $driver);
        $invalid = "coalesce((({$contract}) and ({$allowed})), 0) = 0";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` for each row begin"
                ." if {$invalid} then"
                ." signal sqlstate '45000' set message_text = 'notification_fanout_is_monotonic';"
                .' end if; end',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}`"
                ." when {$invalid} begin"
                ." select raise(abort, 'notification_fanout_is_monotonic'); end",
            );
        }
    }

    private function dropFanoutMonotonicGuard(): void
    {
        $trigger = 'notif_inbound_fanout_monotonic';
        DB::unprepared("drop trigger if exists `{$trigger}`");
    }

    /** Fanout rows are durable event and delivery evidence, including after settlement. */
    private function addFanoutDeleteGuard(): void
    {
        $this->addDeleteGuard(
            self::FANOUT_TABLE,
            'notif_inbound_fanout_no_delete',
            'notification_fanout_delete_forbidden',
        );
    }

    private function dropFanoutDeleteGuard(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_fanout_no_delete`');
    }

    /**
     * Before the framework records this migration as complete, no application
     * writer is allowed to create facts owned by the new guarded schema. This
     * catches fabricated rows left in an auto-committed partial migration; a
     * later manual guard refresh may contain legitimate FK-detached evidence.
     */
    private function attestFirstSealProvenance(): void
    {
        $this->attestFirstSealTicketMessagePointerProvenance();
        $this->attestFirstSealRepairProvenance();
        $this->attestFirstSealFanoutProvenance();
        $this->attestFirstSealExternalDeliveryProvenance();
    }

    private function attestFirstSealTicketMessagePointerProvenance(): void
    {
        if ($this->migrationRepositoryIsSealed()) {
            return;
        }

        if (Schema::hasTable('ticket_messages')
            && Schema::hasColumn('ticket_messages', 'source_inbound_email_message_id')
            && $this->boundedExists(
                DB::table('ticket_messages')
                    ->where(function ($messages): void {
                        $messages->whereNotNull('source_inbound_email_message_id')
                            ->orWhereNotNull('inbound_email_message_id');
                    }),
            )) {
            throw new RuntimeException(
                'Inbound Ticket-message pointer evidence exists before the fanout schema seal.',
            );
        }
    }

    private function attestFirstSealFanoutProvenance(): void
    {
        if ($this->migrationRepositoryIsSealed()) {
            return;
        }

        if (Schema::hasTable(self::FANOUT_TABLE)
            && $this->boundedExists(DB::table(self::FANOUT_TABLE))) {
            throw new RuntimeException(
                'Inbound notification fanout evidence exists before the fanout schema seal.',
            );
        }
    }

    private function attestFirstSealRepairProvenance(): void
    {
        if ($this->migrationRepositoryIsSealed()) {
            return;
        }

        $repair = Schema::hasTable(self::REPAIR_TABLE)
            ? DB::table(self::REPAIR_TABLE)->where('id', 1)->first()
            : null;
        if (! $repair
            || $this->boundedExists(DB::table(self::REPAIR_TABLE)->where('id', '<>', 1))
            || (int) $repair->cursor_id !== 0
            || (int) $repair->page_count !== 0
            || $repair->claim_token !== null
            || $repair->page_through_id !== null
            || $repair->page_row_count !== null
            || $repair->last_attempt_at !== null
            || $repair->error_code !== null
            || ((int) $repair->through_id > 0
                && ($repair->status !== 'pending' || $repair->completed_at !== null))
            || ((int) $repair->through_id === 0
                && ($repair->status !== 'completed' || $repair->completed_at === null))) {
            throw new RuntimeException(
                'Inbound Ticket-message repair progressed before the fanout schema seal.',
            );
        }

        $driver = DB::connection()->getDriverName();
        $metadataKeyExists = in_array($driver, ['mysql', 'mariadb'], true)
            ? "json_type(json_extract(`metadata`, '$.email_message_id')) is not null"
            : "json_type(`metadata`, '$.email_message_id') is not null";
        if ($this->boundedExists(
            DB::table('ticket_messages')
                ->where('id', '>', (int) $repair->through_id)
                ->where(function ($messages) use ($metadataKeyExists): void {
                    $messages->whereNotNull('source_inbound_email_message_id')
                        ->orWhereNotNull('inbound_email_message_id')
                        ->orWhereRaw($metadataKeyExists);
                }),
        )) {
            throw new RuntimeException(
                'Inbound Ticket-message repair scope changed before the fanout schema seal.',
            );
        }
    }

    private function attestFirstSealExternalDeliveryProvenance(): void
    {
        if ($this->migrationRepositoryIsSealed()
            || ! Schema::hasTable('notification_inbound_external_deliveries')
            || ! Schema::hasColumn(
                'notification_inbound_external_deliveries',
                'inbound_notification_fanout_id',
            )) {
            return;
        }

        if ($this->boundedExists(
            DB::table('notification_inbound_external_deliveries')
                ->where(function ($deliveries): void {
                    $deliveries->whereNotNull('inbound_notification_fanout_id')
                        ->orWhereNotNull('canonical_payload_hash');
                }),
        )) {
            throw new RuntimeException(
                'Inbound notification external linkage exists before the fanout schema seal.',
            );
        }
    }

    private function migrationRepositoryIsSealed(): bool
    {
        return Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', self::MIGRATION_NAME)->exists();
    }

    /**
     * MariaDB cannot validate these complete shapes with CHECK constraints
     * because each includes a live ON DELETE SET NULL foreign key. This is a
     * deliberate one-time, quiesced deployment scan rather than a runtime
     * query. The installed triggers protect every write before the scan runs.
     */
    private function attestCurrentContractRows(): void
    {
        $driver = DB::connection()->getDriverName();
        $this->attestTableContract(
            'ticket_messages',
            $this->ticketMessagePointerContract(''),
            'Inbound Ticket-message pointer evidence is malformed.',
        );
        $this->attestTableContract(
            self::REPAIR_TABLE,
            $this->repairContract('', $driver),
            'Inbound notification Ticket-message repair evidence is malformed.',
        );
        $this->attestTableContract(
            self::FANOUT_TABLE,
            $this->fanoutContract('', $driver),
            'Inbound notification fanout evidence is malformed.',
        );
        $this->attestTableContract(
            'notification_inbound_external_deliveries',
            $this->externalDeliveryContract('', $driver),
            'Inbound notification external-delivery evidence is malformed.',
        );
    }

    private function attestTableContract(string $table, string $valid, string $error): void
    {
        if (Schema::hasTable($table)
            && $this->boundedExists(
                DB::table($table)->whereRaw("coalesce(({$valid}), 0) = 0"),
            )) {
            throw new RuntimeException($error);
        }
    }

    /**
     * Bound the one-time clean-data scan on MySQL-family servers. MariaDB and
     * MySQL expose different statement-deadline syntax even though production
     * commonly reaches MariaDB through Laravel's `mysql` driver.
     */
    private function boundedExists(\Illuminate\Database\Query\Builder $query): bool
    {
        $probe = $query->selectRaw('1')->limit(1);
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return $probe->exists();
        }

        $sql = $probe->toSql();
        $bindings = $probe->getBindings();
        $version = strtolower((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION));
        if (str_contains($version, 'mariadb')) {
            return DB::select("set statement max_statement_time=30 for {$sql}", $bindings) !== [];
        }

        $boundedSql = preg_replace(
            '/^select /i',
            'select /*+ MAX_EXECUTION_TIME(30000) */ ',
            $sql,
            1,
        ) ?? throw new RuntimeException('Could not bound the fanout schema attestation query.');

        return DB::select($boundedSql, $bindings) !== [];
    }

    private function ticketMessagePointerContract(string $prefix): string
    {
        $column = static fn (string $name): string => $prefix === '' ? "`{$name}`" : $prefix.$name;
        $source = $column('source_inbound_email_message_id');
        $live = $column('inbound_email_message_id');

        return "(({$source} is null and {$live} is null)"
            ." or ({$source} is not null and {$source} >= 1"
            ." and ({$live} is null or {$live} = {$source})))";
    }

    private function repairInitialContract(string $prefix): string
    {
        $column = static fn (string $name): string => $prefix === '' ? "`{$name}`" : $prefix.$name;
        $id = $column('id');
        $status = $column('status');
        $through = $column('through_id');
        $cursor = $column('cursor_id');
        $claim = $column('claim_token');
        $pageThrough = $column('page_through_id');
        $pageRows = $column('page_row_count');
        $pages = $column('page_count');
        $lastAttempt = $column('last_attempt_at');
        $completed = $column('completed_at');
        $error = $column('error_code');

        return "{$id} = 1 and {$through} >= 0 and {$cursor} = 0 and {$pages} = 0"
            ." and {$claim} is null and {$pageThrough} is null and {$pageRows} is null"
            ." and {$lastAttempt} is null and {$error} is null"
            ." and (({$through} > 0 and {$status} = 'pending' and {$completed} is null)"
            ." or ({$through} = 0 and {$status} = 'completed' and {$completed} is not null))";
    }

    private function repairContract(string $prefix, string $driver): string
    {
        $column = static fn (string $name): string => $prefix === '' ? "`{$name}`" : $prefix.$name;
        $id = $column('id');
        $status = $column('status');
        $through = $column('through_id');
        $cursor = $column('cursor_id');
        $claim = $column('claim_token');
        $pageThrough = $column('page_through_id');
        $pageRows = $column('page_row_count');
        $pages = $column('page_count');
        $lastAttempt = $column('last_attempt_at');
        $completed = $column('completed_at');
        $error = $column('error_code');
        $tokenValid = in_array($driver, ['mysql', 'mariadb'], true)
            ? "length({$claim}) = 64 and binary {$claim} regexp '^[0-9a-f]{64}$'"
            : "length({$claim}) = 64 and {$claim} not glob '*[^0-9a-f]*'";

        return "{$id} = 1"
            ." and {$through} >= 0 and {$cursor} >= 0 and {$cursor} <= {$through}"
            ." and {$pages} >= 0"
            ." and {$status} in ('pending','running','completed','failed')"
            ." and (({$status} = 'pending' and {$claim} is null"
            ." and {$pageThrough} is null and {$pageRows} is null"
            ." and {$completed} is null and {$error} is null)"
            ." or ({$status} = 'running' and {$claim} is not null and {$tokenValid}"
            ." and {$pageThrough} is not null and {$pageThrough} > {$cursor}"
            ." and {$pageThrough} <= {$through}"
            ." and {$pageRows} is not null and {$pageRows} >= 0 and {$pageRows} <= 100"
            ." and ({$pageThrough} = {$through} or {$pageRows} = 100)"
            ." and {$lastAttempt} is not null"
            ." and {$completed} is null and {$error} is null)"
            ." or ({$status} = 'completed' and {$cursor} = {$through}"
            ." and {$claim} is null and {$pageThrough} is null and {$pageRows} is null"
            ." and {$completed} is not null and {$error} is null)"
            ." or ({$status} = 'failed' and {$lastAttempt} is not null"
            ." and {$claim} is null and {$pageThrough} is null and {$pageRows} is null"
            ." and {$completed} is not null and {$error} is not null and {$error} in ("
            ."'repair_pointer_metadata_conflict','repair_pointer_metadata_invalid',"
            ."'repair_email_ticket_conflict',"
            ."'repair_duplicate_email_pointer','repair_page_attestation_conflict')))";
    }

    private function externalDeliveryContract(string $prefix, string $driver): string
    {
        $column = static fn (string $name): string => $prefix === '' ? "`{$name}`" : $prefix.$name;
        $notification = $column('notification_id');
        $user = $column('user_id');
        $fanout = $column('inbound_notification_fanout_id');
        $payloadHash = $column('canonical_payload_hash');
        $mail = $column('requested_mail');
        $webPush = $column('requested_web_push');
        $talk = $column('requested_nextcloud_talk');
        $scope = $column('mail_scope');
        $account = $column('mail_account_id');
        $binding = $column('mail_provider_binding_version');
        $snapshotFailure = $column('mail_snapshot_failure_code');
        $status = $column('status');
        $token = $column('claim_token');
        $attempts = $column('attempt_count');
        $lastAttempt = $column('last_attempt_at');
        $completed = $column('completed_at');
        $error = $column('error_code');
        $tokenValid = in_array($driver, ['mysql', 'mariadb'], true)
            ? "length({$token}) = 64 and binary {$token} regexp '^[0-9a-f]{64}$'"
            : "length({$token}) = 64 and {$token} not glob '*[^0-9a-f]*'";
        $payloadHashValid = in_array($driver, ['mysql', 'mariadb'], true)
            ? "length({$payloadHash}) = 64 and binary {$payloadHash} regexp '^[0-9a-f]{64}$'"
            : "length({$payloadHash}) = 64 and {$payloadHash} not glob '*[^0-9a-f]*'";
        $suppressedErrors = "'inbound_notification_recipient_revoked',"
            ."'inbound_notification_payload_invalid',"
            ."'inbound_notification_payload_attestation_failed',"
            ."'inbound_notification_source_missing',"
            ."'inbound_notification_delivery_missing',"
            ."'inbound_notification_external_channels_disabled',"
            ."'inbound_notification_external_channels_suppressed'";
        $unresolvedErrors = "'inbound_notification_external_worker_lost',"
            ."'inbound_notification_external_delivery_unresolved'";

        return "({$notification} is null or length({$notification}) = 36)"
            ." and ({$user} is null or {$user} >= 1)"
            ." and ({$fanout} is null or {$fanout} >= 1)"
            ." and ({$payloadHash} is null or ({$payloadHashValid}))"
            ." and {$mail} in (0,1) and {$webPush} in (0,1) and {$talk} in (0,1)"
            ." and ({$mail} = 1 or {$webPush} = 1 or {$talk} = 1)"
            ." and (({$mail} = 0 and {$scope} is null and {$account} is null"
            ." and {$binding} is null and {$snapshotFailure} is null)"
            ." or ({$mail} = 1 and {$scope} is not null"
            ." and {$scope} in ('system','tickets')"
            ." and (({$account} is not null and {$account} >= 1"
            ." and {$binding} is not null and {$binding} >= 1"
            ." and {$snapshotFailure} is null)"
            ." or ({$account} is null and {$binding} is null"
            ." and {$snapshotFailure} is not null"
            ." and {$snapshotFailure} in "
            ."('provider_binding_snapshot_missing','provider_binding_snapshot_unavailable')))))"
            ." and {$status} in ('pending','running','completed','suppressed','unresolved')"
            ." and (({$status} = 'pending' and {$token} is null and {$attempts} = 0"
            ." and {$lastAttempt} is null and {$completed} is null and {$error} is null)"
            ." or ({$status} = 'running' and {$token} is not null and {$tokenValid}"
            ." and {$attempts} = 1 and {$lastAttempt} is not null"
            ." and {$completed} is null and {$error} is null)"
            ." or ({$status} = 'completed' and {$token} is null and {$attempts} = 1"
            ." and {$lastAttempt} is not null and {$completed} is not null and {$error} is null)"
            ." or ({$status} = 'suppressed' and {$token} is null and {$attempts} = 1"
            ." and {$lastAttempt} is not null and {$completed} is not null"
            ." and {$error} is not null and {$error} in ({$suppressedErrors}))"
            ." or ({$status} = 'unresolved' and {$token} is null and {$attempts} = 1"
            ." and {$lastAttempt} is not null and {$completed} is not null"
            ." and {$error} is not null and {$error} in ({$unresolvedErrors})))";
    }

    private function fanoutContract(string $prefix, string $driver): string
    {
        $column = static fn (string $name): string => $prefix === '' ? "`{$name}`" : $prefix.$name;
        $email = $column('email_message_id');
        $sourceEmail = $column('source_email_message_id');
        $account = $column('email_account_id');
        $ticket = $column('ticket_id');
        $queue = $column('ticket_queue_id');
        $owner = $column('ticket_owner_user_id');
        $ticketMessage = $column('ticket_message_id');
        $item = $column('email_provider_reconciliation_item_id');
        $automationToken = $column('automation_claim_token');
        $through = $column('notification_setting_through_id');
        $cursor = $column('notification_setting_cursor_id');
        $ownerProcessed = $column('owner_candidate_processed');
        $ownerReserved = $column('owner_priority_reserved');
        $status = $column('status');
        $claim = $column('claim_token');
        $pageThrough = $column('page_setting_through_id');
        $pageRows = $column('page_setting_row_count');
        $pageOwnerPending = $column('page_owner_pending');
        $pageOwnerIncluded = $column('page_owner_candidate_included');
        $attempts = $column('page_attempt_count');
        $pages = $column('page_count');
        $lastAttempt = $column('last_attempt_at');
        $completed = $column('completed_at');
        $error = $column('error_code');
        $tokenValid = function (string $token) use ($driver): string {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                return "length({$token}) = 64 and binary {$token} regexp '^[0-9a-f]{64}$'";
            }

            return "length({$token}) = 64 and {$token} not glob '*[^0-9a-f]*'";
        };

        return "{$sourceEmail} >= 1"
            ." and {$account} >= 1"
            ." and ({$email} is null or {$email} = {$sourceEmail})"
            ." and (({$ticket} is null and {$queue} is null and {$owner} is null and {$ticketMessage} is null)"
            ." or ({$ticket} is not null and {$ticket} >= 1"
            ." and {$queue} is not null and {$queue} >= 1"
            ." and ({$owner} is null or {$owner} >= 1)"
            ." and ({$ticketMessage} is null or {$ticketMessage} >= 1)))"
            ." and {$through} >= 0 and {$cursor} >= 0 and {$cursor} <= {$through}"
            ." and {$attempts} >= 0 and {$pages} >= 0"
            ." and (({$status} = 'running'"
            ." and {$pageThrough} is not null"
            ." and {$pageRows} is not null and {$pageRows} >= 0"
            ." and {$pageOwnerPending} in (0,1)"
            ." and {$pageOwnerIncluded} in (0,1)"
            ." and {$pageOwnerIncluded} <= {$pageOwnerPending}"
            ." and {$pageRows} + {$pageOwnerIncluded} <= 100"
            ." and {$pageThrough} >= {$cursor} and {$pageThrough} <= {$through}"
            ." and ({$pageRows} > 0 or {$pageThrough} = {$through})"
            ." and ({$pageRows} = 0 or {$pageThrough} > {$cursor})"
            ." and ({$pageThrough} = {$through}"
            ." or {$pageRows} + {$pageOwnerIncluded} = 100)"
            ." and (({$pageOwnerPending} = 1 and {$ownerProcessed} = 0)"
            ." or ({$pageOwnerPending} = 0 and {$ownerProcessed} = 1))"
            ." and (({$pageOwnerIncluded} = 1 and {$owner} is not null)"
            ." or ({$pageOwnerIncluded} = 0"
            ." and ({$pageOwnerPending} = 0 or {$owner} is null))))"
            ." or ({$status} <> 'running'"
            ." and {$pageThrough} is null"
            ." and {$pageRows} is null"
            ." and {$pageOwnerPending} is null"
            ." and {$pageOwnerIncluded} is null))"
            ." and {$ownerProcessed} in (0,1)"
            ." and {$ownerReserved} in (0,1)"
            ." and ({$ownerReserved} = 0 or {$ownerProcessed} = 1)"
            ." and (({$item} is null and {$automationToken} is null)"
            ." or ({$item} is not null and {$item} >= 1"
            ." and {$automationToken} is not null and "
            .$tokenValid($automationToken).'))'
            ." and {$status} in ('pending','running','completed','failed')"
            ." and (({$status} = 'pending'"
            ." and {$claim} is null"
            ." and {$attempts} <= 2"
            ." and ({$attempts} = 0 or {$lastAttempt} is not null)"
            ." and {$completed} is null"
            ." and {$error} is null)"
            ." or ({$status} = 'running'"
            ." and {$claim} is not null and ".$tokenValid($claim)
            ." and {$attempts} >= 1 and {$attempts} <= 3"
            ." and {$lastAttempt} is not null"
            ." and {$completed} is null"
            ." and {$error} is null)"
            ." or ({$status} = 'completed'"
            ." and {$claim} is null"
            ." and {$ownerProcessed} = 1"
            ." and {$cursor} = {$through}"
            ." and {$attempts} = 0"
            ." and {$lastAttempt} is not null"
            ." and {$pages} >= 1"
            ." and {$completed} is not null"
            ." and {$error} is null)"
            ." or ({$status} = 'failed'"
            ." and {$claim} is null"
            ." and {$attempts} >= 1 and {$attempts} <= 3"
            ." and {$lastAttempt} is not null"
            ." and {$completed} is not null"
            ." and {$error} is not null"
            ." and (({$error} = 'inbound_notification_fanout_attempts_exhausted'"
            ." and {$attempts} = 3)"
            ." or ({$error} = 'inbound_notification_fanout_source_missing'"
            ." and {$attempts} >= 1 and {$attempts} <= 3)"
            ." or ({$error} = 'inbound_notification_fanout_item_scope_stale'"
            ." and {$attempts} >= 1 and {$attempts} <= 3))))";
    }

    private function hasIndex(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    private function hasForeign(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $foreignKeys = collect(Schema::getForeignKeys($table));
        if ($foreignKeys->contains(
            fn (array $foreign): bool => ($foreign['name'] ?? null) === $name,
        )) {
            return true;
        }

        // SQLite does not retain foreign-key constraint names. Match the two
        // additive authorities structurally so a rerun neither rebuilds the
        // table nor duplicates a FK merely because its declared name was lost.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return false;
        }
        $expected = match ($name) {
            'ticket_messages_inbound_email_fk' => [
                'columns' => ['inbound_email_message_id'],
                'foreign_table' => 'email_messages',
                'foreign_columns' => ['id'],
                'on_delete' => 'set null',
            ],
            'notif_inbound_ext_fanout_fk' => [
                'columns' => ['inbound_notification_fanout_id'],
                'foreign_table' => self::FANOUT_TABLE,
                'foreign_columns' => ['id'],
                'on_delete' => 'restrict',
            ],
            default => null,
        };
        if ($expected === null) {
            return false;
        }

        return $foreignKeys->contains(
            fn (array $foreign): bool => array_values($foreign['columns'] ?? [])
                    === $expected['columns']
                && ($foreign['foreign_table'] ?? null) === $expected['foreign_table']
                && array_values($foreign['foreign_columns'] ?? [])
                    === $expected['foreign_columns']
                && strtolower((string) ($foreign['on_delete'] ?? ''))
                    === $expected['on_delete'],
        );
    }

    private function hasConstraint(string $table, string $name): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ["{$name}_insert", "{$name}_update"])
                ->count() === 2;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->exists();
    }

    private function hasTrigger(string $name): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $name)
                ->exists();
        }

        return DB::table('information_schema.triggers')
            ->where('trigger_schema', DB::connection()->getDatabaseName())
            ->where('trigger_name', $name)
            ->exists();
    }

    private function addDeleteGuard(string $table, string $trigger, string $error): void
    {
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before delete on `{$table}` for each row begin"
                ." signal sqlstate '45000' set message_text = '{$error}'; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before delete on `{$table}` begin"
                ." select raise(abort, '{$error}'); end",
            );
        }
    }

    private function addInsertGuard(
        string $table,
        string $trigger,
        string $valid,
        string $error,
    ): void {
        if ($this->hasTrigger($trigger)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                "create trigger `{$trigger}` before insert on `{$table}` for each row begin"
                ." if coalesce(({$valid}), 0) = 0 then signal sqlstate '45000'"
                ." set message_text = '{$error}'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$trigger}` before insert on `{$table}`"
                ." when coalesce(({$valid}), 0) = 0 begin select raise(abort, '{$error}'); end",
            );
        }
    }

    private function ensureSqliteContractTrigger(
        string $name,
        string $timing,
        string $valid,
        string $error,
    ): void {
        if ($this->hasTrigger($name)) {
            return;
        }

        DB::unprepared(
            "create trigger `{$name}` {$timing} when coalesce(({$valid}), 0) = 0 begin"
            ." select raise(abort, '{$error}'); end",
        );
    }

    private function dropSqliteGuardPair(string $constraint): void
    {
        DB::unprepared("drop trigger if exists `{$constraint}_insert`");
        DB::unprepared("drop trigger if exists `{$constraint}_update`");
    }
};
