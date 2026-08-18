<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
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
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailSmartInboxSuggestionApplyTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $otherUser;

    private AiAgent $agent;

    private int $nextUid = 9100;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Permission::findOrCreate('task.create', 'web');
        Role::create(['name' => 'Smart Inbox Apply Tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Smart Inbox Apply Tech');
        $this->otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherUser->assignRole('Smart Inbox Apply Tech');
        $this->agent = $this->readyAgent();
    }

    #[Test]
    public function reviewed_category_and_tag_apply_additively_and_only_once(): void
    {
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $fixture = $this->conversationFixture($this->actor, 'apply-taxonomy@example.test', true);
        $category = $this->emailCategory('Smart Incident');
        $existingTag = $this->tag('Existing Context');
        $suggestedTag = $this->tag('AI Reviewed');
        $classification = EmailConversationClassification::query()->create([
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'assigned_by' => $this->actor->id,
            'assigned_at' => now(),
            'source' => EmailConversationClassification::SOURCE_MANUAL,
        ]);
        $classification->tags()->syncWithPivotValues([$existingTag->id], ['module' => 'email']);

        $before = $this->unrelatedWriteCounts();
        $categorySuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id, 'category_name' => $category->name],
        );
        $appliedCategory = app(ApplyEmailSmartInboxSuggestion::class)->handle(
            $categorySuggestion,
            $this->actor,
        );
        $again = app(ApplyEmailSmartInboxSuggestion::class)->handle(
            $appliedCategory,
            $this->actor,
        );

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $again->status);
        $this->assertSame(ApplyEmailSmartInboxSuggestion::REFERENCE_CONVERSATION_CLASSIFICATION, $again->applied_reference_type);
        $this->assertSame((string) $classification->id, $again->applied_reference_id);
        $this->assertSame($this->actor->id, $again->applied_by);
        $this->assertNotNull($again->applied_at);
        $this->assertSame(1, $again->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_APPLIED)->count());

        $classification->refresh()->load('tags');
        $this->assertSame($category->id, $classification->category_id);
        $this->assertSame([$existingTag->id], $classification->tags->pluck('id')->all());
        $this->assertSame(1, $this->classificationEventCount($classification));

        $tagSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $suggestedTag->id, 'tag_name' => $suggestedTag->name],
        );
        $appliedTag = app(ApplyEmailSmartInboxSuggestion::class)->handle($tagSuggestion, $this->actor);

        $classification->refresh()->load('tags');
        $this->assertSame($category->id, $classification->category_id);
        $this->assertSame(
            [$existingTag->id, $suggestedTag->id],
            $classification->tags->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame((string) $classification->id, $appliedTag->applied_reference_id);
        $this->assertSame(2, $this->classificationEventCount($classification));
        $this->assertSame($before, $this->unrelatedWriteCounts());
        $this->assertFalse($fixture['placement']->fresh()->provider_seen);
        $this->assertFalse($fixture['placement']->fresh()->provider_flagged);
    }

    #[Test]
    public function reviewed_task_uses_task_action_internal_context_and_ignores_speculative_hints(): void
    {
        $this->actor->givePermissionTo('task.create');
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['tasks.create'],
        ]);
        $fixture = $this->conversationFixture($this->actor, 'apply-task@example.test', false);
        $suggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            [
                'title' => 'Confirm the customer maintenance window',
                'owner_hint' => 'A different technician',
                'due_at_hint' => 'Tomorrow morning',
                'source_message_id' => $fixture['message']->id,
            ],
        );
        $before = $this->unrelatedWriteCounts();

        $applied = app(ApplyEmailSmartInboxSuggestion::class)->handle($suggestion, $this->actor);
        $again = app(ApplyEmailSmartInboxSuggestion::class)->handle($applied, $this->actor);

        $this->assertSame(1, Task::query()->count());
        $task = Task::query()->with('workContext')->firstOrFail();
        $this->assertSame('Confirm the customer maintenance window', $task->title);
        $this->assertSame($this->actor->getMorphClass(), $task->owner_type);
        $this->assertSame($this->actor->id, $task->owner_id);
        $this->assertSame($this->actor->id, $task->created_by);
        $this->assertNull($task->assigned_to);
        $this->assertNull($task->due_at);
        $this->assertSame(Task::VISIBILITY_INTERNAL, $task->visibility);
        $this->assertSame(WorkContextType::INTERNAL, $task->workContext?->type);
        $this->assertSame(ApplyEmailSmartInboxSuggestion::TASK_SOURCE_TYPE, $task->source_type);
        $this->assertSame($suggestion->id, $task->source_id);
        $this->assertSame($suggestion->id, $task->metadata['smart_inbox_suggestion_id']);
        $this->assertSame(ApplyEmailSmartInboxSuggestion::REFERENCE_TASK, $again->applied_reference_type);
        $this->assertSame((string) $task->id, $again->applied_reference_id);
        $this->assertSame(1, $again->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_APPLIED)->count());

        // The result is a normal editable Task, not an opaque AI-owned record.
        $task->forceFill(['title' => 'Confirmed maintenance window'])->save();
        $this->assertSame('Confirmed maintenance window', $task->fresh()->title);
        $this->assertSame($before, $this->unrelatedWriteCounts());
    }

    #[Test]
    public function category_compare_and_set_and_active_targets_fail_closed(): void
    {
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $fixture = $this->conversationFixture($this->actor, 'apply-cas@example.test', true);
        $currentCategory = $this->emailCategory('Human Selected');
        $suggestedCategory = $this->emailCategory('AI Alternative');
        $existingTag = $this->tag('Preserve Me');
        $classification = EmailConversationClassification::query()->create([
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'category_id' => $currentCategory->id,
            'assigned_by' => $this->actor->id,
            'assigned_at' => now(),
            'source' => EmailConversationClassification::SOURCE_MANUAL,
        ]);
        $classification->tags()->syncWithPivotValues([$existingTag->id], ['module' => 'email']);
        $categorySuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $suggestedCategory->id, 'category_name' => $suggestedCategory->name],
        );

        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($categorySuggestion, $this->actor));

        $classification->refresh()->load('tags');
        $this->assertSame($currentCategory->id, $classification->category_id);
        $this->assertSame([$existingTag->id], $classification->tags->pluck('id')->all());
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $categorySuggestion->fresh()->status);
        $this->assertSame(0, $this->classificationEventCount($classification));

        $inactiveTag = $this->tag('Deactivated Target');
        $tagSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $inactiveTag->id, 'tag_name' => $inactiveTag->name],
        );
        $inactiveTag->update(['active' => false]);

        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($tagSuggestion, $this->actor));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $tagSuggestion->fresh()->status);
        $this->assertSame([$existingTag->id], $classification->fresh()->tags()->pluck('tags.id')->all());
    }

    #[Test]
    public function agent_scope_user_permission_and_mailbox_grants_are_all_required(): void
    {
        $fixture = $this->conversationFixture($this->actor, 'apply-intersection@example.test', true);
        $tag = $this->tag('Guarded Tag');
        $tagSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $tag->id, 'tag_name' => $tag->name],
        );

        $this->agent->update([
            'can_execute_actions' => false,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($tagSuggestion, $this->actor));

        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['*'],
        ]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($tagSuggestion, $this->actor));

        $this->agent->update(['allowed_api_scopes' => ['tasks.create']]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($tagSuggestion, $this->actor));

        $taskSuggestion = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            ['title' => 'Permission-gated Task', 'source_message_id' => $fixture['message']->id],
        );
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($taskSuggestion, $this->actor));

        $this->agent->update(['allowed_api_scopes' => ['email.update']]);
        EmailAccountUserGrant::query()
            ->where('email_account_id', $fixture['account']->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => false]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($tagSuggestion, $this->actor));

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $tagSuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $taskSuggestion->fresh()->status);
        $this->assertSame(0, EmailConversationClassification::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function application_requires_the_same_provenance_agent_at_click_time(): void
    {
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $fixture = $this->conversationFixture($this->actor, 'apply-agent-provenance@example.test', true);
        $tag = $this->tag('Provenance Bound');
        $missingProvenance = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $tag->id, 'tag_name' => $tag->name],
        );
        $missingProvenance->forceFill(['ai_agent_id' => null])->save();

        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($missingProvenance, $this->actor));

        $switched = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
            ['tag_id' => $tag->id, 'tag_name' => $tag->name],
        );
        $this->agent->update([
            'is_default' => false,
            'default_domains' => [],
        ]);
        AiAgent::query()->create([
            'ai_provider_id' => $this->agent->ai_provider_id,
            'name' => 'Replacement Smart Inbox Agent',
            'slug' => 'replacement-smart-inbox-agent',
            'model' => $this->agent->model,
            'instructions' => 'This agent did not generate the existing proposal.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => ['email.update'],
            'can_execute_actions' => true,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);

        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($switched, $this->actor));

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $missingProvenance->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $switched->fresh()->status);
        $this->assertSame(0, EmailConversationClassification::query()->count());
        $this->assertSame(0, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_APPLIED)->count());
    }

    #[Test]
    public function terminal_review_only_stale_and_revoked_suggestions_cannot_write(): void
    {
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $fixture = $this->conversationFixture($this->actor, 'apply-state@example.test', true);
        $category = $this->emailCategory('State Guard');

        $review = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            ['summary' => 'Review only'],
        );
        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($review, $this->actor));

        $dismissed = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
            EmailSmartInboxSuggestion::STATUS_DISMISSED,
        );
        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($dismissed, $this->actor));

        $stale = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
        );
        $fixture['message']->update([
            'body_text' => 'The conversation changed before review.',
            'checksum_sha1' => sha1('changed-before-apply'),
        ]);
        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($stale, $this->actor));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $stale->fresh()->status);
        $this->assertSame(1, $stale->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_STALE)->count());

        $revoked = $this->suggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
        );
        EmailAccountUserGrant::query()
            ->where('email_account_id', $fixture['account']->id)
            ->where('user_id', $this->actor->id)
            ->delete();
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($revoked, $this->actor));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $revoked->fresh()->status);
        $this->assertSame(1, $revoked->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)->count());

        $inactiveAccountFixture = $this->conversationFixture(
            $this->actor,
            'apply-inactive-account@example.test',
            true,
        );
        $inactiveAccount = $this->suggestion(
            $inactiveAccountFixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
        );
        $inactiveAccountFixture['account']->update(['is_active' => false]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($inactiveAccount, $this->actor));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $inactiveAccount->fresh()->status);
        $this->assertSame(1, $inactiveAccount->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)->count());

        $disabledUserFixture = $this->conversationFixture(
            $this->otherUser,
            'apply-disabled-user@example.test',
            true,
        );
        $disabledUser = $this->suggestion(
            $disabledUserFixture,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
            EmailSmartInboxSuggestion::STATUS_PENDING,
            $this->otherUser,
        );
        $this->otherUser->update(['status' => User::STATUS_DISABLED]);
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($disabledUser, $this->otherUser));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $disabledUser->fresh()->status);

        $this->assertSame(0, EmailConversationClassification::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_APPLIED)->count());
    }

    #[Test]
    public function cross_user_cross_account_and_forged_placement_references_fail_closed(): void
    {
        $this->agent->update([
            'can_execute_actions' => true,
            'allowed_api_scopes' => ['email.update'],
        ]);
        $first = $this->conversationFixture($this->actor, 'apply-private-a@example.test', true);
        $second = $this->conversationFixture($this->otherUser, 'apply-private-b@example.test', true);
        $category = $this->emailCategory('Private Category');
        $suggestion = $this->suggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
        );

        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($suggestion, $this->otherUser));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);

        $inaccessible = $this->suggestion(
            $second,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
            EmailSmartInboxSuggestion::STATUS_PENDING,
            $this->actor,
        );
        $this->assertAuthorization(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($inaccessible, $this->actor));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $inaccessible->fresh()->status);

        $forgedPlacement = $this->suggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            ['category_id' => $category->id],
        );
        $forgedPlacement->forceFill([
            'selected_email_mailbox_placement_id' => $second['placement']->id,
        ])->save();
        $this->assertValidation(fn () => app(ApplyEmailSmartInboxSuggestion::class)
            ->handle($forgedPlacement, $this->actor));

        $this->assertSame(0, EmailConversationClassification::query()->count());
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $forgedPlacement->fresh()->status);
    }

    /**
     * @return array{account: EmailAccount, folder: EmailFolder, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function conversationFixture(
        User $user,
        string $address,
        bool $canOrganize,
    ): array {
        $uid = ++$this->nextUid;
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Smart Inbox apply test account',
            'from_name' => 'Smart Inbox Apply',
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
            'imap_secret' => 'test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'test-secret',
            'smtp_auth_type' => 'password',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => $canOrganize,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 910,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<apply-'.$uid.'@example.test>',
            'subject' => 'Smart Inbox apply source',
            'from_email' => 'customer@example.test',
            'to_json' => [['name' => 'Support', 'email' => $address]],
            'cc_json' => [],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please review this request before applying a suggestion.',
            'checksum_sha1' => sha1('apply-source-'.$uid),
            'attachments_count' => 0,
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:apply-'.$uid.'@example.test',
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
            'imap_uid_validity' => 910,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'folder', 'conversation', 'message', 'placement');
    }

    /**
     * @param  array{account: EmailAccount, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}  $fixture
     * @param  array<string, mixed>  $proposal
     */
    private function suggestion(
        array $fixture,
        string $effectType,
        array $proposal,
        string $status = EmailSmartInboxSuggestion::STATUS_PENDING,
        ?User $user = null,
    ): EmailSmartInboxSuggestion {
        $user ??= $this->actor;
        $source = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);
        $proposalFingerprint = app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal);

        return EmailSmartInboxSuggestion::query()->create([
            'user_id' => $user->id,
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'selected_email_mailbox_placement_id' => $fixture['placement']->id,
            'effect_type' => $effectType,
            'proposal_json' => $proposal,
            'proposal_fingerprint' => $proposalFingerprint,
            'source_fingerprint' => $source['fingerprint'],
            'source_message_ids_json' => $source['source_message_ids'],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => $status,
            'idempotency_key' => hash('sha256', uniqid('smart-apply-', true)),
            'ai_agent_id' => $this->agent->id,
            'generated_at' => now(),
        ]);
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
            'name' => 'Smart Inbox Apply AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://smart-inbox-apply.test',
            'default_model' => 'smart-inbox-apply-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Smart Inbox Apply Agent',
            'slug' => 'smart-inbox-apply-agent',
            'model' => 'smart-inbox-apply-test',
            'instructions' => 'Propose only reviewed Mail actions.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
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
    private function unrelatedWriteCounts(): array
    {
        return [
            'remote_operations' => EmailRemoteOperation::query()->count(),
            'tickets' => Ticket::query()->count(),
            'rules' => EmailRule::query()->count(),
        ];
    }

    private function classificationEventCount(EmailConversationClassification $classification): int
    {
        return (int) \DB::table('email_conversation_classification_events')
            ->where('email_conversation_classification_id', $classification->id)
            ->count();
    }

    private function assertAuthorization(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the reviewed action to fail authorization.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function assertValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the reviewed action to fail validation.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
