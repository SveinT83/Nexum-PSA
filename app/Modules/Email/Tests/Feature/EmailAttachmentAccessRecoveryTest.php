<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\RecoverEmailMessageAttachments;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\StoreInboundMessage;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailAttachmentRecoveryReadiness;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Webklex\PHPIMAP\Message as ProviderMessage;

class EmailAttachmentAccessRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 88000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $role = Role::create(['name' => 'Mail attachment access tech']);
        $role->givePermissionTo($view);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole($role);
    }

    #[Test]
    public function authorized_active_placements_download_private_attachments_across_mail_folders_and_ticket_links(): void
    {
        $account = $this->account('attachment-download@example.test');
        $ticket = Ticket::factory()->create();

        foreach ([
            ['INBOX', EmailFolder::ROLE_INBOX, null],
            ['Sent', EmailFolder::ROLE_SENT, null],
            ['Archive', EmailFolder::ROLE_ARCHIVE, $ticket->id],
        ] as $index => [$path, $role, $ticketId]) {
            $folder = $this->folder($account, $path, $role, 910 + $index);
            [$message, $placement] = $this->placedMessage($account, $folder, ticketId: $ticketId);
            $attachment = $this->attachment($message, "download-{$index}.pdf");

            $response = $this->actingAs($this->actor)->get(route('tech.mail.attachments.download', [
                'placement' => $placement,
                'attachment' => $attachment,
            ]));

            $response->assertOk()->assertDownload("download-{$index}.pdf");
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        }
    }

    #[Test]
    public function attachment_download_fails_closed_for_revoked_hidden_mismatched_or_unsafe_resources(): void
    {
        $account = $this->account('attachment-deny@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 920);
        [$message, $placement] = $this->placedMessage($account, $folder);
        $attachment = $this->attachment($message, 'allowed.pdf');
        $url = fn (EmailMailboxPlacement $candidate, EmailAttachment $file): string => route(
            'tech.mail.attachments.download',
            ['placement' => $candidate, 'attachment' => $file],
        );

        $placement->forceFill(['local_state' => EmailMailboxPlacement::LOCAL_HIDDEN])->save();
        $this->actingAs($this->actor)->get($url($placement, $attachment))->assertNotFound();
        $placement->forceFill(['local_state' => EmailMailboxPlacement::LOCAL_ACTIVE])->save();

        $placement->forceFill(['provider_missing_at' => now()])->save();
        $this->actingAs($this->actor)->get($url($placement, $attachment))->assertNotFound();
        $placement->forceFill(['provider_missing_at' => null])->save();

        EmailAccountUserGrant::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $this->actor->id)
            ->delete();
        $this->actingAs($this->actor)->get($url($placement, $attachment))->assertNotFound();
        $this->grant($account, $this->actor);

        $account->forceFill(['is_active' => false])->save();
        $this->actingAs($this->actor)->get($url($placement, $attachment))->assertNotFound();
        $account->forceFill(['is_active' => true])->save();

        [$otherMessage] = $this->placedMessage($account, $folder);
        $otherAttachment = $this->attachment($otherMessage, 'other.pdf');
        $this->actingAs($this->actor)->get($url($placement, $otherAttachment))->assertNotFound();

        Storage::disk('local')->put('email/raw/not-an-attachment.pdf', 'private data');
        $unsafe = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => "unsafe\r\nname.pdf",
            'content_type' => 'application/pdf',
            'size_bytes' => 12,
            'disk' => 'local',
            'path' => 'email/raw/not-an-attachment.pdf',
            'checksum_sha1' => sha1('private data'),
        ]);
        $this->actingAs($this->actor)->get($url($placement, $unsafe))->assertNotFound();

        $escape = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'escape.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 12,
            'disk' => 'local',
            'path' => 'email/attachments/../raw/not-an-attachment.pdf',
            'checksum_sha1' => sha1('private data'),
        ]);
        $this->actingAs($this->actor)->get($url($placement, $escape))->assertNotFound();

        $foreignDisk = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'foreign-disk.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => $attachment->size_bytes,
            'disk' => 's3',
            'path' => $attachment->path,
            'checksum_sha1' => $attachment->checksum_sha1,
        ]);
        $this->actingAs($this->actor)->get($url($placement, $foreignDisk))->assertNotFound();

        $missing = EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'missing.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1,
            'disk' => 'local',
            'path' => 'email/attachments/missing.pdf',
            'checksum_sha1' => sha1('missing'),
        ]);
        $this->actingAs($this->actor)->get($url($placement, $missing))->assertNotFound();

        $otherAccount = $this->account('attachment-cross-account@example.test');
        $otherFolder = $this->folder($otherAccount, 'INBOX', EmailFolder::ROLE_INBOX, 921);
        $crossAccountPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $otherAccount->id,
            'email_folder_id' => $otherFolder->id,
            'folder_path' => $otherFolder->path,
            'imap_uid_validity' => $otherFolder->uid_validity,
            'imap_uid' => ++$this->nextUid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
        ]);
        $this->actingAs($this->actor)->get($url($crossAccountPlacement, $attachment))->assertNotFound();

        $noView = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $noView->assignRole(Role::create(['name' => 'Mail attachment no view role']));
        $this->actingAs($noView)->get($url($placement, $attachment))->assertForbidden();
    }

    #[Test]
    public function selected_thread_renders_only_its_exact_placement_bound_attachment_link(): void
    {
        $account = $this->account('attachment-reader@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 930);
        [$selectedMessage, $selectedPlacement] = $this->placedMessage($account, $folder, subject: 'Selected attachment mail');
        [$otherMessage, $otherPlacement] = $this->placedMessage($account, $folder, subject: 'Other attachment mail');
        $selected = $this->attachment($selectedMessage, 'selected.pdf');
        $other = $this->attachment($otherMessage, 'other.pdf');
        $selectedMessage->forceFill(['attachments_count' => 1])->save();
        $otherMessage->forceFill(['attachments_count' => 1])->save();

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $selectedPlacement->id);

        $component->assertSeeHtml('href="'.route('tech.mail.attachments.download', [
            'placement' => $selectedPlacement,
            'attachment' => $selected,
        ]).'"');
        $component->assertDontSeeHtml('href="'.route('tech.mail.attachments.download', [
            'placement' => $otherPlacement,
            'attachment' => $other,
        ]).'"');
    }

    #[Test]
    public function legacy_body_only_snapshot_recovery_is_idempotent_and_repairs_the_counter(): void
    {
        $account = $this->account('attachment-local-recovery@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 940);
        $raw = $this->mimeMessage([['invoice.pdf', 'application/pdf', '%PDF-local']]);
        [$headers, $body] = $this->splitMessage($raw);
        [$message] = $this->placedMessage($account, $folder, attributes: [
            'headers_json' => $this->mimeHeaders($headers),
            'raw_path' => 'email/raw/'.$account->id.'/INBOX/local.eml',
            'attachments_count' => 7,
        ]);
        Storage::disk('local')->put($message->raw_path, $body);

        $providerWasNotUsed = new class($account) extends ImapClient
        {
            public function connect(): void
            {
                throw new \RuntimeException('Provider must not be used when the local snapshot is reparsable.');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $providerWasNotUsed);

        $first = app(RecoverEmailMessageAttachments::class)->handle($message, true);
        $second = app(RecoverEmailMessageAttachments::class)->handle($message->fresh(), true);

        $this->assertSame('recovered', $first['status']);
        $this->assertSame('local_snapshot_complete', $first['reason_code']);
        $this->assertSame(1, $first['after_count']);
        $this->assertSame('unchanged', $second['status']);
        $this->assertSame(1, EmailAttachment::query()->where('message_id', $message->id)->count());
        $this->assertSame(1, $message->fresh()->attachments_count);
        Storage::disk('local')->assertExists(EmailAttachment::query()->where('message_id', $message->id)->sole()->path);
    }

    #[Test]
    public function exact_legacy_attachment_directory_recovers_counter_only_evidence_without_provider_search(): void
    {
        $account = $this->account('attachment-legacy-directory@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 945);
        [$message] = $this->placedMessage($account, $folder, attributes: [
            'headers_json' => [],
            'raw_path' => 'email/raw/'.$account->id.'/INBOX/legacy-directory.eml',
            'attachments_count' => 2,
        ]);
        Storage::disk('local')->put($message->raw_path, 'legacy body without a reconstructable MIME header');

        $legacyDirectory = 'email/attachments/'.$account->id.'/'.$message->imap_uid;
        Storage::disk('local')->put($legacyDirectory.'/6f35eff7', 'Recovered plain attachment');
        Storage::disk('local')->put($legacyDirectory.'/ad08a68c', implode("\r\n", [
            'From: sender@example.test',
            'To: recipient@example.test',
            'Subject: Attached message',
            '',
            'Recovered attached message body.',
        ]));

        $providerWasNotUsed = new class($account) extends ImapClient
        {
            public function connect(): void
            {
                throw new \RuntimeException('Exact legacy attachment evidence must not trigger a provider search.');
            }
        };
        $this->app->bind(ImapClient::class, fn () => $providerWasNotUsed);

        $first = app(RecoverEmailMessageAttachments::class)->handle($message, true);
        $second = app(RecoverEmailMessageAttachments::class)->handle($message->fresh(), true);

        $this->assertSame('recovered', $first['status']);
        $this->assertSame('legacy_attachment_directory_complete', $first['reason_code']);
        $this->assertSame(2, $first['source_count']);
        $this->assertSame(2, $first['processed_count']);
        $this->assertSame(2, $first['after_count']);
        $this->assertSame(2, $message->fresh()->attachments_count);
        $this->assertSame('unchanged', $second['status']);
        $this->assertSame('existing_rows_complete', $second['reason_code']);
        $this->assertSame(
            ['6f35eff7', 'ad08a68c'],
            $message->attachments()->orderBy('filename')->pluck('filename')->all(),
        );
        Storage::disk('local')->assertExists($legacyDirectory.'/6f35eff7');
        Storage::disk('local')->assertExists($legacyDirectory.'/ad08a68c');
    }

    #[Test]
    public function recovery_reports_partial_policy_results_and_counts_only_persisted_rows(): void
    {
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'attachment_allowed_mime_types'],
            ['value' => 'application/pdf'],
        );

        $account = $this->account('attachment-partial@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 950);
        $raw = $this->mimeMessage([
            ['accepted.pdf', 'application/pdf', '%PDF-accepted'],
            ['rejected.png', 'image/png', 'PNG-rejected'],
        ]);
        [$headers, $body] = $this->splitMessage($raw);
        [$message] = $this->placedMessage($account, $folder, attributes: [
            'headers_json' => $this->mimeHeaders($headers),
            'raw_path' => 'email/raw/'.$account->id.'/INBOX/partial.eml',
            'attachments_count' => 4,
        ]);
        Storage::disk('local')->put($message->raw_path, $body);

        $result = app(RecoverEmailMessageAttachments::class)->handle($message);

        $this->assertSame('partial', $result['status']);
        $this->assertSame('local_snapshot_partial', $result['reason_code']);
        $this->assertSame(2, $result['source_count']);
        $this->assertSame(1, $result['processed_count']);
        $this->assertSame(1, $message->fresh()->attachments_count);
        $this->assertSame(['accepted.pdf'], $message->attachments()->pluck('filename')->all());
    }

    #[Test]
    public function unavailable_local_mime_preserves_nonzero_evidence_until_provider_proves_empty(): void
    {
        $account = $this->account('attachment-counter-evidence@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 955);
        [$message, $placement] = $this->placedMessage($account, $folder, attributes: [
            'headers_json' => [],
            'raw_path' => 'email/raw/'.$account->id.'/INBOX/unreconstructable.eml',
            'attachments_count' => 2,
        ]);
        Storage::disk('local')->put($message->raw_path, 'legacy body without its top-level MIME header');

        $unproven = app(RecoverEmailMessageAttachments::class)->handle($message);

        $this->assertSame('failed', $unproven['status']);
        $this->assertSame('provider_fallback_disabled', $unproven['reason_code']);
        $this->assertSame(2, $unproven['counter_before']);
        $this->assertSame(2, $unproven['counter_after']);
        $this->assertSame(2, $message->fresh()->attachments_count);

        $providerMessage = ProviderMessage::fromString(implode("\r\n", [
            'From: sender@example.test',
            'Message-ID: '.$message->message_id,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Provider proves this message has no attachments.',
        ]))->setUid($placement->imap_uid);
        $client = new class($account, $providerMessage, $folder->uid_validity) extends ImapClient
        {
            public function __construct(
                EmailAccount $account,
                private readonly ProviderMessage $providerMessage,
                private readonly int $uidValidity,
            ) {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $this->uidValidity, 'next_uid' => 99999];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                return $this->providerMessage;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $proven = app(RecoverEmailMessageAttachments::class)->handle($message->fresh(), true);

        $this->assertSame('unchanged', $proven['status']);
        $this->assertSame('provider_snapshot_empty', $proven['reason_code']);
        $this->assertSame(2, $proven['counter_before']);
        $this->assertSame(0, $proven['counter_after']);
        $this->assertSame(0, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);
    }

    #[Test]
    public function provider_fallback_reads_only_the_exact_active_uid_namespace_and_disconnects(): void
    {
        $account = $this->account('attachment-provider-recovery@example.test');
        $folder = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 960);
        [$message, $placement] = $this->placedMessage($account, $folder);
        $providerMessage = ProviderMessage::fromString($this->mimeMessage([
            ['provider.pdf', 'application/pdf', '%PDF-provider'],
        ], $message->message_id))->setUid($placement->imap_uid);

        $client = new class($account, $providerMessage, $folder->uid_validity) extends ImapClient
        {
            public bool $connected = false;

            public bool $disconnected = false;

            public ?array $fetch = null;

            public function __construct(
                EmailAccount $account,
                private readonly ProviderMessage $providerMessage,
                private readonly int $uidValidity,
            ) {
                parent::__construct($account);
            }

            public function connect(): void
            {
                $this->connected = true;
            }

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $this->uidValidity, 'next_uid' => 99999];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                $this->fetch = ['uid' => $uid, 'folder_path' => $folderPath];

                return $this->providerMessage;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(RecoverEmailMessageAttachments::class)->handle($message, true);

        $this->assertSame('recovered', $result['status']);
        $this->assertSame('provider_fallback_complete', $result['reason_code']);
        $this->assertSame([
            'uid' => $placement->imap_uid,
            'folder_path' => $folder->path,
        ], $client->fetch);
        $this->assertTrue($client->connected);
        $this->assertTrue($client->disconnected);
        $this->assertSame(1, $message->fresh()->attachments_count);
    }

    #[Test]
    public function provider_fallback_refuses_changed_uidvalidity_before_fetching_message_content(): void
    {
        $account = $this->account('attachment-provider-mismatch@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 970);
        [$message] = $this->placedMessage($account, $folder, attributes: ['attachments_count' => 9]);

        $client = new class($account, $folder->uid_validity + 1) extends ImapClient
        {
            public bool $fetched = false;

            public bool $disconnected = false;

            public function __construct(EmailAccount $account, private readonly int $uidValidity)
            {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $this->uidValidity, 'next_uid' => 99999];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX')
            {
                $this->fetched = true;

                return null;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(RecoverEmailMessageAttachments::class)->handle($message, true);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('provider_uidvalidity_mismatch', $result['reason_code']);
        $this->assertFalse($client->fetched);
        $this->assertTrue($client->disconnected);
        $this->assertSame(9, $message->fresh()->attachments_count);
    }

    #[Test]
    public function provider_fallback_rejects_message_id_mismatch_before_persisting_attachments(): void
    {
        $account = $this->account('attachment-provider-message-id@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 972);
        [$message, $placement] = $this->placedMessage($account, $folder, attributes: ['attachments_count' => 2]);
        $providerMessage = ProviderMessage::fromString($this->mimeMessage([
            ['wrong-message.pdf', 'application/pdf', '%PDF-wrong-message'],
        ], '<different-provider-message@example.test>'))->setUid($placement->imap_uid);

        $client = new class($account, $providerMessage, $folder->uid_validity) extends ImapClient
        {
            public function __construct(
                EmailAccount $account,
                private readonly ProviderMessage $providerMessage,
                private readonly int $uidValidity,
            ) {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $this->uidValidity, 'next_uid' => 99999];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                return $this->providerMessage;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(RecoverEmailMessageAttachments::class)->handle($message, true);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('provider_message_id_mismatch', $result['reason_code']);
        $this->assertSame(2, $result['counter_after']);
        $this->assertSame(2, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);

        $providerWithoutMessageId = ProviderMessage::fromString(implode("\r\n", [
            'From: sender@example.test',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Message-ID evidence is missing.',
        ]))->setUid($placement->imap_uid);
        $missingEvidenceClient = new class($account, $providerWithoutMessageId, $folder->uid_validity) extends ImapClient
        {
            public function __construct(
                EmailAccount $account,
                private readonly ProviderMessage $providerMessage,
                private readonly int $uidValidity,
            ) {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => $this->uidValidity, 'next_uid' => 99999];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                return $this->providerMessage;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $missingEvidenceClient);

        $asymmetric = app(RecoverEmailMessageAttachments::class)->handle($message->fresh(), true);

        $this->assertSame('failed', $asymmetric['status']);
        $this->assertSame('provider_message_id_mismatch', $asymmetric['reason_code']);
        $this->assertSame(2, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);
    }

    #[Test]
    public function provider_fallback_discards_content_if_uidvalidity_changes_during_the_read(): void
    {
        $account = $this->account('attachment-provider-race@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 975);
        [$message, $placement] = $this->placedMessage($account, $folder);
        $providerMessage = ProviderMessage::fromString($this->mimeMessage([
            ['namespace-race.pdf', 'application/pdf', '%PDF-race'],
        ], $message->message_id))->setUid($placement->imap_uid);

        $client = new class($account, $providerMessage, $folder->uid_validity) extends ImapClient
        {
            private int $stateReads = 0;

            public function __construct(
                EmailAccount $account,
                private readonly ProviderMessage $providerMessage,
                private readonly int $uidValidity,
            ) {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void {}

            public function folderState(string $folderPath): array
            {
                $this->stateReads++;

                return [
                    'uid_validity' => $this->stateReads === 1 ? $this->uidValidity : $this->uidValidity + 1,
                    'next_uid' => 99999,
                ];
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                return $this->providerMessage;
            }
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(RecoverEmailMessageAttachments::class)->handle($message, true);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('provider_uidvalidity_changed_during_read', $result['reason_code']);
        $this->assertSame(0, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);
    }

    #[Test]
    public function future_inbound_storage_writes_reparsable_full_rfc822_and_closes_the_fetch_connection(): void
    {
        Queue::fake();
        $account = $this->account('attachment-future-ingest@example.test');
        $raw = $this->mimeMessage(
            [['future.pdf', 'application/pdf', '%PDF-future']],
            '<future-attachment@example.test>',
        );
        $providerMessage = ProviderMessage::fromString($raw)->setUid(98001);
        $client = new class($account, $providerMessage) extends ImapClient
        {
            public bool $disconnected = false;

            public function __construct(EmailAccount $account, private readonly ProviderMessage $providerMessage)
            {
                parent::__construct($account);
            }

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX'): ProviderMessage
            {
                return $this->providerMessage;
            }
        };
        $job = new class(['account_id' => $account->id, 'mailbox' => 'INBOX', 'imap_uid' => 98001, 'uid_validity' => 980, 'message_id' => '<future-attachment@example.test>', 'subject' => 'Future full raw attachment', 'from_email' => 'sender@example.test', 'headers' => [], 'received_at' => now(), 'size_bytes' => strlen($raw), 'is_oversize' => false, 'run_inbound_rules' => false], $client) extends StoreInboundMessage
        {
            public function __construct(array $payload, private readonly ImapClient $client)
            {
                parent::__construct($payload);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        app()->call([$job, 'handle']);

        $message = EmailMessage::query()->where('account_id', $account->id)->where('imap_uid', 98001)->sole();
        $stored = Storage::disk('local')->get($message->raw_path);
        $reparsed = ProviderMessage::fromString($stored);

        $this->assertStringStartsWith('From:', $stored);
        $this->assertSame(1, $reparsed->getAttachments()->count());
        $this->assertSame('future.pdf', $reparsed->getAttachments()->first()->getName());
        $this->assertSame(1, $message->attachments_count);
        $this->assertTrue($client->disconnected);
        Queue::assertNotPushed(ProcessInboundRules::class);
    }

    #[Test]
    public function inbound_refetch_failure_logs_only_stable_sanitized_provider_context(): void
    {
        Queue::fake();
        Log::spy();
        $account = $this->account('attachment-sanitized-log@example.test');
        $client = new class($account) extends ImapClient
        {
            public bool $disconnected = false;

            public function connect(): void {}

            public function disconnect(): void
            {
                $this->disconnected = true;
            }

            public function fetchByUid(int $uid, string $folderPath = 'INBOX')
            {
                throw new \RuntimeException('provider-secret-host-and-protocol-detail');
            }
        };
        $job = new class(['account_id' => $account->id, 'mailbox' => 'INBOX', 'imap_uid' => 98501, 'uid_validity' => 985, 'message_id' => '<sanitized-provider-log@example.test>', 'subject' => 'Sanitized provider log', 'from_email' => 'sender@example.test', 'headers' => [], 'received_at' => now(), 'size_bytes' => 100, 'is_oversize' => false, 'run_inbound_rules' => false], $client) extends StoreInboundMessage
        {
            public function __construct(array $payload, private readonly ImapClient $client)
            {
                parent::__construct($payload);
            }

            protected function makeImapClient(EmailAccount $account): ImapClient
            {
                return $this->client;
            }
        };

        app()->call([$job, 'handle']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Failed to refetch full message', \Mockery::on(function (array $context) use ($account): bool {
                return $context === [
                    'account_id' => $account->id,
                    'uid' => 98501,
                    'reason' => 'provider_read_failed',
                    'exception' => \RuntimeException::class,
                ] && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'provider-secret');
            }));
        $this->assertTrue($client->disconnected);
    }

    #[Test]
    public function recovery_command_is_preflight_only_without_explicit_apply(): void
    {
        $account = $this->account('attachment-command-preflight@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 990);
        [$message] = $this->placedMessage($account, $folder, attributes: ['attachments_count' => 12]);

        $this->artisan('email:recover-attachments', ['--message' => [$message->id]])
            ->expectsOutputToContain('Preflight only')
            ->expectsOutputToContain('#'.$message->id.' account='.$account->id.' rows=0 counter=12 raw_reference=missing')
            ->assertSuccessful();

        $this->assertSame(12, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);
    }

    #[Test]
    public function recovery_command_apply_fails_closed_until_received_at_schema_is_verified_safe(): void
    {
        $account = $this->account('attachment-command-readiness@example.test');
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 995);
        [$message] = $this->placedMessage($account, $folder, attributes: ['attachments_count' => 12]);
        $this->app->instance(EmailAttachmentRecoveryReadiness::class, new class extends EmailAttachmentRecoveryReadiness
        {
            public function check(): array
            {
                return ['safe' => false, 'reason_code' => 'received_at_on_update_present'];
            }
        });

        $this->artisan('email:recover-attachments', [
            '--message' => [$message->id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('received_at_on_update_present')
            ->assertFailed();

        $this->assertSame(12, $message->fresh()->attachments_count);
        $this->assertDatabaseCount('email_attachments', 0);
    }

    private function account(string $address): EmailAccount
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mail attachment test account',
            'from_name' => 'Attachment Test',
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
            'imap_secret' => 'attachment-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'attachment-test-secret',
            'smtp_auth_type' => 'password',
        ]);

        $this->grant($account, $this->actor);

        return $account;
    }

    private function grant(EmailAccount $account, User $user): void
    {
        EmailAccountUserGrant::query()->updateOrCreate([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
        ], [
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);
    }

    private function folder(EmailAccount $account, string $path, string $role, int $uidValidity): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{EmailMessage, EmailMailboxPlacement}
     */
    private function placedMessage(
        EmailAccount $account,
        EmailFolder $folder,
        ?int $ticketId = null,
        string $subject = 'Attachment test message',
        array $attributes = [],
    ): array {
        $uid = ++$this->nextUid;
        $message = EmailMessage::query()->create(array_merge([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid' => $uid,
            'message_id' => '<attachment-'.$uid.'@example.test>',
            'subject' => $subject,
            'from_email' => 'sender@example.test',
            'headers_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Attachment test body.',
            'ticket_id' => $ticketId,
        ], $attributes));
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'last_reconciled_at' => now(),
        ]);

        return [$message, $placement];
    }

    private function attachment(EmailMessage $message, string $filename): EmailAttachment
    {
        $content = '%PDF-'.$filename;
        $path = 'email/attachments/'.$message->account_id.'/'.$message->mailbox.'/'.$message->imap_uid.'/'.$filename;
        Storage::disk('local')->put($path, $content);

        return EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => $filename,
            'content_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'disk' => 'local',
            'path' => $path,
            'checksum_sha1' => sha1($content),
        ]);
    }

    /** @param array<int, array{string, string, string}> $attachments */
    private function mimeMessage(
        array $attachments,
        string $messageId = '<attachment-recovery@example.test>',
    ): string {
        $boundary = 'nexum-attachment-boundary';
        $lines = [
            'From: Sender <sender@example.test>',
            'To: Support <support@example.test>',
            'Subject: Attachment recovery test',
            'Message-ID: '.$messageId,
            'Date: Sat, 15 Aug 2026 12:00:00 +0200',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
            '',
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Attachment recovery body.',
        ];

        foreach ($attachments as [$filename, $mimeType, $content]) {
            array_push($lines,
                '--'.$boundary,
                'Content-Type: '.$mimeType.'; name="'.$filename.'"',
                'Content-Disposition: attachment; filename="'.$filename.'"',
                'Content-Transfer-Encoding: base64',
                '',
                base64_encode($content),
            );
        }

        $lines[] = '--'.$boundary.'--';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    /** @return array{string, string} */
    private function splitMessage(string $raw): array
    {
        return explode("\r\n\r\n", $raw, 2);
    }

    /** @return array<string, array<int, string>> */
    private function mimeHeaders(string $rawHeaders): array
    {
        preg_match('/^MIME-Version:\s*(.+)$/mi', $rawHeaders, $mimeVersion);
        preg_match('/^Content-Type:\s*(.+)$/mi', $rawHeaders, $contentType);

        return [
            'mime-version' => [trim((string) ($mimeVersion[1] ?? '1.0'))],
            'content-type' => [trim((string) ($contentType[1] ?? ''))],
        ];
    }
}
