<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Support\EmailProviderPath;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailSmartInboxSuggestionEligibility
{
    /** @var array<string, bool> */
    private array $agentScopeAvailability = [];

    /** @var array<int, array<string, mixed>> */
    private array $readAgentAvailability = [];

    /** @var array<string, bool> */
    private array $mailboxOrganizeAvailability = [];

    /** @var array<string, array{fingerprint: string, source_message_ids: array<int, int>, schema_version: string}> */
    private array $conversationFingerprints = [];

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly MailAiAgentRuntime $mailAiAgentRuntime,
        private readonly EmailSmartInboxSuggestionIdentity $identity,
        private readonly EmailConversationFingerprint $conversationFingerprint,
    ) {}

    /**
     * Project current action eligibility without changing the suggestion or
     * replacing the authoritative checks inside the write actions.
     *
     * @return array{is_available: bool, can_apply: bool, can_always_do_this: bool}
     */
    public function forDisplay(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $contextPlacement,
    ): array {
        $account = $contextPlacement->account;

        if (! $account || ! $this->hasCurrentContext(
            $suggestion,
            $actor,
            $account,
            $conversation,
        )) {
            return $this->unavailable();
        }

        $canApply = $suggestion->status === EmailSmartInboxSuggestion::STATUS_PENDING
            && $this->canApply($suggestion, $actor, $account, $conversation);
        $canAlwaysDoThis = in_array($suggestion->status, [
            EmailSmartInboxSuggestion::STATUS_PENDING,
            EmailSmartInboxSuggestion::STATUS_APPLIED,
        ], true)
            && $this->canBuildCleanupRule($suggestion, $actor, $account, $conversation);

        return [
            'is_available' => true,
            'can_apply' => $canApply,
            'can_always_do_this' => $canAlwaysDoThis,
        ];
    }

    private function hasCurrentContext(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        if (! $actor->isActive()
            || ! $account->is_active
            || $conversation->status !== EmailConversation::STATUS_ACTIVE
            || (int) $suggestion->user_id !== (int) $actor->id
            || (int) $suggestion->account_id !== (int) $account->id
            || (int) $suggestion->email_conversation_id !== (int) $conversation->id
            || (int) $conversation->account_id !== (int) $account->id
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)
            || ($suggestion->status === EmailSmartInboxSuggestion::STATUS_PENDING
                && ! $this->recordedAgentIsCurrentReadReady($suggestion, $actor))
            || $suggestion->schema_version !== EmailSmartInboxSuggestion::SCHEMA_VERSION
            || ! hash_equals(
                (string) $suggestion->proposal_fingerprint,
                $this->identity->checksum((array) $suggestion->proposal_json),
            )) {
            return false;
        }

        $schema = $suggestion->source_fingerprint_schema
            ?: EmailConversationFingerprint::LEGACY_SCHEMA_VERSION;

        try {
            $current = $this->fingerprint($conversation, $schema);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $current['source_message_ids'] !== []
            && hash_equals((string) $suggestion->source_fingerprint, $current['fingerprint']);
    }

    private function canApply(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        return match ($suggestion->effect_type) {
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => $this->canApplyCategory(
                $suggestion,
                $actor,
                $account,
                $conversation,
            ),
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => $this->canApplyTag(
                $suggestion,
                $actor,
                $account,
                $conversation,
            ),
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => $this->canCreateTask($suggestion, $actor),
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => $this->canApplyCleanup(
                $suggestion,
                $actor,
                $account,
                $conversation,
            ),
            default => false,
        };
    }

    private function canApplyCategory(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        if (! $this->agentCanExecute($suggestion, $actor, 'email.update')
            || ! $this->canOrganize($actor, $account)) {
            return false;
        }

        $categoryId = $this->positiveInteger($suggestion->proposal_json['category_id'] ?? null);
        if (! $categoryId || ! Category::query()
            ->whereKey($categoryId)
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->exists()) {
            return false;
        }

        $classification = $this->classification($account, $conversation);
        $currentCategoryId = $classification?->category_id
            ? (int) $classification->category_id
            : null;

        if ($currentCategoryId !== null && $currentCategoryId !== $categoryId) {
            return false;
        }

        $assignedTags = $this->activeAssignedTags($classification);
        if ($assignedTags === null || ! $this->classificationActionResolvesTags($assignedTags)) {
            return false;
        }

        return ($classification && $currentCategoryId === $categoryId)
            || $this->activePlacementExists($suggestion, $account, $conversation);
    }

    private function canApplyTag(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        if (! $this->agentCanExecute($suggestion, $actor, 'email.update')
            || ! $this->canOrganize($actor, $account)) {
            return false;
        }

        $tagId = $this->positiveInteger($suggestion->proposal_json['tag_id'] ?? null);
        $tag = $tagId
            ? Tag::query()->whereKey($tagId)->where('active', true)->first()
            : null;

        if (! $tag) {
            return false;
        }

        $classification = $this->classification($account, $conversation);
        if ($classification?->category_id && ! Category::query()
            ->whereKey($classification->category_id)
            ->where('type', Category::TYPE_EMAIL)
            ->where('is_active', true)
            ->exists()) {
            return false;
        }

        $assignedTags = $this->activeAssignedTags($classification);
        if ($assignedTags === null) {
            return false;
        }

        if ($classification && $assignedTags->contains('id', $tag->id)) {
            return true;
        }

        return $this->classificationActionResolvesTags(
            $assignedTags->push($tag)->unique('id')->values(),
        ) && $this->activePlacementExists($suggestion, $account, $conversation);
    }

    private function canCreateTask(EmailSmartInboxSuggestion $suggestion, User $actor): bool
    {
        if (! $this->agentCanExecute($suggestion, $actor, 'tasks.create')
            || ! $actor->can('task.create')) {
            return false;
        }

        $title = $suggestion->proposal_json['title'] ?? null;
        if (! is_string($title)) {
            return false;
        }

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 255 || strip_tags($title) !== $title) {
            return false;
        }

        if (! $this->sourceMessageIsAllowed($suggestion, allowNull: true)) {
            return false;
        }

        $task = Task::query()
            ->where('source_type', ApplyEmailSmartInboxSuggestion::TASK_SOURCE_TYPE)
            ->where('source_id', $suggestion->id)
            ->first();

        return ! $task
            || ((int) $task->created_by === (int) $actor->id
                && $task->owner_type === $actor->getMorphClass()
                && (int) $task->owner_id === (int) $actor->id
                && $task->visibility === Task::VISIBILITY_INTERNAL);
    }

    private function canApplyCleanup(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        if (! $this->agentCanExecute($suggestion, $actor, 'email.update')
            || ! $this->canOrganize($actor, $account)) {
            return false;
        }

        $placement = $this->cleanupPlacement($suggestion, $account, $conversation);

        return $placement !== null && $this->cleanupTarget($suggestion, $account, $placement) !== null;
    }

    private function canBuildCleanupRule(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        if (! in_array($suggestion->effect_type, [
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
        ], true)
            || ! $suggestion->ai_agent_id
            || ! $this->recordedAgentIsCurrentReadReady($suggestion, $actor)
            || ! $this->canOrganize($actor, $account)) {
            return false;
        }

        $sourcePlacement = EmailMailboxPlacement::query()
            ->whereKey($suggestion->selected_email_mailbox_placement_id)
            ->where('account_id', $account->id)
            ->first(['id', 'email_folder_id', 'email_message_id']);

        if (! $sourcePlacement
            || ! $this->cleanupTarget($suggestion, $account, $sourcePlacement)
            || ! $this->ruleSourceMessage($suggestion, $conversation)) {
            return false;
        }

        if ($account->isPersonal()) {
            return (int) $account->owner_id === (int) $actor->id;
        }

        return $actor->can('email.rule_manage') && (bool) $account->ticket_ingress_enabled;
    }

    private function agentCanExecute(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
        string $requiredScope,
    ): bool {
        $cacheKey = $actor->id.':'.$suggestion->ai_agent_id.':'.$requiredScope;

        return $this->agentScopeAvailability[$cacheKey] ??= (function () use (
            $suggestion,
            $actor,
            $requiredScope,
        ): bool {
            $availability = $this->mailAiAgentRuntime->writeAvailability($actor, [$requiredScope]);
            $agent = $availability['agent'];
            $allowedScopes = collect($agent?->allowed_api_scopes ?? [])
                ->map(fn (mixed $scope): string => trim((string) $scope));

            return $availability['available']
                && $suggestion->ai_agent_id
                && (int) $suggestion->ai_agent_id === (int) $agent?->id
                && (bool) $agent?->can_execute_actions
                && $allowedScopes->contains($requiredScope);
        })();
    }

    private function recordedAgentIsCurrentReadReady(
        EmailSmartInboxSuggestion $suggestion,
        User $actor,
    ): bool {
        $availability = $this->readAgentAvailability[$actor->id]
            ??= $this->mailAiAgentRuntime->availability($actor);
        $agent = $availability['agent'] ?? null;

        // Pending UI authority remains attached to the active governed Mail
        // agent that produced the suggestion. A disabled, deleted, replaced,
        // provider-blocked, or policy-blocked agent requires fresh analysis.
        return (bool) ($availability['available'] ?? false)
            && $suggestion->ai_agent_id
            && (int) $suggestion->ai_agent_id === (int) $agent?->id;
    }

    private function canOrganize(User $actor, EmailAccount $account): bool
    {
        $cacheKey = $actor->id.':'.$account->id;

        return $this->mailboxOrganizeAvailability[$cacheKey]
            ??= $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE);
    }

    private function activePlacementExists(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
        EmailConversation $conversation,
    ): bool {
        return EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message')
            ->when(
                $suggestion->selected_email_mailbox_placement_id,
                fn ($placements, mixed $placementId) => $placements->whereKey($placementId),
            )
            ->exists();
    }

    private function cleanupPlacement(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
        EmailConversation $conversation,
    ): ?EmailMailboxPlacement {
        if (! $suggestion->selected_email_mailbox_placement_id
            || ! $this->sourceMessageIsAllowed($suggestion, allowNull: true)) {
            return null;
        }

        $sourceMessageId = $suggestion->proposal_json['source_message_id'] ?? null;
        $placement = EmailMailboxPlacement::query()
            ->whereKey($suggestion->selected_email_mailbox_placement_id)
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message')
            ->with(['folder', 'message'])
            ->first();

        if (! $placement
            || ($sourceMessageId !== null
                && (int) $placement->email_message_id !== (int) $sourceMessageId)
            || ! $placement->folder
            || ! $placement->folder->is_selectable
            || ! $placement->folder->sync_enabled
            || ! $this->cleanupSourceEvidenceMatches($suggestion, $placement)) {
            return null;
        }

        $storedSourcePath = $placement->getAttribute('folder_path');
        if (! is_string($storedSourcePath) || $storedSourcePath === '') {
            $storedSourcePath = $placement->folder->getAttribute('path');
        }
        try {
            $sourceFolderPath = EmailProviderPath::normalize((string) $storedSourcePath);
        } catch (InvalidArgumentException) {
            return null;
        }

        return hash_equals((string) $placement->folder->path, $sourceFolderPath)
            && (int) $placement->imap_uid > 0
                ? $placement
                : null;
    }

    private function cleanupTarget(
        EmailSmartInboxSuggestion $suggestion,
        EmailAccount $account,
        EmailMailboxPlacement $sourcePlacement,
    ): ?EmailFolder {
        $targetFolderId = $this->positiveInteger($suggestion->proposal_json['target_folder_id'] ?? null);
        if (! $targetFolderId) {
            return null;
        }

        $target = EmailFolder::query()
            ->whereKey($targetFolderId)
            ->where('account_id', $account->id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->when(
                $suggestion->effect_type === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                fn ($folders) => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->first();

        try {
            $targetPath = $target
                ? EmailProviderPath::normalize((string) $target->getAttribute('path'))
                : null;
        } catch (InvalidArgumentException) {
            $targetPath = null;
        }
        if (! $target
            || $targetPath === null
            || (int) $target->id === (int) $sourcePlacement->email_folder_id
            || (string) ($suggestion->proposal_json['target_folder_path'] ?? '') !== $targetPath
            || (string) ($suggestion->proposal_json['target_folder_name'] ?? '') !== (string) $target->name) {
            return null;
        }

        return $target;
    }

    private function cleanupSourceEvidenceMatches(
        EmailSmartInboxSuggestion $suggestion,
        EmailMailboxPlacement $placement,
    ): bool {
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

            $reviewed = $proposal[$key];
            $matches = is_int($currentValue)
                ? is_numeric($reviewed) && (int) $reviewed === $currentValue
                : is_string($reviewed) && hash_equals($currentValue, $reviewed);

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function ruleSourceMessage(
        EmailSmartInboxSuggestion $suggestion,
        EmailConversation $conversation,
    ): ?EmailMessage {
        $sourceIds = $this->sourceMessageIds($suggestion);
        $messageId = $suggestion->proposal_json['source_message_id'] ?? null;

        if ($messageId === null) {
            $messageId = EmailMailboxPlacement::query()
                ->whereKey($suggestion->selected_email_mailbox_placement_id)
                ->where('account_id', $conversation->account_id)
                ->value('email_message_id');
        }

        if (! is_numeric($messageId) || ! in_array((int) $messageId, $sourceIds, true)) {
            return null;
        }

        return EmailMessage::query()
            ->whereKey((int) $messageId)
            ->where('account_id', $conversation->account_id)
            ->first();
    }

    private function sourceMessageIsAllowed(
        EmailSmartInboxSuggestion $suggestion,
        bool $allowNull,
    ): bool {
        $sourceMessageId = $suggestion->proposal_json['source_message_id'] ?? null;

        if ($sourceMessageId === null) {
            return $allowNull;
        }

        return is_numeric($sourceMessageId)
            && in_array((int) $sourceMessageId, $this->sourceMessageIds($suggestion), true);
    }

    /** @return array<int, int> */
    private function sourceMessageIds(EmailSmartInboxSuggestion $suggestion): array
    {
        return collect($suggestion->source_message_ids_json ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function classification(
        EmailAccount $account,
        EmailConversation $conversation,
    ): ?EmailConversationClassification {
        return EmailConversationClassification::query()
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->first();
    }

    /** @return Collection<int, Tag>|null */
    private function activeAssignedTags(?EmailConversationClassification $classification): ?Collection
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
            ->get();

        return $tags->count() === $assignedIds->count()
            && ! $tags->contains(fn (Tag $tag): bool => ! $tag->active || $tag->trashed())
                ? $tags
                : null;
    }

    /** @param Collection<int, Tag> $tags */
    private function classificationActionResolvesTags(Collection $tags): bool
    {
        return $tags->every(function (Tag $tag): bool {
            $resolved = Tag::query()
                ->where(function ($query) use ($tag): void {
                    $query->where('name', $tag->name)
                        ->orWhere('slug', $tag->slug);
                })
                ->where('active', true)
                ->first();

            return $resolved && (int) $resolved->id === (int) $tag->id;
        });
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 && (string) $id === (string) $value ? $id : null;
    }

    /** @return array{fingerprint: string, source_message_ids: array<int, int>, schema_version: string} */
    private function fingerprint(EmailConversation $conversation, string $schema): array
    {
        $cacheKey = $conversation->id.':'.$schema;

        return $this->conversationFingerprints[$cacheKey]
            ??= $this->conversationFingerprint->forConversation($conversation, $schema);
    }

    /** @return array{is_available: false, can_apply: false, can_always_do_this: false} */
    private function unavailable(): array
    {
        return [
            'is_available' => false,
            'can_apply' => false,
            'can_always_do_this' => false,
        ];
    }
}
