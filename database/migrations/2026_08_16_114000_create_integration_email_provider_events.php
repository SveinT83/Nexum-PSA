<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_email_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_key')->unique();
            $table->uuid('provider_integration_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event_type', 64)->index();
            $table->string('reason_code', 80)->nullable();
            $table->unsignedInteger('configuration_version')->nullable();
            $table->unsignedInteger('credential_version')->nullable();
            $table->char('operation_fingerprint', 64)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('provider_integration_id', 'iep_event_connection_fk')
                ->references('integration_id')->on('integration_email_provider_connections')->restrictOnDelete();
            $table->foreign('actor_id', 'iep_event_actor_fk')
                ->references('id')->on('user_management')->restrictOnDelete();
            $table->index(
                ['provider_integration_id', 'occurred_at'],
                'iep_event_connection_occurred_ix',
            );
        });

        $this->createAppendOnlyGuards();
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_email_provider_events')
            && DB::table('integration_email_provider_events')->exists()) {
            throw new RuntimeException(
                'Append-only Email provider lifecycle events must be preserved before schema rollback.',
            );
        }

        $this->dropAppendOnlyGuards();
        Schema::dropIfExists('integration_email_provider_events');
    }

    private function createAppendOnlyGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER iep_events_append_only_update
                BEFORE UPDATE ON integration_email_provider_events
                BEGIN
                    SELECT RAISE(ABORT, 'email_provider_events_are_append_only');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER iep_events_append_only_delete
                BEFORE DELETE ON integration_email_provider_events
                BEGIN
                    SELECT RAISE(ABORT, 'email_provider_events_are_append_only');
                END
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER iep_events_append_only_update
            BEFORE UPDATE ON integration_email_provider_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'email_provider_events_are_append_only'
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER iep_events_append_only_delete
            BEFORE DELETE ON integration_email_provider_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'email_provider_events_are_append_only'
            SQL);
    }

    private function dropAppendOnlyGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_update');
            DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_delete');

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_update');
        DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_delete');
    }
};
