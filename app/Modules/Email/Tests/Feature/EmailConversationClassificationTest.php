<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailConversationClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $technician;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        $rules = Permission::findOrCreate('email.rule_manage', 'web');

        Role::findOrCreate('Classification technician', 'web')->givePermissionTo([$view, $manage]);
        Role::findOrCreate('Classification admin', 'web')->givePermissionTo($rules);

        $this->technician = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->technician->assignRole('Classification technician');
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Classification admin');
    }

    #[Test]
    public function api_reads_updates_and_clears_one_account_scoped_conversation_classification(): void
    {
        [$account, $placement] = $this->mailboxPlacement('classification-api@example.test', 1001);
        $this->grant($account, $this->technician, organize: true);
        $category = Category::create([
            'name' => 'API Email category',
            'slug' => 'api-email-category',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
        Tag::create(['name' => 'API tag', 'slug' => 'api-tag', 'active' => true]);
        $conversation = $placement->conversation;

        Sanctum::actingAs($this->technician, ['email.read', 'email.update']);

        $this->getJson(route('api.v1.email.mailbox.conversations.classification.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->putJson(route('api.v1.email.mailbox.conversations.classification.update', $conversation), [
            'category_id' => $category->id,
            'tags' => ['API tag'],
        ])
            ->assertOk()
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.tags.0.name', 'API tag')
            ->assertJsonPath('data.source', EmailConversationClassification::SOURCE_MANUAL);

        $this->getJson(route('api.v1.email.mailbox.conversations.classification.show', $conversation))
            ->assertOk()
            ->assertJsonPath('data.email_conversation_id', $conversation->id)
            ->assertJsonPath('data.category.name', 'API Email category');

        $this->deleteJson(route('api.v1.email.mailbox.conversations.classification.destroy', $conversation))
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonCount(0, 'data.tags');

        $this->assertDatabaseHas('email_conversation_classification_events', [
            'account_id' => $account->id,
            'email_conversation_id' => $conversation->id,
            'actor_id' => $this->technician->id,
            'event_type' => 'updated',
        ]);
    }

    #[Test]
    public function conversation_classification_api_hides_ungranted_accounts_and_requires_organize_for_writes(): void
    {
        [$visibleAccount, $visiblePlacement] = $this->mailboxPlacement('classification-readonly@example.test', 1101);
        [$privateAccount, $privatePlacement] = $this->mailboxPlacement('classification-private@example.test', 1201);
        $this->grant($visibleAccount, $this->technician, organize: false);
        $category = Category::create([
            'name' => 'API guarded category',
            'slug' => 'api-guarded-category',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->technician, ['email.read', 'email.update']);

        $this->getJson(route('api.v1.email.mailbox.conversations.classification.show', $visiblePlacement->conversation))
            ->assertOk();
        $this->putJson(route('api.v1.email.mailbox.conversations.classification.update', $visiblePlacement->conversation), [
            'category_id' => $category->id,
        ])->assertForbidden();

        $this->getJson(route('api.v1.email.mailbox.conversations.classification.show', $privatePlacement->conversation))
            ->assertNotFound();
        $this->putJson(route('api.v1.email.mailbox.conversations.classification.update', $privatePlacement->conversation), [
            'category_id' => $category->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('email_conversation_classifications', [
            'account_id' => $privateAccount->id,
        ]);
    }

    #[Test]
    public function rule_actions_keep_message_tags_message_scoped_and_apply_explicit_conversation_taxonomy(): void
    {
        [$account, $placement] = $this->mailboxPlacement('classification-rule@example.test', 1301);
        $message = $placement->message;
        $category = Category::create([
            'name' => 'Rule Email category',
            'slug' => 'rule-email-category',
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
        $conversationTag = Tag::create([
            'name' => 'Conversation rule tag',
            'slug' => 'conversation-rule-tag',
            'active' => true,
        ]);

        $rule = EmailRule::create([
            'name' => 'Explicit conversation classification',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_PRECLASSIFICATION,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'weight' => 1,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'Classification'],
            ],
            'actions_json' => [
                ['type' => 'tag', 'value' => 'Legacy message tag'],
                ['type' => 'tag_message', 'value' => 'Explicit message tag'],
                ['type' => 'tag_conversation', 'value' => (string) $conversationTag->id],
                ['type' => 'set_conversation_category', 'value' => (string) $category->id],
            ],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $rule->accounts()->attach($account->id);
        app(EmailRulePublisher::class)->publish($rule, $this->admin);

        $this->assertTrue(app(InboundEmailRuleEngine::class)->processPreclassification($message));

        $message->refresh()->load('tags');
        $this->assertEqualsCanonicalizing(
            ['Explicit message tag', 'Legacy message tag'],
            $message->tags->pluck('name')->all(),
        );

        $classification = EmailConversationClassification::query()
            ->with('tags')
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $placement->conversation->id)
            ->firstOrFail();
        $this->assertSame($category->id, $classification->category_id);
        $this->assertSame([$conversationTag->id], $classification->tags->pluck('id')->all());
        $this->assertSame(EmailConversationClassification::SOURCE_RULE, $classification->source);
        $this->assertSame($rule->id, $classification->provenance['email_rule_id']);
        $this->assertDatabaseHas('email_conversation_classification_events', [
            'email_conversation_classification_id' => $classification->id,
            'event_type' => 'rule_applied',
        ]);
    }

    #[Test]
    public function admin_rule_builder_rejects_a_ticket_category_for_conversation_classification(): void
    {
        [$account] = $this->mailboxPlacement('classification-forged-category@example.test', 1401);
        $ticketCategory = Category::create([
            'name' => 'Ticket category only',
            'slug' => 'ticket-category-only',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.rules.create'))
            ->post(route('tech.admin.settings.email.rules.store'), [
                'name' => 'Forged Ticket category rule',
                'weight' => 1,
                'account_ids' => [$account->id],
                'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
                'is_active' => '1',
                'stop_processing' => '0',
                'conditions' => [
                    ['field' => 'subject', 'operator' => 'contains', 'value' => 'Classification'],
                ],
                'actions' => [
                    ['type' => 'set_conversation_category', 'value' => (string) $ticketCategory->id],
                ],
            ])
            ->assertRedirect(route('tech.admin.settings.email.rules.create'))
            ->assertSessionHasErrors('actions');

        $this->assertDatabaseMissing('email_rules', ['name' => 'Forged Ticket category rule']);
    }

    /**
     * @return array{EmailAccount, EmailMailboxPlacement}
     */
    private function mailboxPlacement(string $address, int $uid): array
    {
        $account = EmailAccount::create([
            'address' => $address,
            'from_name' => 'Classification mailbox',
            'is_active' => true,
            'account_kind' => EmailAccount::KIND_SHARED,
            'ticket_ingress_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uid,
        ]);
        $message = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<classification-'.$uid.'@example.test>',
            'subject' => 'Classification '.$uid,
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Conversation classification fixture.',
        ]);
        $placement = EmailMailboxPlacement::create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => $uid,
            'imap_uid' => $uid,
            'provider_seen' => false,
        ]);
        app(EmailConversationProjector::class)->assignPlacement($placement);

        return [$account, $placement->fresh(['message', 'conversation'])];
    }

    private function grant(EmailAccount $account, User $user, bool $organize): void
    {
        EmailAccountUserGrant::create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => $organize,
            'can_send' => false,
            'granted_by' => $this->admin->id,
            'granted_at' => now(),
        ]);
    }
}
