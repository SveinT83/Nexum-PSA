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
            $table->unsignedBigInteger('acknowledged_target_uid_validity')
                ->nullable()
                ->after('expected_uid_validity');
            $table->unsignedBigInteger('acknowledged_target_uid')
                ->nullable()
                ->after('acknowledged_target_uid_validity');
            $table->index(
                [
                    'account_id',
                    'acknowledged_target_uid_validity',
                    'acknowledged_target_uid',
                ],
                'em_remote_target_uid_ix',
            );
        });

        $this->addTargetIdentityGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('email_remote_operations')
            && DB::table('email_remote_operations')
                ->where(function ($query): void {
                    $query->whereNotNull('acknowledged_target_uid_validity')
                        ->orWhereNotNull('acknowledged_target_uid');
                })
                ->exists()) {
            throw new RuntimeException(
                'Authoritative provider target identity evidence must be preserved before schema rollback.',
            );
        }

        $this->dropTargetIdentityGuards();

        Schema::table('email_remote_operations', function (Blueprint $table): void {
            $table->dropIndex('em_remote_target_uid_ix');
            $table->dropColumn([
                'acknowledged_target_uid_validity',
                'acknowledged_target_uid',
            ]);
        });
    }

    private function addTargetIdentityGuards(): void
    {
        $table = 'email_remote_operations';
        $constraint = 'em_remote_target_uid_contract_ck';
        $immutable = 'em_remote_target_uid_immutable_bu';
        $driver = DB::connection()->getDriverName();
        $valid = '((`acknowledged_target_uid_validity` is null'
            .' and `acknowledged_target_uid` is null)'
            .' or (`acknowledged_target_uid_validity` is not null'
            .' and `acknowledged_target_uid` is not null'
            .' and `acknowledged_target_uid_validity` >= 1'
            .' and `acknowledged_target_uid` >= 1))';

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                "alter table `{$table}` add constraint `{$constraint}` check ({$valid})",
            );
            DB::unprepared(
                "create trigger `{$immutable}` before update on `{$table}` for each row begin"
                .' if ((OLD.acknowledged_target_uid_validity is not null'
                .' or OLD.acknowledged_target_uid is not null)'
                .' and (not (NEW.acknowledged_target_uid_validity <=> OLD.acknowledged_target_uid_validity)'
                .' or not (NEW.acknowledged_target_uid <=> OLD.acknowledged_target_uid))) then'
                ." signal sqlstate '45000' set message_text = 'authoritative_target_identity_is_immutable';"
                .' end if; end',
            );

            return;
        }

        if ($driver === 'sqlite') {
            $sqliteValid = preg_replace('/`([a-z_]+)`/', 'NEW.`$1`', $valid)
                ?? throw new RuntimeException('Could not build the SQLite target identity guard.');
            DB::unprepared(
                "create trigger `{$constraint}_insert` before insert on `{$table}`"
                ." when not ({$sqliteValid}) begin"
                ." select raise(abort, 'authoritative_target_identity_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$constraint}_update` before update on `{$table}`"
                ." when not ({$sqliteValid}) begin"
                ." select raise(abort, 'authoritative_target_identity_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$immutable}` before update on `{$table}`"
                .' when (OLD.acknowledged_target_uid_validity is not null'
                .' or OLD.acknowledged_target_uid is not null)'
                .' and (NEW.acknowledged_target_uid_validity is not OLD.acknowledged_target_uid_validity'
                .' or NEW.acknowledged_target_uid is not OLD.acknowledged_target_uid) begin'
                ." select raise(abort, 'authoritative_target_identity_is_immutable'); end",
            );
        }
    }

    private function dropTargetIdentityGuards(): void
    {
        $table = 'email_remote_operations';
        $constraint = 'em_remote_target_uid_contract_ck';
        $immutable = 'em_remote_target_uid_immutable_bu';
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasTable($table)) {
            DB::unprepared("drop trigger if exists `{$immutable}`");
            DB::statement("alter table `{$table}` drop constraint `{$constraint}`");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("drop trigger if exists `{$constraint}_insert`");
            DB::unprepared("drop trigger if exists `{$constraint}_update`");
            DB::unprepared("drop trigger if exists `{$immutable}`");
        }
    }
};
