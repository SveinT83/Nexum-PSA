<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_inbound_external_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->nullable()->unique();
            $table->foreignId('user_id')->nullable();
            $table->boolean('requested_mail')->default(false);
            $table->boolean('requested_web_push')->default(false);
            $table->boolean('requested_nextcloud_talk')->default(false);
            $table->string('mail_scope', 24)->nullable();
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->unsignedInteger('mail_provider_binding_version')->nullable();
            $table->string('mail_snapshot_failure_code', 80)->nullable();
            $table->string('status', 24)->default('pending');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->foreign('notification_id', 'notif_inbound_ext_notification_fk')
                ->references('id')->on('notifications')->nullOnDelete();
            $table->foreign('user_id', 'notif_inbound_ext_user_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(['status', 'last_attempt_at', 'id'], 'notif_inbound_ext_due_ix');
        });

        $this->addStatusGuard();
        $this->addDeliveryContractGuard();
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_inbound_external_deliveries')
            && DB::table('notification_inbound_external_deliveries')->exists()) {
            throw new RuntimeException(
                'Inbound notification external-delivery evidence must be preserved before schema rollback.',
            );
        }

        $this->dropDeliveryContractGuard();
        $this->dropStatusGuard();
        Schema::dropIfExists('notification_inbound_external_deliveries');
    }

    private function addStatusGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_status_ck';
        $allowed = "'pending','running','completed','suppressed','unresolved'";
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}`"
                ." check (`status` in ({$allowed}))",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when NEW.status not in ({$allowed}) begin"
                ." select raise(abort, 'notification_external_status_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update of status on `{$table}`"
                ." when NEW.status not in ({$allowed}) begin"
                ." select raise(abort, 'notification_external_status_invalid'); end",
            );
        }
    }

    private function dropStatusGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_status_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }

    /**
     * Keep the outbox payload-free while proving which external channels were
     * requested and which exact provider binding Mail was allowed to use.
     */
    private function addDeliveryContractGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_contract_ck';
        $scopes = "'system','tickets'";
        $failures = "'provider_binding_snapshot_missing','provider_binding_snapshot_unavailable'";
        $valid = '(`requested_mail` in (0,1)'
            .' and `requested_web_push` in (0,1)'
            .' and `requested_nextcloud_talk` in (0,1)'
            .' and (`requested_mail` = 1 or `requested_web_push` = 1 or `requested_nextcloud_talk` = 1)'
            .' and ((`requested_mail` = 0'
            .' and `mail_scope` is null'
            .' and `mail_account_id` is null'
            .' and `mail_provider_binding_version` is null'
            .' and `mail_snapshot_failure_code` is null)'
            .' or (`requested_mail` = 1'
            ." and `mail_scope` in ({$scopes})"
            .' and ((`mail_account_id` >= 1'
            .' and `mail_provider_binding_version` >= 1'
            .' and `mail_snapshot_failure_code` is null)'
            .' or (`mail_account_id` is null'
            .' and `mail_provider_binding_version` is null'
            ." and `mail_snapshot_failure_code` in ({$failures}))))))";
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ({$valid})",
            );

            return;
        }

        if ($driver === 'sqlite') {
            $sqliteValid = preg_replace(
                '/`([a-z_]+)`/',
                'NEW.`$1`',
                $valid,
            ) ?? throw new RuntimeException('Could not build the SQLite outbox contract guard.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$sqliteValid}) begin"
                ." select raise(abort, 'notification_external_contract_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$sqliteValid}) begin"
                ." select raise(abort, 'notification_external_contract_invalid'); end",
            );
        }
    }

    private function dropDeliveryContractGuard(): void
    {
        $table = 'notification_inbound_external_deliveries';
        $constraint = 'notif_inbound_ext_contract_ck';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
        }
    }
};
