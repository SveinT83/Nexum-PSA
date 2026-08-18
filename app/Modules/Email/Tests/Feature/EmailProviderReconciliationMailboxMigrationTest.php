<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * SQLite contract for the mailbox-width migration's fail-closed rollback.
 *
 * Every test restores the expanded schema explicitly because RefreshDatabase
 * reuses one migrated in-memory schema across the class.
 */
class EmailProviderReconciliationMailboxMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function down_refuses_a_long_message_mailbox_without_changing_width_or_identity_index(): void
    {
        $migration = $this->migration();
        $account = $this->account('migration-long-message@example.test');
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => str_repeat('m', 192),
            'imap_uid_validity' => 41,
            'imap_uid' => 1,
            'message_id' => '<migration-long-message@example.test>',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);

        try {
            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Provider mailbox paths longer than 191 characters',
            );
        } finally {
            $message->forceDelete();
            $account->delete();
            $this->restoreExpandedSchema($migration);
        }
    }

    #[Test]
    public function down_refuses_a_long_local_folder_path_even_when_there_are_no_messages(): void
    {
        $migration = $this->migration();
        $account = $this->account('migration-long-folder@example.test');
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => str_repeat('f', 192),
            'name' => 'Long folder',
            'delimiter' => '/',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 42,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        $this->assertSame(0, DB::table('email_messages')->count());

        try {
            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Provider mailbox paths longer than 191 characters',
            );
        } finally {
            $folder->delete();
            $account->delete();
            $this->restoreExpandedSchema($migration);
        }
    }

    #[Test]
    public function down_refuses_reconciliation_evidence_even_when_there_are_no_messages(): void
    {
        $migration = $this->migration();
        $account = $this->account('migration-reconciliation-evidence@example.test');
        $run = EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'mailbox-migration-evidence:'.$account->id),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
        ]);
        $this->assertSame(0, DB::table('email_messages')->count());

        try {
            $this->assertDownRefusesWithoutSchemaMutation(
                $migration,
                'Provider reconciliation evidence must be preserved',
            );
        } finally {
            $run->delete();
            $account->delete();
            $this->restoreExpandedSchema($migration);
        }
    }

    #[Test]
    public function clean_down_and_up_round_trip_preserves_the_exact_identity_index(): void
    {
        $migration = $this->migration();
        $expanded = $this->mailboxSchemaContract();
        $restored = false;

        try {
            $migration->down();

            $legacy = $this->mailboxSchemaContract();
            $this->assertSame($this->portableVarcharType(191), $legacy['mailbox_type']);
            $this->assertSame($expanded['identity_index'], $legacy['identity_index']);

            $migration->up();
            $restored = true;

            $this->assertSame($expanded, $this->mailboxSchemaContract());
        } finally {
            if (! $restored) {
                $migration->up();
            }
        }
    }

    private function assertDownRefusesWithoutSchemaMutation(
        Migration $migration,
        string $expectedMessage,
    ): void {
        $before = $this->mailboxSchemaContract();

        try {
            $migration->down();
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            $this->assertSame($before, $this->mailboxSchemaContract());

            return;
        }

        $this->fail('The mailbox-width rollback must fail before narrowing the schema.');
    }

    /**
     * @return array{
     *     mailbox_type: string|null,
     *     identity_index: array{name: string, columns: array<int, string>, unique: bool}|null
     * }
     */
    private function mailboxSchemaContract(): array
    {
        $column = collect(Schema::getColumns('email_messages'))
            ->firstWhere('name', 'mailbox');
        $index = collect(Schema::getIndexes('email_messages'))
            ->firstWhere('name', 'em_msg_uid_ns_uq');

        return [
            'mailbox_type' => $column['type'] ?? null,
            'identity_index' => $index ? [
                'name' => $index['name'],
                'columns' => $index['columns'],
                'unique' => $index['unique'],
            ] : null,
        ];
    }

    private function restoreExpandedSchema(Migration $migration): void
    {
        // `up()` is idempotent at the identity-index boundary and guarantees
        // cleanup even if a regression unexpectedly let `down()` proceed.
        $migration->up();
    }

    private function portableVarcharType(int $length): string
    {
        // SQLite does not enforce or retain a varchar typmod during a table
        // rebuild; MariaDB retains the production width in its column type.
        return DB::getDriverName() === 'sqlite' ? 'varchar' : "varchar({$length})";
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_16_118100_expand_email_message_mailbox_for_reconciliation.php',
        );
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'from_name' => 'Mailbox Migration Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy',
            'provider_binding_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => encrypt('test-secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => encrypt('test-secret'),
            'smtp_auth_type' => 'password',
        ]);
    }
}
