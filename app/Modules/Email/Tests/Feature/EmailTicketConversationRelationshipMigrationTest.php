<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Jobs\BackfillEmailTicketConversationLinks;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Models\EmailTicketConversationLinkMigrationItem;
use App\Modules\Email\Models\EmailTicketConversationLinkMigrationRun;
use App\Modules\Email\Services\EmailTicketConversationLinkMigrator;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailTicketConversationRelationshipMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $decoy;

    private User $operator;

    private EmailAccount $account;

    private EmailFolder $folder;

    private int $nextUid = 910000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'email.account_manage',
            'email.mailbox_sync_manage',
            'ticket.update',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // The lower-ID user proves that the migration never falls back to
        // User::first() when attributing an operational data change.
        $this->decoy = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator = $this->authorizedOperator();
        $this->assertGreaterThan($this->decoy->id, $this->operator->id);

        $this->account = EmailAccount::query()->create([
            'address' => 'relationship-migration@example.test',
            'from_name' => 'Relationship migration fixture',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'ticket_ingress_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'relationship-migration@example.test',
            'imap_secret' => 'provider-secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'relationship-migration@example.test',
            'smtp_secret' => 'provider-secret',
        ]);
        $this->folder = EmailFolder::query()->create([
            'account_id' => $this->account->id,
            'provider' => 'imap',
            'path' => 'INBOX/private-relationship-path',
            'name' => 'Private relationship fixture',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 987654,
            'uid_next' => 999999,
            'live_start_uid' => 0,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }

    #[Test]
    public function additive_ledger_schema_is_present_and_preview_is_read_only_and_sanitized(): void
    {
        $fixture = $this->legacyFixture();
        $messageBefore = $this->rowSnapshot('email_messages', $fixture['message']->id);
        $placementBefore = $this->rowSnapshot('email_mailbox_placements', $fixture['placement']->id);
        $ticketBefore = $this->rowSnapshot('tickets', $fixture['ticket']->id);

        $run = app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 10);

        $this->assertTrue(Schema::hasColumns('email_ticket_conversation_link_migration_runs', [
            'public_id',
            'requested_by',
            'scope_fingerprint',
            'status',
        ]));
        $this->assertTrue(Schema::hasColumns('email_ticket_conversation_link_migration_items', [
            'email_message_id',
            'email_conversation_id',
            'source_fingerprint',
            'evidence',
        ]));
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED, $run->status);
        $this->assertSame(1, $run->candidate_count);
        $this->assertSame(1, $run->ready_count);
        $this->assertSame($this->operator->id, $run->requested_by);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        $this->assertSame($messageBefore, $this->rowSnapshot('email_messages', $fixture['message']->id));
        $this->assertSame($placementBefore, $this->rowSnapshot('email_mailbox_placements', $fixture['placement']->id));
        $this->assertSame($ticketBefore, $this->rowSnapshot('tickets', $fixture['ticket']->id));

        $evidence = json_encode($run->items()->sole()->evidence, JSON_THROW_ON_ERROR);
        foreach ([
            'Private customer subject',
            'Private customer body',
            'private.sender@example.test',
            'INBOX/private-relationship-path',
            'private-conversation-key',
            '910000',
            '987654',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $evidence);
        }
    }

    #[Test]
    public function command_requires_the_exact_active_authorized_human_actor(): void
    {
        Queue::fake();
        $this->legacyFixture();

        $this->artisan('email:backfill-ticket-conversation-links')
            ->assertExitCode(Command::INVALID);
        $this->artisan('email:backfill-ticket-conversation-links', [
            '--actor' => $this->decoy->id,
        ])->assertExitCode(Command::FAILURE);

        $systemActor = $this->authorizedOperator([
            'is_system_actor' => true,
            'system_actor_key' => 'relationship-migration-test',
        ]);
        $this->artisan('email:backfill-ticket-conversation-links', [
            '--actor' => $systemActor->id,
        ])->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('email_ticket_conversation_link_migration_runs', 0);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function command_separates_frozen_preview_from_explicit_bounded_dispatch(): void
    {
        Queue::fake();
        $this->legacyFixture();

        $this->artisan('email:backfill-ticket-conversation-links', [
            '--actor' => $this->operator->id,
            '--limit' => 10,
        ])->assertSuccessful();

        $run = EmailTicketConversationLinkMigrationRun::query()->sole();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED, $run->status);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertNothingPushed();

        $this->artisan('email:backfill-ticket-conversation-links', [
            '--actor' => $this->operator->id,
            '--apply' => $run->public_id,
        ])->assertSuccessful();

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertPushed(
            BackfillEmailTicketConversationLinks::class,
            fn (BackfillEmailTicketConversationLinks $job): bool => $job->uniqueId()
                === 'email-ticket-link-migration:'.$run->id,
        );
    }

    #[Test]
    public function each_queue_claim_processes_at_most_twenty_five_frozen_items(): void
    {
        Queue::fake();
        for ($index = 0; $index < 26; $index++) {
            $this->legacyFixture();
        }

        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 100);
        $migrator->queueApply($run, $this->operator);
        $job = new BackfillEmailTicketConversationLinks($run->id, $this->operator->id);

        // Queue::fake records the initial unique job without running Laravel's
        // normal UntilProcessing lock release, so model that worker boundary.
        app(UniqueLock::class)->release($job);
        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(25, $run->applied_count);
        $this->assertSame(1, $run->ready_count);
        $this->assertDatabaseCount('email_ticket_conversation_links', 25);
        Queue::assertPushed(BackfillEmailTicketConversationLinks::class, 2);

        app(UniqueLock::class)->release($job);
        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(26, $run->applied_count);
        $this->assertSame(0, $run->ready_count);
        $this->assertDatabaseCount('email_ticket_conversation_links', 26);
        Queue::assertPushed(BackfillEmailTicketConversationLinks::class, 2);
    }

    #[Test]
    public function continuation_dispatch_failure_is_terminal_and_a_fresh_preview_resumes_remaining_items(): void
    {
        Queue::fake();
        for ($index = 0; $index < 26; $index++) {
            $this->legacyFixture();
        }

        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 100);
        $migrator->queueApply($run, $this->operator);
        $job = new class($run->id, $this->operator->id) extends BackfillEmailTicketConversationLinks
        {
            protected function dispatchContinuation(): void
            {
                throw new RuntimeException('sensitive queue transport failure');
            }
        };

        try {
            app()->call([$job, 'handle']);
            $this->fail('A failed continuation dispatch left the committed run looking runnable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'email_ticket_link_migration_continuation_dispatch_failed',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('sensitive', $exception->getMessage());
        }

        $failed = $run->fresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_FAILED, $failed->status);
        $this->assertSame('continuation_dispatch_failed', $failed->error_code);
        $this->assertSame(25, $failed->applied_count);
        $this->assertSame(1, $failed->ready_count);
        $this->assertNotNull($failed->completed_at);
        $this->assertDatabaseCount('email_ticket_conversation_links', 25);

        // Laravel calls failed() after the final attempt. It must not overwrite
        // the more precise continuation-dispatch evidence recorded by handle().
        $job->failed(new RuntimeException('outer worker failure'));
        $this->assertSame('continuation_dispatch_failed', $run->fresh()->error_code);

        $resume = $migrator->preview($this->operator, 100);
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED, $resume->status);
        $this->assertSame(25, $resume->already_mapped_count);
        $this->assertSame(1, $resume->ready_count);
        $migrator->queueApply($resume, $this->operator);
        app()->call([
            new BackfillEmailTicketConversationLinks($resume->id, $this->operator->id),
            'handle',
        ]);

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED, $resume->fresh()->status);
        $this->assertSame(1, $resume->fresh()->applied_count);
        $this->assertDatabaseCount('email_ticket_conversation_links', 26);
        $this->assertSame('continuation_dispatch_failed', $run->fresh()->error_code);
    }

    #[Test]
    public function final_attempt_failure_hook_marks_an_unstarted_queued_run_terminal(): void
    {
        Queue::fake();
        $this->legacyFixture();
        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);
        $migrator->queueApply($run, $this->operator);
        $job = new BackfillEmailTicketConversationLinks($run->id, $this->operator->id);

        $job->failed(new RuntimeException('sensitive worker bootstrap failure'));

        $failed = $run->fresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_FAILED, $failed->status);
        $this->assertSame('worker_failed', $failed->error_code);
        $this->assertSame(1, $failed->ready_count);
        $this->assertNotNull($failed->completed_at);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
    }

    #[Test]
    public function apply_creates_one_audited_primary_link_without_business_or_provider_side_effects(): void
    {
        Queue::fake();
        $fixture = $this->legacyFixture();
        $messageBefore = $this->rowSnapshot('email_messages', $fixture['message']->id);
        $placementBefore = $this->rowSnapshot('email_mailbox_placements', $fixture['placement']->id);
        $ticketBefore = $this->rowSnapshot('tickets', $fixture['ticket']->id);
        $ticketMessageBefore = $this->rowSnapshot('ticket_messages', $fixture['ticket_message']->id);
        $ticketEventCount = DB::table('ticket_events')->count();
        $remoteOperationCount = DB::table('email_remote_operations')->count();
        $notificationCount = DB::table('notifications')->count();

        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);
        $migrator->queueApply($run, $this->operator);
        app()->call([
            new BackfillEmailTicketConversationLinks($run->id, $this->operator->id),
            'handle',
        ]);

        $link = EmailTicketConversationLink::query()->sole();
        $this->assertSame($fixture['ticket']->id, $link->ticket_id);
        $this->assertSame($fixture['message']->id, $link->email_message_id);
        $this->assertSame($fixture['placement']->id, $link->email_mailbox_placement_id);
        $this->assertSame($fixture['conversation']->id, $link->email_conversation_id);
        $this->assertSame($this->operator->id, $link->linked_by);
        $this->assertNotSame($this->decoy->id, $link->linked_by);
        $this->assertSame(EmailTicketConversationLink::ROLE_PRIMARY, $link->relationship_role);
        $this->assertSame(EmailTicketConversationLink::AUDIENCE_CUSTOMER, $link->audience);
        $this->assertSame('legacy_ticket_pointer_migration', $link->metadata['source']);
        $this->assertSame($fixture['ticket_message']->id, $link->metadata['ticket_message_id']);

        $run->refresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->applied_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertSame(
            EmailTicketConversationLinkMigrationItem::STATUS_APPLIED,
            $run->items()->sole()->status,
        );
        $this->assertSame($messageBefore, $this->rowSnapshot('email_messages', $fixture['message']->id));
        $this->assertSame($placementBefore, $this->rowSnapshot('email_mailbox_placements', $fixture['placement']->id));
        $this->assertSame($ticketBefore, $this->rowSnapshot('tickets', $fixture['ticket']->id));
        $this->assertSame($ticketMessageBefore, $this->rowSnapshot('ticket_messages', $fixture['ticket_message']->id));
        $this->assertSame($ticketEventCount, DB::table('ticket_events')->count());
        $this->assertSame($remoteOperationCount, DB::table('email_remote_operations')->count());
        $this->assertSame($notificationCount, DB::table('notifications')->count());

        // Duplicate delivery and a fresh preview are both idempotent.
        app()->call([
            new BackfillEmailTicketConversationLinks($run->id, $this->operator->id),
            'handle',
        ]);
        $rerun = $migrator->preview($this->operator, 10);
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED, $rerun->status);
        $this->assertSame(1, $rerun->already_mapped_count);
        $this->assertDatabaseCount('email_ticket_conversation_links', 1);
    }

    #[Test]
    public function proven_internal_capture_preserves_internal_audience(): void
    {
        Queue::fake();
        $fixture = $this->legacyFixture(visibility: 'internal');
        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);
        $migrator->queueApply($run, $this->operator);

        app()->call([
            new BackfillEmailTicketConversationLinks($run->id, $this->operator->id),
            'handle',
        ]);

        $this->assertSame(
            EmailTicketConversationLink::AUDIENCE_INTERNAL,
            EmailTicketConversationLink::query()->sole()->audience,
        );
        $this->assertSame('internal', $fixture['ticket_message']->fresh()->visibility);
    }

    #[Test]
    public function competing_legacy_ticket_claims_block_the_whole_preview_without_dispatch(): void
    {
        Queue::fake();
        $conversation = $this->conversation();
        $this->legacyFixture(conversation: $conversation);
        $this->legacyFixture(conversation: $conversation);

        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED, $run->status);
        $this->assertSame(2, $run->conflict_count);
        $this->assertSame(
            [
                EmailTicketConversationLinkMigrationItem::STATUS_PRIMARY_CONFLICT,
                EmailTicketConversationLinkMigrationItem::STATUS_PRIMARY_CONFLICT,
            ],
            $run->items()->orderBy('id')->pluck('status')->all(),
        );

        try {
            $migrator->queueApply($run, $this->operator);
            $this->fail('A conflicting migration preview must not be dispatched.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('email_ticket_conversation_links', 0);
            Queue::assertNothingPushed();
        }
    }

    #[Test]
    public function missing_exact_ticket_message_provenance_blocks_without_partial_link(): void
    {
        Queue::fake();
        $this->legacyFixture(withTicketMessage: false);

        $run = app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 10);

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED, $run->status);
        $this->assertSame(1, $run->failed_count);
        $item = $run->items()->sole();
        $this->assertSame(EmailTicketConversationLinkMigrationItem::STATUS_FAILED, $item->status);
        $this->assertSame('missing_capture_provenance', $item->reason_code);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function source_change_after_preview_marks_the_item_stale_and_fails_closed(): void
    {
        Queue::fake();
        $fixture = $this->legacyFixture();
        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);
        $migrator->queueApply($run, $this->operator);
        $fixture['message']->forceFill(['state' => 'reviewed-after-preview'])->save();

        try {
            app()->call([
                new BackfillEmailTicketConversationLinks($run->id, $this->operator->id),
                'handle',
            ]);
            $this->fail('Changed source evidence must fail the frozen apply.');
        } catch (RuntimeException $exception) {
            $this->assertSame('email_ticket_link_migration_item_stale', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_FAILED, $run->status);
        $this->assertSame('item_stale', $run->error_code);
        $this->assertSame(
            EmailTicketConversationLinkMigrationItem::STATUS_STALE,
            $run->items()->sole()->status,
        );
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
    }

    #[Test]
    public function exact_authoritative_link_that_wins_after_preview_is_accepted_without_duplication(): void
    {
        Queue::fake();
        $fixture = $this->legacyFixture();
        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $run = $migrator->preview($this->operator, 10);
        $migrator->queueApply($run, $this->operator);
        $winner = EmailTicketConversationLink::query()->create([
            'ticket_id' => $fixture['ticket']->id,
            'email_message_id' => $fixture['message']->id,
            'email_mailbox_placement_id' => $fixture['placement']->id,
            'account_id' => $this->account->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'linked_by' => $this->decoy->id,
            'conversation_key' => $fixture['conversation']->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'metadata' => ['source' => 'concurrent-authoritative-writer'],
            'linked_at' => now(),
        ]);

        app()->call([
            new BackfillEmailTicketConversationLinks($run->id, $this->operator->id),
            'handle',
        ]);

        $item = $run->fresh()->items()->sole();
        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(EmailTicketConversationLinkMigrationItem::STATUS_ALREADY_MAPPED, $item->status);
        $this->assertSame('concurrent_authoritative_link', $item->reason_code);
        $this->assertSame($winner->id, $item->applied_link_id);
        $this->assertSame($this->decoy->id, $winner->fresh()->linked_by);
        $this->assertDatabaseCount('email_ticket_conversation_links', 1);
    }

    #[Test]
    public function one_source_projected_into_competing_active_conversations_is_blocked(): void
    {
        Queue::fake();
        $fixture = $this->legacyFixture();
        $otherConversation = $this->conversation();
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $fixture['message']->id,
            'email_conversation_id' => $otherConversation->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $this->folder->id,
            'provider' => 'imap',
            'folder_path' => $this->folder->path,
            'remote_message_id' => '<competing-conversation-occurrence@example.test>',
            'imap_uid_validity' => $this->folder->uid_validity,
            'imap_uid' => $this->nextUid++,
            'provider_seen' => false,
            'provider_deleted' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);

        $run = app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 10);
        $item = $run->items()->sole();

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED, $run->status);
        $this->assertSame(EmailTicketConversationLinkMigrationItem::STATUS_ACCOUNT_CONFLICT, $item->status);
        $this->assertSame('active_placement_conversation_conflict', $item->reason_code);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_operator_cannot_apply_someone_elses_frozen_preview(): void
    {
        Queue::fake();
        $this->legacyFixture();
        $other = $this->authorizedOperator();
        $run = app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 10);

        $this->expectException(AuthorizationException::class);

        try {
            app(EmailTicketConversationLinkMigrator::class)->queueApply($run, $other);
        } finally {
            $this->assertDatabaseCount('email_ticket_conversation_links', 0);
            Queue::assertNothingPushed();
        }
    }

    #[Test]
    public function cap_overflow_records_one_blocked_run_without_a_partial_item_cohort(): void
    {
        Queue::fake();
        $this->legacyFixture();
        $this->legacyFixture();

        $run = app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 1);

        $this->assertSame(EmailTicketConversationLinkMigrationRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('scope_overflow', $run->error_code);
        $this->assertSame(2, $run->candidate_count);
        $this->assertSame(2, $run->conflict_count);
        $this->assertDatabaseCount('email_ticket_conversation_link_migration_items', 0);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function expired_preview_and_worker_permission_revocation_fail_closed(): void
    {
        Queue::fake();
        $this->legacyFixture();
        $migrator = app(EmailTicketConversationLinkMigrator::class);
        $expired = $migrator->preview($this->operator, 10);
        $this->travel(16)->minutes();

        try {
            $migrator->queueApply($expired, $this->operator);
            $this->fail('An expired frozen preview must not be queued.');
        } catch (ValidationException) {
            $this->assertSame(
                EmailTicketConversationLinkMigrationRun::STATUS_PREVIEWED,
                $expired->fresh()->status,
            );
        }

        $this->travelBack();
        $revoked = $migrator->preview($this->operator, 10);
        $migrator->queueApply($revoked, $this->operator);
        $this->operator->revokePermissionTo('ticket.update');

        try {
            app()->call([
                new BackfillEmailTicketConversationLinks($revoked->id, $this->operator->id),
                'handle',
            ]);
            $this->fail('A revoked migration operator must fail before writing a link.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'email_ticket_link_migration_actor_unavailable',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            EmailTicketConversationLinkMigrationRun::STATUS_FAILED,
            $revoked->fresh()->status,
        );
        $this->assertSame('actor_unavailable', $revoked->fresh()->error_code);
        $this->assertDatabaseCount('email_ticket_conversation_links', 0);
    }

    #[Test]
    public function ledger_migration_refuses_rollback_after_preview_evidence_exists(): void
    {
        $this->legacyFixture();
        app(EmailTicketConversationLinkMigrator::class)->preview($this->operator, 10);
        $migration = require database_path(
            'migrations/2026_08_24_130000_create_email_ticket_conversation_link_migration_ledger.php',
        );

        try {
            $migration->down();
            $this->fail('Migration evidence must prevent destructive schema rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Refusing to remove Email/Ticket relationship migration evidence.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('email_ticket_conversation_link_migration_runs'));
        $this->assertTrue(Schema::hasTable('email_ticket_conversation_link_migration_items'));
    }

    /**
     * @return array{
     *     ticket: Ticket,
     *     message: EmailMessage,
     *     conversation: EmailConversation,
     *     placement: EmailMailboxPlacement,
     *     ticket_message: ?TicketMessage
     * }
     */
    private function legacyFixture(
        ?Ticket $ticket = null,
        ?EmailConversation $conversation = null,
        string $visibility = 'public',
        bool $withTicketMessage = true,
    ): array {
        $ticket ??= Ticket::factory()->create();
        $conversation ??= $this->conversation();
        $uid = $this->nextUid++;
        $message = EmailMessage::query()->create([
            'account_id' => $this->account->id,
            'mailbox' => $this->folder->path,
            'imap_uid_validity' => $this->folder->uid_validity,
            'imap_uid' => $uid,
            'message_id' => "<relationship-migration-{$uid}@example.test>",
            'subject' => 'Private customer subject',
            'from_email' => 'private.sender@example.test',
            'received_at' => now()->startOfSecond(),
            'state' => 'linked',
            'body_text' => 'Private customer body',
            'ticket_id' => $ticket->id,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $this->folder->id,
            'provider' => 'imap',
            'folder_path' => $this->folder->path,
            'remote_message_id' => $message->message_id,
            'imap_uid_validity' => $this->folder->uid_validity,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_deleted' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $ticketMessage = $withTicketMessage
            ? TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'source_inbound_email_message_id' => $message->id,
                'inbound_email_message_id' => $message->id,
                'author_id' => $visibility === 'internal' ? $this->operator->id : null,
                'author_type' => $visibility === 'internal' ? 'user' : 'contact',
                'type' => $visibility === 'internal' ? 'internal_note' : 'customer_reply',
                'visibility' => $visibility,
                'subject' => 'Private captured Ticket subject',
                'body' => 'Private captured Ticket body',
                'metadata' => ['email_message_id' => $message->id],
            ])
            : null;

        return compact('ticket', 'message', 'conversation', 'placement') + [
            'ticket_message' => $ticketMessage,
        ];
    }

    private function conversation(): EmailConversation
    {
        return EmailConversation::query()->create([
            'account_id' => $this->account->id,
            'conversation_key' => 'private-conversation-key-'.$this->nextUid,
            'status' => EmailConversation::STATUS_ACTIVE,
            'message_count' => 0,
            'active_placement_count' => 0,
            'provider_unread_count' => 0,
            'has_attachments' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function authorizedOperator(array $overrides = []): User
    {
        $operator = User::factory()->create(array_merge([
            'status' => User::STATUS_ACTIVE,
            'is_system_actor' => false,
            'system_actor_key' => null,
        ], $overrides));
        $operator->givePermissionTo([
            'email.account_manage',
            'email.mailbox_sync_manage',
            'ticket.update',
        ]);

        return $operator;
    }

    /** @return array<string, mixed> */
    private function rowSnapshot(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->firstOrFail();
    }
}
