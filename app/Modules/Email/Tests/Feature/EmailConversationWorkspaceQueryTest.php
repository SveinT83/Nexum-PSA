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
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailConversationWorkspaceQueryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $nextUid = 30000;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Mail conversation query tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Mail conversation query tech');
    }

    #[Test]
    public function durable_conversation_rows_are_database_grouped_paginated_and_keep_aggregate_metadata(): void
    {
        [$account, $folder] = $this->mailbox('query-pagination@example.test');
        $latestConversation = null;
        $latestRoot = null;

        for ($index = 1; $index <= 30; $index++) {
            $fixture = $this->durableConversation(
                $account,
                $folder,
                sprintf('Paginated conversation %02d', $index),
                now()->subMinutes(31 - $index),
                providerSeen: $index === 30,
            );

            if ($index === 30) {
                $latestConversation = $fixture['conversation'];
                $latestRoot = $fixture['message'];
            }
        }

        $this->assertInstanceOf(EmailConversation::class, $latestConversation);
        $this->assertInstanceOf(EmailMessage::class, $latestRoot);

        EmailMessageUserState::query()->create([
            'email_message_id' => $latestRoot->id,
            'user_id' => $this->actor->id,
            'is_unread' => false,
        ]);
        $latestReply = $this->appendDurableMessage(
            $latestConversation,
            $account,
            $folder,
            'Paginated conversation 30 latest reply',
            now(),
            providerSeen: false,
        );

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox');
        $firstPage = $component->viewData('placements');

        $this->assertSame(30, $firstPage->total());
        $this->assertCount(25, $firstPage->items());
        $this->assertSame($latestReply->id, $firstPage->first()->id);
        $this->assertSame(2, $firstPage->first()->getAttribute('mail_conversation_count'));
        $this->assertSame(1, $firstPage->first()->getAttribute('mail_conversation_unread_for_me_count'));
        $this->assertSame(1, $firstPage->first()->getAttribute('mail_conversation_mailbox_unread_count'));

        $component->call('selectPlacement', $latestReply->id);
        $expandedFirstPage = $component->viewData('placements');

        $this->assertSame(30, $expandedFirstPage->total());
        $this->assertCount(25, $expandedFirstPage->items());
        $this->assertSame($latestReply->id, $expandedFirstPage->first()->id);
        preg_match_all('/data-mail-conversation-children="\d+"/', $component->html(), $expandedRegions);
        $this->assertCount(1, $expandedRegions[0]);

        $component->call('setPage', 2);
        $secondPage = $component->viewData('placements');

        $this->assertSame(30, $secondPage->total());
        $this->assertCount(5, $secondPage->items());
        $this->assertSame('Paginated conversation 05', $secondPage->first()->message->subject);
        $this->assertSame('Paginated conversation 01', $secondPage->last()->message->subject);
    }

    #[Test]
    public function durable_reader_renders_the_complete_authorized_thread_and_smart_review_queue(): void
    {
        $agent = $this->readyReadAgent();
        [$account, $folder] = $this->mailbox('query-thread@example.test');
        $fixture = $this->durableConversation(
            $account,
            $folder,
            'Complete thread mail 01',
            now()->subMinutes(25),
        );
        $latestPlacement = $fixture['placement'];

        for ($index = 2; $index <= 25; $index++) {
            $latestPlacement = $this->appendDurableMessage(
                $fixture['conversation'],
                $account,
                $folder,
                sprintf('Complete thread mail %02d', $index),
                now()->subMinutes(25 - $index),
            );
        }

        $proposal = [
            'summary' => 'Reader-first Smart Inbox review marker.',
            'key_points' => [],
            'questions' => [],
            'urgency' => 'normal',
            'reply_needed' => false,
        ];
        $source = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);
        EmailSmartInboxSuggestion::query()->create([
            'user_id' => $this->actor->id,
            'account_id' => $account->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'selected_email_mailbox_placement_id' => $latestPlacement->id,
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
            'source_fingerprint' => $source['fingerprint'],
            'source_message_ids_json' => $source['source_message_ids'],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'reader-first-smart-inbox-order'),
            'ai_agent_id' => $agent->id,
            'ai_model' => $agent->model,
            'ai_policy_revision' => 1,
            'generated_at' => now(),
        ]);

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $latestPlacement->id)
            ->assertSee('Complete thread mail 01')
            ->assertSee('Complete thread mail 25')
            ->assertSee('25 mails')
            ->assertSee('Smart Inbox')
            ->assertDontSee('Showing 20 of 25');

        $html = $component->html();
        $smartInboxTriggerPosition = strpos($html, 'data-smart-inbox-trigger');
        $readerBodyPosition = strpos($html, 'data-mail-reader-body');
        $messageBodyPosition = strrpos($html, 'Body for Complete thread mail 25');
        $smartInboxResultSlotPosition = strpos($html, 'data-smart-inbox-results-slot');
        $this->assertNotFalse($smartInboxTriggerPosition);
        $this->assertNotFalse($readerBodyPosition);
        $this->assertNotFalse($messageBodyPosition);
        $this->assertNotFalse($smartInboxResultSlotPosition);
        $this->assertTrue(
            $smartInboxTriggerPosition < $readerBodyPosition,
            'The Smart Inbox trigger must remain above the complete conversation reader.',
        );
        $this->assertTrue(
            $messageBodyPosition < $smartInboxResultSlotPosition,
            'The optional Smart Inbox result region must remain after the complete selected conversation.',
        );

        $this->assertSame(25, $component->viewData('conversationPlacementsTotal'));
        $this->assertCount(25, $component->viewData('conversationPlacements'));
        $this->assertFalse($component->viewData('conversationPlacementsTruncated'));
        preg_match_all('/data-mail-conversation-child-placement-id="(\d+)"/', $component->html(), $childMatches);
        $this->assertCount(25, $childMatches[1]);
    }

    #[Test]
    public function desktop_workspace_uses_equal_flex_panes_and_resets_to_natural_mobile_flow(): void
    {
        $source = file_get_contents(base_path('app/Modules/Email/Views/Livewire/Tech/mail-workspace.blade.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('@media (min-width: 1200px)', $source);
        $this->assertStringContainsString('.tech-shell-content > .content', $source);
        $this->assertStringContainsString('min-height: max(32rem, calc(100dvh - 178px));', $source);
        $this->assertStringContainsString('flex: 1 1 0;', $source);
        $this->assertStringContainsString('min-height: 0;', $source);
        $this->assertStringContainsString('@media (max-width: 1199.98px)', $source);
        $this->assertStringContainsString('height: auto;', $source);
        $this->assertStringContainsString('data-mail-conversation-list-pane', $source);
        $this->assertStringContainsString('data-mail-reader-pane', $source);
    }

    private function readyReadAgent(): AiAgent
    {
        $policy = AiDataEgressPolicy::installation();
        $policy->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'full_context',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->actor->id,
            'reviewed_at' => now(),
            'updated_by' => $this->actor->id,
        ]);
        $provider = AiProvider::query()->create([
            'name' => 'Mail conversation query AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://mail-conversation-query.test',
            'default_model' => 'mail-conversation-query-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Mail Conversation Query Agent',
            'slug' => 'mail-conversation-query-agent-'.Str::lower(Str::random(8)),
            'model' => 'mail-conversation-query-test',
            'instructions' => 'Return only the governed Mail summary schema.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);
    }

    #[Test]
    public function selected_conversation_expands_full_context_as_clickable_exact_placement_rows(): void
    {
        [$account, $inbox] = $this->mailbox('query-expanded-list@example.test');
        $archive = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);
        $fixture = $this->durableConversation(
            $account,
            $inbox,
            'Expanded list root',
            now()->subMinutes(3),
            providerSeen: true,
        );
        $archived = $this->appendDurableMessage(
            $fixture['conversation'],
            $account,
            $archive,
            'Expanded archived context',
            now()->subMinutes(2),
            providerSeen: true,
        );
        $latest = $this->appendDurableMessage(
            $fixture['conversation'],
            $account,
            $inbox,
            'Expanded latest inbox reply',
            now()->subMinute(),
        );
        $hidden = $this->appendDurableMessage(
            $fixture['conversation'],
            $account,
            $inbox,
            'Hidden conversation copy',
            now(),
        );
        $hidden->forceFill(['local_state' => EmailMailboxPlacement::LOCAL_HIDDEN])->save();

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox')
            ->assertDontSeeHtml('data-mail-conversation-child-placement-id=')
            ->call('selectPlacement', $latest->id)
            ->assertSet('selectedPlacementId', $latest->id)
            ->assertSee('Conversation mails')
            ->assertSee('3 mails in conversation')
            ->assertSee('2 mails in this view')
            ->assertSee('Expanded archived context')
            ->assertDontSee('Hidden conversation copy')
            ->assertSeeHtml('aria-label="Emails in selected conversation"')
            ->assertSeeHtml('aria-controls="mail-conversation-children-'.$latest->id.'"');

        $this->assertSame(1, $component->viewData('placements')->total());
        preg_match_all('/data-mail-conversation-child-placement-id="(\d+)"/', $component->html(), $childMatches);
        $this->assertSame([
            (string) $latest->id,
            (string) $archived->id,
            (string) $fixture['placement']->id,
        ], $childMatches[1]);
        $component->assertDontSeeHtml('data-mail-conversation-child-placement-id="'.$hidden->id.'"');
        $this->assertMatchesRegularExpression(
            '/id="mail-conversation-child-'.$latest->id.'"[^>]*aria-current="true"/s',
            $component->html(),
        );
        foreach ([$latest, $archived, $fixture['placement']] as $childPlacement) {
            $this->assertMatchesRegularExpression(
                '/id="mail-conversation-child-'.$childPlacement->id.'"[^>]*wire:click="selectPlacement\('.$childPlacement->id.'\)"/s',
                $component->html(),
            );
        }
        $this->assertMatchesRegularExpression(
            '/id="mail-conversation-row-'.$latest->id.'"[^>]*aria-expanded="true"[^>]*aria-controls="mail-conversation-children-'.$latest->id.'"/s',
            $component->html(),
        );

        $component
            ->call('selectPlacement', $fixture['placement']->id)
            ->assertSet('selectedPlacementId', $fixture['placement']->id)
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSee('Expanded archived context');

        $this->assertMatchesRegularExpression(
            '/id="mail-conversation-child-'.$fixture['placement']->id.'"[^>]*aria-current="true"/s',
            $component->html(),
        );
    }

    #[Test]
    public function conversation_and_child_list_badges_follow_only_the_current_users_unread_state(): void
    {
        [$account, $inbox] = $this->mailbox('query-personal-unread@example.test');
        $otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $fixture = $this->durableConversation(
            $account,
            $inbox,
            'Personally read but mailbox unread',
            now()->subMinute(),
            providerSeen: false,
        );
        $latestPlacement = $this->appendDurableMessage(
            $fixture['conversation'],
            $account,
            $inbox,
            'Personally unread but mailbox read',
            now(),
            providerSeen: true,
        );

        foreach ([
            [$fixture['message']->id, $this->actor->id, false],
            [$fixture['message']->id, $otherUser->id, true],
            [$latestPlacement->email_message_id, $this->actor->id, true],
            [$latestPlacement->email_message_id, $otherUser->id, false],
        ] as [$messageId, $userId, $isUnread]) {
            EmailMessageUserState::query()->create([
                'email_message_id' => $messageId,
                'user_id' => $userId,
                'is_unread' => $isUnread,
            ]);
        }

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox')
            ->call('selectPlacement', $latestPlacement->id)
            ->assertSeeHtml('data-mail-conversation-unread-for-me="1"')
            ->assertSeeHtml('data-mail-placement-unread-for-me="'.$latestPlacement->id.'"')
            ->assertDontSeeHtml('data-mail-placement-unread-for-me="'.$fixture['placement']->id.'"');

        $html = $component->html();
        preg_match(
            '/<button\b[^>]*data-mail-conversation-row="'.$latestPlacement->id.'"[^>]*>.*?<\/button>/s',
            $html,
            $parentRow,
        );
        preg_match(
            '/<button\b[^>]*id="mail-conversation-child-'.$latestPlacement->id.'"[^>]*>.*?<\/button>/s',
            $html,
            $latestChildRow,
        );
        preg_match(
            '/<button\b[^>]*id="mail-conversation-child-'.$fixture['placement']->id.'"[^>]*>.*?<\/button>/s',
            $html,
            $readChildRow,
        );
        $this->assertNotEmpty($parentRow[0] ?? null);
        $this->assertNotEmpty($latestChildRow[0] ?? null);
        $this->assertNotEmpty($readChildRow[0] ?? null);
        $this->assertStringContainsString('data-mail-conversation-unread-for-me="1"', $parentRow[0]);
        $this->assertStringContainsString('data-mail-placement-unread-for-me="'.$latestPlacement->id.'"', $latestChildRow[0]);
        $this->assertStringNotContainsString('data-mail-placement-unread-for-me', $readChildRow[0]);
        $this->assertStringNotContainsString('Mailbox unread', $parentRow[0]);
        $this->assertStringNotContainsString('Mailbox unread', $latestChildRow[0]);
        $this->assertStringNotContainsString('Mailbox unread', $readChildRow[0]);

        $component
            ->call('selectPlacement', $fixture['placement']->id)
            ->assertSee('Read for me')
            ->assertSee('Mailbox unread')
            ->call('selectPlacement', $latestPlacement->id)
            ->assertSee('Unread for me')
            ->assertSee('Mailbox read');
    }

    #[Test]
    public function legacy_header_reader_is_scoped_to_the_selected_account_for_multi_mailbox_users(): void
    {
        [$firstAccount, $firstFolder] = $this->mailbox('query-legacy-one@example.test');
        [$secondAccount, $secondFolder] = $this->mailbox('query-legacy-two@example.test');
        $firstArchive = EmailFolder::query()->create([
            'account_id' => $firstAccount->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);
        $rootId = '<shared-legacy-header@example.test>';

        $this->legacyPlacement(
            $firstAccount,
            $firstFolder,
            'Selected account root',
            $rootId,
            null,
            now()->subMinute(),
        );
        $selected = $this->legacyPlacement(
            $firstAccount,
            $firstFolder,
            'Selected account reply',
            '<selected-account-reply@example.test>',
            $rootId,
            now(),
        );
        $archived = $this->legacyPlacement(
            $firstAccount,
            $firstArchive,
            'Selected account archived context',
            '<selected-account-archived@example.test>',
            $rootId,
            now()->subSecond(),
        );
        $otherAccountPlacement = $this->legacyPlacement(
            $secondAccount,
            $secondFolder,
            'Other authorized account must not enter reader',
            $rootId,
            null,
            now()->addSecond(),
        );

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('accountId', $firstAccount->id)
            ->call('selectPlacement', $selected->id)
            ->assertSee('Selected account root')
            ->assertSee('Selected account reply')
            ->assertSee('Selected account archived context')
            ->assertDontSee('Other authorized account must not enter reader')
            ->assertDontSee('Smart Inbox')
            ->call('selectPlacement', $archived->id)
            ->assertSet('selectedPlacementId', $archived->id)
            ->assertSeeHtml('aria-expanded="true"')
            ->assertSeeHtml('data-mail-conversation-child-placement-id="'.$archived->id.'"')
            ->assertDontSeeHtml('data-mail-conversation-child-placement-id="'.$otherAccountPlacement->id.'"');

        $thread = $component->viewData('conversationPlacements');

        $this->assertSame(3, $component->viewData('conversationPlacementsTotal'));
        $this->assertSame([$firstAccount->id], $thread->pluck('account_id')->unique()->values()->all());
        $this->assertTrue($thread->every(fn (EmailMailboxPlacement $placement): bool => $placement->email_conversation_id === null));

        EmailAccountUserGrant::query()
            ->where('email_account_id', $firstAccount->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        $component
            ->call('selectPlacement', $archived->id)
            ->assertDontSee('Selected account root')
            ->assertDontSee('Selected account archived context')
            ->assertDontSeeHtml('data-mail-conversation-child-placement-id="'.$archived->id.'"');
    }

    #[Test]
    public function long_legacy_thread_keeps_its_filtered_parent_expanded_after_selecting_context_child(): void
    {
        [$account, $inbox] = $this->mailbox('query-long-legacy@example.test');
        $archive = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);
        $rootId = '<long-legacy-root@example.test>';
        $filteredParent = $this->legacyPlacement(
            $account,
            $inbox,
            'Long legacy filtered parent',
            $rootId,
            null,
            now()->subHour(),
        );
        $newestContextChild = null;

        for ($index = 1; $index <= 55; $index++) {
            $newestContextChild = $this->legacyPlacement(
                $account,
                $archive,
                sprintf('Long legacy context %02d', $index),
                sprintf('<long-legacy-context-%02d@example.test>', $index),
                $rootId,
                now()->subMinutes(56 - $index),
            );
        }

        $this->assertInstanceOf(EmailMailboxPlacement::class, $newestContextChild);

        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'inbox')
            ->call('selectPlacement', $filteredParent->id)
            ->assertSeeHtml('data-mail-conversation-children="'.$filteredParent->id.'"')
            ->call('selectPlacement', $newestContextChild->id)
            ->assertSet('selectedPlacementId', $newestContextChild->id)
            ->assertSeeHtml('data-mail-conversation-children="'.$filteredParent->id.'"')
            ->assertSeeHtml('data-mail-conversation-child-placement-id="'.$newestContextChild->id.'"');

        $this->assertMatchesRegularExpression(
            '/id="mail-conversation-row-'.$filteredParent->id.'"[^>]*aria-expanded="true"[^>]*aria-controls="mail-conversation-children-'.$filteredParent->id.'"/s',
            $component->html(),
        );
    }

    #[Test]
    public function smart_inbox_personal_rule_prefill_reauthorizes_and_only_opens_the_review_form(): void
    {
        [$account, $inbox] = $this->mailbox('query-personal-rule@example.test', personal: true);
        $archive = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'Archive',
            'name' => 'Archive',
            'role' => EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);
        [$otherAccount, $otherInbox] = $this->mailbox('query-personal-rule-other@example.test', personal: true);
        $fixture = $this->durableConversation($account, $inbox, 'Personal cleanup source', now());
        $component = Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->call('selectPlacement', $fixture['placement']->id)
            ->dispatch(
                'smart-inbox-personal-rule-prefill',
                ruleName: 'Archive mail from customer@example.test',
                conditionField: 'from',
                conditionValue: 'customer@example.test',
                actionType: 'archive',
                targetFolderId: $archive->id,
            )
            ->assertSet('personalRuleModalOpen', true)
            ->assertSet('personalRuleName', 'Archive mail from customer@example.test')
            ->assertSet('personalRuleConditionField', 'from')
            ->assertSet('personalRuleConditionValue', 'customer@example.test')
            ->assertSet('personalRuleActionType', 'archive')
            ->assertSet('personalRuleTargetFolderId', $archive->id)
            ->assertSee('Review it before saving.');

        $this->assertSame(0, EmailRule::query()->count());

        $component
            ->dispatch(
                'smart-inbox-personal-rule-prefill',
                ruleName: 'Cross-account target',
                conditionField: 'from',
                conditionValue: 'customer@example.test',
                actionType: 'move_to_folder',
                targetFolderId: $otherInbox->id,
            )
            ->assertSet('personalRuleModalOpen', false)
            ->assertSee('The Smart Inbox personal rule draft is no longer available.');

        $account->update(['is_active' => false]);

        $component
            ->dispatch(
                'smart-inbox-personal-rule-prefill',
                ruleName: 'Revoked target',
                conditionField: 'from',
                conditionValue: 'customer@example.test',
                actionType: 'archive',
                targetFolderId: $archive->id,
            )
            ->assertSet('personalRuleModalOpen', false)
            ->assertSee('The Smart Inbox personal rule draft is no longer available.');

        $this->assertSame(0, EmailRule::query()->count());
        $this->assertNotSame($account->id, $otherAccount->id);
    }

    /** @return array{0: EmailAccount, 1: EmailFolder} */
    private function mailbox(string $address, bool $personal = false): array
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mail conversation query test account',
            'from_name' => 'Mail Query Test',
            'account_kind' => $personal ? EmailAccount::KIND_PERSONAL : EmailAccount::KIND_SHARED,
            'owner_id' => $personal ? $this->actor->id : null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'query-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'query-test-secret',
            'smtp_auth_type' => 'password',
        ]);
        $grant = EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $this->actor->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        app(EmailUnreadAccessEpochService::class)->reconcileAfterMutation(
            $account,
            $this->actor,
            false,
            $personal
                ? EmailUnreadAccessEpochService::SOURCE_PERSONAL_OWNER
                : EmailUnreadAccessEpochService::SOURCE_DIRECT_GRANT,
            $personal ? 'owner:'.$this->actor->id : 'grant:'.$grant->id,
            $this->actor,
        );
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => ++$this->nextUid,
        ]);

        return [$account, $folder];
    }

    /**
     * @return array{conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function durableConversation(
        EmailAccount $account,
        EmailFolder $folder,
        string $subject,
        Carbon $receivedAt,
        bool $providerSeen = false,
    ): array {
        $message = $this->message($account, $subject, $receivedAt);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:'.$message->id,
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => $providerSeen ? 0 : 1,
            'has_attachments' => false,
            'first_message_at' => $receivedAt,
            'last_message_at' => $receivedAt,
        ]);
        $placement = $this->placement($account, $folder, $message, $conversation, $providerSeen);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('conversation', 'message', 'placement');
    }

    private function appendDurableMessage(
        EmailConversation $conversation,
        EmailAccount $account,
        EmailFolder $folder,
        string $subject,
        Carbon $receivedAt,
        bool $providerSeen = false,
    ): EmailMailboxPlacement {
        $message = $this->message($account, $subject, $receivedAt);
        $placement = $this->placement($account, $folder, $message, $conversation, $providerSeen);
        $conversation->forceFill([
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $placement->id,
            'last_message_at' => $receivedAt,
            'message_count' => $conversation->message_count + 1,
            'active_placement_count' => $conversation->active_placement_count + 1,
            'provider_unread_count' => $conversation->provider_unread_count + ($providerSeen ? 0 : 1),
        ])->save();

        return $placement;
    }

    private function legacyPlacement(
        EmailAccount $account,
        EmailFolder $folder,
        string $subject,
        string $messageId,
        ?string $inReplyTo,
        Carbon $receivedAt,
    ): EmailMailboxPlacement {
        $message = $this->message($account, $subject, $receivedAt, $messageId, $inReplyTo);

        return $this->placement($account, $folder, $message, null, false);
    }

    private function message(
        EmailAccount $account,
        string $subject,
        Carbon $receivedAt,
        ?string $messageId = null,
        ?string $inReplyTo = null,
    ): EmailMessage {
        $uid = ++$this->nextUid;

        return EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => $messageId ?? '<query-'.$uid.'@example.test>',
            'in_reply_to' => $inReplyTo,
            'references' => $inReplyTo,
            'subject' => $subject,
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => $account->address]],
            'received_at' => $receivedAt,
            'state' => 'untriaged',
            'body_text' => 'Body for '.$subject,
            'checksum_sha1' => sha1($subject.'|'.$uid),
        ]);
    }

    private function placement(
        EmailAccount $account,
        EmailFolder $folder,
        EmailMessage $message,
        ?EmailConversation $conversation,
        bool $providerSeen,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation?->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => $providerSeen,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
    }
}
