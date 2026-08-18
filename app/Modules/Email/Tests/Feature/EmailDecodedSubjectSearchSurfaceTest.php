<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Support\EmailSubjectPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailDecodedSubjectSearchSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 51000;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Decoded subject search technician'])
            ->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Decoded subject search technician');
    }

    #[Test]
    public function decoded_subjects_are_searchable_across_mail_legacy_inbox_and_api_without_changing_api_subjects(): void
    {
        [$account, $inbox] = $this->mailbox('decoded-search@example.test');
        $qRaw = '=?UTF-8?Q?Pr=C3=B8ve_bl=C3=A5b=C3=A6r?=';
        $base64Raw = '=?UTF-8?B?'.base64_encode('Base64 søknad').'?=';
        $truncatedRaw = '=?utf-8?Q?Fwd=3A_DEKKSPERTEN_DA_=28936529364=29_har_f=C3=';

        $qMessage = $this->message($account, $inbox, $qRaw);
        $base64Message = $this->message($account, $inbox, $base64Raw);
        $truncatedMessage = $this->message($account, $inbox, $truncatedRaw);

        foreach ([
            'blåbær' => [$qMessage],
            'søknad' => [$base64Message],
            'DEKKSPERTEN' => [$truncatedMessage],
        ] as $term => $expectedMessages) {
            $expectedIds = collect($expectedMessages)->pluck('id')->all();

            $this->assertMailSearch($term, $expectedIds);
            $this->assertLegacyInboxSearch($term, $expectedIds);
            $api = $this->apiSearch($term);

            $this->assertEqualsCanonicalizing($expectedIds, collect($api->json('data'))->pluck('id')->all());
            $this->assertSame(
                $expectedMessages[0]->subject,
                collect($api->json('data'))->firstWhere('id', $expectedMessages[0]->id)['subject'],
            );
        }

        $this->assertSame($qRaw, $qMessage->fresh()->subject);
        $this->assertSame($base64Raw, $base64Message->fresh()->subject);
        $this->assertSame($truncatedRaw, $truncatedMessage->fresh()->subject);
    }

    #[Test]
    public function raw_subject_sender_and_body_search_compatibility_is_preserved_on_every_surface(): void
    {
        [$account, $inbox] = $this->mailbox('search-compatibility@example.test');
        $rawSubject = $this->message(
            $account,
            $inbox,
            '=?UTF-8?Q?Pr=C3=B8ve_r=C3=A5tekst?=',
        );
        $plainSubject = $this->message(
            $account,
            $inbox,
            'Plain subject compatibility needle',
        );
        $sender = $this->message(
            $account,
            $inbox,
            'Ordinary sender test',
            fromName: 'Unique Search Sender',
        );
        $body = $this->message(
            $account,
            $inbox,
            'Ordinary body test',
            body: 'The compatibility-body-needle is present only in this body.',
        );

        foreach ([
            'C3=B8ve' => [$rawSubject->id],
            'subject compatibility' => [$plainSubject->id],
            'Unique Search Sender' => [$sender->id],
            'compatibility-body-needle' => [$body->id],
        ] as $term => $expectedIds) {
            $this->assertMailSearch($term, $expectedIds);
            $this->assertLegacyInboxSearch($term, $expectedIds);
            $this->assertEqualsCanonicalizing(
                $expectedIds,
                collect($this->apiSearch($term)->json('data'))->pluck('id')->all(),
            );
        }
    }

    #[Test]
    public function search_or_branches_cannot_escape_ticket_account_folder_or_mailbox_authorization_filters(): void
    {
        [$account, $inbox] = $this->mailbox('search-boundary@example.test');
        $sent = $this->folder($account, 'Sent', EmailFolder::ROLE_SENT);
        [$otherAccount, $otherInbox] = $this->mailbox('search-other@example.test');
        [$privateAccount, $privateInbox] = $this->mailbox('search-private@example.test', grant: false);

        $visible = $this->message(
            $account,
            $inbox,
            'Visible scoped result',
            body: 'boundary-search-needle',
        );
        $ticketLinked = $this->message(
            $account,
            $inbox,
            'boundary-search-needle linked Ticket',
            ticketId: 987654,
        );
        $sentMessage = $this->message(
            $account,
            $sent,
            'boundary-search-needle sent folder',
        );
        $otherAccountMessage = $this->message(
            $otherAccount,
            $otherInbox,
            'boundary-search-needle other selected account',
        );
        $privateMessage = $this->message(
            $privateAccount,
            $privateInbox,
            'boundary-search-needle inaccessible account',
        );

        $mail = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'folder')
            ->set('accountId', $account->id)
            ->set('folderId', $inbox->id)
            ->set('search', 'boundary-search-needle')
            ->viewData('placements');

        $this->assertEqualsCanonicalizing(
            [$visible->id, $ticketLinked->id],
            $mail->getCollection()->pluck('message.id')->all(),
        );
        $this->assertNotContains($sentMessage->id, $mail->getCollection()->pluck('message.id')->all());
        $this->assertNotContains($otherAccountMessage->id, $mail->getCollection()->pluck('message.id')->all());
        $this->assertNotContains($privateMessage->id, $mail->getCollection()->pluck('message.id')->all());

        $this->assertLegacyInboxSearch(
            'boundary-search-needle',
            [$visible->id],
            $account->id,
        );
        $api = $this->apiSearch('boundary-search-needle', $account->id);
        $this->assertSame([$visible->id], collect($api->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function decoded_subject_search_keeps_durable_conversation_pagination_in_the_database(): void
    {
        [$account, $inbox] = $this->mailbox('search-pagination@example.test');
        $latestMessageIds = [];

        for ($index = 1; $index <= 30; $index++) {
            $latestAt = now()->subMinutes(31 - $index);
            $firstRawSubject = sprintf('=?UTF-8?Q?S=C3=B8kbar_samtale_%02d_f=C3=B8rste?=', $index);
            $latestRawSubject = sprintf('=?UTF-8?Q?S=C3=B8kbar_samtale_%02d_siste?=', $index);
            $firstMessage = $this->message(
                $account,
                $inbox,
                $firstRawSubject,
                receivedAt: $latestAt->copy()->subSecond(),
                createPlacement: false,
            );
            $latestMessage = $this->message(
                $account,
                $inbox,
                $latestRawSubject,
                receivedAt: $latestAt,
                createPlacement: false,
            );
            $conversation = EmailConversation::query()->create([
                'account_id' => $account->id,
                'conversation_key' => sprintf('decoded-search-page-%02d', $index),
                'status' => EmailConversation::STATUS_ACTIVE,
                'subject' => $latestRawSubject,
                'first_email_message_id' => $firstMessage->id,
                'latest_email_message_id' => $latestMessage->id,
                'message_count' => 2,
                'active_placement_count' => 2,
                'provider_unread_count' => 2,
                'has_attachments' => false,
                'first_message_at' => $firstMessage->received_at,
                'last_message_at' => $latestMessage->received_at,
            ]);
            $this->placement($account, $inbox, $firstMessage, $conversation);
            $latestPlacement = $this->placement($account, $inbox, $latestMessage, $conversation);
            $conversation->forceFill([
                'latest_email_mailbox_placement_id' => $latestPlacement->id,
            ])->save();
            $latestMessageIds[] = $latestMessage->id;
        }

        $this->assertSame(60, EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->count());

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox')
            ->set('search', 'Søkbar');
        $firstPage = $component->viewData('placements');

        $this->assertSame(30, $firstPage->total());
        $this->assertCount(25, $firstPage->items());
        $this->assertTrue($firstPage->getCollection()->every(
            fn (EmailMailboxPlacement $placement): bool => $placement->account_id === $account->id,
        ));
        $this->assertTrue($firstPage->getCollection()->every(
            fn (EmailMailboxPlacement $placement): bool => $placement->getAttribute('mail_conversation_count') === 2,
        ));

        $component->call('setPage', 2);
        $secondPage = $component->viewData('placements');

        $this->assertSame(30, $secondPage->total());
        $this->assertCount(5, $secondPage->items());
        $this->assertTrue($secondPage->getCollection()->every(
            fn (EmailMailboxPlacement $placement): bool => $placement->getAttribute('mail_conversation_count') === 2,
        ));
        $this->assertEqualsCanonicalizing(
            $latestMessageIds,
            $firstPage->getCollection()
                ->concat($secondPage->getCollection())
                ->pluck('email_message_id')
                ->all(),
        );
    }

    /**
     * @param  array<int, int>  $expectedMessageIds
     */
    private function assertMailSearch(string $term, array $expectedMessageIds): void
    {
        $placements = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox')
            ->set('search', $term)
            ->viewData('placements');

        $this->assertEqualsCanonicalizing(
            $expectedMessageIds,
            $placements->getCollection()->pluck('message.id')->all(),
        );
    }

    /**
     * @param  array<int, int>  $expectedMessageIds
     */
    private function assertLegacyInboxSearch(
        string $term,
        array $expectedMessageIds,
        ?int $accountId = null,
    ): void {
        $response = $this->actingAs($this->actor)->get(route('tech.inbox.index', array_filter([
            'q' => $term,
            'account_id' => $accountId,
        ], fn (mixed $value): bool => $value !== null)));

        $response->assertOk()->assertViewHas(
            'messages',
            fn ($messages): bool => collect($messages->items())
                ->pluck('id')
                ->sort()
                ->values()
                ->all() === collect($expectedMessageIds)->sort()->values()->all(),
        );
    }

    private function apiSearch(string $term, ?int $accountId = null)
    {
        Sanctum::actingAs($this->actor, ['email.read']);

        return $this->getJson(route('api.v1.email.inbox.messages.index', array_filter([
            'q' => $term,
            'account_id' => $accountId,
        ], fn (mixed $value): bool => $value !== null)))->assertOk();
    }

    /** @return array{0: EmailAccount, 1: EmailFolder} */
    private function mailbox(string $address, bool $grant = true): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Decoded subject search test account',
            'from_name' => 'Decoded Subject Search',
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
            'imap_secret' => 'decoded-search-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'decoded-search-secret',
            'smtp_auth_type' => 'password',
        ]);

        if ($grant) {
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $this->actor->id,
                'can_view' => true,
                'can_organize' => false,
                'can_send' => false,
                'granted_at' => now(),
            ]);
        }

        return [$account, $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX)];
    }

    private function folder(EmailAccount $account, string $path, string $role): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }

    private function message(
        EmailAccount $account,
        EmailFolder $folder,
        string $subject,
        string $fromName = 'Search Sender',
        string $body = 'Ordinary searchable body.',
        ?int $ticketId = null,
        mixed $receivedAt = null,
        bool $createPlacement = true,
    ): EmailMessage {
        $uid = ++$this->nextUid;
        $message = new EmailMessage;
        $message->forceFill([
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'imap_uid' => $uid,
            'message_id' => '<decoded-search-'.$uid.'@example.test>',
            'subject' => $subject,
            'subject_search' => EmailSubjectPresenter::present($subject),
            'from_name' => $fromName,
            'from_email' => 'sender-'.$uid.'@example.test',
            'to_json' => [['email' => $account->address]],
            'received_at' => $receivedAt ?: now()->subSeconds($uid % 1000),
            'state' => $ticketId ? 'linked' : 'untriaged',
            'body_text' => $body,
            'ticket_id' => $ticketId,
        ])->save();

        if ($createPlacement) {
            $this->placement($account, $folder, $message);
        }

        return $message;
    }

    private function placement(
        EmailAccount $account,
        EmailFolder $folder,
        EmailMessage $message,
        ?EmailConversation $conversation = null,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation?->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }
}
