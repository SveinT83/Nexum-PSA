<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_messages')
            || ! Schema::hasColumn('email_messages', 'received_at')) {
            return;
        }

        // Freeze the incident scope before removing the unsafe rule. Messages
        // ingested after this point receive a higher ID and must never enter a
        // historical repair simply because the command is run later.
        $scopeMaxMessageId = (int) (DB::table('email_messages')->max('id') ?? 0);

        // Older MariaDB/MySQL defaults silently gave the first TIMESTAMP
        // column ON UPDATE CURRENT_TIMESTAMP. Remove that behavior before any
        // repair or later projection update can touch another received date.
        $this->removeImplicitReceivedAtUpdate();
        $this->addFingerprintSchemaMarker();
        $this->createRepairLedger();
        $this->seedExactRepairScope($scopeMaxMessageId);
    }

    public function down(): void
    {
        // Forward-only safety migration. Restoring the implicit ON UPDATE rule
        // or discarding repair evidence would reintroduce data corruption.
    }

    private function removeImplicitReceivedAtUpdate(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne(<<<'SQL'
            SELECT EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_messages'
              AND COLUMN_NAME = 'received_at'
            LIMIT 1
        SQL);

        if ($column && str_contains(mb_strtolower((string) $column->EXTRA), 'on update')) {
            DB::statement(<<<'SQL'
                ALTER TABLE email_messages
                MODIFY COLUMN received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            SQL);
        }

        $verified = DB::selectOne(<<<'SQL'
            SELECT EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_messages'
              AND COLUMN_NAME = 'received_at'
            LIMIT 1
        SQL);

        if ($verified && str_contains(mb_strtolower((string) $verified->EXTRA), 'on update')) {
            throw new \RuntimeException('email_messages.received_at still has an unsafe ON UPDATE rule.');
        }
    }

    private function addFingerprintSchemaMarker(): void
    {
        if (! Schema::hasTable('email_smart_inbox_suggestions')
            || Schema::hasColumn('email_smart_inbox_suggestions', 'source_fingerprint_schema')) {
            return;
        }

        Schema::table('email_smart_inbox_suggestions', function (Blueprint $table): void {
            // Existing NULL rows are legacy v1 fingerprints. New code records
            // v2 explicitly, which keeps staged old workers compatible.
            $table->string('source_fingerprint_schema', 80)
                ->nullable()
                ->after('source_fingerprint');
        });
    }

    private function createRepairLedger(): void
    {
        if (Schema::hasTable('email_message_received_at_repairs')) {
            return;
        }

        Schema::create('email_message_received_at_repairs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_message_id')->unique('email_emrar_message_unique');
            // Use DATETIME deliberately. Older MySQL/MariaDB versions may
            // assign implicit current-time behavior to a table's first
            // TIMESTAMP column, which is the defect this ledger documents.
            $table->dateTime('observed_received_at')->nullable();
            $table->dateTime('repaired_received_at')->nullable();
            $table->string('evidence_source', 60)->nullable();
            $table->char('evidence_fingerprint', 64)->nullable();
            // Unproven values remain candidates only. They are never copied
            // into received_at by the default repair path.
            $table->dateTime('candidate_received_at')->nullable();
            $table->string('candidate_source', 60)->nullable();
            $table->string('status', 30)->default('pending')->index('email_emrar_status_index');
            $table->string('reason_code', 100)->nullable();
            $table->unsignedInteger('smart_suggestions_recovered')->default(0);
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('repaired_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('email_message_id', 'email_emrar_message_fk')
                ->references('id')
                ->on('email_messages')
                ->cascadeOnDelete();
        });
    }

    private function seedExactRepairScope(int $scopeMaxMessageId): void
    {
        if (! Schema::hasTable('email_message_received_at_repairs') || $scopeMaxMessageId < 1) {
            return;
        }

        DB::table('email_messages')
            ->where('id', '<=', $scopeMaxMessageId)
            ->select(['id', 'received_at'])
            ->orderBy('id')
            ->chunkById(500, function ($messages): void {
                $now = now();
                $rows = collect($messages)->map(fn (object $message): array => [
                    'email_message_id' => (int) $message->id,
                    'observed_received_at' => $message->received_at,
                    'status' => 'pending',
                    'reason_code' => 'legacy_received_at_on_update_scope',
                    'smart_suggestions_recovered' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('email_message_received_at_repairs')->insertOrIgnore($rows);
                }
            }, 'id');
    }
};
