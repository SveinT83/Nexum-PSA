<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\ApplyEmailCursorRebaseline;
use App\Modules\Email\Actions\CancelEmailHistoricalImport;
use App\Modules\Email\Actions\PreviewEmailCursorRebaseline;
use App\Modules\Email\Actions\PreviewEmailHistoricalImport;
use App\Modules\Email\Actions\RunEmailRemoteOperation;
use App\Modules\Email\Actions\StartEmailHistoricalImport;
use App\Modules\Email\Jobs\ImportHistoricalEmailMessages;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCursorRebaselineRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailHistoricalImportItem;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Queries\EmailMailboxMaintenanceQuery;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

class EmailHistoricalImportAndCursorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalMaintenanceFakeImapClient $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Cache::flush();
    }

    #[Test]
    public function maintenance_audit_instants_are_datetime_columns_and_status_contracts_are_exact(): void
    {
        foreach ([
            'email_folder_uid_namespaces' => ['established_at', 'superseded_at', 'created_at', 'updated_at'],
            'email_historical_import_runs' => ['preview_expires_at', 'queued_at', 'started_at', 'cancellation_requested_at', 'finished_at', 'created_at', 'updated_at'],
            'email_historical_import_items' => ['first_attempt_at', 'last_attempt_at', 'completed_at', 'created_at', 'updated_at'],
            'email_cursor_rebaseline_runs' => ['preview_expires_at', 'applied_at', 'finished_at', 'created_at', 'updated_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('datetime', Schema::getColumnType($table, $column), "{$table}.{$column}");
            }
        }

        $this->assertSame([
            'previewed', 'queued', 'running', 'cancelling', 'completed', 'partial', 'failed', 'cancelled', 'stale',
        ], [
            EmailHistoricalImportRun::STATUS_PREVIEWED,
            EmailHistoricalImportRun::STATUS_QUEUED,
            EmailHistoricalImportRun::STATUS_RUNNING,
            EmailHistoricalImportRun::STATUS_CANCELLING,
            EmailHistoricalImportRun::STATUS_COMPLETED,
            EmailHistoricalImportRun::STATUS_PARTIAL,
            EmailHistoricalImportRun::STATUS_FAILED,
            EmailHistoricalImportRun::STATUS_CANCELLED,
            EmailHistoricalImportRun::STATUS_STALE,
        ]);
    }

    #[Test]
    public function permission_roles_and_maintenance_page_require_both_permissions_without_exposing_message_content(): void
    {
        $this->seed(RoleSeeder::class);
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('email.mailbox_sync_manage'));
        $this->assertTrue(Role::findByName('Superuser')->hasPermissionTo('email.mailbox_sync_manage'));
        $this->assertFalse(Role::findByName('Tech')->hasPermissionTo('email.mailbox_sync_manage'));

        $operator = $this->user(['email.account_manage']);
        $account = $this->account();
        $folder = $this->folder($account, 701, 50);
        EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => 701,
            'imap_uid' => 49,
            'subject' => 'SECRET CONTENT MUST NOT LEAK',
            'received_at' => now(),
        ]);

        $this->actingAs($operator)
            ->get(route('tech.admin.settings.email.accounts.mailbox-maintenance', $account))
            ->assertForbidden();

        $operator->givePermissionTo('email.mailbox_sync_manage');
        $this->actingAs($operator->fresh())
            ->get(route('tech.admin.settings.email.accounts.mailbox-maintenance', $account))
            ->assertOk()
            ->assertSee('Mailbox maintenance')
            ->assertDontSee('SECRET CONTENT MUST NOT LEAK');

        $account->forceFill(['is_active' => false])->save();
        $this->actingAs($operator->fresh())
            ->get(route('tech.admin.settings.email.accounts.mailbox-maintenance', $account))
            ->assertForbidden();
    }

    #[Test]
    public function maintenance_folder_history_is_database_paginated_without_truncating_access(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        EmailFolder::query()->insert(array_map(
            static fn (int $index): array => [
                'account_id' => $account->id,
                'provider' => 'imap',
                'path' => sprintf('Folder %03d', $index),
                'name' => sprintf('Folder %03d', $index),
                'role' => EmailFolder::ROLE_CUSTOM,
                'is_selectable' => true,
                'sync_enabled' => true,
                'sync_status' => EmailFolder::SYNC_SYNCED,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            range(1, 125),
        ));

        $firstPage = app(EmailMailboxMaintenanceQuery::class)->forAccount($account, $operator)['folders'];

        $this->assertSame(100, $firstPage->count());
        $this->assertSame(125, $firstPage->total());
        $this->assertSame(2, $firstPage->lastPage());
        $this->assertSame('Folder 001', $firstPage->first()->path);
        $this->assertSame('Folder 100', $firstPage->last()->path);

        $this->actingAs($operator)
            ->get(route('tech.admin.settings.email.accounts.mailbox-maintenance', [
                'account' => $account,
                'folder_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('Showing folders 101–125 of 125.')
            ->assertSee('Folder 125')
            ->assertDontSee('Folder 001');
    }

    #[Test]
    public function preview_rejects_overflow_hard_and_uid_span_limits_instead_of_silently_truncating(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $first = $this->folder($account, 711, 200);
        $second = $this->folder($account, 712, 200, 'Archive');
        $provider = $this->fakeProvider($account, [
            'INBOX' => $this->state(711, 201),
            'Archive' => $this->state(712, 201),
        ], [
            'INBOX' => [10, 11],
            'Archive' => [12],
        ]);

        $action = app(PreviewEmailHistoricalImport::class);
        try {
            $action->handle($account, $operator, $this->scope([$first, $second], cap: 2));
            $this->fail('A multi-folder cap overflow must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cap', $exception->errors());
        }
        $this->assertSame(['Archive', 'INBOX'], $provider->searchFolders);
        $this->assertDatabaseCount('email_historical_import_runs', 0);

        foreach ([
            $this->scope([$first], cap: 501),
            [...$this->scope([$first]), 'date_from' => '2026-06-01', 'date_to' => '2026-07-02'],
        ] as $invalid) {
            try {
                $action->handle($account, $operator, $invalid);
                $this->fail('The unsafe historical scope must fail validation.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $wideFirst = $this->folder($account, 713, 30000, 'Wide/First');
        $wideSecond = $this->folder($account, 714, 30000, 'Wide/Second');
        $wideProvider = $this->fakeProvider($account, [
            'Wide/First' => $this->state(713, 30001),
            'Wide/Second' => $this->state(714, 30001),
        ], [
            'Wide/First' => [],
            'Wide/Second' => [],
        ]);
        try {
            $action->handle($account, $operator, $this->scope([$wideFirst, $wideSecond], cap: 2));
            $this->fail('Sparse multi-folder scans must share one global numeric budget.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('uid_from', $exception->errors());
        }
        $this->assertSame(['Wide/First'], $wideProvider->searchFolders);
    }

    #[Test]
    public function preview_and_confirmation_are_durable_metadata_only_idempotent_and_queued_on_email(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 721, 100);
        $this->fakeProvider($account, ['INBOX' => $this->state(721, 101)], ['INBOX' => [4, 8]]);

        $cursorBefore = [$folder->uid_validity, $folder->live_start_uid, $account->imap_live_start_uid];
        $run = app(PreviewEmailHistoricalImport::class)->handle(
            $account,
            $operator,
            $this->scope([$folder], cap: 10),
        );

        $this->assertSame(EmailHistoricalImportRun::STATUS_PREVIEWED, $run->status);
        $this->assertSame(2, $run->matched_count);
        $this->assertCount(2, $run->items);
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertSame($cursorBefore, [
            $folder->fresh()->uid_validity,
            $folder->fresh()->live_start_uid,
            $account->fresh()->imap_live_start_uid,
        ]);

        Queue::fake();
        $queued = app(StartEmailHistoricalImport::class)->handle(
            $account,
            $run,
            $operator,
            $run->preview_fingerprint,
        );
        $again = app(StartEmailHistoricalImport::class)->handle(
            $account,
            $queued,
            $operator,
            $run->preview_fingerprint,
        );

        $this->assertSame(EmailHistoricalImportRun::STATUS_QUEUED, $again->status);
        Queue::assertPushed(ImportHistoricalEmailMessages::class, 1);
        Queue::assertPushed(
            ImportHistoricalEmailMessages::class,
            fn (ImportHistoricalEmailMessages $job): bool => $job->runId === $run->id && $job->queue === 'email',
        );
    }

    #[Test]
    public function confirmation_rechecks_policy_and_private_storage_before_any_provider_read(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 723, 100);
        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(723, 101)],
            ['INBOX' => [4, 5]],
        );
        $policyRun = app(PreviewEmailHistoricalImport::class)->handle(
            $account,
            $operator,
            $this->scope([$folder], cap: 2),
        );
        $readsAfterPreview = $provider->connectCalls;
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'historical_import_max_messages'],
            ['value' => '1'],
        );

        try {
            app(StartEmailHistoricalImport::class)->handle(
                $account,
                $policyRun,
                $operator,
                $policyRun->preview_fingerprint,
            );
            $this->fail('A lowered installation cap must invalidate the exact preview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cap', $exception->errors());
        }
        $this->assertSame($readsAfterPreview, $provider->connectCalls);
        $this->assertSame(EmailHistoricalImportRun::STATUS_STALE, $policyRun->fresh()->status);
        $this->assertSame('HISTORICAL_IMPORT_POLICY_CAP_CHANGED', $policyRun->fresh()->error_code);

        CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'historical_import_max_messages')
            ->update(['value' => '10']);
        $storageRun = app(PreviewEmailHistoricalImport::class)->handle(
            $account,
            $operator,
            $this->scope([$folder], cap: 2),
        );
        $readsAfterStoragePreview = $provider->connectCalls;
        Storage::fake('local');
        Storage::disk('local')->put('email/raw', 'a file cannot be a payload directory');
        Storage::disk('local')->makeDirectory('email/attachments');

        try {
            app(StartEmailHistoricalImport::class)->handle(
                $account,
                $storageRun,
                $operator,
                $storageRun->preview_fingerprint,
            );
            $this->fail('An unwritable payload root must fail before provider verification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        $this->assertSame($readsAfterStoragePreview, $provider->connectCalls);
        $this->assertSame(EmailHistoricalImportRun::STATUS_FAILED, $storageRun->fresh()->status);
        $this->assertSame(
            'HISTORICAL_IMPORT_PRIVATE_STORAGE_UNAVAILABLE',
            $storageRun->fresh()->error_code,
        );
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertDatabaseCount('email_mailbox_placements', 0);
    }

    #[Test]
    public function preview_reauthorizes_the_exact_folder_after_provider_read(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 725, 100);
        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(725, 101)],
            ['INBOX' => [4]],
        );
        $provider->disableFolderIdAfterStateReads = $folder->id;
        $provider->disableAfterStateReadCount = 2;

        try {
            app(PreviewEmailHistoricalImport::class)->handle(
                $account,
                $operator,
                $this->scope([$folder], cap: 10),
            );
            $this->fail('A folder disabled during provider preview must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('folders', $exception->errors());
        }

        $this->assertDatabaseCount('email_historical_import_runs', 0);
    }

    #[Test]
    public function queued_import_uses_namespace_identity_skips_automation_and_projects_insert_only_read_baselines(): void
    {
        Storage::fake('local');
        $operator = $this->operator();
        $viewer = $this->user(['email.inbox_view']);
        $account = $this->account();
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $viewer->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account,
            $viewer,
            false,
            EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            'historical-import-test-grant',
            $operator,
        );
        $folder = $this->folder($account, 732, 100);

        // A previous namespace may contain the same numeric UID without
        // blocking or being overwritten by the current import.
        EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid_validity' => 731,
            'imap_uid' => 5,
            'subject' => 'Old namespace evidence',
            'received_at' => now()->subMonth(),
        ]);

        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(732, 101)],
            ['INBOX' => [5]],
            ['INBOX:5' => [
                'mailbox' => 'INBOX',
                'imap_uid' => 5,
                'message_id' => '<historical-5@example.test>',
                'subject' => 'Historical namespace message',
                'from_email' => 'sender@example.test',
                'headers' => [],
                'received_at' => now()->subDays(2),
                'size_bytes' => 2048,
            ]],
        );
        $provider->exactMessages['INBOX:5'] = Message::fromString($this->historicalRawMessage());
        $run = app(PreviewEmailHistoricalImport::class)->handle($account, $operator, $this->scope([$folder], cap: 10));
        $run->forceFill(['status' => EmailHistoricalImportRun::STATUS_QUEUED, 'queued_at' => now()])->save();
        $cursorBefore = $folder->live_start_uid;
        $ticketCount = DB::table('tickets')->count();

        app()->call([new ImportHistoricalEmailMessages($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(EmailHistoricalImportRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->imported_count);
        $this->assertSame(2, EmailMessage::query()->where('account_id', $account->id)->where('imap_uid', 5)->count());
        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('imap_uid_validity', 732)
            ->where('imap_uid', 5)
            ->firstOrFail();
        $placement = EmailMailboxPlacement::query()->where('email_message_id', $message->id)->firstOrFail();
        $this->assertSame($folder->active_uid_namespace_id, $placement->uid_namespace_id);
        $this->assertSame('Historical exact body.', trim((string) $message->body_text));
        $this->assertNotNull($message->raw_path);
        Storage::disk('local')->assertExists($message->raw_path);
        $attachment = EmailAttachment::query()->where('message_id', $message->id)->sole();
        $this->assertSame('evidence.txt', $attachment->filename);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($cursorBefore, $folder->fresh()->live_start_uid);
        $this->assertSame($ticketCount, DB::table('tickets')->count());
        $this->assertDatabaseCount('email_remote_operations', 0);
        $this->assertDatabaseCount('email_sent_reconciliations', 0);
        $this->assertDatabaseCount('email_smart_inbox_suggestions', 0);
        $this->assertSame(0, $provider->providerWriteCalls);
        $this->assertSame([['INBOX', 5]], $provider->exactFetchCalls);
        $this->assertSame(3, $provider->connectCalls);
        $this->assertSame(3, $provider->disconnectCalls);

        $baseline = EmailMessageUserState::query()
            ->where('email_message_id', $message->id)
            ->where('user_id', $viewer->id)
            ->firstOrFail();
        $this->assertFalse($baseline->is_unread);
        $this->assertNull($baseline->marked_read_at);
        $this->assertNull($baseline->marked_unread_at);

        $workspace = Livewire::actingAs($viewer)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox');
        $renderedPlacement = collect($workspace->viewData('placements')->items())
            ->firstWhere('id', $placement->id);
        $this->assertNotNull($renderedPlacement);
        $this->assertSame(0, (int) $renderedPlacement->getAttribute('mail_conversation_unread_for_me_count'));
        $workspace->assertDontSeeHtml('data-mail-placement-unread-for-me="'.$placement->id.'"');

        // Redelivery remains terminal and cannot overwrite personal state.
        $baseline->forceFill(['is_unread' => true, 'marked_unread_at' => now()])->save();
        app()->call([new ImportHistoricalEmailMessages($run->id), 'handle']);
        $this->assertTrue($baseline->fresh()->is_unread);
    }

    #[Test]
    public function queued_import_never_reports_imported_when_required_raw_storage_is_incomplete(): void
    {
        Storage::fake('local');
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 733, 100);
        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(733, 101)],
            ['INBOX' => [6]],
            ['INBOX:6' => [
                'mailbox' => 'INBOX',
                'imap_uid' => 6,
                'message_id' => '<historical-incomplete-6@example.test>',
                'subject' => 'Historical storage evidence',
                'from_email' => 'sender@example.test',
                'headers' => [],
                'received_at' => now()->subDays(2),
                'size_bytes' => 1024,
            ]],
        );
        $provider->exactMessages['INBOX:6'] = new class
        {
            public function getHTMLBody(): ?string
            {
                return null;
            }

            public function getTextBody(): string
            {
                return 'Historical exact body without a reparsable RFC 822 snapshot.';
            }

            public function getAttachments(): array
            {
                return [];
            }
        };

        $run = app(PreviewEmailHistoricalImport::class)->handle(
            $account,
            $operator,
            $this->scope([$folder], cap: 10),
        );
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_QUEUED,
            'queued_at' => now(),
        ])->save();

        app()->call([new ImportHistoricalEmailMessages($run->id), 'handle']);

        $run->refresh();
        $item = EmailHistoricalImportItem::query()
            ->where('email_historical_import_run_id', $run->id)
            ->sole();
        $message = EmailMessage::query()
            ->where('account_id', $account->id)
            ->where('imap_uid_validity', 733)
            ->where('imap_uid', 6)
            ->firstOrFail();

        $this->assertSame(EmailHistoricalImportRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(0, $run->imported_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(EmailHistoricalImportItem::STATUS_FAILED, $item->status);
        $this->assertSame('HISTORICAL_IMPORT_STORAGE_INCOMPLETE', $item->error_code);
        $this->assertNull($message->raw_path);
        $this->assertDatabaseHas('email_mailbox_placements', [
            'email_message_id' => $message->id,
            'email_folder_id' => $folder->id,
            'imap_uid' => 6,
        ]);
        $this->assertSame(0, $provider->providerWriteCalls);
    }

    #[Test]
    public function queued_import_reauthorizes_at_each_batch_boundary(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 735, 100);
        $uids = range(1, 51);
        $payloads = [];
        foreach ($uids as $uid) {
            $payloads['INBOX:'.$uid] = [
                'mailbox' => 'INBOX',
                'imap_uid' => $uid,
                'message_id' => "<batch-{$uid}@example.test>",
                'subject' => "Historical batch {$uid}",
                'headers' => [],
                'received_at' => now()->subDay(),
                'size_bytes' => 30 * 1024 * 1024,
            ];
        }
        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(735, 101)],
            ['INBOX' => $uids],
            $payloads,
        );
        $provider->disableUserIdAfterPayloads = $operator->id;
        $provider->disableAfterPayloadCount = 50;
        $run = app(PreviewEmailHistoricalImport::class)->handle($account, $operator, $this->scope([$folder], cap: 100));
        $run->forceFill(['status' => EmailHistoricalImportRun::STATUS_QUEUED, 'queued_at' => now()])->save();

        app()->call([new ImportHistoricalEmailMessages($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(EmailHistoricalImportRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('HISTORICAL_IMPORT_AUTHORIZATION_REVOKED', $run->error_code);
        $this->assertSame(50, $run->imported_count);
        $this->assertSame(1, $run->pending_count);
    }

    #[Test]
    public function queued_import_rechecks_the_installation_cap_at_each_batch_boundary(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 737, 100);
        $uids = range(1, 51);
        $payloads = [];
        foreach ($uids as $uid) {
            $payloads['INBOX:'.$uid] = [
                'mailbox' => 'INBOX',
                'imap_uid' => $uid,
                'message_id' => "<policy-batch-{$uid}@example.test>",
                'subject' => "Historical policy batch {$uid}",
                'headers' => [],
                'received_at' => now()->subDay(),
                'size_bytes' => 30 * 1024 * 1024,
            ];
        }
        $provider = $this->fakeProvider(
            $account,
            ['INBOX' => $this->state(737, 101)],
            ['INBOX' => $uids],
            $payloads,
        );
        $provider->lowerHistoricalCapAfterPayloads = 50;
        $provider->historicalCapAfterPayloads = 49;
        $run = app(PreviewEmailHistoricalImport::class)->handle(
            $account,
            $operator,
            $this->scope([$folder], cap: 100),
        );
        $run->forceFill(['status' => EmailHistoricalImportRun::STATUS_QUEUED, 'queued_at' => now()])->save();

        app()->call([new ImportHistoricalEmailMessages($run->id), 'handle']);

        $run->refresh();
        $this->assertSame(EmailHistoricalImportRun::STATUS_STALE, $run->status);
        $this->assertSame('HISTORICAL_IMPORT_POLICY_CAP_CHANGED', $run->error_code);
        $this->assertSame(50, $run->imported_count);
        $this->assertSame(1, $run->pending_count);
        $this->assertSame(50, $provider->payloadReadCount);
    }

    #[Test]
    public function any_current_authorized_operator_can_cancel_a_run_from_a_disabled_requester(): void
    {
        $requester = $this->operator();
        $incidentOperator = $this->operator();
        $account = $this->account();
        $run = EmailHistoricalImportRun::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'requested_by' => $requester->id,
            'status' => EmailHistoricalImportRun::STATUS_RUNNING,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'uid_from' => 1,
            'requested_cap' => 1,
            'effective_cap' => 1,
            'folder_scope_json' => [],
            'provider_snapshot_json' => [],
            'preview_fingerprint' => str_repeat('c', 64),
            'idempotency_key' => str_repeat('d', 64),
            'preview_expires_at' => now()->addMinutes(15),
        ]);
        $requester->forceFill(['status' => User::STATUS_DISABLED])->save();

        $cancelled = app(CancelEmailHistoricalImport::class)->handle(
            $account,
            $run,
            $incidentOperator,
        );

        $this->assertSame(EmailHistoricalImportRun::STATUS_CANCELLING, $cancelled->status);
        $this->assertSame($requester->id, $cancelled->requested_by);
        $this->assertSame($incidentOperator->id, $cancelled->cancelled_by);
    }

    #[Test]
    public function changed_uidvalidity_rebaseline_creates_a_new_namespace_and_retires_old_placements_without_provider_writes(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 741, 20);
        $folder->forceFill([
            'sync_status' => EmailFolder::SYNC_ERROR,
            'sync_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
            'sync_error_message' => 'Provider identity changed.',
        ])->save();
        $message = $this->message($account, $folder, 19);
        $oldNamespaceId = $folder->active_uid_namespace_id;
        $placement = $this->placement($message, $folder, $oldNamespaceId, 19);
        $provider = $this->fakeProvider($account, ['INBOX' => $this->state(742, 501)], ['INBOX' => []]);

        $preview = app(PreviewEmailCursorRebaseline::class)->handle(
            $account,
            $folder,
            $operator,
            'Provider UIDVALIDITY reset confirmed by mailbox maintenance.',
        );
        $this->assertSame(EmailCursorRebaselineRun::STATUS_PREVIEWED, $preview->status);
        $messageCount = EmailMessage::query()->count();
        $stateCount = EmailMessageUserState::query()->count();

        $completed = app(ApplyEmailCursorRebaseline::class)->handle(
            $account,
            $folder,
            $preview,
            $operator,
            $preview->preview_fingerprint,
            $this->confirmation($preview),
        );

        $folder->refresh();
        $this->assertSame(EmailCursorRebaselineRun::STATUS_COMPLETED, $completed->status);
        $this->assertNotSame($oldNamespaceId, $folder->active_uid_namespace_id);
        $this->assertSame(EmailFolderUidNamespace::STATUS_SUPERSEDED, EmailFolderUidNamespace::findOrFail($oldNamespaceId)->status);
        $this->assertSame(742, $folder->uid_validity);
        $this->assertSame(500, $folder->live_start_uid);
        $this->assertSame(742, $account->fresh()->imap_uid_validity);
        $this->assertSame(500, $account->fresh()->imap_live_start_uid);
        $this->assertSame($oldNamespaceId, $placement->fresh()->uid_namespace_id);
        $this->assertSame(EmailMailboxPlacement::LOCAL_HIDDEN, $placement->fresh()->local_state);
        $this->assertSame('IMAP_UID_NAMESPACE_SUPERSEDED', $placement->fresh()->sync_error_code);
        $this->assertSame($messageCount, EmailMessage::query()->count());
        $this->assertSame($stateCount, EmailMessageUserState::query()->count());
        $this->assertSame(0, $provider->providerWriteCalls);

        $again = app(ApplyEmailCursorRebaseline::class)->handle(
            $account->fresh(),
            $folder->fresh(),
            $completed,
            $operator,
            $preview->preview_fingerprint,
            $this->confirmation($preview),
        );
        $this->assertSame($completed->new_uid_namespace_id, $again->new_uid_namespace_id);
        $this->assertSame(2, EmailFolderUidNamespace::query()->where('email_folder_id', $folder->id)->count());
    }

    #[Test]
    public function documented_same_uidvalidity_recovery_reuses_namespace_and_only_resets_live_highwater(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 751, 20);
        $folder->forceFill([
            'sync_status' => EmailFolder::SYNC_ERROR,
            'sync_error_code' => 'IMAP_FOLDER_STATE',
            'sync_error_message' => 'Folder cursor state needs recovery.',
        ])->save();
        $message = $this->message($account, $folder, 19);
        $placement = $this->placement($message, $folder, $folder->active_uid_namespace_id, 19);
        $provider = $this->fakeProvider($account, ['INBOX' => $this->state(751, 81)], ['INBOX' => []]);
        $namespaceId = $folder->active_uid_namespace_id;

        $preview = app(PreviewEmailCursorRebaseline::class)->handle(
            $account,
            $folder,
            $operator,
            'Recover the documented folder cursor state without changing UID identity.',
        );
        $completed = app(ApplyEmailCursorRebaseline::class)->handle(
            $account,
            $folder,
            $preview,
            $operator,
            $preview->preview_fingerprint,
            $this->confirmation($preview),
        );

        $this->assertSame(EmailCursorRebaselineRun::STATUS_COMPLETED, $completed->status);
        $this->assertSame($namespaceId, $completed->new_uid_namespace_id);
        $this->assertSame($namespaceId, $folder->fresh()->active_uid_namespace_id);
        $this->assertSame(80, $folder->fresh()->live_start_uid);
        $this->assertSame(EmailFolderUidNamespace::STATUS_ACTIVE, EmailFolderUidNamespace::findOrFail($namespaceId)->status);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $placement->fresh()->local_state);
        $this->assertSame(1, EmailFolderUidNamespace::query()->where('email_folder_id', $folder->id)->count());
        $this->assertSame(0, $provider->providerWriteCalls);
    }

    #[Test]
    public function rebaseline_becomes_stale_when_old_namespace_placement_count_changes_after_preview(): void
    {
        $operator = $this->operator();
        $account = $this->account();
        $folder = $this->folder($account, 756, 20);
        $folder->forceFill([
            'sync_status' => EmailFolder::SYNC_ERROR,
            'sync_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
            'sync_error_message' => 'Provider identity changed.',
        ])->save();
        $firstMessage = $this->message($account, $folder, 19);
        $firstPlacement = $this->placement($firstMessage, $folder, $folder->active_uid_namespace_id, 19);
        $this->fakeProvider($account, ['INBOX' => $this->state(757, 101)], ['INBOX' => []]);

        $preview = app(PreviewEmailCursorRebaseline::class)->handle(
            $account,
            $folder,
            $operator,
            'Preview the changed namespace before another projection appears.',
        );
        $this->assertSame(1, $preview->old_placement_count);
        $lateMessage = $this->message($account, $folder, 18);
        $latePlacement = $this->placement($lateMessage, $folder, $folder->active_uid_namespace_id, 18);

        try {
            app(ApplyEmailCursorRebaseline::class)->handle(
                $account,
                $folder,
                $preview,
                $operator,
                $preview->preview_fingerprint,
                $this->confirmation($preview),
            );
            $this->fail('A placement projected after preview must make re-baseline stale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rebaseline', $exception->errors());
        }

        $this->assertSame(EmailCursorRebaselineRun::STATUS_STALE, $preview->fresh()->status);
        $this->assertSame('CURSOR_REBASELINE_LOCAL_SNAPSHOT_CHANGED', $preview->fresh()->error_code);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $firstPlacement->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $latePlacement->fresh()->local_state);
        $this->assertSame(756, $folder->fresh()->uid_validity);
        $this->assertSame(1, EmailFolderUidNamespace::query()->where('email_folder_id', $folder->id)->count());
    }

    #[Test]
    public function shared_provider_lock_blocks_remote_operation_execution_and_import_has_bounded_overlap_retries(): void
    {
        $operator = $this->user(['email.inbox_view', 'email.inbox_manage']);
        $account = $this->account();
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $operator->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $folder = $this->folder($account, 761, 20);
        $message = $this->message($account, $folder, 19);
        $placement = $this->placement($message, $folder, $folder->active_uid_namespace_id, 19);
        $operation = EmailRemoteOperation::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'email_folder_id' => $folder->id,
            'email_mailbox_placement_id' => $placement->id,
            'requested_by' => $operator->id,
            'provider' => 'imap',
            'operation_type' => 'mark_seen',
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'idempotency_key' => 'lock-race-operation',
            'source_folder_path' => $folder->path,
        ]);
        $provider = $this->fakeProvider($account, ['INBOX' => $this->state(761, 21)], ['INBOX' => []]);
        $lock = EmailAccountProviderLock::acquire($account->id, 60);
        $this->assertNotNull($lock);

        try {
            $result = app(RunEmailRemoteOperation::class)->handle($operation, 'test', $operator);
            $this->assertSame(EmailRemoteOperation::STATUS_PENDING, $result->status);
            $this->assertSame(0, $provider->connectCalls);
        } finally {
            $lock?->release();
        }

        $run = EmailHistoricalImportRun::query()->create([
            'account_id' => $account->id,
            'provider_binding_version' => 1,
            'requested_by' => $operator->id,
            'status' => EmailHistoricalImportRun::STATUS_QUEUED,
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-01',
            'uid_from' => 1,
            'requested_cap' => 1,
            'effective_cap' => 1,
            'folder_scope_json' => [],
            'provider_snapshot_json' => [],
            'preview_fingerprint' => str_repeat('a', 64),
            'idempotency_key' => str_repeat('b', 64),
            'preview_expires_at' => now()->addMinutes(15),
        ]);
        $job = new ImportHistoricalEmailMessages($run->id);
        $middleware = $job->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertTrue($middleware->shareKey);
        $this->assertSame(EmailAccountProviderLock::RELEASE_AFTER_SECONDS, $middleware->releaseAfter);
        $this->assertSame('email', $job->queue);
        $this->assertGreaterThan(3, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertTrue($job->retryUntil()->isFuture());
    }

    private function operator(): User
    {
        return $this->user(['email.account_manage', 'email.mailbox_sync_manage']);
    }

    /** @param array<int, string> $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function account(string $address = 'maintenance@example.test'): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mailbox maintenance fixture',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'provider_binding_version' => 1,
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => Crypt::encryptString('secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => $address,
            'smtp_secret' => Crypt::encryptString('secret'),
            'smtp_auth_type' => 'password',
        ]);
    }

    private function folder(
        EmailAccount $account,
        int $uidValidity,
        int $liveStartUid,
        string $path = 'INBOX',
    ): EmailFolder {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => $path,
            'name' => $path,
            'role' => $path === 'INBOX' ? EmailFolder::ROLE_INBOX : EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'uid_next' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
        ]);
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'uid_next_at_establishment' => $liveStartUid + 1,
            'live_start_uid' => $liveStartUid,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_fixture',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        if ($folder->isInbox()) {
            $account->forceFill([
                'imap_uid_validity' => $uidValidity,
                'imap_live_start_uid' => $liveStartUid,
                'imap_live_cursor_initialized_at' => now(),
            ])->save();
        }

        return $folder->refresh();
    }

    private function message(EmailAccount $account, EmailFolder $folder, int $uid): EmailMessage
    {
        return EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'message_id' => "<message-{$uid}@example.test>",
            'subject' => 'Stored provider evidence',
            'received_at' => now(),
        ]);
    }

    private function placement(EmailMessage $message, EmailFolder $folder, int $namespaceId, int $uid): EmailMailboxPlacement
    {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $message->account_id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespaceId,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
    }

    /** @param array<int, EmailFolder> $folders */
    private function scope(array $folders, int $cap = 100, int $uidFrom = 1, ?int $uidTo = null): array
    {
        return [
            'folder_ids' => array_map(fn (EmailFolder $folder): int => $folder->id, $folders),
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-02',
            'uid_from' => $uidFrom,
            'uid_to' => $uidTo,
            'cap' => $cap,
        ];
    }

    /** @return array<string, int|null> */
    private function state(int $uidValidity, int $nextUid): array
    {
        return [
            'uid_validity' => $uidValidity,
            'next_uid' => $nextUid,
            'highest_modseq' => 10,
            'exists_count' => 10,
            'unseen_count' => 2,
        ];
    }

    private function fakeProvider(
        EmailAccount $account,
        array $states,
        array $uids,
        array $payloads = [],
    ): HistoricalMaintenanceFakeImapClient {
        $this->provider = new HistoricalMaintenanceFakeImapClient($account, $states, $uids, $payloads);
        $provider = $this->provider;
        $this->app->bind(ImapClient::class, fn () => $provider);

        return $provider;
    }

    private function confirmation(EmailCursorRebaselineRun $run): array
    {
        return [
            'old_uid_validity' => (int) $run->old_uid_validity,
            'observed_uid_validity' => (int) $run->observed_uid_validity,
            'observed_uid_next' => (int) $run->observed_uid_next,
        ];
    }

    private function historicalRawMessage(): string
    {
        return implode("\r\n", [
            'From: Sender <sender@example.test>',
            'To: Maintenance <maintenance@example.test>',
            'Subject: Historical namespace message',
            'Message-ID: <historical-5@example.test>',
            'Date: Fri, 14 Aug 2026 09:00:00 +0000',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="historical-boundary"',
            '',
            '--historical-boundary',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Historical exact body.',
            '--historical-boundary',
            'Content-Type: text/plain; name="evidence.txt"',
            'Content-Disposition: attachment; filename="evidence.txt"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('historical attachment evidence'),
            '--historical-boundary--',
            '',
        ]);
    }
}

class HistoricalMaintenanceFakeImapClient extends ImapClient
{
    public int $connectCalls = 0;

    public int $providerWriteCalls = 0;

    public int $disconnectCalls = 0;

    /** @var array<string, Message> */
    public array $exactMessages = [];

    /** @var array<int, array{0: string, 1: int}> */
    public array $exactFetchCalls = [];

    public ?int $disableUserIdAfterPayloads = null;

    public int $disableAfterPayloadCount = 0;

    public ?int $disableFolderIdAfterStateReads = null;

    public int $disableAfterStateReadCount = 0;

    public int $payloadReadCount = 0;

    public int $lowerHistoricalCapAfterPayloads = 0;

    public int $historicalCapAfterPayloads = 0;

    private int $stateReadCount = 0;

    /** @var array<int, string> */
    public array $searchFolders = [];

    public function __construct(
        EmailAccount $account,
        private readonly array $states,
        private readonly array $uids,
        private readonly array $payloads,
    ) {
        parent::__construct($account);
    }

    public function connect(): void
    {
        $this->connectCalls++;
    }

    public function disconnect(): void
    {
        $this->disconnectCalls++;
    }

    public function folderState(string $folderPath): array
    {
        $this->stateReadCount++;
        if ($this->disableFolderIdAfterStateReads
            && $this->stateReadCount === $this->disableAfterStateReadCount) {
            EmailFolder::query()->whereKey($this->disableFolderIdAfterStateReads)->update([
                'sync_enabled' => false,
                'updated_at' => now(),
            ]);
        }

        return $this->states[$folderPath];
    }

    public function searchHistoricalUidsInFolder(
        string $folderPath,
        CarbonInterface $dateFrom,
        CarbonInterface $dateTo,
        int $uidFrom,
        int $uidTo,
        int $limit,
    ): array {
        $this->searchFolders[] = $folderPath;

        return collect($this->uids[$folderPath] ?? [])
            ->map(fn (mixed $uid): int => (int) $uid)
            ->filter(fn (int $uid): bool => $uid >= $uidFrom && $uid <= $uidTo)
            ->sort()
            ->take($limit)
            ->values()
            ->all();
    }

    public function payloadByUid(int $uid, string $folderPath = 'INBOX'): ?array
    {
        $this->payloadReadCount++;
        if ($this->disableUserIdAfterPayloads
            && $this->payloadReadCount === $this->disableAfterPayloadCount) {
            User::query()->whereKey($this->disableUserIdAfterPayloads)->update([
                'status' => User::STATUS_DISABLED,
                'updated_at' => now(),
            ]);
        }
        if ($this->lowerHistoricalCapAfterPayloads > 0
            && $this->payloadReadCount === $this->lowerHistoricalCapAfterPayloads) {
            CommonSetting::query()->updateOrCreate(
                ['type' => 'emailhub', 'name' => 'historical_import_max_messages'],
                ['value' => (string) $this->historicalCapAfterPayloads],
            );
        }

        return $this->payloads[$folderPath.':'.$uid] ?? null;
    }

    public function fetchByUid(int $uid, string $folderPath = 'INBOX')
    {
        $this->exactFetchCalls[] = [$folderPath, $uid];

        return $this->exactMessages[$folderPath.':'.$uid] ?? null;
    }

    public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
    {
        $this->providerWriteCalls++;

        return true;
    }
}
