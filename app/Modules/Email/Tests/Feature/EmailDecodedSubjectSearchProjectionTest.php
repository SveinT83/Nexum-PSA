<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Support\EmailSubjectPresenter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class EmailDecodedSubjectSearchProjectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function eloquent_create_and_update_keep_the_non_fillable_projection_in_sync(): void
    {
        $account = $this->account('subject-write@example.test');
        $rawSubject = '=?UTF-8?Q?Pr=C3=B8ve_bl=C3=A5b=C3=A6r?=';

        $message = EmailMessage::query()->create([
            ...$this->messageAttributes($account, 1),
            'subject' => $rawSubject,
            'subject_search' => 'caller-controlled value',
        ]);

        $this->assertNotContains('subject_search', $message->getFillable());
        $this->assertSame($rawSubject, $message->refresh()->subject);
        $this->assertSame('Prøve blåbær', $message->subject_search);
        $this->assertArrayNotHasKey('subject_search', $message->toArray());

        $message->forceFill(['subject_search' => 'tampered projection'])->save();
        $this->assertSame('Prøve blåbær', $message->refresh()->subject_search);

        $updatedRawSubject = '=?ISO-8859-1?Q?Oppdatert_bl=E5b=E6r?=';
        $message->update(['subject' => $updatedRawSubject]);

        $message->refresh();
        $this->assertSame($updatedRawSubject, $message->subject);
        $this->assertSame('Oppdatert blåbær', $message->subject_search);
    }

    #[Test]
    public function plain_and_malformed_subjects_use_the_exact_presentation_contract(): void
    {
        $account = $this->account('subject-contract@example.test');
        $subjects = [
            'Vanlig norsk: Prøve blåbær 😊',
            '=?utf-8?Q?Fwd=3A_DEKKSPERTEN_DA_=28936529364=29_har_f=C3=',
            '=?X-UNKNOWN?Q?Hello_=E5?=',
            " \r\n\t",
            null,
        ];

        foreach ($subjects as $offset => $subject) {
            $message = EmailMessage::query()->create([
                ...$this->messageAttributes($account, $offset + 10),
                'subject' => $subject,
            ])->refresh();

            $this->assertSame($subject, $message->subject);
            $this->assertSame(
                EmailSubjectPresenter::present($subject),
                $message->subject_search,
            );
        }
    }

    #[Test]
    public function oversize_inbound_storage_preserves_the_raw_subject_without_provider_writes(): void
    {
        Queue::fake();
        Storage::fake('local');

        $account = $this->account('subject-inbound@example.test');
        $account->forceFill([
            'ticket_ingress_enabled' => false,
            // Even an otherwise destructive legacy policy must not run when
            // this mailbox is not authorized for Ticket ingress.
            'delete_policy' => 'auto_delete',
        ])->save();
        $rawSubject = '=?UTF-8?Q?Innkommende_pr=C3=B8ve_bl=C3=A5b=C3=A6r?=';

        app()->call([new StoreInboundMessage([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 75,
            'uid_validity' => 750,
            'message_id' => '<subject-inbound@example.test>',
            'subject' => $rawSubject,
            'from_name' => 'Inbound Sender',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'size_bytes' => 8192,
            'is_oversize' => true,
        ]), 'handle']);

        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('imap_uid', 75)
            ->sole();

        $this->assertSame($rawSubject, $message->subject);
        $this->assertSame('Innkommende prøve blåbær', $message->subject_search);
        $this->assertTrue($message->is_oversize);
        $this->assertNull($message->raw_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('email_remote_operations', 0);
        Queue::assertNotPushed(ProcessInboundRules::class);
    }

    #[Test]
    public function migration_backfill_preserves_raw_identity_and_timestamps_and_is_idempotent(): void
    {
        $account = $this->account('subject-backfill@example.test');
        Schema::table('email_messages', function ($table): void {
            $table->dropColumn('subject_search');
        });

        $rawSubject = '=?UTF-8?B?UHLDuHZlIGJsw6Viw6Zy?=';
        $timestamp = '2026-08-15 08:30:00';
        $messageId = DB::table('email_messages')->insertGetId([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 90,
            'message_id' => '<subject-backfill@example.test>',
            'subject' => $rawSubject,
            'from_email' => 'provider-sender@example.test',
            'received_at' => $timestamp,
            'size_bytes' => 4096,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $providerIdentity = app(EmailProviderMessageIdentity::class);
        $conversationProjector = app(EmailConversationProjector::class);
        $messageBefore = EmailMessage::query()->findOrFail($messageId);
        $identityBefore = $providerIdentity->forMessage($messageBefore);
        $conversationKey = $conversationProjector->conversationKey($messageBefore);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => $conversationKey,
            'subject' => $rawSubject,
            'first_email_message_id' => $messageId,
            'latest_email_message_id' => $messageId,
            'message_count' => 1,
            'active_placement_count' => 0,
            'provider_unread_count' => 0,
            'has_attachments' => false,
            'first_message_at' => $timestamp,
            'last_message_at' => $timestamp,
        ]);

        $this->assertNotNull($identityBefore);

        $initialMigration = require database_path(
            'migrations/2026_08_15_121000_add_email_message_subject_search.php'
        );
        $initialMigration->up();

        $firstPass = DB::table('email_messages')->find($messageId);
        $this->assertSame($rawSubject, $firstPass->subject);
        $this->assertSame('<subject-backfill@example.test>', $firstPass->message_id);
        $this->assertSame('provider-sender@example.test', $firstPass->from_email);
        $this->assertSame(4096, $firstPass->size_bytes);
        $this->assertSame('Prøve blåbær', $firstPass->subject_search);
        $this->assertSame($timestamp, $firstPass->created_at);
        $this->assertSame($timestamp, $firstPass->updated_at);
        $this->assertSame(
            $identityBefore,
            $providerIdentity->forMessage(EmailMessage::query()->findOrFail($messageId)),
        );

        // A stale derived value is repaired without touching source identity or
        // timestamps, and a further pass becomes a no-op.
        DB::table('email_messages')
            ->where('id', $messageId)
            ->update(['subject_search' => 'stale derived value']);
        $hardeningMigration = require database_path(
            'migrations/2026_08_15_121100_harden_email_message_subject_search_backfill.php'
        );
        $hardeningMigration->up();
        $secondPass = (array) DB::table('email_messages')->find($messageId);
        $hardeningMigration->up();
        $thirdPass = (array) DB::table('email_messages')->find($messageId);

        $this->assertSame('Prøve blåbær', $secondPass['subject_search']);
        $this->assertSame($timestamp, $secondPass['updated_at']);
        $this->assertSame($secondPass, $thirdPass);
        $this->assertSame(
            $identityBefore,
            $providerIdentity->forMessage(EmailMessage::query()->findOrFail($messageId)),
        );
        $this->assertSame($conversationKey, $conversation->refresh()->conversation_key);
        $this->assertSame($rawSubject, $conversation->subject);
    }

    #[Test]
    public function hardening_backfill_does_not_overwrite_a_concurrent_subject_writer(): void
    {
        $account = $this->account('subject-cas@example.test');
        $originalTimestamp = '2026-08-15 09:00:00';
        $writerTimestamp = '2026-08-15 09:01:00';
        $writerRawSubject = '=?UTF-8?Q?Concurrent_bl=C3=A5b=C3=A6r?=';
        $writerProjection = EmailSubjectPresenter::present($writerRawSubject);
        $messageId = DB::table('email_messages')->insertGetId([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 91,
            'message_id' => '<subject-cas@example.test>',
            'subject' => '=?UTF-8?Q?Original_pr=C3=B8ve?=',
            'subject_search' => null,
            'received_at' => $originalTimestamp,
            'created_at' => $originalTimestamp,
            'updated_at' => $originalTimestamp,
        ]);
        $writerRan = false;

        DB::listen(function (QueryExecuted $query) use (
            &$writerRan,
            $messageId,
            $writerProjection,
            $writerRawSubject,
            $writerTimestamp,
        ): void {
            $sql = strtolower(ltrim($query->sql));

            if ($writerRan
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'email_messages')
                || ! str_contains($sql, 'subject_search')) {
                return;
            }

            // QueryExecuted fires after the chunk rows have been read. This
            // write therefore races precisely between the SELECT and CAS UPDATE.
            $writerRan = true;
            DB::table('email_messages')
                ->where('id', $messageId)
                ->update([
                    'subject' => $writerRawSubject,
                    'subject_search' => $writerProjection,
                    'updated_at' => $writerTimestamp,
                ]);
        });

        $migration = require database_path(
            'migrations/2026_08_15_121100_harden_email_message_subject_search_backfill.php'
        );
        $migration->up();

        $message = DB::table('email_messages')->find($messageId);
        $this->assertTrue($writerRan);
        $this->assertSame($writerRawSubject, $message->subject);
        $this->assertSame($writerProjection, $message->subject_search);
        $this->assertSame($writerTimestamp, $message->updated_at);
    }

    #[Test]
    public function hardening_backfill_repairs_projection_across_an_unrelated_concurrent_write(): void
    {
        $account = $this->account('subject-unrelated-cas@example.test');
        $originalTimestamp = '2026-08-15 09:10:00';
        $writerTimestamp = '2026-08-15 09:11:00';
        $rawSubject = '=?UTF-8?Q?Repair_bl=C3=A5b=C3=A6r?=';
        $messageId = DB::table('email_messages')->insertGetId([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 92,
            'message_id' => '<subject-unrelated-cas@example.test>',
            'subject' => $rawSubject,
            'subject_search' => null,
            'state' => 'untriaged',
            'received_at' => $originalTimestamp,
            'created_at' => $originalTimestamp,
            'updated_at' => $originalTimestamp,
        ]);
        $writerRan = false;

        DB::listen(function (QueryExecuted $query) use (
            &$writerRan,
            $messageId,
            $writerTimestamp,
        ): void {
            $sql = strtolower(ltrim($query->sql));

            if ($writerRan
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'email_messages')
                || ! str_contains($sql, 'subject_search')) {
                return;
            }

            $writerRan = true;
            DB::table('email_messages')
                ->where('id', $messageId)
                ->update([
                    'state' => 'archived',
                    'updated_at' => $writerTimestamp,
                ]);
        });

        $migration = require database_path(
            'migrations/2026_08_15_121100_harden_email_message_subject_search_backfill.php'
        );
        $migration->up();

        $message = DB::table('email_messages')->find($messageId);
        $this->assertTrue($writerRan);
        $this->assertSame($rawSubject, $message->subject);
        $this->assertSame('Repair blåbær', $message->subject_search);
        $this->assertSame('archived', $message->state);
        $this->assertSame($writerTimestamp, $message->updated_at);
    }

    #[Test]
    public function a_worker_that_saw_no_projection_column_detects_it_after_migration(): void
    {
        $capability = new ReflectionProperty(EmailMessage::class, 'subjectSearchColumnAvailable');
        $capability->setValue(null, null);

        Schema::table('email_messages', function ($table): void {
            $table->dropColumn('subject_search');
        });

        $account = $this->account('subject-worker@example.test');
        $message = EmailMessage::query()->create([
            ...$this->messageAttributes($account, 93),
            'subject' => 'Subject before migration',
        ]);

        $migration = require database_path(
            'migrations/2026_08_15_121000_add_email_message_subject_search.php'
        );
        $migration->up();

        $rawSubject = '=?UTF-8?Q?Etter_migrering_bl=C3=A5b=C3=A6r?=';
        $message->update(['subject' => $rawSubject]);

        $message->refresh();
        $this->assertSame($rawSubject, $message->subject);
        $this->assertSame('Etter migrering blåbær', $message->subject_search);
    }

    #[Test]
    public function search_text_scope_groups_all_or_fields_beneath_outer_constraints(): void
    {
        $account = $this->account('subject-scope@example.test');
        $otherAccount = $this->account('subject-scope-other@example.test');

        $decodedSubject = $this->message($account, 101, [
            'subject' => '=?UTF-8?Q?Pr=C3=B8ve_bl=C3=A5b=C3=A6r?=',
        ]);
        $rawSubject = $this->message($account, 102, ['subject' => 'Needle raw subject']);
        DB::table('email_messages')->where('id', $rawSubject->id)->update(['subject_search' => null]);
        $fromName = $this->message($account, 103, ['from_name' => 'Needle Operator']);
        $fromEmail = $this->message($account, 104, ['from_email' => 'needle@example.test']);
        $body = $this->message($account, 105, ['body_text' => 'Body contains the needle token.']);
        $this->message($account, 106, ['subject' => 'Unrelated message']);
        $this->message($otherAccount, 107, ['from_name' => 'Needle Outside Scope']);

        $this->assertSame(
            [$decodedSubject->id],
            EmailMessage::query()
                ->where('account_id', $account->id)
                ->searchText('blåbær')
                ->pluck('id')
                ->all(),
        );

        $matches = EmailMessage::query()
            ->where('account_id', $account->id)
            ->searchText(' needle ')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([
            $rawSubject->id,
            $fromName->id,
            $fromEmail->id,
            $body->id,
        ], $matches);
        $this->assertSame(
            6,
            EmailMessage::query()
                ->where('account_id', $account->id)
                ->searchText('   ')
                ->count(),
        );
    }

    #[Test]
    public function search_text_scope_treats_percent_underscore_and_escape_as_literals(): void
    {
        $account = $this->account('subject-literal-search@example.test');

        $percent = $this->message($account, 201, ['subject' => 'Status 100% complete']);
        $this->message($account, 202, ['subject' => 'Status 100X complete']);
        $underscore = $this->message($account, 203, ['from_name' => 'Agent_Name']);
        $this->message($account, 204, ['from_name' => 'AgentXName']);
        $escape = $this->message($account, 205, ['body_text' => 'Alert! urgent']);
        $this->message($account, 206, ['body_text' => 'AlertX urgent']);

        $this->assertSame(
            [$percent->id],
            EmailMessage::query()->searchText('%')->pluck('id')->all(),
        );
        $this->assertSame(
            [$underscore->id],
            EmailMessage::query()->searchText('_')->pluck('id')->all(),
        );
        $this->assertSame(
            [$escape->id],
            EmailMessage::query()->searchText('!')->pluck('id')->all(),
        );
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Decoded subject search projection test',
            'from_name' => 'Subject Search',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'subject-search-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'subject-search-test-secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    /** @return array<string, mixed> */
    private function messageAttributes(EmailAccount $account, int $uid): array
    {
        return [
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => "<subject-search-{$uid}@example.test>",
            'received_at' => now(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function message(EmailAccount $account, int $uid, array $overrides = []): EmailMessage
    {
        return EmailMessage::query()->create([
            ...$this->messageAttributes($account, $uid),
            'subject' => "Message {$uid}",
            ...$overrides,
        ]);
    }
}
