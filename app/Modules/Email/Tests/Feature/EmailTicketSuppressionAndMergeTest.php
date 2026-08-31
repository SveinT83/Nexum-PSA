<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\SuppressEmailConversationTicketCorrelation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversationTicketSuppression;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailTicketCorrelationSuppressionService;
use App\Modules\Email\Services\InboundEmailTicketCorrelationService;
use App\Modules\Ticket\Actions\MergeTickets;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketKeyAlias;
use App\Modules\Ticket\Support\TicketMergeSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailTicketSuppressionAndMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private EmailAccount $account;

    private EmailFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech');
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole('Tech');
        $this->user->givePermissionTo([
            'email.inbox_view',
            'email.inbox_manage',
            'ticket.view',
            'ticket.update',
        ]);
        $this->account = EmailAccount::create([
            'address' => 'support@example.test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => 'secret',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => 'secret',
        ]);
        DB::table('email_account_user_grants')->insert([
            'email_account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_by' => $this->user->id,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->folder = EmailFolder::create([
            'account_id' => $this->account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1,
        ]);
    }

    #[Test]
    public function conversation_suppression_blocks_future_ticket_correlation_without_provider_mutation(): void
    {
        [$root, $rootPlacement] = $this->messageAndPlacement(501, '<suppressed-root@example.test>');
        app(SuppressEmailConversationTicketCorrelation::class)->handle($rootPlacement, $this->user);

        [$reply, $replyPlacement] = $this->messageAndPlacement(502, '<suppressed-reply@example.test>', [
            'in_reply_to' => '<suppressed-root@example.test>',
            'references' => '<suppressed-root@example.test>',
            'subject' => 'Re: Suppressed conversation',
        ]);

        $this->assertTrue(app(EmailTicketCorrelationSuppressionService::class)->isSuppressed($reply));
        $this->assertTrue(app(InboundEmailTicketCorrelationService::class)->correlate($reply));
        $this->assertNull($reply->fresh()->ticket_id);
        $this->assertDatabaseHas('email_conversation_ticket_suppressions', [
            'account_id' => $this->account->id,
            'status' => EmailConversationTicketSuppression::STATUS_ACTIVE,
        ]);
        $this->assertFalse($rootPlacement->fresh()->provider_seen);
        $this->assertFalse($replyPlacement->fresh()->provider_seen);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $replyPlacement->fresh()->local_state);
    }

    #[Test]
    public function ticket_merge_deduplicates_mail_links_and_retains_the_old_key_as_an_alias(): void
    {
        $target = Ticket::factory()->create(['ticket_key' => 'TD-2026-880001']);
        $source = Ticket::factory()->create(['ticket_key' => 'TD-2026-880002']);
        [$message, $placement] = $this->messageAndPlacement(601, '<merge-link@example.test>');
        $conversation = app(EmailConversationProjector::class)->assignPlacement($placement);

        EmailTicketConversationLink::create([
            'ticket_id' => $target->id,
            'email_message_id' => $message->id,
            'email_mailbox_placement_id' => $placement->id,
            'account_id' => $this->account->id,
            'email_conversation_id' => $conversation->id,
            'linked_by' => $this->user->id,
            'conversation_key' => $conversation->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_SECONDARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now(),
        ]);
        EmailTicketConversationLink::create([
            'ticket_id' => $source->id,
            'email_message_id' => $message->id,
            'email_mailbox_placement_id' => $placement->id,
            'account_id' => $this->account->id,
            'email_conversation_id' => $conversation->id,
            'linked_by' => $this->user->id,
            'conversation_key' => $conversation->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_INTERNAL,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now()->subMinute(),
        ]);

        app(MergeTickets::class)->handle($source, $target, $this->user, 'Same conversation.');

        $links = EmailTicketConversationLink::query()
            ->where('ticket_id', $target->id)
            ->where('email_message_id', $message->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->get();
        $this->assertCount(1, $links);
        $this->assertSame(EmailTicketConversationLink::ROLE_PRIMARY, $links->first()->relationship_role);
        $this->assertSame(EmailTicketConversationLink::AUDIENCE_INTERNAL, $links->first()->audience);
        $this->assertDatabaseHas('ticket_key_aliases', [
            'alias_key' => $source->ticket_key,
            'ticket_id' => $target->id,
            'source_ticket_id' => $source->id,
        ]);

        [$reply] = $this->messageAndPlacement(602, '<old-key-reply@example.test>', [
            'subject' => 'Re: '.$source->ticket_key.' still open',
        ]);
        $this->assertTrue(app(InboundEmailTicketCorrelationService::class)->correlate($reply));
        $this->assertSame($target->id, $reply->fresh()->ticket_id);
        $this->assertSame($target->id, TicketKeyAlias::where('alias_key', $source->ticket_key)->value('ticket_id'));
    }

    #[Test]
    public function stale_ticket_merge_preview_is_rejected_without_partial_changes(): void
    {
        $target = Ticket::factory()->create(['ticket_key' => 'TD-2026-880003']);
        $source = Ticket::factory()->create(['ticket_key' => 'TD-2026-880004']);
        $snapshots = [
            $target->id => TicketMergeSnapshot::fingerprint($target),
            $source->id => TicketMergeSnapshot::fingerprint($source),
        ];
        $source->forceFill(['subject' => 'Changed after preview'])->save();

        $this->actingAs($this->user)
            ->from(route('tech.tickets.index'))
            ->post(route('tech.tickets.merge'), [
                'ticket_ids' => [$target->id, $source->id],
                'target_ticket_id' => $target->id,
                'ticket_snapshots' => $snapshots,
            ])
            ->assertRedirect(route('tech.tickets.index'))
            ->assertSessionHasErrors('ticket_ids');

        $this->assertNotSoftDeleted('tickets', ['id' => $source->id]);
        $this->assertNull($source->fresh()->merged_into_ticket_id);
    }

    /** @return array{EmailMessage, EmailMailboxPlacement} */
    private function messageAndPlacement(int $uid, string $messageId, array $overrides = []): array
    {
        $message = EmailMessage::create(array_merge([
            'account_id' => $this->account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => $messageId,
            'subject' => 'Suppression and merge test',
            'from_email' => 'sender@example.test',
            'received_at' => now()->addSeconds($uid),
            'state' => 'untriaged',
            'body_text' => 'Test message body.',
        ], $overrides));
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $this->folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);

        return [$message, $placement];
    }
}
