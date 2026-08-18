<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailSmartInboxReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT_ALIAS = 'tech.mail.smart-inbox-review-queue';

    private User $actor;

    private User $otherUser;

    private AiAgent $agent;

    private int $nextUid = 12100;

    private int $suggestionSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Permission::findOrCreate('task.create', 'web');
        Role::create(['name' => 'Smart Inbox Review Tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Smart Inbox Review Tech');
        $this->otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherUser->assignRole('Smart Inbox Review Tech');
        $this->agent = $this->readyAgent();
    }

    #[Test]
    public function queue_is_user_and_account_scoped_and_revocation_is_durable_but_hidden(): void
    {
        $first = $this->conversationFixture($this->actor, 'review-private-a@example.test');
        $second = $this->conversationFixture($this->actor, 'review-private-b@example.test');
        $this->grantMailbox($first['account'], $this->otherUser, false);
        $own = $this->suggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $this->reviewProposal('Visible review for the selected mailbox.'),
        );
        $otherUserSuggestion = $this->suggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $this->reviewProposal('Other user private suggestion.'),
            $this->otherUser,
        );
        $otherAccountSuggestion = $this->suggestion(
            $second,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $this->reviewProposal('Other mailbox private suggestion.'),
        );

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($first))
            ->assertSee('Visible review for the selected mailbox.')
            ->assertDontSee('Other user private suggestion.')
            ->assertDontSee('Other mailbox private suggestion.')
            ->call('dismiss', $otherUserSuggestion->id)
            ->assertSet('feedbackMessage', null)
            ->assertDontSee('Smart Inbox action is unavailable.')
            ->assertDontSee('Other user private suggestion.');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $otherUserSuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $otherAccountSuggestion->fresh()->status);

        EmailAccountUserGrant::query()
            ->where('email_account_id', $first['account']->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($first))
            ->assertDontSee('Smart Inbox')
            ->assertDontSee('Select an accessible Mail conversation')
            ->assertDontSee('Visible review for the selected mailbox.')
            ->assertDontSee('Other user private suggestion.');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $own->fresh()->status);
        $this->assertSame(1, $own->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)
            ->count());
    }

    #[Test]
    public function viewing_refreshes_stale_state_without_rendering_raw_mail_or_provider_fields(): void
    {
        $fixture = $this->conversationFixture($this->actor, 'review-stale@example.test');
        EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'private-attachment-name.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 25,
            'disk' => 'local',
            'path' => 'email/private/private-attachment-name.txt',
        ]);
        $proposal = $this->reviewProposal('Safe bounded review text.');
        $proposal['raw_source'] = 'raw-source-must-stay-hidden';
        $proposal['provider_payload'] = 'provider-payload-must-stay-hidden';
        $proposal['attachment_name'] = 'private-attachment-name.txt';
        $proposal['html'] = '<script>raw-html-must-stay-hidden</script>';
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $proposal,
        );
        $this->suggestion(
            $fixture,
            'provider_payload_hidden_type',
            ['provider_payload' => 'unknown-effect-payload-must-stay-hidden'],
        );

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Safe bounded review text.')
            ->assertDontSee('Future Smart Inbox action')
            ->assertDontSee('provider_payload_hidden_type')
            ->assertDontSee('unknown-effect-payload-must-stay-hidden')
            ->assertDontSee('raw-source-must-stay-hidden')
            ->assertDontSee('provider-payload-must-stay-hidden')
            ->assertDontSee('private-attachment-name.txt')
            ->assertDontSee('raw-html-must-stay-hidden');

        $fixture['message']->forceFill([
            'body_text' => 'raw-body-after-change-must-stay-hidden',
            'body_html_sanitized' => '<p>raw-html-after-change-must-stay-hidden</p>',
            'checksum_sha1' => sha1('review-queue-stale-change'),
        ])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Safe bounded review text.')
            ->assertDontSee('Stale')
            ->assertDontSee('The conversation changed after analysis.')
            ->assertSee('No current review suggestions.')
            ->assertDontSee('raw-body-after-change-must-stay-hidden')
            ->assertDontSee('raw-html-after-change-must-stay-hidden');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $suggestion->fresh()->status);
        $this->assertSame(1, $suggestion->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_STALE)
            ->count());

        $this->agent->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Smart Inbox')
            ->assertDontSee('No current review suggestions.');
    }

    #[Test]
    public function pending_review_disappears_when_its_recorded_agent_is_inactive_or_replaced(): void
    {
        $fixture = $this->conversationFixture($this->actor, 'review-agent-lifecycle@example.test');
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $this->reviewProposal('Recorded-agent review must not outlive its agent.'),
        );

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Recorded-agent review must not outlive its agent.')
            ->assertSee('Dismiss Conversation review suggestion');

        $this->agent->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Smart Inbox')
            ->assertDontSee('Recorded-agent review must not outlive its agent.')
            ->assertDontSee('Dismiss Conversation review suggestion');

        $this->agent->forceFill([
            'is_active' => true,
            'is_default' => false,
            'default_domains' => [],
        ])->save();
        AiAgent::query()->create([
            'ai_provider_id' => $this->agent->ai_provider_id,
            'name' => 'Replacement Smart Inbox Review Agent',
            'slug' => 'replacement-smart-inbox-review-agent',
            'model' => $this->agent->model,
            'instructions' => 'Analyze new Mail selections only.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Analyze')
            ->assertSee('No current review suggestions.')
            ->assertDontSee('Recorded-agent review must not outlive its agent.')
            ->assertDontSee('Dismiss Conversation review suggestion')
            ->assertDontSee('1 to review');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
    }

    #[Test]
    public function applied_result_remains_visible_after_its_recorded_agent_is_disabled(): void
    {
        $this->agent->forceFill([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ])->save();
        $fixture = $this->conversationFixture(
            $this->actor,
            'review-applied-agent-history@example.test',
            true,
        );
        $category = $this->emailCategory('Durable applied history');
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id, 'category_name' => $category->name],
        );
        app(ApplyEmailSmartInboxSuggestion::class)->handle($suggestion, $this->actor);
        $this->agent->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Smart Inbox')
            ->assertSee('Durable applied history')
            ->assertSee('Conversation classification updated')
            ->assertDontSee('Analyze')
            ->assertDontSee('Apply Apply Email category suggestion')
            ->assertDontSee('Correct Apply Email category suggestion')
            ->assertDontSee('Dismiss Apply Email category suggestion');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $this->assertSame($category->id, EmailConversationClassification::query()->sole()->category_id);
    }

    #[Test]
    public function split_review_region_starts_collapsed_and_write_disabled_task_and_move_cards_are_not_rendered(): void
    {
        $this->actor->givePermissionTo('task.create');
        $fixture = $this->conversationFixture(
            $this->actor,
            'review-reader-first@example.test',
            true,
        );
        $targetFolder = EmailFolder::query()->create([
            'account_id' => $fixture['account']->id,
            'path' => 'Processed',
            'name' => 'Processed',
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1211,
        ]);
        $taskSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            [
                'title' => 'Unavailable task card must stay hidden',
                'source_message_id' => $fixture['message']->id,
            ],
        );
        $moveSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            [
                'target_folder_id' => $targetFolder->id,
                'target_folder_name' => $targetFolder->name,
                'target_folder_path' => $targetFolder->path,
                'source_message_id' => $fixture['message']->id,
                'source_placement_id' => $fixture['placement']->id,
                'source_folder_id' => $fixture['placement']->email_folder_id,
                'source_folder_path' => $fixture['placement']->folder_path,
                'source_imap_uid' => $fixture['placement']->imap_uid,
                'source_uid_validity' => $fixture['placement']->imap_uid_validity,
                'source_sync_version' => $fixture['placement']->sync_version,
            ],
        );

        $component = Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSet('reviewOpen', false)
            ->assertSeeHtml('data-smart-inbox-trigger')
            ->assertSeeHtml('data-smart-inbox-results')
            ->assertSeeHtml('data-smart-inbox-default-state="collapsed"')
            ->assertSee('No current review suggestions.')
            ->assertDontSee('Unavailable task card must stay hidden')
            ->assertDontSee('Move provider mail')
            ->assertDontSee('Processed')
            ->assertDontSee('Needs review');

        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*data-smart-inbox-trigger)(?=[^>]*aria-expanded="false")(?=[^>]*aria-controls="smart-inbox-review-results-\d+-\d+")[^>]*>/',
            $component->html(),
        );
        $this->assertMatchesRegularExpression(
            '/<section\b(?=[^>]*data-smart-inbox-results)(?=[^>]*\shidden(?:\s|>))[^>]*>/',
            $component->html(),
        );

        $component
            ->call('toggleReview')
            ->assertSet('reviewOpen', true);

        $this->assertMatchesRegularExpression(
            '/<button\b(?=[^>]*data-smart-inbox-trigger)(?=[^>]*aria-expanded="true")[^>]*>/',
            $component->html(),
        );
        $this->assertMatchesRegularExpression(
            '/<section\b(?=[^>]*data-smart-inbox-results)(?![^>]*\shidden(?:\s|>))[^>]*>/',
            $component->html(),
        );
        preg_match_all('/\bdata-smart-inbox-results\b/', $component->html(), $resultRegions);
        $this->assertCount(1, $resultRegions[0]);

        $component
            ->call('closeReview')
            ->assertSet('reviewOpen', false);

        $component
            ->call('apply', $taskSuggestion->id)
            ->assertSet('feedbackMessage', null)
            ->assertDontSee('Smart Inbox action is unavailable.')
            ->call('apply', $moveSuggestion->id)
            ->assertSet('feedbackMessage', null)
            ->assertDontSee('Smart Inbox action is unavailable.');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $taskSuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $moveSuggestion->fresh()->status);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function apply_is_rendered_only_for_the_recorded_current_agent_with_the_exact_named_scope(): void
    {
        $this->actor->givePermissionTo('task.create');
        $this->agent->forceFill([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['*'],
        ])->save();
        $fixture = $this->conversationFixture($this->actor, 'review-agent-eligibility@example.test');
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            [
                'title' => 'Review exact agent scope',
                'source_message_id' => $fixture['message']->id,
            ],
        );

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Review exact agent scope')
            ->assertDontSee('Apply Create Task suggestion');

        $this->agent->forceFill(['allowed_api_scopes' => ['tasks.create']])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Review exact agent scope')
            ->assertSee('Apply Create Task suggestion');

        $this->agent->forceFill([
            'is_default' => false,
            'default_domains' => [],
        ])->save();
        AiAgent::query()->create([
            'ai_provider_id' => $this->agent->ai_provider_id,
            'name' => 'Replacement Smart Inbox UI Agent',
            'slug' => 'replacement-smart-inbox-ui-agent',
            'model' => $this->agent->model,
            'instructions' => 'This agent did not produce the recorded suggestion.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => ['tasks.create'],
            'can_execute_actions' => true,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Review exact agent scope')
            ->assertDontSee('Apply Create Task suggestion');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function category_card_requires_current_mailbox_organize_access_and_an_active_target(): void
    {
        $this->agent->forceFill([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ])->save();
        $fixture = $this->conversationFixture($this->actor, 'review-current-target@example.test');
        $category = $this->emailCategory('Current active target');
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id, 'category_name' => $category->name],
        );

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Current active target');

        EmailAccountUserGrant::query()
            ->where('email_account_id', $fixture['account']->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => true]);

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Current active target')
            ->assertSee('Apply Apply Email category suggestion');

        $category->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Current active target')
            ->assertDontSee('Apply Apply Email category suggestion');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        $this->assertSame(0, EmailConversationClassification::query()->count());
    }

    #[Test]
    public function manual_analysis_is_read_only_and_displays_bounded_effect_and_provenance(): void
    {
        $fixture = $this->conversationFixture($this->actor, 'review-analyze@example.test');
        EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'confidential-customer-file.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 100,
            'disk' => 'local',
            'path' => 'email/private/confidential-customer-file.pdf',
        ]);
        $this->fakeSummary([
            'summary' => '<b>Review</b> confidential-customer-file.pdf safely.',
            'key_points' => ['No provider write is needed.'],
            'questions' => [],
            'action_items' => [[
                'text' => '<strong>Confirm the maintenance window</strong>',
                'owner' => 'Technician',
                'due_at' => 'Tomorrow',
                'source_message_id' => $fixture['message']->id,
                'provider_payload' => 'nested-provider-payload-must-stay-hidden',
            ]],
            'suggested_labels' => [],
            'urgency' => 'normal',
            'reply_needed' => true,
            'raw_prompt' => 'raw-prompt-must-stay-hidden',
            'provider_payload' => 'provider-payload-must-stay-hidden',
        ]);
        $before = $this->writeCounts();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->call('analyze')
            ->assertSee('Smart Inbox analysis completed.')
            ->assertSee('Conversation review')
            ->assertDontSee('Create Task')
            ->assertSee('Review [attachment omitted] safely.')
            ->assertDontSee('Confirm the maintenance window')
            ->assertSee('Smart Inbox Review Agent')
            ->assertSee('Read-only review.')
            ->assertDontSee('confidential-customer-file.pdf')
            ->assertDontSee('raw-prompt-must-stay-hidden')
            ->assertDontSee('provider-payload-must-stay-hidden')
            ->assertDontSee('<b>', false);

        $this->assertSame(2, EmailSmartInboxSuggestion::query()->count());
        $this->assertSame(2, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_GENERATED)
            ->count());
        $this->assertSame($before, $this->writeCounts());
        $this->assertFalse($fixture['placement']->fresh()->provider_seen);
        $this->assertFalse($fixture['placement']->fresh()->provider_flagged);
        Http::assertSentCount(1);
    }

    #[Test]
    public function dismiss_correct_and_allowlisted_apply_actions_return_clear_feedback(): void
    {
        $this->actor->givePermissionTo('task.create');
        $this->agent->forceFill([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update', 'tasks.create'],
        ])->save();
        $fixture = $this->conversationFixture($this->actor, 'review-actions@example.test', true);
        $category = $this->emailCategory('Reviewed Incident');
        $tag = $this->tag('Reviewed Follow-up');
        $review = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            $this->reviewProposal('Informational review only.'),
        );
        $task = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            [
                'title' => 'Original reviewed Task',
                'owner_hint' => 'Technician',
                'due_at_hint' => 'Tomorrow',
                'source_message_id' => $fixture['message']->id,
            ],
        );
        $categorySuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id, 'category_name' => $category->name],
        );
        $tagSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $tag->id, 'tag_name' => $tag->name],
        );

        $component = Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Apply Conversation review suggestion')
            ->call('apply', $review->id)
            ->assertSee('review-only and cannot be applied');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $review->fresh()->status);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, EmailConversationClassification::query()->count());

        $component
            ->call('dismiss', $review->id)
            ->assertSee('Smart Inbox suggestion dismissed.')
            ->call('beginCorrection', $task->id)
            ->assertSet('correctingSuggestionId', $task->id)
            ->set('correctionTaskTitle', 'Corrected reviewed Task')
            ->set('correctionOwnerHint', 'Operations')
            ->set('correctionDueHint', 'Next Monday')
            ->set('correctionExplanation', 'Human-reviewed Task correction.')
            ->set('correctionConfidence', '0.80')
            ->call('saveCorrection')
            ->assertSee('Smart Inbox correction saved for review.')
            ->call('apply', $categorySuggestion->id)
            ->assertSee('Conversation classification updated')
            ->call('apply', $tagSuggestion->id)
            ->assertSee('Conversation classification updated')
            ->call('apply', $task->id)
            ->assertSee('Internal Task created');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_DISMISSED, $review->fresh()->status);
        $this->assertSame('Corrected reviewed Task', $task->fresh()->proposal_json['title']);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $task->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $categorySuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $tagSuggestion->fresh()->status);
        $this->assertSame(1, $task->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_CORRECTED)
            ->count());
        $this->assertSame(1, $review->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_DISMISSED)
            ->count());

        $classification = EmailConversationClassification::query()->with('tags')->sole();
        $this->assertSame($category->id, $classification->category_id);
        $this->assertSame([$tag->id], $classification->tags->pluck('id')->all());
        $this->assertSame(1, Task::query()->count());
        $this->assertSame('Corrected reviewed Task', Task::query()->sole()->title);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
        $this->assertFalse($fixture['placement']->fresh()->provider_seen);
        $this->assertFalse($fixture['placement']->fresh()->provider_flagged);
    }

    /**
     * @return array{account: EmailAccount, folder: EmailFolder, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function conversationFixture(
        User $user,
        string $address,
        bool $canOrganize = false,
    ): array {
        $uid = ++$this->nextUid;
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Smart Inbox review queue test account',
            'from_name' => 'Smart Inbox Review',
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
            'imap_secret' => 'mailbox-secret-must-stay-hidden',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'smtp-secret-must-stay-hidden',
            'smtp_auth_type' => 'password',
        ]);
        $this->grantMailbox($account, $user, $canOrganize);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1210,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<smart-review-'.$uid.'@example.test>',
            'subject' => 'Smart Inbox review source',
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['name' => 'Support', 'email' => $address]],
            'cc_json' => [],
            'headers_json' => ['x-provider-secret' => 'provider-header-must-stay-hidden'],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'raw-mail-body-must-stay-hidden',
            'body_html_sanitized' => '<p>raw-mail-html-must-stay-hidden</p>',
            'raw_path' => 'email/raw/raw-source-must-stay-hidden.eml',
            'checksum_sha1' => sha1('smart-review-source-'.$uid),
            'attachments_count' => 0,
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:smart-review-'.$uid.'@example.test',
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 1,
            'has_attachments' => false,
            'first_message_at' => $message->received_at,
            'last_message_at' => $message->received_at,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1210,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'folder', 'conversation', 'message', 'placement');
    }

    private function grantMailbox(EmailAccount $account, User $user, bool $canOrganize): void
    {
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => $canOrganize,
            'can_send' => false,
            'granted_at' => now(),
        ]);
    }

    /**
     * @param  array{account: EmailAccount, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}  $fixture
     * @param  array<string, mixed>  $proposal
     */
    private function suggestion(
        array $fixture,
        string $effectType,
        array $proposal,
        ?User $user = null,
    ): EmailSmartInboxSuggestion {
        $user ??= $this->actor;
        $source = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);

        return EmailSmartInboxSuggestion::query()->create([
            'user_id' => $user->id,
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'selected_email_mailbox_placement_id' => $fixture['placement']->id,
            'effect_type' => $effectType,
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
            'explanation' => 'Governed Smart Inbox review evidence.',
            'confidence' => 0.8,
            'source_fingerprint' => $source['fingerprint'],
            'source_message_ids_json' => $source['source_message_ids'],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'smart-review-ui-'.++$this->suggestionSequence),
            'ai_agent_id' => $this->agent->id,
            'ai_model' => $this->agent->model,
            'ai_policy_revision' => 1,
            'generated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewProposal(string $summary): array
    {
        return [
            'summary' => $summary,
            'key_points' => [],
            'questions' => [],
            'urgency' => 'normal',
            'reply_needed' => true,
        ];
    }

    private function readyAgent(): AiAgent
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
            'name' => 'Smart Inbox Review AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://smart-inbox-review.test',
            'default_model' => 'smart-inbox-review-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Smart Inbox Review Agent',
            'slug' => 'smart-inbox-review-agent-'.Str::lower(Str::random(8)),
            'model' => 'smart-inbox-review-test',
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

    /** @param array<string, mixed> $summary */
    private function fakeSummary(array $summary): void
    {
        Http::fake([
            'http://smart-inbox-review.test/api/chat' => Http::response([
                'model' => 'smart-inbox-review-test',
                'message' => ['content' => json_encode($summary)],
            ]),
        ]);
    }

    private function emailCategory(string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
    }

    private function tag(string $name): Tag
    {
        return Tag::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'active' => true,
        ]);
    }

    /** @return array<string, int> */
    private function writeCounts(): array
    {
        return [
            'tasks' => Task::query()->count(),
            'tickets' => Ticket::query()->count(),
            'remote_operations' => EmailRemoteOperation::query()->count(),
            'classifications' => EmailConversationClassification::query()->count(),
            'categories' => Category::query()->count(),
            'tags' => Tag::query()->count(),
        ];
    }

    /**
     * @param  array{conversation: EmailConversation, placement: EmailMailboxPlacement}  $fixture
     * @return array{conversationId: int, selectedPlacementId: int}
     */
    private function componentProps(array $fixture): array
    {
        return [
            'conversationId' => (int) $fixture['conversation']->id,
            'selectedPlacementId' => (int) $fixture['placement']->id,
        ];
    }
}
