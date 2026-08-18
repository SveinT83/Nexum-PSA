<?php

namespace App\Modules\Email\Livewire\Tech;

use App\Models\Core\User;
use App\Modules\Email\Actions\AnalyzeEmailConversationForSmartInbox;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\ApplyEmailSmartInboxSuggestionBatch;
use App\Modules\Email\Actions\BuildEmailSmartInboxRulePrefill;
use App\Modules\Email\Actions\CorrectEmailSmartInboxSuggestion;
use App\Modules\Email\Actions\DismissEmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Services\EmailSmartInboxSuggestionEligibility;
use App\Modules\Email\Services\EmailSmartInboxSuggestionStateService;
use App\Modules\Email\Services\MailAiAgentRuntime;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

class SmartInboxReviewQueue extends Component
{
    private const REFRESH_LIMIT = 50;

    private const DISPLAY_LIMIT = 25;

    private const CORRECTABLE_EFFECTS = [
        EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
        EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
        EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
        EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
        EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
        EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
    ];

    private const APPLICABLE_EFFECTS = [
        EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
        EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
        EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
        EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
        EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
    ];

    private const CLEANUP_EFFECTS = [
        EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
        EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
    ];

    /** The parent Mail workspace owns and updates the durable selection. */
    #[Reactive]
    public ?int $conversationId = null;

    #[Reactive]
    public ?int $selectedPlacementId = null;

    /** The selected conversation owns this ephemeral disclosure state. */
    #[Locked]
    public bool $reviewOpen = false;

    #[Locked]
    public ?int $correctingSuggestionId = null;

    public string $correctionSummary = '';

    public string $correctionUrgency = 'unknown';

    public bool $correctionReplyNeeded = false;

    public string $correctionTaskTitle = '';

    public string $correctionOwnerHint = '';

    public string $correctionDueHint = '';

    public ?int $correctionCategoryId = null;

    public ?int $correctionTagId = null;

    public ?int $correctionTargetFolderId = null;

    public string $correctionExplanation = '';

    public string $correctionConfidence = '';

    public ?string $feedbackMessage = null;

    public string $feedbackType = 'info';

    /** @var array<int, int|string> */
    public array $selectedSuggestionIds = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $batchResults = [];

    public function toggleReview(): void
    {
        $this->reviewOpen = ! $this->reviewOpen;

        $this->dispatch(
            $this->reviewOpen ? 'mail-smart-inbox-opened' : 'mail-smart-inbox-closed',
            elementId: ($this->reviewOpen ? 'smart-inbox-review-results-' : 'smart-inbox-review-trigger-')
                .$this->reviewDomSuffix(),
        );
    }

    public function closeReview(): void
    {
        $this->reviewOpen = false;
        $this->dispatch(
            'mail-smart-inbox-closed',
            elementId: 'smart-inbox-review-trigger-'.$this->reviewDomSuffix(),
        );
    }

    public function analyze(AnalyzeEmailConversationForSmartInbox $analyze): void
    {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $analyze->handle($conversation, $placement, $actor);
            $this->cancelCorrection();
            $this->setFeedback('Smart Inbox analysis completed.', 'success');
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'Smart Inbox analysis is unavailable for this selection.');
        }
    }

    public function beginCorrection(
        int $suggestionId,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestion = $this->scopedSuggestion(
                $suggestionId,
                $actor,
                $conversation,
                $placement,
                $stateService,
            );

            if ($suggestion->status !== EmailSmartInboxSuggestion::STATUS_PENDING
                || ! in_array($suggestion->effect_type, self::CORRECTABLE_EFFECTS, true)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion cannot be corrected.',
                ]);
            }

            $proposal = is_array($suggestion->proposal_json) ? $suggestion->proposal_json : [];
            $this->correctingSuggestionId = (int) $suggestion->id;
            $this->correctionSummary = (string) ($proposal['summary'] ?? '');
            $this->correctionUrgency = in_array(
                $proposal['urgency'] ?? null,
                ['low', 'normal', 'high', 'unknown'],
                true,
            ) ? (string) $proposal['urgency'] : 'unknown';
            $this->correctionReplyNeeded = (bool) ($proposal['reply_needed'] ?? false);
            $this->correctionTaskTitle = (string) ($proposal['title'] ?? '');
            $this->correctionOwnerHint = (string) ($proposal['owner_hint'] ?? '');
            $this->correctionDueHint = (string) ($proposal['due_at_hint'] ?? '');
            $this->correctionCategoryId = is_numeric($proposal['category_id'] ?? null)
                ? (int) $proposal['category_id']
                : null;
            $this->correctionTagId = is_numeric($proposal['tag_id'] ?? null)
                ? (int) $proposal['tag_id']
                : null;
            $this->correctionTargetFolderId = is_numeric($proposal['target_folder_id'] ?? null)
                ? (int) $proposal['target_folder_id']
                : null;
            $this->correctionExplanation = (string) ($suggestion->explanation ?? '');
            $this->correctionConfidence = $suggestion->confidence === null
                ? ''
                : rtrim(rtrim(number_format((float) $suggestion->confidence, 4, '.', ''), '0'), '.');
        } catch (Throwable $exception) {
            $this->cancelCorrection();
            $this->handleActionFailure($exception, 'Smart Inbox suggestion is unavailable.');
        }
    }

    public function cancelCorrection(): void
    {
        $this->correctingSuggestionId = null;
        $this->resetCorrectionFields();
        $this->resetValidation();
    }

    public function saveCorrection(
        CorrectEmailSmartInboxSuggestion $correct,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        $this->beginAction();

        try {
            if (! $this->correctingSuggestionId) {
                throw new AuthorizationException('Smart Inbox suggestion not found.');
            }

            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestion = $this->scopedSuggestion(
                $this->correctingSuggestionId,
                $actor,
                $conversation,
                $placement,
                $stateService,
            );

            if ($suggestion->status !== EmailSmartInboxSuggestion::STATUS_PENDING
                || ! in_array($suggestion->effect_type, self::CORRECTABLE_EFFECTS, true)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion cannot be corrected.',
                ]);
            }

            $proposal = $this->validatedCorrectionProposal($suggestion);
            $feedback = $this->validate([
                'correctionExplanation' => ['nullable', 'string', 'max:1000'],
                'correctionConfidence' => ['nullable', 'numeric', 'between:0,1'],
            ], [], [
                'correctionExplanation' => 'explanation',
                'correctionConfidence' => 'confidence',
            ]);
            $correct->handle(
                $suggestion,
                $actor,
                $proposal,
                $feedback['correctionExplanation'] ?? null,
                $feedback['correctionConfidence'] ?? null,
            );
            $this->cancelCorrection();
            $this->setFeedback('Smart Inbox correction saved for review.', 'success');
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'Smart Inbox correction could not be saved.');
        }
    }

    public function dismiss(
        int $suggestionId,
        DismissEmailSmartInboxSuggestion $dismiss,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestion = $this->scopedSuggestion(
                $suggestionId,
                $actor,
                $conversation,
                $placement,
                $stateService,
            );
            $dismiss->handle($suggestion, $actor);

            if ($this->correctingSuggestionId === $suggestionId) {
                $this->cancelCorrection();
            }

            $this->setFeedback('Smart Inbox suggestion dismissed.', 'success');
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'Smart Inbox suggestion could not be dismissed.');
        }
    }

    public function apply(
        int $suggestionId,
        ApplyEmailSmartInboxSuggestion $apply,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestion = $this->scopedSuggestion(
                $suggestionId,
                $actor,
                $conversation,
                $placement,
                $stateService,
            );

            // Keep informational and future effect types review-only until a
            // dedicated slice adds both a guarded domain action and UI copy.
            if (! in_array($suggestion->effect_type, self::APPLICABLE_EFFECTS, true)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'This Smart Inbox suggestion is review-only and cannot be applied.',
                ]);
            }

            $applied = $apply->handle($suggestion, $actor);

            if ($this->correctingSuggestionId === $suggestionId) {
                $this->cancelCorrection();
            }

            $feedback = $this->appliedFeedback($applied);
            $this->setFeedback($feedback['message'], $feedback['type']);
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'Smart Inbox suggestion could not be applied.');
        }
    }

    public function applySelected(ApplyEmailSmartInboxSuggestionBatch $applyBatch): void
    {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestionIds = $this->scopedCleanupBatchIds($actor, $conversation, $placement);
            $result = $applyBatch->handle($suggestionIds, $actor);
            $this->batchResults = collect($result['results'])
                ->values()
                ->map(fn (array $item, int $index): array => [
                    'label' => 'Item '.($index + 1),
                    'status' => $item['status'] === 'succeeded' ? 'succeeded' : 'failed',
                    'message' => $item['status'] === 'succeeded'
                        ? 'Provider cleanup completed and was recorded.'
                        : $this->batchFailureMessage((string) ($item['reason_code'] ?? 'operation_failed')),
                ])
                ->all();
            $this->selectedSuggestionIds = [];

            $succeeded = collect($this->batchResults)->where('status', 'succeeded')->count();
            $failed = count($this->batchResults) - $succeeded;
            $message = $failed === 0
                ? trans_choice(
                    '{1} One reviewed cleanup action completed.|[2,*] :count reviewed cleanup actions completed.',
                    $succeeded,
                    ['count' => $succeeded],
                )
                : $succeeded.' cleanup action(s) completed and '.$failed.' failed. Review the item results below.';
            $this->setFeedback($message, $failed === 0 ? 'success' : 'warning');
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'The selected Smart Inbox cleanup actions could not be applied.');
        }
    }

    public function alwaysDoThis(
        int $suggestionId,
        BuildEmailSmartInboxRulePrefill $buildPrefill,
        EmailSmartInboxSuggestionStateService $stateService,
    ): mixed {
        $this->beginAction();

        try {
            [$actor, $conversation, $placement] = $this->authorizedContext();
            $suggestion = $this->scopedSuggestion(
                $suggestionId,
                $actor,
                $conversation,
                $placement,
                $stateService,
            );
            $prefill = $buildPrefill->handle($suggestion, $actor);

            if ($prefill['mode'] === 'admin' && filled($prefill['admin_route'])) {
                return $this->redirect((string) $prefill['admin_route'], navigate: true);
            }

            $payload = $prefill['personal_payload'];
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'The personal cleanup rule prefill is unavailable.',
                ]);
            }

            $this->dispatch(
                'smart-inbox-personal-rule-prefill',
                ruleName: (string) ($payload['name'] ?? ''),
                conditionField: (string) ($payload['condition_field'] ?? ''),
                conditionValue: (string) ($payload['condition_value'] ?? ''),
                actionType: (string) ($payload['action_type'] ?? ''),
                targetFolderId: (int) ($payload['target_folder_id'] ?? 0),
            );
            $this->setFeedback('Personal rule draft prepared. Review it before saving.', 'info');

            return null;
        } catch (Throwable $exception) {
            $this->handleActionFailure($exception, 'The cleanup rule prefill is unavailable.');

            return null;
        }
    }

    public function render(
        MailboxAccess $mailboxAccess,
        MailAiAgentRuntime $mailAiAgentRuntime,
        EmailSmartInboxSuggestionEligibility $eligibility,
        EmailSmartInboxSuggestionStateService $stateService,
    ): View {
        $actor = $this->currentActor();

        if (! $actor) {
            return view('email::Livewire.Tech.smart-inbox-review-queue', $this->emptyViewData());
        }

        // Refresh before account authorization/filtering. A revoked mailbox
        // must transition its pending rows to durable revoked audit state even
        // though the response remains completely hidden afterward.
        $this->refreshPendingSuggestionStates($actor, $stateService);
        $context = $this->resolveContext($actor, $mailboxAccess);

        if (! $context) {
            return view('email::Livewire.Tech.smart-inbox-review-queue', $this->emptyViewData());
        }

        [$conversation, $placement] = $context;
        $suggestions = EmailSmartInboxSuggestion::query()
            ->where('user_id', $actor->id)
            ->where('account_id', $placement->account_id)
            ->where('email_conversation_id', $conversation->id)
            ->whereIn('status', [
                EmailSmartInboxSuggestion::STATUS_PENDING,
                EmailSmartInboxSuggestion::STATUS_APPLIED,
            ])
            ->whereIn('effect_type', self::CORRECTABLE_EFFECTS)
            ->with('aiAgent:id,name')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('generated_at')
            ->latest('id')
            ->limit(self::DISPLAY_LIMIT)
            ->get();
        $remoteOperationStatuses = EmailRemoteOperation::query()
            ->whereIn(
                'id',
                $suggestions
                    ->where('applied_reference_type', ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION)
                    ->pluck('applied_reference_id')
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id),
            )
            ->pluck('status', 'id');
        $presentedSuggestions = $suggestions
            ->map(function (EmailSmartInboxSuggestion $suggestion) use (
                $actor,
                $conversation,
                $eligibility,
                $placement,
                $remoteOperationStatuses,
            ): array {
                $actionEligibility = $eligibility->forDisplay(
                    $suggestion,
                    $actor,
                    $conversation,
                    $placement,
                );

                return $this->presentSuggestion(
                    $suggestion,
                    $actionEligibility,
                    $suggestion->applied_reference_type === ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION
                        ? $remoteOperationStatuses->get((int) $suggestion->applied_reference_id)
                        : null,
                );
            })
            ->filter(fn (array $suggestion): bool => $suggestion['is_available']
                && ($suggestion['status'] === EmailSmartInboxSuggestion::STATUS_APPLIED
                    || $suggestion['effect_type'] === EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY
                    || $suggestion['can_apply']
                    || $suggestion['can_always_do_this']))
            ->values();
        $analysisAvailable = (bool) $mailAiAgentRuntime->availability($actor)['available'];
        $showSurface = $analysisAvailable
            || $presentedSuggestions->isNotEmpty()
            || filled($this->feedbackMessage)
            || $this->batchResults !== [];
        $batchSelectableIds = $presentedSuggestions
            ->where('can_batch_select', true)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        $selectedBatchCount = collect($this->selectedSuggestionIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->intersect($batchSelectableIds)
            ->unique()
            ->count();

        return view('email::Livewire.Tech.smart-inbox-review-queue', [
            'showSurface' => $showSurface,
            'analysisAvailable' => $analysisAvailable,
            'conversationPendingCount' => $presentedSuggestions
                ->where('status', EmailSmartInboxSuggestion::STATUS_PENDING)
                ->count(),
            'selectedBatchCount' => $selectedBatchCount,
            'suggestions' => $presentedSuggestions,
            'categoryOptions' => $this->categoryOptions(),
            'tagOptions' => $this->tagOptions(),
            'folderOptions' => $this->folderOptions($placement),
        ]);
    }

    /**
     * @return array{0: User, 1: EmailConversation, 2: EmailMailboxPlacement}
     */
    private function authorizedContext(): array
    {
        $actor = $this->currentActor();
        $context = $actor ? $this->resolveContext($actor, app(MailboxAccess::class)) : null;

        if (! $actor || ! $context) {
            throw new AuthorizationException('Smart Inbox selection not found.');
        }

        return [$actor, $context[0], $context[1]];
    }

    private function currentActor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }

    /** @return array{0: EmailConversation, 1: EmailMailboxPlacement}|null */
    private function resolveContext(User $actor, MailboxAccess $mailboxAccess): ?array
    {
        if (! $this->conversationId || ! $this->selectedPlacementId) {
            return null;
        }

        $placement = EmailMailboxPlacement::query()
            ->whereKey($this->selectedPlacementId)
            ->where('email_conversation_id', $this->conversationId)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->with('account:id,account_kind,owner_id,is_active,ticket_ingress_enabled')
            ->first();

        if (! $placement?->account
            || ! $placement->account->is_active
            || ! $mailboxAccess->canAccessAccount($actor, $placement->account, MailboxAccess::VIEW)) {
            return null;
        }

        $conversation = EmailConversation::query()
            ->whereKey($this->conversationId)
            ->where('account_id', $placement->account_id)
            ->where('status', EmailConversation::STATUS_ACTIVE)
            ->first();

        return $conversation ? [$conversation, $placement] : null;
    }

    private function refreshPendingSuggestionStates(
        User $actor,
        EmailSmartInboxSuggestionStateService $stateService,
    ): void {
        if (! $this->conversationId) {
            return;
        }

        EmailSmartInboxSuggestion::query()
            ->where('user_id', $actor->id)
            ->where('email_conversation_id', $this->conversationId)
            ->where('status', EmailSmartInboxSuggestion::STATUS_PENDING)
            ->oldest('id')
            ->limit(self::REFRESH_LIMIT)
            ->get()
            ->each(function (EmailSmartInboxSuggestion $suggestion) use ($actor, $stateService): void {
                try {
                    $stateService->refresh($suggestion, $actor);
                } catch (AuthorizationException) {
                    // The response stays hidden. The state service owns any
                    // durable revoked transition that current authority allows.
                }
            });
    }

    private function scopedSuggestion(
        int $suggestionId,
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
        EmailSmartInboxSuggestionStateService $stateService,
    ): EmailSmartInboxSuggestion {
        $suggestion = EmailSmartInboxSuggestion::query()
            ->whereKey($suggestionId)
            ->where('user_id', $actor->id)
            ->where('account_id', $placement->account_id)
            ->where('email_conversation_id', $conversation->id)
            ->first();

        if (! $suggestion) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        $suggestion = $stateService->refresh($suggestion, $actor);

        if ($suggestion->status === EmailSmartInboxSuggestion::STATUS_REVOKED) {
            throw new AuthorizationException('Smart Inbox suggestion not found.');
        }

        return $suggestion;
    }

    /** @return array<int, int> */
    private function scopedCleanupBatchIds(
        User $actor,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
    ): array {
        $ids = collect($this->selectedSuggestionIds)
            ->map(function (mixed $id): int {
                if (! is_int($id) && (! is_string($id) || ! ctype_digit($id))) {
                    throw ValidationException::withMessages([
                        'suggestions' => 'Choose valid Smart Inbox cleanup suggestions.',
                    ]);
                }

                return (int) $id;
            })
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'suggestions' => 'Choose at least one pending cleanup suggestion.',
            ]);
        }

        if ($ids->count() > ApplyEmailSmartInboxSuggestionBatch::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'suggestions' => 'Choose no more than '.ApplyEmailSmartInboxSuggestionBatch::MAX_ITEMS.' cleanup suggestions.',
            ]);
        }

        $scopedIds = EmailSmartInboxSuggestion::query()
            ->whereIn('id', $ids->all())
            ->where('user_id', $actor->id)
            ->where('account_id', $placement->account_id)
            ->where('email_conversation_id', $conversation->id)
            ->where('status', EmailSmartInboxSuggestion::STATUS_PENDING)
            ->whereIn('effect_type', self::CLEANUP_EFFECTS)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        if ($scopedIds->count() !== $ids->count()) {
            throw new AuthorizationException('Smart Inbox cleanup selection not found.');
        }

        return $ids->all();
    }

    /** @return array<string, mixed> */
    private function validatedCorrectionProposal(EmailSmartInboxSuggestion $suggestion): array
    {
        $current = is_array($suggestion->proposal_json) ? $suggestion->proposal_json : [];

        return match ($suggestion->effect_type) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => $this->reviewSummaryCorrection($current),
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => $this->taskCorrection($current),
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => $this->categoryCorrection($current),
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => $this->tagCorrection($current),
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => $this->cleanupCorrection($current),
            default => throw ValidationException::withMessages([
                'suggestion' => 'This Smart Inbox effect cannot be corrected.',
            ]),
        };
    }

    /** @param array<string, mixed> $current */
    private function reviewSummaryCorrection(array $current): array
    {
        $validated = $this->validate([
            'correctionSummary' => ['required', 'string', 'max:1200'],
            'correctionUrgency' => ['required', 'in:low,normal,high,unknown'],
            'correctionReplyNeeded' => ['boolean'],
        ], [], [
            'correctionSummary' => 'summary',
            'correctionUrgency' => 'urgency',
            'correctionReplyNeeded' => 'reply needed',
        ]);

        return [
            'summary' => $validated['correctionSummary'],
            'key_points' => $current['key_points'] ?? [],
            'questions' => $current['questions'] ?? [],
            'urgency' => $validated['correctionUrgency'],
            'reply_needed' => (bool) $validated['correctionReplyNeeded'],
        ];
    }

    /** @param array<string, mixed> $current */
    private function taskCorrection(array $current): array
    {
        $validated = $this->validate([
            'correctionTaskTitle' => ['required', 'string', 'max:255'],
            'correctionOwnerHint' => ['nullable', 'string', 'max:180'],
            'correctionDueHint' => ['nullable', 'string', 'max:120'],
        ], [], [
            'correctionTaskTitle' => 'Task title',
            'correctionOwnerHint' => 'owner hint',
            'correctionDueHint' => 'due hint',
        ]);

        return [
            'title' => $validated['correctionTaskTitle'],
            'owner_hint' => $validated['correctionOwnerHint'] !== ''
                ? $validated['correctionOwnerHint']
                : null,
            'due_at_hint' => $validated['correctionDueHint'] !== ''
                ? $validated['correctionDueHint']
                : null,
            'source_message_id' => $current['source_message_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $current */
    private function categoryCorrection(array $current): array
    {
        $validated = $this->validate([
            'correctionCategoryId' => ['required', 'integer', 'min:1'],
        ], [], ['correctionCategoryId' => 'Email category']);

        return [
            'category_id' => (int) $validated['correctionCategoryId'],
            'source_message_id' => $current['source_message_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $current */
    private function tagCorrection(array $current): array
    {
        $validated = $this->validate([
            'correctionTagId' => ['required', 'integer', 'min:1'],
        ], [], ['correctionTagId' => 'tag']);

        return [
            'tag_id' => (int) $validated['correctionTagId'],
            'source_message_id' => $current['source_message_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $current */
    private function cleanupCorrection(array $current): array
    {
        $validated = $this->validate([
            'correctionTargetFolderId' => ['required', 'integer', 'min:1'],
        ], [], ['correctionTargetFolderId' => 'provider folder']);

        return [
            'target_folder_id' => (int) $validated['correctionTargetFolderId'],
            'source_message_id' => $current['source_message_id'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentSuggestion(
        EmailSmartInboxSuggestion $suggestion,
        array $eligibility,
        ?string $remoteOperationStatus = null,
    ): array {
        $appliedPresentation = $this->appliedReferencePresentation($suggestion, $remoteOperationStatus);

        return [
            'id' => (int) $suggestion->id,
            'effect_type' => $suggestion->effect_type,
            'effect_label' => $this->effectLabel($suggestion->effect_type),
            'effect_icon' => $this->effectIcon($suggestion->effect_type),
            'status' => $suggestion->status,
            'status_label' => $appliedPresentation['status_label']
                ?? ($suggestion->status === EmailSmartInboxSuggestion::STATUS_PENDING
                    ? 'Needs review'
                    : Str::headline($suggestion->status)),
            'status_class' => $appliedPresentation['status_class'] ?? $this->statusClass($suggestion->status),
            'impact' => $this->effectImpact($suggestion->effect_type),
            'details' => $this->proposalDetails($suggestion),
            'explanation' => filled($suggestion->explanation) ? (string) $suggestion->explanation : null,
            'confidence' => $suggestion->confidence === null
                ? null
                : (int) round((float) $suggestion->confidence * 100),
            'agent' => filled($suggestion->aiAgent?->name)
                ? (string) $suggestion->aiAgent->name
                : 'Governed Mail AI',
            'model' => filled($suggestion->ai_model) ? (string) $suggestion->ai_model : null,
            'policy_revision' => $suggestion->ai_policy_revision,
            'generated_at' => $suggestion->generated_at?->format('Y-m-d H:i'),
            'source_count' => collect($suggestion->source_message_ids_json ?? [])->filter()->count(),
            'is_available' => (bool) ($eligibility['is_available'] ?? false),
            'can_correct' => $suggestion->status === EmailSmartInboxSuggestion::STATUS_PENDING
                && in_array($suggestion->effect_type, self::CORRECTABLE_EFFECTS, true),
            'can_apply' => (bool) ($eligibility['can_apply'] ?? false),
            'can_dismiss' => $suggestion->status === EmailSmartInboxSuggestion::STATUS_PENDING,
            'can_batch_select' => (bool) ($eligibility['can_apply'] ?? false)
                && in_array($suggestion->effect_type, self::CLEANUP_EFFECTS, true),
            'can_always_do_this' => (bool) ($eligibility['can_always_do_this'] ?? false),
            'is_correcting' => (int) $this->correctingSuggestionId === (int) $suggestion->id,
            'applied_label' => $appliedPresentation['label']
                ?? $this->appliedReferenceLabel($suggestion->applied_reference_type),
            'applied_class' => $appliedPresentation['class'] ?? 'text-success',
            'applied_icon' => $appliedPresentation['icon'] ?? 'bi-check-circle',
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function proposalDetails(EmailSmartInboxSuggestion $suggestion): array
    {
        $proposal = is_array($suggestion->proposal_json) ? $suggestion->proposal_json : [];

        return match ($suggestion->effect_type) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => array_values(array_filter([
                filled($proposal['summary'] ?? null)
                    ? ['label' => 'Summary', 'value' => (string) $proposal['summary']]
                    : null,
                ['label' => 'Urgency', 'value' => Str::headline((string) ($proposal['urgency'] ?? 'unknown'))],
                ['label' => 'Reply needed', 'value' => (bool) ($proposal['reply_needed'] ?? false) ? 'Yes' : 'No'],
            ])),
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => array_values(array_filter([
                filled($proposal['title'] ?? null)
                    ? ['label' => 'Task', 'value' => (string) $proposal['title']]
                    : null,
                filled($proposal['owner_hint'] ?? null)
                    ? ['label' => 'Owner hint', 'value' => (string) $proposal['owner_hint']]
                    : null,
                filled($proposal['due_at_hint'] ?? null)
                    ? ['label' => 'Due hint', 'value' => (string) $proposal['due_at_hint']]
                    : null,
            ])),
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => filled($proposal['category_name'] ?? null)
                ? [['label' => 'Email category', 'value' => (string) $proposal['category_name']]]
                : [],
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => filled($proposal['tag_name'] ?? null)
                ? [['label' => 'Tag', 'value' => (string) $proposal['tag_name']]]
                : [],
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => array_values(array_filter([
                filled($proposal['target_folder_name'] ?? null)
                    ? ['label' => 'Provider folder', 'value' => (string) $proposal['target_folder_name']]
                    : null,
                filled($proposal['target_folder_path'] ?? null)
                    ? ['label' => 'Folder path', 'value' => (string) $proposal['target_folder_path']]
                    : null,
            ])),
            default => [],
        };
    }

    private function effectLabel(string $effectType): string
    {
        return match ($effectType) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => 'Conversation review',
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => 'Apply Email category',
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => 'Apply tag',
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => 'Create Task',
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL => 'Archive provider mail',
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => 'Move provider mail',
            default => 'Future Smart Inbox action',
        };
    }

    private function effectIcon(string $effectType): string
    {
        return match ($effectType) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => 'bi-card-text',
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => 'bi-folder-check',
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => 'bi-tags',
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => 'bi-check2-square',
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL => 'bi-archive',
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => 'bi-folder-symlink',
            default => 'bi-lightbulb',
        };
    }

    private function effectImpact(string $effectType): string
    {
        return match ($effectType) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => 'Read-only review. It never changes Mail, the provider mailbox, or a PSA record.',
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => 'Would add an existing Email category in Nexum. It does not change the provider mailbox.',
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => 'Would add an existing Nexum tag. It does not change the provider mailbox.',
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => 'Would create one editable internal Task owned by you. It does not change the provider mailbox.',
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL => 'Would move the selected provider message to its Archive folder after your confirmation. Nexum records the result and offers Undo only while the verified operation remains eligible.',
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => 'Would move the selected provider message to the named folder after your confirmation. Nexum records the result and offers Undo only while the verified operation remains eligible.',
            default => 'Review required. This interface does not apply this effect.',
        };
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            EmailSmartInboxSuggestion::STATUS_PENDING => 'text-bg-warning',
            EmailSmartInboxSuggestion::STATUS_APPLIED => 'text-bg-success',
            EmailSmartInboxSuggestion::STATUS_STALE => 'text-bg-secondary',
            EmailSmartInboxSuggestion::STATUS_DISMISSED => 'text-bg-light border text-secondary',
            default => 'text-bg-light border text-secondary',
        };
    }

    private function appliedReferenceLabel(?string $referenceType): ?string
    {
        return match ($referenceType) {
            ApplyEmailSmartInboxSuggestion::REFERENCE_CONVERSATION_CLASSIFICATION => 'Conversation classification updated',
            ApplyEmailSmartInboxSuggestion::REFERENCE_TASK => 'Internal Task created',
            ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION => 'Provider cleanup operation recorded',
            default => null,
        };
    }

    /**
     * A cleanup suggestion is durably applied once its ledger row is recorded,
     * but the provider outcome has its own state. Present both honestly on
     * every render instead of turning every durable reference green.
     *
     * @return array{status_label: string, status_class: string, label: string, class: string, icon: string}|array{}
     */
    private function appliedReferencePresentation(
        EmailSmartInboxSuggestion $suggestion,
        ?string $remoteOperationStatus,
    ): array {
        if ($suggestion->status !== EmailSmartInboxSuggestion::STATUS_APPLIED
            || $suggestion->applied_reference_type !== ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION) {
            return [];
        }

        return match ($remoteOperationStatus) {
            EmailRemoteOperation::STATUS_SUCCEEDED => [
                'status_label' => 'Provider completed',
                'status_class' => 'text-bg-success',
                'label' => 'Provider cleanup completed and recorded',
                'class' => 'text-success',
                'icon' => 'bi-check-circle',
            ],
            EmailRemoteOperation::STATUS_FAILED => [
                'status_label' => 'Provider failed',
                'status_class' => 'text-bg-danger',
                'label' => 'Provider cleanup failed; review recent Mail operations',
                'class' => 'text-danger',
                'icon' => 'bi-exclamation-triangle',
            ],
            EmailRemoteOperation::STATUS_SUPERSEDED => [
                'status_label' => 'Provider unconfirmed',
                'status_class' => 'text-bg-warning',
                'label' => 'Provider cleanup could not be confirmed safely',
                'class' => 'text-warning-emphasis',
                'icon' => 'bi-exclamation-triangle',
            ],
            EmailRemoteOperation::STATUS_CANCELLED => [
                'status_label' => 'Provider cancelled',
                'status_class' => 'text-bg-secondary',
                'label' => 'Provider cleanup was cancelled',
                'class' => 'text-secondary',
                'icon' => 'bi-x-circle',
            ],
            EmailRemoteOperation::STATUS_PENDING,
            EmailRemoteOperation::STATUS_RUNNING => [
                'status_label' => 'Provider pending',
                'status_class' => 'text-bg-info',
                'label' => 'Provider cleanup is waiting for acknowledgement',
                'class' => 'text-info-emphasis',
                'icon' => 'bi-clock-history',
            ],
            default => [
                'status_label' => 'Provider unavailable',
                'status_class' => 'text-bg-danger',
                'label' => 'Provider cleanup record is unavailable; review recent Mail operations',
                'class' => 'text-danger',
                'icon' => 'bi-exclamation-triangle',
            ],
        };
    }

    /** @return array{message: string, type: string} */
    private function appliedFeedback(EmailSmartInboxSuggestion $suggestion): array
    {
        if ($suggestion->applied_reference_type === ApplyEmailSmartInboxSuggestion::REFERENCE_EMAIL_REMOTE_OPERATION) {
            $status = EmailRemoteOperation::query()
                ->whereKey((int) $suggestion->applied_reference_id)
                ->value('status');

            return match ($status) {
                EmailRemoteOperation::STATUS_SUCCEEDED => [
                    'message' => 'Provider cleanup completed and was recorded.',
                    'type' => 'success',
                ],
                EmailRemoteOperation::STATUS_FAILED => [
                    'message' => 'The cleanup request was recorded, but the provider operation failed. Review recent Mail operations before retrying.',
                    'type' => 'danger',
                ],
                EmailRemoteOperation::STATUS_SUPERSEDED => [
                    'message' => 'The cleanup request was recorded, but its provider result could not be proven safely. Review recent Mail operations.',
                    'type' => 'warning',
                ],
                EmailRemoteOperation::STATUS_CANCELLED => [
                    'message' => 'The cleanup request was recorded and then cancelled before completion.',
                    'type' => 'warning',
                ],
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING => [
                    'message' => 'The cleanup request was recorded and is waiting for provider acknowledgement.',
                    'type' => 'info',
                ],
                default => [
                    'message' => 'The cleanup request was recorded, but its provider operation could not be found. Review recent Mail operations.',
                    'type' => 'danger',
                ],
            };
        }

        return [
            'message' => $this->appliedReferenceLabel($suggestion->applied_reference_type)
                ?? 'Smart Inbox suggestion applied.',
            'type' => 'success',
        ];
    }

    /** @return Collection<int, array{id: int, name: string, role: string}> */
    private function folderOptions(EmailMailboxPlacement $placement): Collection
    {
        return EmailFolder::query()
            ->where('account_id', $placement->account_id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKeyNot($placement->email_folder_id)
            ->orderByRaw("CASE WHEN role = 'archive' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'role'])
            ->map(fn (EmailFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => (string) $folder->name,
                'role' => (string) $folder->role,
            ]);
    }

    private function batchFailureMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'not_found', 'not_authorized' => 'This cleanup suggestion is no longer available.',
            'stale' => 'The conversation changed. Analyze it again before cleanup.',
            'dismissed' => 'This cleanup suggestion was already dismissed.',
            'validation_failed' => 'The cleanup suggestion no longer passes its safety checks.',
            'not_cleanup_effect' => 'Only reviewed provider Archive and Move suggestions can be applied in this cleanup batch.',
            'duplicate_source_placement' => 'Another selected cleanup item already targets this exact provider message placement.',
            'remote_operation_failed' => 'The provider rejected the cleanup operation.',
            'remote_operation_superseded' => 'The provider result could not be proven safely and requires reconciliation.',
            'remote_operation_pending', 'remote_operation_running' => 'The provider operation is still awaiting acknowledgement.',
            'remote_operation_cancelled' => 'The provider cleanup operation was cancelled.',
            default => 'The cleanup operation could not be completed.',
        };
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function categoryOptions(): Collection
    {
        return Category::query()
            ->active()
            ->where('type', Category::TYPE_EMAIL)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ]);
    }

    /** @return Collection<int, array{id: int, name: string}> */
    private function tagOptions(): Collection
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
            ]);
    }

    private function beginAction(): void
    {
        $this->feedbackMessage = null;
        $this->feedbackType = 'info';
        $this->batchResults = [];
        $this->resetErrorBag();
    }

    private function setFeedback(string $message, string $type): void
    {
        $this->feedbackMessage = $message;
        $this->feedbackType = $type;
    }

    private function handleActionFailure(Throwable $exception, string $fallback): void
    {
        if ($exception instanceof AuthorizationException) {
            // Controls that lose authority disappear on the next render. Do
            // not replace them with a generic error that cannot help the user.
            $this->feedbackMessage = null;
            $this->feedbackType = 'info';
            $this->selectedSuggestionIds = [];

            return;
        }

        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();
            $message = is_string($message) && $message !== ''
                ? Str::limit(strip_tags($message), 240, '')
                : $fallback;
            $this->setFeedback($message, 'danger');

            return;
        }

        report($exception);
        $this->setFeedback($fallback, 'danger');
    }

    private function resetCorrectionFields(): void
    {
        $this->correctionSummary = '';
        $this->correctionUrgency = 'unknown';
        $this->correctionReplyNeeded = false;
        $this->correctionTaskTitle = '';
        $this->correctionOwnerHint = '';
        $this->correctionDueHint = '';
        $this->correctionCategoryId = null;
        $this->correctionTagId = null;
        $this->correctionTargetFolderId = null;
        $this->correctionExplanation = '';
        $this->correctionConfidence = '';
    }

    private function reviewDomSuffix(): string
    {
        return ((int) $this->conversationId).'-'.((int) $this->selectedPlacementId);
    }

    /** @return array<string, mixed> */
    private function emptyViewData(): array
    {
        return [
            // A successful cleanup can replace the selected provider
            // placement before this child rerenders. Keep only the already
            // sanitized action outcome visible; an unavailable context with
            // no outcome still renders no Smart Inbox surface.
            'showSurface' => filled($this->feedbackMessage) || $this->batchResults !== [],
            'analysisAvailable' => false,
            'conversationPendingCount' => 0,
            'selectedBatchCount' => 0,
            'suggestions' => collect(),
            'categoryOptions' => collect(),
            'tagOptions' => collect(),
            'folderOptions' => collect(),
        ];
    }
}
