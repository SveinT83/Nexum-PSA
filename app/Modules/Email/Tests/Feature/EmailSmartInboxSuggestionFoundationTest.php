<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\AnalyzeEmailConversationForSmartInbox;
use App\Modules\Email\Actions\CorrectEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\DismissEmailSmartInboxSuggestion;
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
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Task\Models\Task as TaskRecord;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailSmartInboxSuggestionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $otherUser;

    private User $policyReviewer;

    private int $nextUid = 8100;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Smart Inbox Tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Smart Inbox Tech');
        $this->otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherUser->assignRole('Smart Inbox Tech');
        $this->policyReviewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    #[Test]
    public function governed_analysis_persists_only_typed_normalized_idempotent_suggestions(): void
    {
        $agent = $this->readyAgent();
        $category = Category::query()->create([
            'name' => 'Incident Review',
            'slug' => 'incident-review',
            'type' => Category::TYPE_EMAIL,
            'description' => 'Existing Email category.',
            'is_active' => true,
        ]);
        Category::query()->create([
            'name' => 'Incident Review',
            'slug' => 'incident-review-ticket',
            'type' => Category::TYPE_TICKET,
            'is_active' => true,
        ]);
        $tag = Tag::query()->create([
            'name' => 'Follow Up',
            'slug' => 'follow-up',
            'active' => true,
        ]);
        Tag::query()->create([
            'name' => 'Inactive Label',
            'slug' => 'inactive-label',
            'active' => false,
        ]);

        $fixture = $this->conversationFixture($this->actor, 'smart-normalized@example.test');
        EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'customer-secret-evidence.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 1234,
            'disk' => 'local',
            'path' => 'email/private/customer-secret-evidence.pdf',
        ]);

        $response = [
            'summary' => '<b>Review</b> customer-secret-evidence.pdf before replying.',
            'key_points' => ['<i>Customer needs help</i>', 'Keep the provider state unchanged.'],
            'questions' => ['Who owns the follow-up?'],
            'action_items' => [[
                'text' => '<strong>Prepare an incident checklist</strong>',
                'owner' => 'Nexum technician',
                'due_at' => 'Tomorrow',
                'source_message_id' => $fixture['message']->id,
                'raw_provider_payload' => 'must-never-persist',
            ]],
            'suggested_labels' => [
                [
                    'type' => 'category',
                    'label' => 'Incident Review',
                    'reason' => '<em>Existing category fits.</em>',
                    'confidence' => 0.92,
                    'source_message_id' => $fixture['message']->id,
                ],
                [
                    'type' => 'tag',
                    'label' => 'Follow Up',
                    'reason' => 'Requires technician follow-up.',
                    'confidence' => 5,
                    'source_message_id' => $fixture['message']->id,
                ],
                ['type' => 'tag', 'label' => 'AI Must Not Create This', 'confidence' => 1],
                ['type' => 'category', 'label' => 'Unknown Email Category', 'confidence' => 1],
                ['type' => 'tag', 'label' => 'Inactive Label', 'confidence' => 1],
            ],
            'urgency' => 'high',
            'reply_needed' => true,
            'provenance' => [
                'source_message_ids' => [$fixture['message']->id],
                'limitations' => ['Attachments and raw source were excluded.'],
            ],
            'raw_prompt' => 'raw-prompt-must-never-persist',
            'raw_response' => '<html>raw-response-must-never-persist</html>',
            'provider_payload' => ['secret' => 'provider-payload-must-never-persist'],
            'attachment_names' => ['customer-secret-evidence.pdf'],
        ];
        $this->fakeSummary($response);

        $domainCounts = $this->nonSuggestionDomainCounts();
        $suggestions = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        );

        $this->assertCount(4, $suggestions);
        $this->assertSame([
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
        ], $suggestions->pluck('effect_type')->all());

        $review = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY);
        $task = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_CREATE_TASK);
        $categorySuggestion = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY);
        $tagSuggestion = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_APPLY_TAG);

        $this->assertSame('Review [attachment omitted] before replying.', $review->proposal_json['summary']);
        $this->assertSame('Prepare an incident checklist', $task->proposal_json['title']);
        $this->assertSame($category->id, $categorySuggestion->proposal_json['category_id']);
        $this->assertSame($tag->id, $tagSuggestion->proposal_json['tag_id']);
        $this->assertSame(1.0, $tagSuggestion->confidence);
        $this->assertSame([$fixture['message']->id], $review->source_message_ids_json);
        $this->assertSame($agent->id, $review->ai_agent_id);
        $this->assertSame($agent->ai_provider_id, $review->ai_provider_id);
        $this->assertNotNull($review->ai_execution_id);
        $this->assertSame(EmailSmartInboxSuggestion::SCHEMA_VERSION, $review->schema_version);
        $this->assertSame(4, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_GENERATED)->count());

        $durableJson = DB::table('email_smart_inbox_suggestions')->get()->toJson()
            .DB::table('email_smart_inbox_suggestion_events')->get()->toJson();

        foreach ([
            'customer-secret-evidence.pdf',
            'raw-prompt-must-never-persist',
            'raw-response-must-never-persist',
            'provider-payload-must-never-persist',
            'must-never-persist',
            'email/private/customer-secret-evidence.pdf',
            '<b>',
            '<html>',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $durableJson);
        }

        $this->assertSame($domainCounts, $this->nonSuggestionDomainCounts());
        $this->assertFalse($fixture['placement']->fresh()->provider_seen);
        $this->assertFalse($fixture['placement']->fresh()->provider_flagged);
        $this->assertSame(2, Category::query()->where('name', 'Incident Review')->count());
        $this->assertFalse(Tag::query()->where('name', 'AI Must Not Create This')->exists());
        Http::assertSentCount(1);

        $rerun = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        );

        $this->assertSame($suggestions->pluck('id')->all(), $rerun->pluck('id')->all());
        $this->assertSame(4, EmailSmartInboxSuggestion::query()->count());
        $this->assertSame(4, EmailSmartInboxSuggestionEvent::query()->count());
        Http::assertSentCount(2);
    }

    #[Test]
    public function analysis_and_existing_suggestions_are_exactly_user_and_account_scoped(): void
    {
        $this->readyAgent();
        $this->fakeSummary($this->minimalSummary());
        $first = $this->conversationFixture($this->actor, 'smart-private-a@example.test');
        $second = $this->conversationFixture($this->otherUser, 'smart-private-b@example.test');

        $this->assertThrowsAuthorization(function () use ($first): void {
            app(AnalyzeEmailConversationForSmartInbox::class)->handle(
                $first['conversation'],
                $first['placement'],
                $this->otherUser,
            );
        });
        $this->assertSame(0, EmailSmartInboxSuggestion::query()->count());

        try {
            app(AnalyzeEmailConversationForSmartInbox::class)->handle(
                $first['conversation'],
                $second['placement'],
                $this->otherUser,
            );
            $this->fail('A placement from another account must not be analyzed through this conversation.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $suggestion = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $first['conversation'],
            $first['placement'],
            $this->actor,
        )->firstOrFail();

        $this->assertSame($this->actor->id, $suggestion->user_id);
        $this->assertSame($first['account']->id, $suggestion->account_id);
        $this->assertSame($first['conversation']->id, $suggestion->email_conversation_id);
        $this->assertSame($first['placement']->id, $suggestion->selected_email_mailbox_placement_id);

        $this->assertThrowsAuthorization(function () use ($suggestion): void {
            app(EmailSmartInboxSuggestionStateService::class)->refresh($suggestion, $this->otherUser);
        });
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
    }

    #[Test]
    public function missing_ai_governance_fails_closed_without_persisting_suggestions(): void
    {
        Http::fake();
        $fixture = $this->conversationFixture($this->actor, 'smart-policy-denied@example.test');

        try {
            app(AnalyzeEmailConversationForSmartInbox::class)->handle(
                $fixture['conversation'],
                $fixture['placement'],
                $this->actor,
            );
            $this->fail('Smart Inbox analysis must fail when no governed agent is available.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mail AI is not available', $exception->getMessage());
        }

        $this->assertSame(0, EmailSmartInboxSuggestion::query()->count());
        $this->assertSame(0, EmailSmartInboxSuggestionEvent::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function provider_failures_are_sanitized_before_reaching_the_user(): void
    {
        $this->readyAgent();
        $fixture = $this->conversationFixture($this->actor, 'smart-provider-failure@example.test');
        $providerSecret = 'provider-response-secret-7f4e6d';
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => $providerSecret],
            ], 500),
        ]);

        try {
            app(AnalyzeEmailConversationForSmartInbox::class)->handle(
                $fixture['conversation'],
                $fixture['placement'],
                $this->actor,
            );
            $this->fail('A provider failure must stop Smart Inbox analysis.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mail AI provider request failed', $exception->getMessage());
            $this->assertStringNotContainsString($providerSecret, $exception->getMessage());
        }

        $this->assertSame(0, EmailSmartInboxSuggestion::query()->count());
        $this->assertSame(0, EmailSmartInboxSuggestionEvent::query()->count());
    }

    #[Test]
    public function analysis_reauthorizes_after_the_provider_call_before_persisting(): void
    {
        $this->readyAgent();
        $fixture = $this->conversationFixture($this->actor, 'smart-analysis-race@example.test');
        $summary = $this->minimalSummary();
        Http::fake(function () use ($fixture, $summary) {
            EmailAccountUserGrant::query()
                ->where('email_account_id', $fixture['account']->id)
                ->where('user_id', $this->actor->id)
                ->delete();

            return Http::response([
                'model' => 'smart-inbox-test',
                'message' => ['content' => json_encode($summary)],
            ], 200);
        });

        $this->assertThrowsAuthorization(function () use ($fixture): void {
            app(AnalyzeEmailConversationForSmartInbox::class)->handle(
                $fixture['conversation'],
                $fixture['placement'],
                $this->actor,
            );
        });

        $this->assertSame(0, EmailSmartInboxSuggestion::query()->count());
        $this->assertSame(0, EmailSmartInboxSuggestionEvent::query()->count());
    }

    #[Test]
    public function conversation_changes_mark_pending_suggestions_stale_and_block_actions(): void
    {
        $this->readyAgent();
        $this->fakeSummary($this->minimalSummary());
        $fixture = $this->conversationFixture($this->actor, 'smart-stale@example.test');
        $suggestion = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        )->firstOrFail();

        $fixture['message']->forceFill([
            'body_text' => 'The conversation changed after the suggestion was generated.',
            'checksum_sha1' => sha1('changed-conversation-content'),
        ])->save();

        $refreshed = app(EmailSmartInboxSuggestionStateService::class)->refresh($suggestion, $this->actor);

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $refreshed->status);
        $this->assertNotNull($refreshed->stale_at);
        $this->assertSame(1, $refreshed->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_STALE)->count());

        app(EmailSmartInboxSuggestionStateService::class)->refresh($refreshed, $this->actor);
        $this->assertSame(1, $refreshed->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_STALE)->count());

        try {
            app(DismissEmailSmartInboxSuggestion::class)->handle($refreshed, $this->actor);
            $this->fail('A stale suggestion must not be dismissible as if it were current.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $refreshed->fresh()->status);
    }

    #[Test]
    public function mailbox_revocation_is_durable_and_fails_closed_for_later_actions(): void
    {
        $this->readyAgent();
        $this->fakeSummary($this->minimalSummary());
        $fixture = $this->conversationFixture($this->actor, 'smart-revoked@example.test');
        $suggestion = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        )->firstOrFail();

        EmailAccountUserGrant::query()
            ->where('email_account_id', $fixture['account']->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        $revoked = app(EmailSmartInboxSuggestionStateService::class)->refresh($suggestion, $this->actor);

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_REVOKED, $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(1, $revoked->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)->count());

        $this->assertThrowsAuthorization(function () use ($revoked): void {
            app(DismissEmailSmartInboxSuggestion::class)->handle($revoked, $this->actor);
        });
        $this->assertSame(1, $revoked->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)->count());
    }

    #[Test]
    public function corrections_and_dismissals_are_idempotent_and_append_immutable_events(): void
    {
        $this->readyAgent();
        $fixture = $this->conversationFixture($this->actor, 'smart-feedback@example.test');
        EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'never-store-this-name.txt',
            'content_type' => 'text/plain',
            'size_bytes' => 50,
            'disk' => 'local',
            'path' => 'email/private/never-store-this-name.txt',
        ]);
        $summary = $this->minimalSummary();
        $summary['action_items'] = [[
            'text' => 'Original task title',
            'owner' => 'Tech',
            'due_at' => null,
            'source_message_id' => $fixture['message']->id,
        ]];
        $this->fakeSummary($summary);

        $task = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        )->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_CREATE_TASK);

        $corrected = app(CorrectEmailSmartInboxSuggestion::class)->handle(
            $task,
            $this->actor,
            [
                'title' => '<b>Corrected task title</b>',
                'owner_hint' => 'Operations',
                'due_at_hint' => 'Next Monday',
                'source_message_id' => $fixture['message']->id,
                'provider_payload' => 'must-not-be-retained',
            ],
            'Reviewed without never-store-this-name.txt or provider material.',
            4,
        );
        app(CorrectEmailSmartInboxSuggestion::class)->handle(
            $corrected,
            $this->actor,
            [
                'title' => '<b>Corrected task title</b>',
                'owner_hint' => 'Operations',
                'due_at_hint' => 'Next Monday',
                'source_message_id' => $fixture['message']->id,
                'provider_payload' => 'must-not-be-retained',
            ],
            'Reviewed without never-store-this-name.txt or provider material.',
            4,
        );

        $corrected->refresh();
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $corrected->status);
        $this->assertSame('Corrected task title', $corrected->proposal_json['title']);
        $this->assertArrayNotHasKey('provider_payload', $corrected->proposal_json);
        $this->assertStringNotContainsString('never-store-this-name.txt', (string) $corrected->explanation);
        $this->assertSame(1.0, $corrected->confidence);
        $this->assertSame($this->actor->id, $corrected->corrected_by);
        $this->assertNotNull($corrected->corrected_at);
        $this->assertSame(1, $corrected->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_CORRECTED)->count());

        $dismissed = app(DismissEmailSmartInboxSuggestion::class)->handle($corrected, $this->actor);
        app(DismissEmailSmartInboxSuggestion::class)->handle($dismissed, $this->actor);

        $dismissed->refresh();
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_DISMISSED, $dismissed->status);
        $this->assertSame($this->actor->id, $dismissed->dismissed_by);
        $this->assertSame(1, $dismissed->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_DISMISSED)->count());

        $event = $dismissed->events()->firstOrFail();

        try {
            $event->forceFill(['reason_code' => 'tampered'])->save();
            $this->fail('Suggestion events must be immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        try {
            $event->delete();
            $this->fail('Suggestion events must not be deletable through the model.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function api_queue_is_token_user_account_and_current_access_scoped(): void
    {
        $this->readyAgent();
        $this->fakeSummary($this->minimalSummary());
        $visible = $this->conversationFixture($this->actor, 'smart-api-visible@example.test');
        $private = $this->conversationFixture($this->otherUser, 'smart-api-private@example.test');
        $privateSuggestion = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $private['conversation'],
            $private['placement'],
            $this->otherUser,
        )->firstOrFail();

        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);

        $analysis = $this->postJson(
            route('api.v1.email.mailbox.conversations.smart-inbox.analyze', $visible['conversation']),
            ['placement_id' => $visible['placement']->id],
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_id', $visible['account']->id)
            ->assertJsonPath('data.0.status', EmailSmartInboxSuggestion::STATUS_PENDING)
            ->assertJsonPath('data.0.effect_type', EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY);
        $suggestionId = (int) $analysis->json('data.0.id');

        $this->getJson(route('api.v1.email.smart-inbox.suggestions.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suggestionId);
        $this->getJson(route('api.v1.email.smart-inbox.suggestions.count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.total', 1);
        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $suggestionId))
            ->assertOk()
            ->assertJsonPath('data.events.0.event_type', EmailSmartInboxSuggestionEvent::TYPE_GENERATED);
        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $privateSuggestion->id))
            ->assertNotFound();

        $this->patchJson(route('api.v1.email.smart-inbox.suggestions.correct', $suggestionId), [
            'proposal' => [
                'summary' => '<b>Human-reviewed summary</b>',
                'key_points' => ['Keep the review explicit.'],
                'questions' => [],
                'urgency' => 'normal',
                'reply_needed' => false,
            ],
            'explanation' => 'Reviewed by the technician.',
        ])
            ->assertOk()
            ->assertJsonPath('data.proposal.summary', 'Human-reviewed summary');

        Sanctum::actingAs($this->actor, ['email.read']);
        $this->postJson(route('api.v1.email.smart-inbox.suggestions.dismiss', $suggestionId))
            ->assertForbidden();

        Sanctum::actingAs($this->actor, ['email.read', 'email.update']);
        $this->postJson(route('api.v1.email.smart-inbox.suggestions.dismiss', $suggestionId))
            ->assertOk()
            ->assertJsonPath('data.status', EmailSmartInboxSuggestion::STATUS_DISMISSED);
        $this->postJson(route('api.v1.email.smart-inbox.suggestions.dismiss', $suggestionId))
            ->assertOk()
            ->assertJsonPath('data.status', EmailSmartInboxSuggestion::STATUS_DISMISSED);

        EmailAccountUserGrant::query()
            ->where('email_account_id', $visible['account']->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $suggestionId))
            ->assertNotFound();
        $this->assertDatabaseHas('email_smart_inbox_suggestions', [
            'id' => $suggestionId,
            'status' => EmailSmartInboxSuggestion::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('email_smart_inbox_suggestion_events', [
            'email_smart_inbox_suggestion_id' => $suggestionId,
            'event_type' => EmailSmartInboxSuggestionEvent::TYPE_REVOKED,
        ]);
    }

    #[Test]
    public function api_hides_terminal_suggestions_after_grant_revocation_or_account_deactivation(): void
    {
        $this->readyAgent();
        $this->fakeSummary($this->minimalSummary());
        $grantFixture = $this->conversationFixture($this->actor, 'smart-applied-revoked@example.test');
        $inactiveFixture = $this->conversationFixture($this->actor, 'smart-inactive-account@example.test');
        $applied = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $grantFixture['conversation'],
            $grantFixture['placement'],
            $this->actor,
        )->firstOrFail();
        $inactive = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $inactiveFixture['conversation'],
            $inactiveFixture['placement'],
            $this->actor,
        )->firstOrFail();
        $applied->forceFill([
            'status' => EmailSmartInboxSuggestion::STATUS_APPLIED,
            'applied_by' => $this->actor->id,
            'applied_at' => now(),
            'applied_reference_type' => 'test_reference',
            'applied_reference_id' => '1',
        ])->save();

        Sanctum::actingAs($this->actor, ['email.read']);
        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $applied->id))
            ->assertOk()
            ->assertJsonPath('data.status', EmailSmartInboxSuggestion::STATUS_APPLIED);

        EmailAccountUserGrant::query()
            ->where('email_account_id', $grantFixture['account']->id)
            ->where('user_id', $this->actor->id)
            ->delete();
        $inactiveFixture['account']->update(['is_active' => false]);

        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $applied->id))
            ->assertNotFound();
        $this->getJson(route('api.v1.email.smart-inbox.suggestions.show', $inactive->id))
            ->assertNotFound();

        $this->assertDatabaseHas('email_smart_inbox_suggestions', [
            'id' => $applied->id,
            'status' => EmailSmartInboxSuggestion::STATUS_REVOKED,
        ]);
        $this->assertDatabaseHas('email_smart_inbox_suggestions', [
            'id' => $inactive->id,
            'status' => EmailSmartInboxSuggestion::STATUS_REVOKED,
        ]);
        $this->assertSame(2, EmailSmartInboxSuggestionEvent::query()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_REVOKED)
            ->whereIn('email_smart_inbox_suggestion_id', [$applied->id, $inactive->id])
            ->count());
    }

    /**
     * @return array{account: EmailAccount, folder: EmailFolder, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function conversationFixture(User $user, string $address): array
    {
        $uid = ++$this->nextUid;
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Smart Inbox test account',
            'from_name' => 'Smart Inbox',
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
            'imap_secret' => 'mailbox-credential-must-never-persist',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'smtp-credential-must-never-persist',
            'smtp_auth_type' => 'password',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
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
            'uid_validity' => 810,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<smart-'.$uid.'@example.test>',
            'subject' => 'Smart Inbox source conversation',
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['name' => 'Support', 'email' => $address]],
            'cc_json' => [],
            'headers_json' => [
                'x-provider-payload' => 'provider-header-must-never-persist',
                'authentication-results' => 'private-provider-evidence',
            ],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please review this customer request and identify the next action.',
            'body_html_sanitized' => '<p>Please review this customer request.</p>',
            'raw_path' => 'email/raw/source-must-never-persist.eml',
            'checksum_sha1' => sha1('smart-source-'.$uid),
            'attachments_count' => 0,
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:smart-'.$uid.'@example.test',
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
            'metadata' => ['source' => 'test'],
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 810,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();

        return compact('account', 'folder', 'conversation', 'message', 'placement');
    }

    private function readyAgent(): AiAgent
    {
        $policy = AiDataEgressPolicy::installation();
        $policy->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'full_context',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->policyReviewer->id,
            'reviewed_at' => now(),
            'updated_by' => $this->policyReviewer->id,
        ]);
        $provider = AiProvider::query()->create([
            'name' => 'Smart Inbox Local AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://smart-inbox-ai.test',
            'default_model' => 'smart-inbox-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Smart Inbox Default Agent',
            'slug' => 'smart-inbox-default-'.Str::lower(Str::random(8)),
            'model' => 'smart-inbox-test',
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
            'http://smart-inbox-ai.test/api/chat' => Http::response([
                'model' => 'smart-inbox-test',
                'message' => ['content' => json_encode($summary)],
            ], 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function minimalSummary(): array
    {
        return [
            'summary' => 'The customer request needs review.',
            'key_points' => ['A technician should inspect the request.'],
            'questions' => [],
            'action_items' => [],
            'suggested_labels' => [],
            'urgency' => 'normal',
            'reply_needed' => true,
            'provenance' => [
                'source_message_ids' => [],
                'limitations' => ['Attachments and raw source were excluded.'],
            ],
        ];
    }

    /** @return array<string, int> */
    private function nonSuggestionDomainCounts(): array
    {
        return [
            'tasks' => TaskRecord::query()->count(),
            'tickets' => Ticket::query()->count(),
            'remote_operations' => EmailRemoteOperation::query()->count(),
            'conversation_classifications' => EmailConversationClassification::query()->count(),
            'categories' => Category::query()->count(),
            'tags' => Tag::query()->count(),
        ];
    }

    private function assertThrowsAuthorization(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the operation to fail with mailbox authorization.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }
}
