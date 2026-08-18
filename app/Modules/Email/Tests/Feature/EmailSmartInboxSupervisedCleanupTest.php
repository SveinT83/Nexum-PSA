<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\AnalyzeEmailConversationForSmartInbox;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestionBatch;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailSmartInboxCleanupOperation;
use App\Modules\Email\Actions\UndoEmailRemoteOperation;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailRuleVersion;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailConversationFingerprint;
use App\Modules\Email\Services\EmailRemoteOperationUndoEligibility;
use App\Modules\Email\Services\EmailRulePublisher;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEligibility;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\InboundEmailRuleEngine;
use App\Modules\Email\Services\InboundEmailSignalClassifier;
use App\Modules\Email\Services\PersonalEmailRuleEngine;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Signal\Models\Signal;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailSmartInboxSupervisedCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT_ALIAS = 'tech.mail.smart-inbox-review-queue';

    private User $actor;

    private User $otherUser;

    private AiAgent $agent;

    private int $nextUid = 9500;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('email.inbox_manage', 'web');
        Permission::findOrCreate('email.rule_manage', 'web');
        Role::findOrCreate('Admin', 'web');

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Admin');
        $this->actor->givePermissionTo([
            'email.inbox_view',
            'email.inbox_manage',
            'email.rule_manage',
        ]);
        $this->otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherUser->givePermissionTo(['email.inbox_view', 'email.inbox_manage']);
        $this->agent = $this->readyAgent();
    }

    #[Test]
    public function governed_generation_accepts_only_existing_same_account_cleanup_targets_without_writes_or_private_payloads(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-generate@example.test');
        $otherConversationMessage = $this->additionalConversationMessage($fixture);
        $foreign = $this->mailboxFixture($this->otherUser, 'cleanup-foreign@example.test');
        EmailAttachment::query()->create([
            'message_id' => $fixture['message']->id,
            'filename' => 'private-cleanup-evidence.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 400,
            'disk' => 'local',
            'path' => 'email/private/private-cleanup-evidence.pdf',
        ]);
        $summary = $this->minimalSummary();
        $summary['cleanup_suggestions'] = [
            [
                'type' => 'archive',
                'target_folder_id' => $fixture['archive']->id,
                'reason' => '<b>Archive private-cleanup-evidence.pdf after review.</b>',
                'confidence' => 0.91,
                'source_message_id' => $fixture['message']->id,
                'provider_payload' => 'must-not-persist',
            ],
            [
                'type' => 'move',
                'target_folder_id' => $fixture['custom']->id,
                'reason' => 'Move to the existing Processed folder.',
                'confidence' => 0.8,
                'source_message_id' => $fixture['message']->id,
            ],
            [
                'type' => 'move',
                'target_folder_id' => $fixture['custom']->id,
                'reason' => 'wrong-selected-source',
                'source_message_id' => $otherConversationMessage['message']->id,
            ],
            ['type' => 'move', 'target_folder_id' => $foreign['custom']->id, 'reason' => 'cross account'],
            ['type' => 'move', 'target_folder_id' => 999999, 'reason' => 'invented folder'],
            ['type' => 'delete_permanently', 'target_folder_id' => $fixture['archive']->id],
        ];
        Http::fake([
            'http://smart-inbox-cleanup-ai.test/api/chat' => Http::response([
                'model' => 'smart-inbox-cleanup-test',
                'message' => ['content' => json_encode($summary)],
            ]),
        ]);

        $suggestions = app(AnalyzeEmailConversationForSmartInbox::class)->handle(
            $fixture['conversation'],
            $fixture['placement'],
            $this->actor,
        );

        $this->assertSame([
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
        ], $suggestions->pluck('effect_type')->all());
        $archive = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL);
        $move = $suggestions->firstWhere('effect_type', EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER);
        $this->assertSame([
            'target_folder_id' => $fixture['archive']->id,
            'target_folder_name' => 'Archive',
            'target_folder_path' => 'Archive',
            'source_message_id' => $fixture['message']->id,
            'source_placement_id' => $fixture['placement']->id,
            'source_folder_id' => $fixture['folder']->id,
            'source_folder_path' => 'INBOX',
            'source_imap_uid' => $fixture['placement']->imap_uid,
            'source_uid_validity' => $fixture['placement']->imap_uid_validity,
            'source_sync_version' => $fixture['placement']->sync_version,
        ], $archive->proposal_json);
        $this->assertSame($fixture['custom']->id, $move->proposal_json['target_folder_id']);
        $this->assertSame('Archive [attachment omitted] after review.', $archive->explanation);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
        $this->assertSame(0, EmailRule::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $fixture['placement']->fresh()->local_state);
        $this->assertTrue($fixture['placement']->fresh()->provider_seen);

        $durable = DB::table('email_smart_inbox_suggestions')->get()->toJson()
            .DB::table('email_smart_inbox_suggestion_events')->get()->toJson();
        foreach ([
            'private-cleanup-evidence.pdf',
            'email/private/private-cleanup-evidence.pdf',
            'must-not-persist',
            'wrong-selected-source',
            'cross account',
            'invented folder',
            'delete_permanently',
            '<b>',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $durable);
        }

        Http::assertSent(function ($request) use ($fixture, $foreign): bool {
            $messages = (array) ($request->data()['messages'] ?? []);
            $userMessage = collect($messages)->firstWhere('role', 'user');
            $input = json_decode((string) ($userMessage['content'] ?? ''), true);
            $targetIds = collect((array) ($input['cleanup_targets'] ?? []))
                ->pluck('target_folder_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $encodedInput = json_encode($input);

            return $targetIds === [$fixture['archive']->id, $fixture['custom']->id]
                && ! in_array($foreign['custom']->id, $targetIds, true)
                && ! str_contains($encodedInput, 'private-cleanup-evidence.pdf')
                && ! str_contains($encodedInput, 'provider-private-header')
                && ! str_contains($encodedInput, 'imap-private-secret');
        });
    }

    #[Test]
    public function reviewed_cleanup_uses_one_recoverable_operation_preserves_seen_and_personal_unread_and_supports_verified_undo(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-apply@example.test');
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $fixture['archive'],
        );
        $client = $this->moveClient($fixture['account'], [[
            'uid' => $fixture['placement']->imap_uid,
            'path' => 'INBOX',
            'seen' => true,
        ]]);
        $this->app->bind(ImapClient::class, fn () => $client);

        $applied = app(ApplyEmailSmartInboxSuggestion::class)->handle($suggestion, $this->actor);
        $again = app(ApplyEmailSmartInboxSuggestion::class)->handle($applied, $this->actor);
        $operation = EmailRemoteOperation::query()->findOrFail((int) $applied->applied_reference_id);

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $applied->status);
        $this->assertSame(ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION, $applied->applied_reference_type);
        $this->assertSame($applied->applied_reference_id, $again->applied_reference_id);
        $this->assertSame('mail-op:smart-inbox:'.$suggestion->id, $operation->idempotency_key);
        $this->assertSame('archive', $operation->operation_type);
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $operation->status);
        $this->assertNotNull($operation->result_snapshot_json);
        $this->assertSame(1, $client->moves);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(1, $applied->events()->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_APPLIED)->count());
        $this->assertTrue(EmailMessageUserState::query()
            ->where('email_message_id', $fixture['message']->id)
            ->where('user_id', $this->actor->id)
            ->value('is_unread'));
        $targetPlacement = EmailMailboxPlacement::query()
            ->where('email_message_id', $fixture['message']->id)
            ->where('email_folder_id', $fixture['archive']->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->firstOrFail();
        $this->assertTrue($targetPlacement->provider_seen);

        $eligibility = app(EmailRemoteOperationUndoEligibility::class)->evaluate($operation, $this->actor);
        $this->assertTrue($eligibility['eligible']);
        $inverse = app(UndoEmailRemoteOperation::class)->handle($operation, $this->actor);

        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, $inverse->status);
        $this->assertSame($operation->id, $inverse->inverse_of_email_remote_operation_id);
        $this->assertSame(2, $client->moves);
        $this->assertTrue(EmailMessageUserState::query()
            ->where('email_message_id', $fixture['message']->id)
            ->where('user_id', $this->actor->id)
            ->value('is_unread'));
    }

    #[Test]
    public function smart_inbox_cleanup_preserves_byte_distinct_source_and_target_paths(): void
    {
        $fixture = $this->mailboxFixture(
            $this->actor,
            'cleanup-byte-identity@example.test',
        );
        $fixture['folder']->forceFill([
            'path' => 'Source ',
            'name' => 'Exact source',
            'role' => EmailFolder::ROLE_CUSTOM,
        ])->save();
        $fixture['placement']->forceFill(['folder_path' => 'Source '])->save();
        $fixture['message']->forceFill(['mailbox' => 'Source '])->save();
        $sourceSibling = $this->folder(
            $fixture['account'],
            'Source',
            EmailFolder::ROLE_CUSTOM,
            177,
        );

        $fixture['custom']->forceFill([
            'path' => 'Target ',
            'name' => 'Exact target',
        ])->save();
        $targetSibling = $this->folder(
            $fixture['account'],
            'Target',
            EmailFolder::ROLE_CUSTOM,
            199,
        );
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom']->fresh(),
        );

        $eligibility = app(EmailSmartInboxSuggestionEligibility::class)->forDisplay(
            $suggestion,
            $this->actor,
            $fixture['conversation'],
            $fixture['placement']->fresh(),
        );
        $operation = app(RecordEmailSmartInboxCleanupOperation::class)->handle(
            $suggestion,
            $fixture['placement']->fresh(),
            $this->actor,
        );

        $this->assertTrue($eligibility['can_apply']);
        $this->assertSame('Source ', $operation->source_folder_path);
        $this->assertSame('Target ', $operation->target_folder_path);
        $this->assertSame('Source ', $operation->request_json['source_folder_path']);
        $this->assertSame('Target ', $operation->request_json['target_folder_path']);
        $this->assertSame('Source', $sourceSibling->fresh()->path);
        $this->assertSame('Target', $targetSibling->fresh()->path);
    }

    #[Test]
    public function bounded_batch_uses_the_exact_unique_snapshot_and_reports_partial_failure_without_new_arrivals(): void
    {
        $first = $this->mailboxFixture($this->actor, 'cleanup-batch-a@example.test');
        $stale = $this->mailboxFixture($this->actor, 'cleanup-batch-b@example.test');
        $foreign = $this->mailboxFixture($this->otherUser, 'cleanup-batch-c@example.test');
        $firstSuggestion = $this->cleanupSuggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $first['archive'],
        );
        $staleSuggestion = $this->cleanupSuggestion(
            $stale,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $stale['custom'],
        );
        $foreignSuggestion = $this->cleanupSuggestion(
            $foreign,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $foreign['archive'],
            $this->otherUser,
        );
        $notSubmitted = $this->cleanupSuggestion(
            $first,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $first['custom'],
        );
        $stale['message']->forceFill([
            'body_text' => 'This message arrived or changed after confirmation.',
            'checksum_sha1' => sha1('batch-stale-change'),
        ])->save();
        $client = $this->moveClient($first['account'], [[
            'uid' => $first['placement']->imap_uid,
            'path' => 'INBOX',
            'seen' => true,
        ]]);
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(ApplyEmailSmartInboxSuggestionBatch::class)->handle([
            $firstSuggestion->id,
            $firstSuggestion->id,
            $staleSuggestion->id,
            $foreignSuggestion->id,
        ], $this->actor);

        $this->assertSame([
            $firstSuggestion->id,
            $staleSuggestion->id,
            $foreignSuggestion->id,
        ], $result['snapshot_ids']);
        $this->assertSame(['succeeded', 'failed', 'failed'], array_column($result['results'], 'status'));
        $this->assertSame([null, 'stale', 'not_authorized'], array_column($result['results'], 'reason_code'));
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $notSubmitted->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $staleSuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $foreignSuggestion->fresh()->status);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(1, $client->moves);

        $repeat = app(ApplyEmailSmartInboxSuggestionBatch::class)->handle([$firstSuggestion->id], $this->actor);
        $this->assertSame(
            $result['results'][0]['applied_reference_id'],
            $repeat['results'][0]['applied_reference_id'],
        );
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(1, $client->moves);

        $this->expectException(ValidationException::class);
        app(ApplyEmailSmartInboxSuggestionBatch::class)->handle(range(1, 51), $this->actor);
    }

    #[Test]
    public function cleanup_batch_rejects_non_cleanup_effects_and_reserves_each_exact_source_once(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-batch-source-once@example.test');
        $archive = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $fixture['archive'],
        );
        $move = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom'],
        );
        $category = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $fixture['archive'],
        );
        $categoryProposal = ['category_id' => 999999, 'source_message_id' => $fixture['message']->id];
        $category->forceFill([
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
            'proposal_json' => $categoryProposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($categoryProposal),
        ])->save();
        $task = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $fixture['archive'],
        );
        $taskProposal = ['title' => 'This batch must not create a Task', 'source_message_id' => $fixture['message']->id];
        $task->forceFill([
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
            'proposal_json' => $taskProposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($taskProposal),
        ])->save();
        $client = $this->moveClient($fixture['account'], [[
            'uid' => $fixture['placement']->imap_uid,
            'path' => 'INBOX',
            'seen' => true,
        ]]);
        $this->app->bind(ImapClient::class, fn () => $client);

        $result = app(ApplyEmailSmartInboxSuggestionBatch::class)->handle([
            $archive->id,
            $move->id,
            $category->id,
            $task->id,
        ], $this->actor);

        $this->assertSame(
            ['succeeded', 'failed', 'failed', 'failed'],
            array_column($result['results'], 'status'),
        );
        $this->assertSame(
            [null, 'duplicate_source_placement', 'not_cleanup_effect', 'not_cleanup_effect'],
            array_column($result['results'], 'reason_code'),
        );
        $this->assertSame(1, $client->moves);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $move->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $category->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $task->fresh()->status);
        $this->assertSame(0, DB::table('email_conversation_classifications')->count());
        $this->assertSame(0, DB::table('tasks')->count());
    }

    #[Test]
    public function cleanup_apply_rechecks_organize_access_active_target_and_exact_reviewed_source(): void
    {
        $targetStale = $this->mailboxFixture($this->actor, 'cleanup-target-stale@example.test');
        $targetSuggestion = $this->cleanupSuggestion(
            $targetStale,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $targetStale['archive'],
        );
        $targetStale['archive']->forceFill(['sync_enabled' => false])->save();

        try {
            app(ApplyEmailSmartInboxSuggestion::class)->handle($targetSuggestion, $this->actor);
            $this->fail('A disabled provider target must block cleanup.');
        } catch (ValidationException) {
            $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $targetSuggestion->fresh()->status);
        }

        $revoked = $this->mailboxFixture($this->actor, 'cleanup-organize-revoked@example.test');
        $revokedSuggestion = $this->cleanupSuggestion(
            $revoked,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $revoked['custom'],
        );
        EmailAccountUserGrant::query()
            ->where('email_account_id', $revoked['account']->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => false]);

        try {
            app(ApplyEmailSmartInboxSuggestion::class)->handle($revokedSuggestion, $this->actor);
            $this->fail('Revoked mailbox Organize access must block cleanup.');
        } catch (AuthorizationException) {
            $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $revokedSuggestion->fresh()->status);
        }

        $moved = $this->mailboxFixture($this->actor, 'cleanup-source-moved@example.test');
        $movedSuggestion = $this->cleanupSuggestion(
            $moved,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $moved['archive'],
        );
        $moved['placement']->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_version' => 2,
            'provider_missing_at' => now(),
        ])->save();
        $currentPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $moved['message']->id,
            'email_conversation_id' => $moved['conversation']->id,
            'account_id' => $moved['account']->id,
            'email_folder_id' => $moved['custom']->id,
            'provider' => 'imap',
            'folder_path' => $moved['custom']->path,
            'imap_uid_validity' => $moved['custom']->uid_validity,
            'imap_uid' => ++$this->nextUid,
            'provider_seen' => true,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $moved['conversation']->forceFill([
            'latest_email_mailbox_placement_id' => $currentPlacement->id,
        ])->save();

        try {
            app(ApplyEmailSmartInboxSuggestion::class)->handle($movedSuggestion, $this->actor);
            $this->fail('Cleanup must not follow a message to a placement created after review.');
        } catch (ValidationException) {
            $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $movedSuggestion->fresh()->status);
        }

        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function cleanup_apply_rejects_changed_provider_identity_when_reviewed_evidence_is_present(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-source-evidence@example.test');
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom'],
        );
        $proposal = array_merge($suggestion->proposal_json, [
            'source_placement_id' => (int) $fixture['placement']->id,
            'source_folder_id' => (int) $fixture['placement']->email_folder_id,
            'source_folder_path' => (string) $fixture['placement']->folder_path,
            'source_imap_uid' => (int) $fixture['placement']->imap_uid,
            'source_uid_validity' => (int) $fixture['placement']->imap_uid_validity,
            'source_sync_version' => (int) $fixture['placement']->sync_version,
        ]);
        $suggestion->forceFill([
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
        ])->save();
        $fixture['placement']->forceFill(['sync_version' => 2])->save();

        try {
            app(ApplyEmailSmartInboxSuggestion::class)->handle($suggestion, $this->actor);
            $this->fail('Cleanup must reject provider identity changed after review.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'source identity changed',
                (string) collect($exception->errors())->flatten()->first(),
            );
        }

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
    }

    #[Test]
    public function always_do_this_returns_existing_builder_prefills_without_rule_writes_and_admin_defaults_inactive_and_stopping(): void
    {
        $shared = $this->mailboxFixture(
            $this->actor,
            'cleanup-prefill-shared@example.test',
            EmailAccount::KIND_SHARED,
            true,
        );
        $adminSuggestion = $this->cleanupSuggestion(
            $shared,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $shared['archive'],
        );
        $before = $this->ruleWriteCounts();

        $admin = app(BuildEmailSmartInboxRulePrefill::class)->handle($adminSuggestion, $this->actor);

        $this->assertSame('admin', $admin['mode']);
        $this->assertNull($admin['personal_payload']);
        $this->assertSame(BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE, $admin['admin_query']['action_type']);
        $this->assertSame($shared['archive']->id, $admin['admin_query']['target_folder_id']);
        $this->assertSame(0, $admin['admin_query']['is_active']);
        $this->assertSame(1, $admin['admin_query']['stop_processing']);
        $this->assertSame($before, $this->ruleWriteCounts());
        $decodedAdminRoute = urldecode((string) $admin['admin_route']);
        foreach (array_filter([
            $shared['message']->from_email,
            $shared['message']->subject,
            $admin['admin_query']['name'],
            $admin['admin_query']['condition_value'],
        ]) as $privatePrefillValue) {
            $this->assertStringNotContainsString((string) $privatePrefillValue, $decodedAdminRoute);
        }
        parse_str((string) parse_url((string) $admin['admin_route'], PHP_URL_QUERY), $adminRouteQuery);
        $this->assertSame([BuildEmailSmartInboxRulePrefill::ADMIN_PREFILL_TOKEN_QUERY], array_keys($adminRouteQuery));
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9]{64}$/',
            (string) $adminRouteQuery[BuildEmailSmartInboxRulePrefill::ADMIN_PREFILL_TOKEN_QUERY],
        );

        $response = $this->actingAs($this->actor)->get($admin['admin_route'])->assertOk();
        $response->assertSee('Archive at mail provider');
        $response->assertSee('value="'.$shared['archive']->id.'"', false);
        preg_match('/<input[^>]*id="is_active"[^>]*>/', $response->getContent(), $activeInput);
        preg_match('/<input[^>]*id="stop_processing"[^>]*>/', $response->getContent(), $stopInput);
        $this->assertNotEmpty($activeInput);
        $this->assertNotEmpty($stopInput);
        $this->assertStringNotContainsString('checked', $activeInput[0]);
        $this->assertStringContainsString('checked', $stopInput[0]);
        $this->assertSame($before, $this->ruleWriteCounts());
        $this->actingAs($this->actor)->get($admin['admin_route'])->assertNotFound();

        $crossUserPrefill = app(BuildEmailSmartInboxRulePrefill::class)->handle($adminSuggestion, $this->actor);
        $this->otherUser->assignRole('Admin');
        $this->otherUser->givePermissionTo('email.rule_manage');
        $this->actingAs($this->otherUser)
            ->get((string) $crossUserPrefill['admin_route'])
            ->assertNotFound();
        $this->actingAs($this->actor);

        $personal = $this->mailboxFixture(
            $this->actor,
            'cleanup-prefill-personal@example.test',
            EmailAccount::KIND_PERSONAL,
        );
        $personalSuggestion = $this->cleanupSuggestion(
            $personal,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $personal['custom'],
        );
        $personalPrefill = app(BuildEmailSmartInboxRulePrefill::class)->handle($personalSuggestion, $this->actor);

        $this->assertSame('personal', $personalPrefill['mode']);
        $this->assertNull($personalPrefill['admin_route']);
        $this->assertSame('move_to_folder', $personalPrefill['personal_payload']['action_type']);
        $this->assertSame($personal['custom']->id, $personalPrefill['personal_payload']['target_folder_id']);
        $this->assertSame('from', $personalPrefill['personal_payload']['condition_field']);
        $this->assertSame($personal['message']->from_email, $personalPrefill['personal_payload']['condition_value']);
        $this->assertSame($before, $this->ruleWriteCounts());

        try {
            app(BuildEmailSmartInboxRulePrefill::class)->handle($adminSuggestion, $this->otherUser);
            $this->fail('Cross-user prefill must remain hidden.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->ruleWriteCounts());
        }
    }

    #[Test]
    public function livewire_cleanup_review_renders_only_safe_fields_corrects_the_folder_and_applies_once(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-livewire-single@example.test');
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom'],
        );
        $proposal = $suggestion->proposal_json;
        $proposal['raw_provider_payload'] = ['token' => 'livewire-provider-token-must-stay-hidden'];
        $proposal['raw_headers'] = ['x-private' => 'livewire-header-must-stay-hidden'];
        $suggestion->forceFill([
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
            'explanation' => 'Reviewed <script>alert("cleanup-ui-xss")</script>',
            'ai_trace_json' => ['provider_payload' => 'livewire-trace-must-stay-hidden'],
        ])->save();
        $client = $this->moveClient($fixture['account'], [[
            'uid' => $fixture['placement']->imap_uid,
            'path' => 'INBOX',
            'seen' => true,
        ]]);
        $this->app->bind(ImapClient::class, fn () => $client);

        $component = Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Move provider mail')
            ->assertSee('Processed')
            ->assertDontSee('Always do this')
            ->assertSee('Apply this provider cleanup action?')
            ->assertSee('Reviewed <script>alert("cleanup-ui-xss")</script>')
            ->assertDontSee('<script>alert("cleanup-ui-xss")</script>', false)
            ->assertDontSee('livewire-provider-token-must-stay-hidden')
            ->assertDontSee('livewire-header-must-stay-hidden')
            ->assertDontSee('livewire-trace-must-stay-hidden')
            ->assertDontSee('imap-private-secret')
            ->assertDontSee('Please review this message without changing provider read state.')
            ->call('beginCorrection', $suggestion->id)
            ->assertSet('correctingSuggestionId', $suggestion->id)
            ->assertSet('correctionTargetFolderId', $fixture['custom']->id)
            ->assertSee('Provider destination folder')
            ->assertSee('Archive')
            ->set('correctionTargetFolderId', $fixture['archive']->id)
            ->set('correctionExplanation', 'Human selected the existing Archive folder.')
            ->set('correctionConfidence', '0.95')
            ->call('saveCorrection')
            ->assertSet('correctingSuggestionId', null)
            ->assertSee('Smart Inbox correction saved for review.');

        $corrected = $suggestion->fresh();
        $this->assertSame($fixture['archive']->id, $corrected->proposal_json['target_folder_id']);
        $this->assertSame('Archive', $corrected->proposal_json['target_folder_name']);
        $this->assertSame('Archive', $corrected->proposal_json['target_folder_path']);
        $this->assertSame($fixture['placement']->id, $corrected->proposal_json['source_placement_id']);
        $this->assertSame($fixture['folder']->id, $corrected->proposal_json['source_folder_id']);
        $this->assertSame('INBOX', $corrected->proposal_json['source_folder_path']);
        $this->assertSame($fixture['placement']->imap_uid, $corrected->proposal_json['source_imap_uid']);
        $this->assertSame($fixture['placement']->imap_uid_validity, $corrected->proposal_json['source_uid_validity']);
        $this->assertSame($fixture['placement']->sync_version, $corrected->proposal_json['source_sync_version']);
        $this->assertArrayNotHasKey('raw_provider_payload', $corrected->proposal_json);
        $this->assertArrayNotHasKey('raw_headers', $corrected->proposal_json);
        $this->assertSame(1, $corrected->events()
            ->where('event_type', EmailSmartInboxSuggestionEvent::TYPE_CORRECTED)
            ->count());

        $component
            ->call('apply', $suggestion->id)
            ->assertSee('Provider cleanup completed and was recorded.');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $this->assertSame(ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION, $suggestion->fresh()->applied_reference_type);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(1, $client->moves);
        $this->assertSame(0, EmailRule::query()->count());
        $this->assertSame(0, EmailRuleVersion::query()->count());
    }

    #[Test]
    public function livewire_cleanup_reports_provider_failure_as_danger_instead_of_success(): void
    {
        $fixture = $this->mailboxFixture($this->actor, 'cleanup-livewire-failed@example.test');
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom'],
        );
        $client = new class($fixture['account']) extends ImapClient
        {
            public function connect(): void {}

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                return ['ok' => false];
            }

            public function disconnect(): void {}
        };
        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->call('apply', $suggestion->id)
            ->assertSet('feedbackType', 'danger')
            ->assertSee('the provider operation failed')
            ->set('feedbackMessage', null)
            ->assertSee('Provider failed')
            ->assertSee('Provider cleanup failed; review recent Mail operations')
            ->assertDontSee('Provider cleanup operation recorded')
            ->assertDontSee('text-bg-success', false);

        $operation = EmailRemoteOperation::query()->sole();
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $operation->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
    }

    #[Test]
    public function livewire_cleanup_batch_uses_the_selected_snapshot_and_personal_prefill_only_dispatches_a_draft(): void
    {
        $batch = $this->mailboxFixture($this->actor, 'cleanup-livewire-batch@example.test');
        $second = $this->additionalConversationMessage($batch);
        $archiveSuggestion = $this->cleanupSuggestion(
            $batch,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $batch['archive'],
        );
        $moveSuggestion = $this->cleanupSuggestion(
            $second,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $batch['custom'],
        );
        $client = $this->moveClient($batch['account'], [
            [
                'uid' => $batch['placement']->imap_uid,
                'path' => 'INBOX',
                'seen' => true,
            ],
            [
                'uid' => $second['placement']->imap_uid,
                'path' => 'INBOX',
                'seen' => true,
            ],
        ]);
        $this->app->bind(ImapClient::class, fn () => $client);

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($batch))
            ->set('selectedSuggestionIds', [$archiveSuggestion->id, $moveSuggestion->id])
            ->assertSee('2 cleanup suggestions selected')
            ->assertSee('Apply the selected provider cleanup actions?')
            ->call('applySelected')
            ->assertSet('selectedSuggestionIds', [])
            ->assertSet('batchResults.0.status', 'succeeded')
            ->assertSet('batchResults.1.status', 'failed')
            ->assertSet('batchResults.1.message', 'The conversation changed. Analyze it again before cleanup.')
            ->assertSee('1 cleanup action(s) completed and 1 failed.');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_APPLIED, $archiveSuggestion->fresh()->status);
        $this->assertSame(EmailSmartInboxSuggestion::STATUS_STALE, $moveSuggestion->fresh()->status);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(1, $client->moves);
        $this->assertTrue(EmailMessageUserState::query()
            ->where('email_message_id', $batch['message']->id)
            ->where('user_id', $this->actor->id)
            ->value('is_unread'));

        $personal = $this->mailboxFixture(
            $this->actor,
            'cleanup-livewire-personal@example.test',
            EmailAccount::KIND_PERSONAL,
        );
        $personalSuggestion = $this->cleanupSuggestion(
            $personal,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $personal['custom'],
        );
        $before = $this->ruleWriteCounts();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($personal))
            ->assertSee('Always do this')
            ->call('alwaysDoThis', $personalSuggestion->id)
            ->assertDispatched(
                'smart-inbox-personal-rule-prefill',
                fn (string $event, array $params): bool => $event === 'smart-inbox-personal-rule-prefill'
                    && $params['conditionField'] === 'from'
                    && $params['conditionValue'] === $personal['message']->from_email
                    && $params['actionType'] === 'move_to_folder'
                    && $params['targetFolderId'] === $personal['custom']->id,
            )
            ->assertSee('Personal rule draft prepared. Review it before saving.');

        $this->assertSame($before, $this->ruleWriteCounts());
    }

    #[Test]
    public function personal_cleanup_prefill_disappears_when_its_recorded_agent_is_inactive_or_replaced(): void
    {
        $fixture = $this->mailboxFixture(
            $this->actor,
            'cleanup-prefill-agent-lifecycle@example.test',
            EmailAccount::KIND_PERSONAL,
        );
        $suggestion = $this->cleanupSuggestion(
            $fixture,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            $fixture['custom'],
        );
        $before = $this->ruleWriteCounts();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertSee('Move provider mail')
            ->assertSee('Always do this');

        $this->agent->forceFill(['is_active' => false])->save();

        Livewire::actingAs($this->actor)
            ->test(self::COMPONENT_ALIAS, $this->componentProps($fixture))
            ->assertDontSee('Smart Inbox')
            ->assertDontSee('Move provider mail')
            ->assertDontSee('Always do this');

        $this->agent->forceFill([
            'is_active' => true,
            'is_default' => false,
            'default_domains' => [],
        ])->save();
        AiAgent::query()->create([
            'ai_provider_id' => $this->agent->ai_provider_id,
            'name' => 'Replacement Smart Inbox Cleanup Agent',
            'slug' => 'replacement-smart-inbox-cleanup-agent',
            'model' => $this->agent->model,
            'instructions' => 'Analyze new cleanup candidates only.',
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
            ->assertDontSee('Move provider mail')
            ->assertDontSee('Always do this');

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        $this->assertSame($before, $this->ruleWriteCounts());
    }

    #[Test]
    public function published_admin_cleanup_reauthorizes_publisher_stops_ticket_fallback_and_skips_later_actions_after_revocation(): void
    {
        $success = $this->mailboxFixture(
            $this->actor,
            'cleanup-rule-success@example.test',
            EmailAccount::KIND_SHARED,
            true,
        );
        $successRule = $this->publishedCleanupRule(
            $success,
            [[
                'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
                'value' => $success['archive']->path,
                'target_folder_id' => $success['archive']->id,
            ]],
        );
        $client = $this->moveClient($success['account'], [[
            'uid' => $success['placement']->imap_uid,
            'path' => 'INBOX',
            'seen' => true,
        ]]);
        $this->app->bind(ImapClient::class, fn () => $client);

        app(InboundEmailRuleEngine::class)->process($success['message'], true);

        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailRemoteOperation::STATUS_SUCCEEDED, EmailRemoteOperation::query()->sole()->status);
        $this->assertSame(1, $successRule->fresh()->hit_count);
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Signal::query()->count());
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SUCCEEDED, $successRule->executionAttempts()->sole()->status);

        $revoked = $this->mailboxFixture(
            $this->actor,
            'cleanup-rule-revoked@example.test',
            EmailAccount::KIND_SHARED,
            true,
        );
        $revokedRule = $this->publishedCleanupRule(
            $revoked,
            [
                [
                    'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_MOVE,
                    'value' => $revoked['custom']->path,
                    'target_folder_id' => $revoked['custom']->id,
                ],
                [
                    'type' => 'emit_signal',
                    'value' => 'cleanup_should_not_run',
                    'signal_type' => 'cleanup_should_not_run',
                ],
            ],
        );
        $fallbackRule = $this->publishedCleanupRule(
            $revoked,
            [['type' => 'archive', 'value' => null]],
            2,
        );
        EmailAccountUserGrant::query()
            ->where('email_account_id', $revoked['account']->id)
            ->where('user_id', $this->actor->id)
            ->update(['can_organize' => false]);
        $operationsBefore = EmailRemoteOperation::query()->count();

        app(InboundEmailRuleEngine::class)->process($revoked['message'], true);

        $attempt = $revokedRule->executionAttempts()->sole();
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_FAILED, $attempt->status);
        $this->assertSame('provider_cleanup_authorization_revoked', $attempt->action_results_json[0]['reason']);
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SKIPPED, $attempt->action_results_json[1]['status']);
        $this->assertSame('not_run_after_provider_cleanup_failure', $attempt->action_results_json[1]['reason']);
        $this->assertSame($operationsBefore, EmailRemoteOperation::query()->count());
        $this->assertFalse(Signal::query()->where('signal_type', 'cleanup_should_not_run')->exists());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, $revokedRule->fresh()->hit_count);
        $this->assertSame(1, $fallbackRule->fresh()->hit_count);
        $this->assertSame('archived', $revoked['message']->fresh()->state);
    }

    #[Test]
    public function unresolved_provider_owner_blocks_published_rules_and_reviewed_cleanup_without_a_new_operation(): void
    {
        $ruleFixture = $this->mailboxFixture(
            $this->actor,
            'cleanup-rule-operation-conflict@example.test',
            EmailAccount::KIND_SHARED,
            true,
        );
        $rule = $this->publishedCleanupRule($ruleFixture, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
            'value' => $ruleFixture['archive']->path,
            'target_folder_id' => $ruleFixture['archive']->id,
        ]]);
        $ruleOwner = app(RecordEmailRemoteOperation::class)->pending(
            $ruleFixture['account'],
            PerformEmailRemoteOperation::MARK_SEEN,
            'rule-absence-owner:'.$ruleFixture['placement']->id,
            $this->actor,
            $ruleFixture['folder'],
            $ruleFixture['placement'],
            [
                'source_folder_path' => $ruleFixture['placement']->folder_path,
                'placement_sync_version' => $ruleFixture['placement']->sync_version,
                'placement_imap_uid' => $ruleFixture['placement']->imap_uid,
                'placement_uid_validity' => $ruleFixture['placement']->imap_uid_validity,
                'target_state' => ['provider_seen' => true],
            ],
        );
        $ruleOwner->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'reconciliation_required_at' => now(),
        ])->save();
        $providerClientResolutions = 0;
        $this->app->bind(ImapClient::class, function () use (&$providerClientResolutions): never {
            $providerClientResolutions++;

            throw new RuntimeException('Provider client resolution is forbidden while an absence operation is unresolved.');
        });

        app(InboundEmailRuleEngine::class)->process($ruleFixture['message'], true);

        $ruleAttempt = $rule->executionAttempts()->sole();
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_FAILED, $ruleAttempt->status);
        $this->assertSame('provider_cleanup_operation_rejected', $ruleAttempt->action_results_json[0]['reason']);
        $this->assertSame(0, $rule->fresh()->hit_count);
        $this->assertSame(1, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $ruleOwner->fresh()->status);

        $reviewFixture = $this->mailboxFixture(
            $this->actor,
            'cleanup-review-operation-conflict@example.test',
        );
        $suggestion = $this->cleanupSuggestion(
            $reviewFixture,
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            $reviewFixture['archive'],
        );
        $reviewOwner = app(RecordEmailRemoteOperation::class)->pending(
            $reviewFixture['account'],
            PerformEmailRemoteOperation::MARK_SEEN,
            'review-absence-owner:'.$reviewFixture['placement']->id,
            $this->actor,
            $reviewFixture['folder'],
            $reviewFixture['placement'],
            [
                'source_folder_path' => $reviewFixture['placement']->folder_path,
                'placement_sync_version' => $reviewFixture['placement']->sync_version,
                'placement_imap_uid' => $reviewFixture['placement']->imap_uid,
                'placement_uid_validity' => $reviewFixture['placement']->imap_uid_validity,
                'target_state' => ['provider_seen' => true],
            ],
        );
        $reviewOwner->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
            'reconciliation_required_at' => now(),
        ])->save();

        try {
            app(ApplyEmailSmartInboxSuggestion::class)->handle($suggestion, $this->actor);
            $this->fail('Reviewed cleanup must not reserve a second unresolved provider operation.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Another provider mailbox operation is still unresolved for this placement.',
                $exception->errors()['placement'][0],
            );
        }

        $this->assertSame(EmailSmartInboxSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
        $this->assertNull($suggestion->fresh()->applied_reference_id);
        $this->assertSame(2, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailRemoteOperation::STATUS_FAILED, $reviewOwner->fresh()->status);
        $this->assertSame(0, $providerClientResolutions);
    }

    #[Test]
    public function reconciliation_rule_processing_denies_admin_and_personal_provider_mutations(): void
    {
        $admin = $this->mailboxFixture(
            $this->actor,
            'reconciliation-admin-rule@example.test',
            EmailAccount::KIND_SHARED,
            true,
        );
        $adminRule = $this->publishedCleanupRule($admin, [[
            'type' => BuildEmailSmartInboxRulePrefill::ADMIN_ACTION_PROVIDER_ARCHIVE,
            'value' => $admin['archive']->path,
            'target_folder_id' => $admin['archive']->id,
        ]]);

        $personal = $this->mailboxFixture(
            $this->actor,
            'reconciliation-personal-rule@example.test',
            EmailAccount::KIND_PERSONAL,
            false,
        );
        $personalRule = app(CreatePersonalEmailRule::class)->handle(
            $personal['placement'],
            $this->actor,
            [
                'name' => 'Move matching personal reconciliation mail',
                'condition_field' => 'from',
                'condition_value' => $personal['message']->from_email,
                'action_type' => CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
                'target_folder_id' => $personal['custom']->id,
            ],
        );

        $providerClientResolutions = 0;
        $this->app->bind(ImapClient::class, function () use (&$providerClientResolutions): never {
            $providerClientResolutions++;

            throw new \RuntimeException('Provider client resolution is forbidden for reconciliation rules.');
        });
        $classifier = $this->mock(InboundEmailSignalClassifier::class);
        $classifier->shouldReceive('classifyAndRecord')->once()->andReturnNull();
        $classifier->shouldReceive('shouldStopTicketRouting')->once()->with(null)->andReturnFalse();
        $notifications = $this->mock(DispatchInboundEmailNotification::class);
        $notifications->shouldReceive('handle')->zeroOrMoreTimes();
        $ruleEngine = app(InboundEmailRuleEngine::class);
        $personalRuleEngine = app(PersonalEmailRuleEngine::class);

        foreach ([$admin['message'], $personal['message']] as $message) {
            (new ProcessInboundRules($message->id, false, 1))->handle(
                $ruleEngine,
                $classifier,
                $personalRuleEngine,
                $notifications,
            );
        }

        $adminAttempt = $adminRule->executionAttempts()->sole();
        $personalAttempt = $personalRule->executionAttempts()->sole();
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_FAILED, $adminAttempt->status);
        $this->assertSame('provider_mutation_not_authorized', $adminAttempt->action_results_json[0]['reason']);
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_FAILED, $personalAttempt->status);
        $this->assertSame(EmailRuleExecutionAttempt::STATUS_SKIPPED, $personalAttempt->action_results_json[0]['status']);
        $this->assertSame('provider_mutation_not_authorized', $personalAttempt->action_results_json[0]['reason']);
        $this->assertSame(0, $providerClientResolutions);
        $this->assertSame(0, EmailRemoteOperation::query()->count());
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $admin['placement']->fresh()->local_state);
        $this->assertSame(EmailMailboxPlacement::LOCAL_ACTIVE, $personal['placement']->fresh()->local_state);
    }

    /**
     * @return array{account: EmailAccount, folder: EmailFolder, archive: EmailFolder, custom: EmailFolder, conversation: EmailConversation, message: EmailMessage, placement: EmailMailboxPlacement}
     */
    private function mailboxFixture(
        User $user,
        string $address,
        string $kind = EmailAccount::KIND_SHARED,
        bool $ticketIngress = false,
    ): array {
        $uid = ++$this->nextUid;
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Supervised Smart Inbox cleanup test',
            'from_name' => 'Cleanup test',
            'account_kind' => $kind,
            'owner_id' => $kind === EmailAccount::KIND_PERSONAL ? $user->id : null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => $ticketIngress,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'imap-private-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'smtp-private-secret',
            'smtp_auth_type' => 'password',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => false,
            'granted_at' => now(),
        ]);
        $folder = $this->folder($account, 'INBOX', EmailFolder::ROLE_INBOX, 77);
        $archive = $this->folder($account, 'Archive', EmailFolder::ROLE_ARCHIVE, 88);
        $custom = $this->folder($account, 'Processed', EmailFolder::ROLE_CUSTOM, 99);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<cleanup-'.$uid.'@example.test>',
            'subject' => 'Supervised cleanup request '.$uid,
            'from_name' => 'Customer',
            'from_email' => 'customer-'.$uid.'@example.test',
            'to_json' => [['name' => 'Support', 'email' => $address]],
            'cc_json' => [],
            'headers_json' => ['x-provider-private' => 'provider-private-header'],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Please review this message without changing provider read state.',
            'body_html_sanitized' => '<p>Please review this message.</p>',
            'raw_path' => 'email/private/source-'.$uid.'.eml',
            'checksum_sha1' => sha1('cleanup-source-'.$uid),
            'attachments_count' => 0,
        ]);
        $conversation = EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'message:cleanup-'.$uid.'@example.test',
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'message_count' => 1,
            'active_placement_count' => 1,
            'provider_unread_count' => 0,
            'has_attachments' => false,
            'first_message_at' => $message->received_at,
            'last_message_at' => $message->received_at,
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $folder->active_uid_namespace_id,
            'provider' => 'imap',
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $uid,
            'provider_seen' => true,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
        ]);
        $conversation->forceFill(['latest_email_mailbox_placement_id' => $placement->id])->save();
        EmailMessageUserState::query()->create([
            'email_message_id' => $message->id,
            'user_id' => $user->id,
            'last_opened_placement_id' => $placement->id,
            'is_unread' => true,
            'opened_count' => 0,
        ]);

        return compact('account', 'folder', 'archive', 'custom', 'conversation', 'message', 'placement');
    }

    /** @param array<string, mixed> $fixture */
    private function componentProps(array $fixture): array
    {
        return [
            'conversationId' => (int) $fixture['conversation']->id,
            'selectedPlacementId' => (int) $fixture['placement']->id,
        ];
    }

    /**
     * Add an independently movable source message to the same conversation so
     * the Livewire batch starts with two legitimate fixed-snapshot items and
     * can report the later fingerprint recheck as an honest partial result.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function additionalConversationMessage(array $fixture): array
    {
        $uid = ++$this->nextUid;
        $message = $fixture['message']->replicate();
        $message->forceFill([
            'imap_uid' => $uid,
            'message_id' => '<cleanup-'.$uid.'@example.test>',
            'subject' => 'Second supervised cleanup request '.$uid,
            'checksum_sha1' => sha1('cleanup-source-'.$uid),
            'received_at' => now()->addSecond(),
        ])->save();
        $placement = $fixture['placement']->replicate();
        $placement->forceFill([
            'email_message_id' => $message->id,
            'imap_uid' => $uid,
            'sync_version' => 1,
        ])->save();
        $fixture['conversation']->forceFill([
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $placement->id,
            'message_count' => 2,
            'active_placement_count' => 2,
        ])->save();
        EmailMessageUserState::query()->create([
            'email_message_id' => $message->id,
            'user_id' => $this->actor->id,
            'last_opened_placement_id' => $placement->id,
            'is_unread' => true,
            'opened_count' => 0,
        ]);

        return array_merge($fixture, compact('message', 'placement'));
    }

    private function folder(EmailAccount $account, string $path, string $role, int $uidValidity): EmailFolder
    {
        $folder = EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $role,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $uidValidity,
        ]);

        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => $uidValidity,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'test_smart_inbox_cleanup',
            'established_at' => now(),
        ]);
        $folder->forceFill(['active_uid_namespace_id' => $namespace->id])->save();

        return $folder->refresh();
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function cleanupSuggestion(
        array $fixture,
        string $effectType,
        EmailFolder $target,
        ?User $user = null,
    ): EmailSmartInboxSuggestion {
        $user ??= $this->actor;
        $source = app(EmailConversationFingerprint::class)->forConversation($fixture['conversation']);
        $proposal = [
            'target_folder_id' => (int) $target->id,
            'target_folder_name' => (string) $target->name,
            'target_folder_path' => (string) $target->path,
            'source_message_id' => (int) $fixture['message']->id,
            'source_placement_id' => (int) $fixture['placement']->id,
            'source_folder_id' => (int) $fixture['placement']->email_folder_id,
            'source_folder_path' => (string) $fixture['placement']->folder_path,
            'source_imap_uid' => (int) $fixture['placement']->imap_uid,
            'source_uid_validity' => (int) $fixture['placement']->imap_uid_validity,
            'source_sync_version' => (int) $fixture['placement']->sync_version,
        ];

        return EmailSmartInboxSuggestion::query()->create([
            'user_id' => $user->id,
            'account_id' => $fixture['account']->id,
            'email_conversation_id' => $fixture['conversation']->id,
            'selected_email_mailbox_placement_id' => $fixture['placement']->id,
            'effect_type' => $effectType,
            'proposal_json' => $proposal,
            'proposal_fingerprint' => app(EmailSmartInboxSuggestionIdentity::class)->checksum($proposal),
            'explanation' => 'Reviewed reversible provider cleanup.',
            'confidence' => 0.9,
            'source_fingerprint' => $source['fingerprint'],
            'source_message_ids_json' => $source['source_message_ids'],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'idempotency_key' => hash('sha256', uniqid('cleanup-suggestion-', true)),
            'ai_agent_id' => $this->agent->id,
            'generated_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function publishedCleanupRule(array $fixture, array $actions, int $weight = 1): EmailRule
    {
        $rule = EmailRule::query()->create([
            'name' => 'Provider cleanup '.$fixture['account']->id.'-'.$weight,
            'description' => 'Explicit supervised cleanup rule test.',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'routing_phase' => EmailRule::ROUTING_PHASE_NORMAL,
            'rule_kind' => EmailRule::KIND_ADMIN,
            'owner_id' => null,
            'weight' => $weight,
            'is_active' => true,
            'lifecycle_status' => EmailRule::LIFECYCLE_DRAFT,
            'stop_processing' => true,
            'conditions_json' => [[
                'field' => 'from',
                'operator' => 'equals',
                'value' => $fixture['message']->from_email,
            ]],
            'actions_json' => $actions,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $rule->accounts()->sync([$fixture['account']->id]);
        app(EmailRulePublisher::class)->publish($rule, $this->actor);

        return $rule->fresh(['accounts', 'publishedVersion']);
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
            'name' => 'Smart Inbox Cleanup AI',
            'provider_key' => 'ollama',
            'base_url' => 'http://smart-inbox-cleanup-ai.test',
            'default_model' => 'smart-inbox-cleanup-test',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Smart Inbox Cleanup Agent',
            'slug' => 'smart-inbox-cleanup-agent-'.Str::lower(Str::random(8)),
            'model' => 'smart-inbox-cleanup-test',
            'instructions' => 'Return only governed reviewed Mail proposals.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => ['email.update'],
            'can_execute_actions' => true,
            'is_default' => true,
            'default_domains' => ['email'],
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function minimalSummary(): array
    {
        return [
            'summary' => 'Review the customer message.',
            'key_points' => ['Cleanup remains supervised.'],
            'questions' => [],
            'action_items' => [],
            'suggested_labels' => [],
            'cleanup_suggestions' => [],
            'urgency' => 'normal',
            'reply_needed' => false,
            'provenance' => ['source_message_ids' => [], 'limitations' => []],
        ];
    }

    /** @return array<string, int> */
    private function ruleWriteCounts(): array
    {
        return [
            'rules' => EmailRule::query()->count(),
            'versions' => EmailRuleVersion::query()->count(),
            'pivots' => DB::table('email_rule_accounts')->count(),
            'remote_operations' => EmailRemoteOperation::query()->count(),
        ];
    }

    /**
     * @param  array<int, array{uid: int, path: string, seen: bool}>  $initialPlacements
     */
    private function moveClient(EmailAccount $account, array $initialPlacements): ImapClient
    {
        return new class($account, $initialPlacements) extends ImapClient
        {
            public int $moves = 0;

            /** @var array<string, array<int, array{seen: bool, flagged: bool}>> */
            public array $locations = [];

            public function __construct(EmailAccount $account, array $initialPlacements)
            {
                parent::__construct($account);

                foreach ($initialPlacements as $placement) {
                    $this->locations[$placement['path']][(int) $placement['uid']] = [
                        'seen' => (bool) $placement['seen'],
                        'flagged' => false,
                    ];
                }
            }

            public function connect(): void {}

            public function folderState(string $folderPath): array
            {
                return ['uid_validity' => match ($folderPath) {
                    'INBOX' => 77,
                    'Archive' => 88,
                    'Processed' => 99,
                    default => 0,
                }];
            }

            public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
            {
                $state = $this->locations[$folderPath][$uid] ?? null;

                return [
                    'exists' => $state !== null,
                    'imap_uid' => $uid,
                    'folder_path' => $folderPath,
                    'provider_seen' => (bool) ($state['seen'] ?? false),
                    'provider_flagged' => (bool) ($state['flagged'] ?? false),
                ];
            }

            public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
            {
                $state = $this->locations[$sourceFolderPath][$uid] ?? [
                    'seen' => true,
                    'flagged' => false,
                ];
                unset($this->locations[$sourceFolderPath][$uid]);
                $this->moves++;
                $targetUid = 10000 + $this->moves;
                $this->locations[$targetFolderPath][$targetUid] = $state;

                return [
                    'ok' => true,
                    'target_folder_path' => $targetFolderPath,
                    'target_imap_uid' => $targetUid,
                    'target_uid_validity' => match ($targetFolderPath) {
                        'INBOX' => 77,
                        'Archive' => 88,
                        'Processed' => 99,
                        default => 0,
                    },
                    'target_uid_authoritative' => in_array($targetFolderPath, ['INBOX', 'Archive', 'Processed'], true),
                ];
            }

            public function disconnect(): void {}
        };
    }
}
