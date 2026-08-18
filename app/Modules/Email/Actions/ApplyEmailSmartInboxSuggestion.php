<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEventRecorder;
use App\Modules\Email\Services\EmailSmartInboxSuggestionIdentity;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use App\Modules\Email\Services\MailAiAgentRuntime;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyEmailSmartInboxSuggestion
{
    public const REFERENCE_CONVERSATION_CLASSIFICATION = 'email_conversation_classification';

    public const REFERENCE_TASK = 'task';

    public const REFERENCE_EMAIL_REMOTE_OPERATION = 'email_remote_operation';

    public const TASK_SOURCE_TYPE = 'email_smart_inbox_suggestion';

    public function __construct(
        private readonly EmailSmartInboxSuggestionStateService $stateService,
        private readonly EmailSmartInboxSuggestionEventRecorder $eventRecorder,
        private readonly EmailSmartInboxSuggestionIdentity $identity,
        private readonly MailAiAgentRuntime $mailAiAgentRuntime,
        private readonly MailboxAccess $mailboxAccess,
        private readonly UpdateEmailConversationClassification $updateClassification,
        private readonly StoreTask $storeTask,
        private readonly RecordEmailSmartInboxCleanupOperation $recordCleanupOperation,
        private readonly RunEmailRemoteOperation $runRemoteOperation,
    ) {}

    /**
     * Apply one reviewed allowlisted proposal. The suggestion row is the
     * serialization point, so the domain write or pending provider-operation
     * record and applied audit evidence commit together. Provider I/O starts
     * only after this transaction has returned successfully.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): EmailSmartInboxSuggestion {
        $result = DB::transaction(function () use ($suggestion, $actor): array {
            $locked = EmailSmartInboxSuggestion::query()
                ->lockForUpdate()
                ->findOrFail($suggestion->id);
            $locked = $this->stateService->evaluateLocked($locked, $actor);

            // These state transitions are intentionally returned rather than
            // thrown here so the state service's stale/revoked audit commits.
            if ($locked->status === EmailSmartInboxSuggestion::STATUS_REVOKED) {
                return ['suggestion' => $locked, 'error' => 'revoked'];
            }

            if ($locked->status === EmailSmartInboxSuggestion::STATUS_STALE) {
                return ['suggestion' => $locked, 'error' => 'stale'];
            }

            [$account, $conversation] = $this->currentContext($locked, $actor);

            // A repeated click performs no new write. Current user and mailbox
            // authorization are still checked before returning the reference.
            if ($locked->status === EmailSmartInboxSuggestion::STATUS_APPLIED) {
                return ['suggestion' => $locked->refresh(), 'error' => null];
            }

            if ($locked->status !== EmailSmartInboxSuggestion::STATUS_PENDING) {
                return ['suggestion' => $locked, 'error' => 'terminal'];
            }

            $this->assertProposalIntegrity($locked);

            $application = match ($locked->effect_type) {
                EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => $this->applyCategory(
                    $locked,
                    $actor,
                    $account,
                    $conversation,
                ),
                EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => $this->applyTag(
                    $locked,
                    $actor,
                    $account,
                    $conversation,
                ),
                EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => $this->createTask(
                    $locked,
                    $actor,
                ),
                EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => $this->recordCleanup(
                    $locked,
                    $actor,
                    $account,
                    $conversation,
                ),
                default => throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion is review-only and cannot be applied.',
                ]),
            };
            $referenceType = $application[0];
            $referenceId = $application[1];
            $remoteOperationId = $application[2] ?? null;

            $before = $this->eventRecorder->snapshot($locked);
            $locked->forceFill([
                'status' => EmailSmartInboxSuggestion::STATUS_APPLIED,
                'applied_by' => $actor->id,
                'applied_at' => now(),
                'applied_reference_type' => $referenceType,
                'applied_reference_id' => (string) $referenceId,
            ])->save();
            $this->eventRecorder->record(
                $locked,
                EmailSmartInboxSuggestionEvent::TYPE_APPLIED,
                $actor,
                $before,
                'user_applied',
            );

            return [
                'suggestion' => $locked->refresh(),
                'error' => null,
                'remote_operation_id' => $remoteOperationId,
            ];
        });

        if ($result['error'] === 'revoked') {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        if ($result['error'] === 'stale') {
            throw ValidationException::withMessages([
                'suggestion' => 'This suggestion is stale because the Mail conversation changed.',
            ]);
        }

        if ($result['error'] === 'terminal') {
            throw ValidationException::withMessages([
                'suggestion' => 'Only a pending Smart Inbox suggestion can be applied.',
            ]);
        }

        if ($remoteOperationId = ($result['remote_operation_id'] ?? null)) {
            $operation = EmailRemoteOperation::query()->find($remoteOperationId);

            if ($operation) {
                $this->runRemoteOperation->handle($operation, 'smart_inbox', $actor);
            }
        }

        return $result['suggestion']->refresh();
    }

    /**
     * Re-read and lock the security boundary used by the pending action. This
     * complements the state service's fingerprint check with active-account
     * and active-conversation checks at the exact write instant.
     *
     * @return array{0: EmailAccount, 1: EmailConversation}
     */
    private function currentContext(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): array {
        $actorIsActive = User::query()
            ->whereKey($actor->id)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();

        if (! $actorIsActive) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        $account = EmailAccount::query()
            ->whereKey($suggestion->account_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $account
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        $conversation = EmailConversation::query()
            ->whereKey($suggestion->email_conversation_id)
            ->where('account_id', $account->id)
            ->where('status', EmailConversation::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (! $conversation) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        return [$account, $conversation];
    }

    private function assertProposalIntegrity(EmailSmartInboxSuggestion $suggestion): void
    {
        $proposalFingerprint = $this->identity->checksum((array) $suggestion->proposal_json);

        if ($suggestion->schema_version !== EmailSmartInboxSuggestion::SCHEMA_VERSION
            || ! hash_equals((string) $suggestion->proposal_fingerprint, $proposalFingerprint)) {
            throw ValidationException::withMessages([
                'suggestion' => 'This Smart Inbox proposal is no longer valid for application.',
            ]);
        }
    }

    /** @return array{0: string, 1: int} */
    private function applyCategory(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): array {
        $this->authorizeAgentScope($suggestion, $actor, 'email.update');
        $this->authorizeOrganize($actor, $account);

        $categoryId = $this->positiveInteger($suggestion->proposal_json['category_id'] ?? null);
        $category = Category::query()
            ->whereKey($categoryId)
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed Email category is no longer active.',
            ]);
        }

        $classification = $this->lockedClassification($account, $conversation);
        $currentCategoryId = $classification?->category_id
            ? (int) $classification->category_id
            : null;

        // Category application is compare-and-set: an AI review never replaces
        // a different category selected by a human or another guarded action.
        if ($currentCategoryId !== null && $currentCategoryId !== (int) $category->id) {
            throw ValidationException::withMessages([
                'suggestion' => 'The conversation already has a different category. Review it before applying this suggestion.',
            ]);
        }

        $assignedTags = $this->activeAssignedTags($classification);
        $this->assertClassificationActionResolvesTags($assignedTags);
        $tagNames = $assignedTags->pluck('name')->all();

        if ($classification && $currentCategoryId === (int) $category->id) {
            return [self::REFERENCE_CONVERSATION_CLASSIFICATION, (int) $classification->id];
        }

        $updated = $this->updateClassification->handle(
            $this->activePlacement($suggestion, $account, $conversation),
            $actor,
            (int) $category->id,
            $tagNames,
        );

        return [self::REFERENCE_CONVERSATION_CLASSIFICATION, (int) $updated->id];
    }

    /** @return array{0: string, 1: int} */
    private function applyTag(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): array {
        $this->authorizeAgentScope($suggestion, $actor, 'email.update');
        $this->authorizeOrganize($actor, $account);

        $tagId = $this->positiveInteger($suggestion->proposal_json['tag_id'] ?? null);
        $tag = Tag::query()
            ->whereKey($tagId)
            ->where('active', true)
            ->lockForUpdate()
            ->first();

        if (! $tag) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed tag is no longer active.',
            ]);
        }

        $classification = $this->lockedClassification($account, $conversation);
        $categoryId = $this->activeAssignedCategoryId($classification);
        $assignedTags = $this->activeAssignedTags($classification);

        if ($classification && $assignedTags->contains('id', $tag->id)) {
            return [self::REFERENCE_CONVERSATION_CLASSIFICATION, (int) $classification->id];
        }

        $targetTags = $assignedTags
            ->push($tag)
            ->unique('id')
            ->values();
        $this->assertClassificationActionResolvesTags($targetTags);
        $tagNames = $targetTags
            ->pluck('name')
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
        $updated = $this->updateClassification->handle(
            $this->activePlacement($suggestion, $account, $conversation),
            $actor,
            $categoryId,
            $tagNames,
        );

        return [self::REFERENCE_CONVERSATION_CLASSIFICATION, (int) $updated->id];
    }

    /** @return array{0: string, 1: int} */
    private function createTask(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): array {
        $this->authorizeAgentScope($suggestion, $actor, 'tasks.create');

        if (! $actor->can('task.create')) {
            throw new AuthorizationException('You do not have permission to create Tasks.');
        }

        $title = $this->taskTitle($suggestion->proposal_json['title'] ?? null);
        $sourceMessageId = $suggestion->proposal_json['source_message_id'] ?? null;
        $sourceMessageIds = collect($suggestion->source_message_ids_json ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($sourceMessageId !== null
            && (! is_numeric($sourceMessageId)
                || ! in_array((int) $sourceMessageId, $sourceMessageIds, true))) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed Task source is no longer part of this conversation.',
            ]);
        }

        $task = Task::query()
            ->where('source_type', self::TASK_SOURCE_TYPE)
            ->where('source_id', $suggestion->id)
            ->lockForUpdate()
            ->first();

        if ($task) {
            if ((int) $task->created_by !== (int) $actor->id
                || $task->owner_type !== $actor->getMorphClass()
                || (int) $task->owner_id !== (int) $actor->id
                || $task->visibility !== Task::VISIBILITY_INTERNAL) {
                throw ValidationException::withMessages([
                    'suggestion' => 'The deterministic Task reference is already in use.',
                ]);
            }
        } else {
            $task = $this->storeTask->handle([
                'title' => $title,
                'visibility' => Task::VISIBILITY_INTERNAL,
                'source_type' => self::TASK_SOURCE_TYPE,
                'source_id' => $suggestion->id,
                // AI due/owner hints remain review context. They never become
                // a speculative due date or assignee during application.
                'metadata' => [
                    'smart_inbox_suggestion_id' => (int) $suggestion->id,
                    'source_message_id' => $sourceMessageId !== null ? (int) $sourceMessageId : null,
                ],
            ], $actor, $actor);
        }

        return [self::REFERENCE_TASK, (int) $task->id];
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function recordCleanup(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): array {
        $this->authorizeAgentScope($suggestion, $actor, 'email.update');
        $this->authorizeOrganize($actor, $account);

        $operation = $this->recordCleanupOperation->handle(
            $suggestion,
            $this->cleanupPlacement($suggestion, $account, $conversation),
            $actor,
        );

        return [
            self::REFERENCE_EMAIL_REMOTE_OPERATION,
            (int) $operation->id,
            (int) $operation->id,
        ];
    }

    private function authorizeAgentScope(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        string $requiredScope,
    ): void {
        $availability = $this->mailAiAgentRuntime->writeAvailability($actor, [$requiredScope]);
        $agent = $availability['agent'];
        $allowedScopes = collect($agent?->allowed_api_scopes ?? [])
            ->map(fn (mixed $scope): string => trim((string) $scope));

        // Application authority stays attached to the agent that produced the
        // proposal. Switching the configured Mail agent or losing provenance
        // requires a new analysis instead of laundering an old suggestion
        // through another agent's write grants.
        if (! $availability['available']
            || ! $suggestion->ai_agent_id
            || (int) $suggestion->ai_agent_id !== (int) $agent?->id
            || ! $agent?->can_execute_actions
            // A reviewed cross-domain effect needs the named scope itself. A
            // wildcard is not accepted as a substitute for this click contract.
            || ! $allowedScopes->contains($requiredScope)) {
            throw new AuthorizationException('The current Mail AI agent is not authorized for this action.');
        }
    }

    private function authorizeOrganize(User $actor, EmailAccount $account): void
    {
        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE)) {
            throw new AuthorizationException('You need mailbox Organize access to apply this suggestion.');
        }
    }

    private function lockedClassification(
        EmailAccount $account,
        EmailConversation $conversation,
    ): ?EmailConversationClassification {
        return EmailConversationClassification::query()
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->lockForUpdate()
            ->first();
    }

    private function activeAssignedCategoryId(?EmailConversationClassification $classification): ?int
    {
        if (! $classification?->category_id) {
            return null;
        }

        $category = Category::query()
            ->whereKey($classification->category_id)
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'suggestion' => 'The conversation has an inactive category that must be reviewed first.',
            ]);
        }

        return (int) $category->id;
    }

    /** @return Collection<int, Tag> */
    private function activeAssignedTags(?EmailConversationClassification $classification): Collection
    {
        if (! $classification) {
            return collect();
        }

        $morphTypes = array_values(array_unique([
            EmailConversationClassification::class,
            $classification->getMorphClass(),
        ]));
        $assignedIds = DB::table('taggables')
            ->where('taggable_id', $classification->id)
            ->whereIn('taggable_type', $morphTypes)
            ->lockForUpdate()
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($assignedIds->isEmpty()) {
            return collect();
        }

        $tags = Tag::withTrashed()
            ->whereIn('id', $assignedIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($tags->count() !== $assignedIds->count()
            || $tags->contains(fn (Tag $tag): bool => ! $tag->active || $tag->trashed())) {
            throw ValidationException::withMessages([
                'suggestion' => 'The conversation has an inactive tag that must be reviewed first.',
            ]);
        }

        return $tags;
    }

    /**
     * The existing classification action intentionally accepts tag names so
     * humans may create new definitions when separately authorized. Smart
     * Inbox never creates definitions, and therefore rejects an ambiguous
     * duplicate name/slug instead of applying a different tag ID.
     *
     * @param  Collection<int, Tag>  $tags
     */
    private function assertClassificationActionResolvesTags(Collection $tags): void
    {
        foreach ($tags as $tag) {
            $resolved = Tag::query()
                ->where(function ($query) use ($tag): void {
                    $query->where('name', $tag->name)
                        ->orWhere('slug', $tag->slug);
                })
                ->where('active', true)
                ->first();

            if (! $resolved || (int) $resolved->id !== (int) $tag->id) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A conversation tag is ambiguous and must be reviewed first.',
                ]);
            }
        }
    }

    private function activePlacement(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
        EmailConversation $conversation,
    ): EmailMailboxPlacement {
        $query = EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message');

        if ($suggestion->selected_email_mailbox_placement_id) {
            $query->whereKey($suggestion->selected_email_mailbox_placement_id);
        }

        $placement = $query->orderBy('id')->lockForUpdate()->first();

        if (! $placement) {
            throw ValidationException::withMessages([
                'suggestion' => 'The selected Mail placement is no longer active in this conversation.',
            ]);
        }

        return $placement;
    }

    private function cleanupPlacement(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
        EmailConversation $conversation,
    ): EmailMailboxPlacement {
        $selectedPlacementId = $suggestion->selected_email_mailbox_placement_id;

        if (! is_int($selectedPlacementId) || $selectedPlacementId < 1) {
            throw ValidationException::withMessages([
                'suggestion' => 'The reviewed provider source placement is unavailable.',
            ]);
        }

        $sourceMessageId = $suggestion->proposal_json['source_message_id'] ?? null;
        $sourceMessageIds = collect($suggestion->source_message_ids_json ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($sourceMessageId !== null
            && (! is_numeric($sourceMessageId)
                || ! in_array((int) $sourceMessageId, $sourceMessageIds, true))) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed cleanup source is no longer part of this conversation.',
            ]);
        }

        // Cleanup is bound to the exact placement the user reviewed. Following
        // source_message_id to whichever placement happens to be active now
        // could chain a second move after the first provider operation.
        $placement = EmailMailboxPlacement::query()
            ->whereKey($selectedPlacementId)
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message')
            ->with(['account', 'folder', 'message'])
            ->lockForUpdate()
            ->first();

        if (! $placement
            || ($sourceMessageId !== null
                && (int) $placement->email_message_id !== (int) $sourceMessageId)) {
            throw ValidationException::withMessages([
                'suggestion' => 'The reviewed provider source placement is stale or no longer active.',
            ]);
        }

        $this->assertReviewedCleanupSourceEvidence($suggestion, $placement);

        return $placement;
    }

    /**
     * New cleanup suggestions may persist a compare-and-set snapshot of the
     * reviewed provider identity. Keep legacy proposals compatible, but once
     * any snapshot field is present it must still match the exact placement.
     */
    private function assertReviewedCleanupSourceEvidence(
        EmailSmartInboxSuggestion $suggestion,
        EmailMailboxPlacement $placement,
    ): void {
        $proposal = is_array($suggestion->proposal_json) ? $suggestion->proposal_json : [];
        $expected = [
            'source_placement_id' => (int) $placement->id,
            'source_folder_id' => (int) $placement->email_folder_id,
            'source_folder_path' => (string) $placement->folder_path,
            'source_imap_uid' => (int) $placement->imap_uid,
            'source_uid_validity' => (int) $placement->imap_uid_validity,
            'source_sync_version' => (int) $placement->sync_version,
        ];

        foreach ($expected as $key => $currentValue) {
            if (! array_key_exists($key, $proposal)) {
                continue;
            }

            $reviewedValue = $proposal[$key];
            $matches = is_int($currentValue)
                ? is_numeric($reviewedValue) && (int) $reviewedValue === $currentValue
                : is_string($reviewedValue) && hash_equals($currentValue, $reviewedValue);

            if (! $matches) {
                throw ValidationException::withMessages([
                    'suggestion' => 'The reviewed provider source identity changed. Analyze the current placement again.',
                ]);
            }
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed target is invalid.',
            ]);
        }

        return (int) $value;
    }

    private function taskTitle(mixed $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed Task title is invalid.',
            ]);
        }

        $title = trim($value);

        if ($title === '' || mb_strlen($title) > 255 || strip_tags($title) !== $title) {
            throw ValidationException::withMessages([
                'suggestion' => 'The proposed Task title is invalid.',
            ]);
        }

        return $title;
    }
}
