<?php

namespace App\Modules\Email\Livewire\Tech;

use App\Modules\Email\Livewire\Tech\Concerns\ManagesSharedComposerDraft;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\AssistEmailComposerWithAi;
use App\Modules\Email\Actions\CancelEmailRemoteOperation;
use App\Modules\Email\Actions\CreatePersonalEmailRule;
use App\Modules\Email\Actions\CreateProviderEmailFolder;
use App\Modules\Email\Actions\LinkEmailConversationToTicket;
use App\Modules\Email\Actions\ManageProviderEmailFolder;
use App\Modules\Email\Actions\MarkEmailAsSpam;
use App\Modules\Email\Actions\PerformEmailRemoteOperation;
use App\Modules\Email\Actions\RecordEmailMessageOpened;
use App\Modules\Email\Actions\RetryEmailRemoteOperation;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Actions\SetEmailUnreadForMe;
use App\Modules\Email\Actions\SubmitEmailComposerDraft;
use App\Modules\Email\Actions\SuppressEmailConversationTicketCorrelation;
use App\Modules\Email\Actions\SummarizeEmailWithAi;
use App\Modules\Email\Actions\UndoEmailRemoteOperation;
use App\Modules\Email\Actions\UpdateEmailConversationClassification;
use App\Modules\Email\Jobs\FetchImapAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageClassification;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\EmailCollaborationGate;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailDraftConflictException;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailLiveCatchUpService;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use App\Modules\Email\Services\EmailRemoteOperationEvidenceSanitizer;
use App\Modules\Email\Services\EmailRemoteOperationUndoEligibility;
use App\Modules\Email\Services\EmailSignatureRenderer;
use App\Modules\Email\Services\EmailSubmissionConflictException;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Services\MailAiAgentRuntime;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessDecision;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\CreateTicketFromInboundEmail;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

class MailWorkspace extends Component
{
    use ManagesSharedComposerDraft;
    use WithFileUploads;
    use WithPagination;

    public string $invalidationVersion = '0';

    public string $liveAuthorizationEpoch = '0';

    public string $liveGlobalAuthorizationGeneration = '0';

    public string $liveAppliedReceipt = '';

    public bool $liveEnabled = false;

    public bool $collaborationEnabled = false;

    public function mount(): void
    {
        $this->liveEnabled = app(EmailLiveRuntimeReadiness::class)->ready()
            && Schema::hasTable('email_live_projection_streams');
        // The separately approved UI gate is checked before the Order 9
        // backend gate. The quarantined SQL-lock/whisper scaffold is never a
        // fallback, even if its historical table exists on an old install.
        $this->collaborationEnabled = $this->liveEnabled
            && config('email_live.collaboration_ui_enabled', false)
            && app(EmailCollaborationGate::class)->available();

        if ($this->liveEnabled) {
            $this->invalidationVersion = (string) (EmailLiveProjectionStream::query()
                ->where('stream_type', EmailLiveProjectionStream::TYPE_USER)
                ->where('user_id', auth()->id())
                ->value('current_version') ?? '0');
            $this->liveAuthorizationEpoch = (string) (DB::table('email_live_user_access_states')
                ->where('user_id', auth()->id())
                ->value('authorization_epoch') ?? '0');
            $this->liveGlobalAuthorizationGeneration = (string) (DB::table('email_live_global_authority_states')
                ->where('id', 1)
                ->value('authorization_generation') ?? '0');
            $this->liveAppliedReceipt = app(EmailLiveCatchUpService::class)->receipt(
                $this->user(),
                $this->invalidationVersion,
                $this->liveAuthorizationEpoch,
                $this->liveGlobalAuthorizationGeneration,
            );
        }

        if ($this->selectedPlacementId && in_array($this->openComposerMode, [
            SendEmailComposerMessage::MODE_REPLY,
            SendEmailComposerMessage::MODE_REPLY_ALL,
        ], true)) {
            $mode = $this->openComposerMode;
            $this->openComposerMode = '';
            $this->startComposer($mode);
        }
    }

    public function getListeners()
    {
        return [
            'mail-filters-changed' => 'applyFilters',
            'email-mail-invalidated' => 'handleEmailProjectionInvalidated',
        ];
    }

    public function handleEmailProjectionInvalidated(array $payload): void
    {
        if (! $this->liveEnabled) {
            return;
        }

        // Socket payloads are opaque hints. Durable state is re-read before
        // any identifier or projection refresh is trusted.
        $this->catchUpInvalidation();
    }

    public function catchUpInvalidation(bool $forceBoundedRefresh = false): void
    {
        if (! $this->liveEnabled) {
            return;
        }

        $user = $this->user();
        if (! $user) {
            return;
        }

        $catchUp = app(EmailLiveCatchUpService::class);
        $catchUp->acknowledgeAppliedVersion(
            $user,
            $this->invalidationVersion,
            $this->liveAuthorizationEpoch,
            $this->liveGlobalAuthorizationGeneration,
            $this->liveAppliedReceipt,
        );
        $result = $catchUp->catchUp(
            $user,
            $this->invalidationVersion,
            $this->liveAuthorizationEpoch,
            $this->liveGlobalAuthorizationGeneration,
            $forceBoundedRefresh,
        );
        $this->invalidationVersion = $result['to_version'];
        $this->liveAuthorizationEpoch = $result['authorization_epoch'];
        $this->liveGlobalAuthorizationGeneration = $result['global_authorization_generation'];
        $this->liveAppliedReceipt = $result['applied_receipt'];

        if ($result['skip_render']) {
            $this->skipRender();

            return;
        }

        $this->refreshMailState();

    }

    public function acknowledgeConversation(): void
    {
        if (! config('email_live.conversation_acknowledgement_enabled', false)
            || ! Schema::hasTable('email_conversation_action_runs')
            || ! Schema::hasTable('email_conversation_action_items')) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Conversation acknowledgement is not available yet.',
            ];

            return;
        }

        // The safe action requires a separate bounded preview and explicit
        // confirmation surface. Do not turn a callable Livewire method into
        // an implicit whole-conversation mutation while that UI remains gated.
        $this->mailActionStatus = [
            'type' => 'warning',
            'message' => 'Conversation acknowledgement requires preview and confirmation.',
        ];
    }

    private function refreshMailState(): void
    {
        // For Livewire, simply calling a method that does nothing but exists
        // will trigger a re-render and re-run the queries in the blade view.
        // We can also clear some specific caches if needed.
        $this->unreadForMeByMessage = [];
    }

    private const LEGACY_CONVERSATION_LIST_LIMIT = 100;

    private const LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT = 50;

    private const LEGACY_CONVERSATION_THREAD_LIMIT = 200;

    protected string $paginationTheme = 'bootstrap';

    public string $viewMode = 'unread';

    public mixed $accountId = '';

    public mixed $folderId = '';

    public string $search = '';

    public string $listFilter = 'all';

    public ?int $selectedPlacementId = null;

    public string $openComposerMode = '';

    public int $perPage = 25;

    public int $legacyConversationThreadLimit = self::LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT;

    private bool $legacyConversationListTruncated = false;

    /** @var array<int, bool> */
    private array $ordinaryViewAccessByAccount = [];

    /** @var array<int, bool> */
    private array $unreadForMeByMessage = [];

    /** @var array<int>|null */
    private ?array $ordinaryAccountIdsCache = null;

    /** @var array{type: string, message: string}|null */
    public ?array $mailActionStatus = null;

    /** @var array{type: string, message: string}|null */
    public ?array $composerActionStatus = null;

    public bool $composerOpen = false;

    public string $composerMode = SendEmailComposerMessage::MODE_REPLY;

    public mixed $composerAccountId = '';

    public string $composerTo = '';

    public string $composerCc = '';

    public string $composerSubject = '';

    public string $composerBodyHtml = '';

    public string $composerIdempotencyKey = '';

    public mixed $composerDraftId = '';

    public string $composerDraftFence = '';

    public string $composerDraftStatus = '';

    public string $composerDraftSavedAt = '';

    public string $composerDraftProviderStatus = '';

    public string $composerDraftProviderMessage = '';

    public bool $composerDraftHasUnsavedAttachments = false;

    public string $composerDraftBaselineHash = '';

    /** @var array<int, mixed> */
    public array $composerAttachments = [];

    /** @var array<int, array{id: int, filename: string, size_bytes: int, content_type: string|null}> */
    public array $composerDraftAttachments = [];

    public mixed $classificationCategoryId = '';

    public string $classificationTagsInput = '';

    public bool $classificationEditorOpen = false;

    public mixed $moveTargetFolderId = '';

    public bool $movePanelOpen = false;

    public bool $personalRuleModalOpen = false;

    public bool $newFolderFormOpen = false;

    public string $newFolderName = '';

    public mixed $newFolderParentId = '';

    public bool $folderManagerOpen = false;

    public mixed $folderManagerAccountId = '';

    public array $folderManagerExpandedPaths = [];

    public mixed $folderRenameFolderId = '';

    public string $folderRenameName = '';

    public mixed $folderDeleteFolderId = '';

    public mixed $folderMoveSourceFolderId = '';

    public mixed $folderMoveTargetFolderId = '';

    public mixed $folderMoveFolderId = '';

    public mixed $folderMoveParentFolderId = '';

    public string $personalRuleName = '';

    public string $personalRuleConditionField = 'from';

    public string $personalRuleConditionValue = '';

    public string $personalRuleActionType = CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER;

    public mixed $personalRuleTargetFolderId = '';

    /** @var array<string, mixed>|null */
    public ?array $mailAiSummary = null;

    public string $composerAiInstruction = '';

    /** @var array<string, mixed>|null */
    public ?array $composerAiResult = null;

    public bool $remoteOperationsOpen = false;

    public bool $ticketLinkPanelOpen = false;

    public string $ticketLinkTarget = '';

    protected $queryString = [
        'viewMode' => ['as' => 'view', 'except' => 'unread'],
        'accountId' => ['as' => 'account', 'except' => ''],
        'folderId' => ['as' => 'folder', 'except' => ''],
        'search' => ['as' => 'q', 'except' => ''],
        'listFilter' => ['as' => 'filter', 'except' => 'all'],
        'selectedPlacementId' => ['as' => 'message', 'except' => null],
        'openComposerMode' => ['as' => 'compose', 'except' => ''],
    ];

    public function setView(string $mode): void
    {
        if (! in_array($mode, ['unread', 'inbox', 'drafts', 'all'], true)) {
            return;
        }

        $this->viewMode = $mode;
        $this->folderId = '';
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetTicketLinkPanel();
        $this->resetNewFolderForm();
        $this->resetMailAiSummary();
        $this->resetPage();
        $this->dispatchFilterChange();
    }

    public function selectAccount(mixed $accountId = ''): void
    {
        $this->accountId = $this->positiveId($accountId) ?: '';
        $this->folderId = '';
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetTicketLinkPanel();
        $this->resetNewFolderForm();
        $this->resetMailAiSummary();
        $this->resetPage();
        $this->dispatchFilterChange();
    }

    public function selectFolder(mixed $folderId): void
    {
        $id = $this->positiveId($folderId);

        if (! $id) {
            return;
        }

        $this->viewMode = 'folder';
        $this->folderId = $id;
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetTicketLinkPanel();
        $this->resetNewFolderForm();
        $this->resetMailAiSummary();
        $this->resetPage();
        $this->dispatchFilterChange();
    }

    public function applyFilters(string $viewMode = 'unread', mixed $accountId = '', mixed $folderId = ''): void
    {
        if (! in_array($viewMode, ['unread', 'inbox', 'drafts', 'all', 'folder'], true)) {
            $viewMode = 'unread';
        }

        $this->viewMode = $viewMode;
        $this->accountId = $this->positiveId($accountId) ?: '';
        $this->folderId = $this->positiveId($folderId) ?: '';
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetTicketLinkPanel();
        $this->resetNewFolderForm();
        $this->resetMailAiSummary();
        $this->resetPage();
    }

    public function selectPlacement(mixed $placementId): void
    {
        $id = $this->positiveId($placementId);

        if (! $id) {
            return;
        }

        $accounts = $this->contentAccounts(ResolveMailboxAccessDecision::CONTENT_VIEW);
        $accountIds = $accounts->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        $metadata = EmailMailboxPlacement::query()
            ->whereIn('account_id', $accountIds)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereKey($id)
            ->first(['id', 'account_id', 'email_message_id']);

        if (! $metadata) {
            return;
        }

        $account = $accounts->firstWhere('id', (int) $metadata->account_id);
        if (! $account instanceof EmailAccount
            || ! $this->authorizeContentUse(
                $account,
                ResolveMailboxAccessDecision::CONTENT_VIEW,
                'message',
                (int) $metadata->email_message_id,
            )) {
            return;
        }

        $placement = $this->basePlacementQuery($accountIds)
            ->whereKey($id)
            ->first();

        if ($placement) {
            $this->selectedPlacementId = $placement->id;
            $this->legacyConversationThreadLimit = self::LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT;
            $this->mailActionStatus = null;
            $this->resetComposer();
            $this->classificationEditorOpen = false;
            $this->resetMoveForm();
            $this->resetPersonalRuleForm();
            $this->resetTicketLinkPanel();
            $this->resetMailAiSummary();
            $this->recordOpened($placement);
            $this->syncClassificationForm($placement);
        }
    }

    public function loadMoreLegacyConversation(): void
    {
        $this->legacyConversationThreadLimit = min(
            self::LEGACY_CONVERSATION_THREAD_LIMIT,
            max(self::LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT, $this->legacyConversationThreadLimit)
                + self::LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT,
        );
    }

    public function setSelectedUnreadForMe(bool $isUnread): void
    {
        $id = $this->positiveId($this->selectedPlacementId);
        $user = $this->user();

        if (! $id || ! $user) {
            return;
        }

        $accountIds = $this->ordinaryAccessibleAccounts(app(MailboxAccess::class))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $placement = $this->basePlacementQuery($accountIds)
            ->whereKey($id)
            ->first();

        if (! $placement?->message
            || ! $placement->account
            || ! app(MailboxAccess::class)->canAccessAccount($user, $placement->account, MailboxAccess::VIEW)) {
            return;
        }

        try {
            app(SetEmailUnreadForMe::class)->handle($user, $placement->message, $isUnread);
            $this->unreadForMeByMessage[$placement->message->id] = $isUnread;
        } catch (AuthorizationException) {
            return;
        }

        $this->dispatch('mail-state-changed');
    }

    public function markSelectedReadForMe(): void
    {
        $this->setSelectedUnreadForMe(false);
    }

    public function sendAndReceiveMail(): void
    {
        $accounts = $this->manualRefreshAccounts();

        if ($accounts->isEmpty()) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'No organize-authorized mailboxes are available for manual send/receive.',
            ];

            return;
        }

        $batchSize = $this->manualFetchBatchSize();

        foreach ($accounts as $account) {
            FetchImapAccount::dispatch($account->id, $batchSize);
        }

        $count = $accounts->count();
        $this->mailActionStatus = [
            'type' => 'info',
            'message' => $count === 1
                ? 'Send/receive queued for '.$accounts->first()->address.'.'
                : 'Send/receive queued for '.number_format($count).' mailboxes.',
        ];

        $this->dispatch('mail-state-changed');
    }

    public function refreshSelectedFolder(): void
    {
        $folder = $this->selectedFolderForRefresh();

        if (! $folder?->account) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Choose a folder in a mailbox you can organize before refreshing it.',
            ];

            return;
        }

        FetchImapAccount::dispatch($folder->account_id, $this->manualFetchBatchSize());

        $this->mailActionStatus = [
            'type' => 'info',
            'message' => 'Folder refresh queued for '.$folder->account->address.' / '.($folder->name ?: $folder->path).'.',
        ];

        $this->dispatch('mail-state-changed');
    }

    public function markSelectedSpam(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user || ! $this->canMarkSpamPlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Organize access before marking this message as spam.',
            ];

            return;
        }

        $rule = app(MarkEmailAsSpam::class)->handle($placement->message, $user);

        if ($this->hasArchiveTarget($placement)) {
            try {
                $remoteOperation = app(PerformEmailRemoteOperation::class)->handle($placement, PerformEmailRemoteOperation::ARCHIVE, $user);
            } catch (ValidationException $exception) {
                $this->mailActionStatus = [
                    'type' => 'warning',
                    'message' => 'Spam rule "'.$rule->name.'" was updated, but the provider archive action could not run: '
                        .(collect($exception->errors())->flatten()->first() ?: 'Archive target is unavailable.'),
                ];

                return;
            } catch (RuntimeException $exception) {
                $this->mailActionStatus = [
                    'type' => 'danger',
                    'message' => 'Spam rule "'.$rule->name.'" was updated, but provider archive failed: '.$exception->getMessage(),
                ];

                return;
            }

            if ($remoteOperation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
                $this->selectedPlacementId = null;
                $this->resetComposer();
                $this->resetClassificationForm();
                $this->resetMoveForm();
                $this->resetPersonalRuleForm();
                $this->resetMailAiSummary();
                $this->mailActionStatus = [
                    'type' => 'success',
                    'message' => 'Message was marked as spam, rule "'.$rule->name.'" was updated, and the provider placement was archived.',
                ];
            } elseif ($remoteOperation->status === EmailRemoteOperation::STATUS_FAILED) {
                $this->mailActionStatus = [
                    'type' => 'danger',
                    'message' => 'Spam rule "'.$rule->name.'" was updated, but provider archive failed: '
                        .($remoteOperation->error_message ?: 'The mail server rejected the archive action.'),
                ];
            } else {
                $this->mailActionStatus = [
                    'type' => 'info',
                    'message' => 'Spam rule "'.$rule->name.'" was updated, and the provider archive action is waiting for acknowledgement.',
                ];
            }

            $this->dispatch('mail-state-changed');

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Message was marked as spam and rule "'.$rule->name.'" was updated. No provider Archive folder is available.',
        ];

        $this->dispatch('mail-state-changed');
    }

    public function suppressSelectedTicketCorrelation(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user || ! $this->canOrganizePlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Organize access before marking a conversation as not a Ticket.',
            ];

            return;
        }

        try {
            app(SuppressEmailConversationTicketCorrelation::class)->handle($placement, $user);
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Ticket correlation could not be suppressed: '.$exception->getMessage(),
            ];

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'This Mail conversation will no longer create or join Tickets automatically.',
        ];
        $this->resetTicketLinkPanel();
        $this->dispatch('mail-state-changed');
    }

    public function createTicketForSelected(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user || ! $this->canCreateTicketFromPlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need Ticket create permission and mailbox Organize access before creating a Ticket from this email.',
            ];

            return;
        }

        try {
            $ticket = app(CreateTicketFromInboundEmail::class)->handle($placement->message);
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'The Ticket action could not be completed: '.$exception->getMessage(),
            ];

            return;
        }

        try {
            app(LinkEmailConversationToTicket::class)->handle(
                $placement->fresh(['message', 'account', 'folder']),
                $ticket,
                $user,
                EmailTicketConversationLink::ROLE_PRIMARY,
            );
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Ticket '.$ticket->ticket_key.' was created, but the Mail conversation link audit could not be recorded: '.$exception->getMessage(),
            ];

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Ticket '.$ticket->ticket_key.' was linked from the selected email.',
        ];

        $this->resetTicketLinkPanel();
        $this->dispatch('mail-state-changed');
    }

    public function createTicketFromAiReview(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user || ! $this->canUseAiTicketCreateAction($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'AI Ticket creation requires Mail AI write access, Ticket create permission, and mailbox Organize access.',
            ];

            return;
        }

        $this->createTicketForSelected();
    }

    public function toggleTicketLinkPanel(): void
    {
        $placement = $this->selectedPlacementForAction();

        if (! $placement?->message) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before linking an existing Ticket.',
            ];

            return;
        }

        if (! $this->canLinkTicketFromPlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need Ticket update permission and mailbox Organize access before linking mail to an existing Ticket.',
            ];

            return;
        }

        $this->ticketLinkPanelOpen = ! $this->ticketLinkPanelOpen;
        $this->ticketLinkTarget = '';
        $this->resetValidation(['ticketLinkTarget']);
    }

    public function linkSelectedToTicket(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user || ! $this->canLinkTicketFromPlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need Ticket update permission and mailbox Organize access before linking this message.',
            ];

            return;
        }

        $this->validate([
            'ticketLinkTarget' => ['required', 'string', 'max:80'],
        ]);

        $ticket = $this->resolveTicketLinkTarget();

        if (! $ticket) {
            $this->addError('ticketLinkTarget', 'Enter an existing Ticket key or numeric Ticket ID.');

            return;
        }

        try {
            app(LinkEmailConversationToTicket::class)->handle(
                $placement,
                $ticket,
                $user,
                $placement->message->ticket_id ? EmailTicketConversationLink::ROLE_SECONDARY : EmailTicketConversationLink::ROLE_PRIMARY,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) collect($messages)->first());
            }

            return;
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'The Ticket link could not be completed: '.$exception->getMessage(),
            ];

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Mail conversation was linked to '.$ticket->ticket_key.'.',
        ];
        $this->resetTicketLinkPanel();
        $this->dispatch('mail-state-changed');
    }

    public function setProviderSeenForSelected(bool $seen): void
    {
        $this->runProviderOperation(
            $seen ? PerformEmailRemoteOperation::MARK_SEEN : PerformEmailRemoteOperation::MARK_UNSEEN,
        );
    }

    public function setProviderFlaggedForSelected(bool $flagged): void
    {
        $this->runProviderOperation(
            $flagged ? PerformEmailRemoteOperation::FLAG : PerformEmailRemoteOperation::UNFLAG,
        );
    }

    public function archiveSelected(): void
    {
        $this->runProviderOperation(PerformEmailRemoteOperation::ARCHIVE, true);
    }

    public function trashSelected(): void
    {
        $this->runProviderOperation(PerformEmailRemoteOperation::TRASH, true);
    }

    public function toggleMovePanel(): void
    {
        $placement = $this->selectedPlacementForAction();

        if (! $placement) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before moving it to another folder.',
            ];

            return;
        }

        if (! $this->canOrganizePlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Organize access before moving this message.',
            ];

            return;
        }

        $this->movePanelOpen = ! $this->movePanelOpen;

        if ($this->movePanelOpen) {
            $this->moveTargetFolderId = $this->moveTargetFolders($placement)->first()?->id ?: '';
            $this->classificationEditorOpen = false;
            $this->personalRuleModalOpen = false;
            $this->resetValidation(['moveTargetFolderId']);
        }
    }

    public function moveSelectedToFolder(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before moving it to another folder.',
            ];

            return;
        }

        $this->validate([
            'moveTargetFolderId' => ['required', 'integer', 'exists:email_folders,id'],
        ]);

        $targetFolder = EmailFolder::query()->find($this->positiveId($this->moveTargetFolderId));

        try {
            $remoteOperation = app(PerformEmailRemoteOperation::class)->handle(
                $placement,
                PerformEmailRemoteOperation::MOVE,
                $user,
                $targetFolder,
            );
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'The mailbox move cannot be completed for this placement.',
            ];

            return;
        } catch (RuntimeException $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => $exception->getMessage(),
            ];

            return;
        }

        if ($remoteOperation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
            $this->selectedPlacementId = null;
            $this->resetComposer();
            $this->resetClassificationForm();
            $this->resetMoveForm();
            $this->resetPersonalRuleForm();
            $this->resetMailAiSummary();
            $this->mailActionStatus = [
                'type' => 'success',
                'message' => 'Message was moved to '.$remoteOperation->target_folder_path.'.',
            ];
        } elseif ($remoteOperation->status === EmailRemoteOperation::STATUS_FAILED) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => $remoteOperation->error_message ?: 'The mail server rejected the mailbox move.',
            ];
        } else {
            $this->mailActionStatus = [
                'type' => 'info',
                'message' => 'The mailbox move is still waiting for mail server acknowledgement.',
            ];
        }

        $this->dispatch('mail-state-changed');
    }

    public function openRuleAction(): mixed
    {
        $placement = $this->selectedPlacementForAction();

        if (! $placement?->message || ! $placement->account) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before creating a mail rule.',
            ];

            return null;
        }

        if ($this->canCreatePersonalRuleForPlacement($placement)) {
            $this->prefillPersonalRuleForm($placement);
            $this->personalRuleModalOpen = true;
            $this->movePanelOpen = false;
            $this->classificationEditorOpen = false;
            $this->resetComposer();
            $this->resetErrorBag();
            $this->mailActionStatus = null;

            return null;
        }

        if ($this->canOpenAdminRulesForPlacement($placement)) {
            $condition = $this->adminRuleCondition($placement);

            return redirect()->route('tech.admin.settings.email.rules.create', [
                'account_id' => $placement->account_id,
                'condition_field' => $condition['field'],
                'condition_value' => $condition['value'],
                'name' => 'Rule for '.$condition['value'],
            ]);
        }

        $this->mailActionStatus = [
            'type' => 'warning',
            'message' => 'This mailbox does not allow personal rule creation from Mail.',
        ];

        return null;
    }

    #[On('smart-inbox-personal-rule-prefill')]
    public function applySmartInboxPersonalRulePrefill(
        string $ruleName = '',
        string $conditionField = '',
        string $conditionValue = '',
        string $actionType = '',
        mixed $targetFolderId = null,
    ): void {
        $placement = $this->selectedPlacementForAction();
        $targetId = $this->positiveId($targetFolderId);
        $supportedAction = in_array($actionType, [
            CreatePersonalEmailRule::ACTION_ARCHIVE,
            CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER,
        ], true);
        $supportedCondition = array_key_exists($conditionField, $this->personalRuleConditionOptions());

        if (! $placement
            || ! $this->canCreatePersonalRuleForPlacement($placement)
            || ! $supportedAction
            || ! $supportedCondition
            || trim($conditionValue) === ''
            || ! $targetId) {
            $this->rejectSmartInboxPersonalRulePrefill();

            return;
        }

        $target = EmailFolder::query()
            ->whereKey($targetId)
            ->where('account_id', $placement->account_id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKeyNot((int) $placement->email_folder_id)
            ->when(
                $actionType === CreatePersonalEmailRule::ACTION_ARCHIVE,
                fn (Builder $folders): Builder => $folders->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->first();

        if (! $target) {
            $this->rejectSmartInboxPersonalRulePrefill();

            return;
        }

        // This event only fills the existing review form. The normal explicit
        // save action remains the sole path that creates and publishes a rule.
        $this->personalRuleName = Str::limit(trim($ruleName), 255, '');
        $this->personalRuleConditionField = $conditionField;
        $this->personalRuleConditionValue = Str::limit(trim($conditionValue), 1000, '');
        $this->personalRuleActionType = $actionType;
        $this->personalRuleTargetFolderId = $target->id;
        $this->personalRuleModalOpen = true;
        $this->movePanelOpen = false;
        $this->classificationEditorOpen = false;
        $this->resetComposer();
        $this->resetErrorBag();
        $this->mailActionStatus = [
            'type' => 'info',
            'message' => 'Smart Inbox prepared a personal rule draft. Review it before saving.',
        ];
    }

    private function rejectSmartInboxPersonalRulePrefill(): void
    {
        $this->resetPersonalRuleForm();
        $this->mailActionStatus = [
            'type' => 'warning',
            'message' => 'The Smart Inbox personal rule draft is no longer available.',
        ];
    }

    public function closePersonalRuleModal(): void
    {
        $this->resetPersonalRuleForm();
    }

    public function createPersonalRule(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before creating a mail rule.',
            ];

            return;
        }

        $this->validate([
            'personalRuleName' => ['nullable', 'string', 'max:255'],
            'personalRuleConditionField' => ['required', 'string', 'in:from,from_domain,subject,to,cc'],
            'personalRuleConditionValue' => ['required', 'string', 'max:1000'],
            'personalRuleActionType' => ['required', 'string', 'in:move_to_folder,archive'],
            'personalRuleTargetFolderId' => ['required_if:personalRuleActionType,move_to_folder', 'nullable', 'integer', 'exists:email_folders,id'],
        ]);

        try {
            $rule = app(CreatePersonalEmailRule::class)->handle($placement, $user, [
                'name' => $this->personalRuleName,
                'condition_field' => $this->personalRuleConditionField,
                'condition_value' => $this->personalRuleConditionValue,
                'action_type' => $this->personalRuleActionType,
                'target_folder_id' => $this->positiveId($this->personalRuleTargetFolderId),
            ]);
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) collect($messages)->first());
            }

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Personal rule "'.$rule->name.'" was created.',
        ];
        $this->resetPersonalRuleForm();
        $this->dispatch('mail-state-changed');
    }

    public function generateMailAiSummary(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement?->message || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before using Mail AI.',
            ];

            return;
        }

        if (! $this->canUseAiAction($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $this->mailAiUnavailableMessage($user),
            ];

            return;
        }

        $accountIds = $this->ordinaryAccessibleAccounts(app(MailboxAccess::class))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        try {
            $this->mailAiSummary = app(SummarizeEmailWithAi::class)->handle(
                $placement,
                $user,
                $this->conversationPlacements($placement, $accountIds),
            );
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'Mail AI could not summarize this message.',
            ];

            return;
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Mail AI failed: '.$exception->getMessage(),
            ];

            return;
        }

        $this->mailActionStatus = null;
        $this->classificationEditorOpen = false;
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetComposer();
    }

    public function clearMailAiSummary(): void
    {
        $this->resetMailAiSummary();
    }

    public function applyComposerAi(string $intent): void
    {
        $user = $this->user();

        if (! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Sign in before using Mail AI.',
            ];

            return;
        }

        if (! $this->composerOpen) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Open a composer before using Mail AI.',
            ];

            return;
        }

        if ($intent === AssistEmailComposerWithAi::INTENT_DRAFT_REPLY
            && ! in_array($this->composerMode, [SendEmailComposerMessage::MODE_REPLY, SendEmailComposerMessage::MODE_REPLY_ALL], true)) {
            $this->setComposerActionStatus('warning', 'Draft reply is available for Reply and Reply all only.');

            return;
        }

        if (! $this->canUseComposerAiAction($this->selectedPlacementForAction())) {
            $this->setComposerActionStatus('warning', $this->mailAiUnavailableMessage($user));

            return;
        }

        $this->validate([
            'composerAiInstruction' => ['nullable', 'string', 'max:1000'],
        ]);

        [$composerBodyForAi, $preservedForwardBlock] = $this->composerBodyForAi();

        try {
            $payload = [
                'intent' => $intent,
                'composer_mode' => $this->composerMode,
                'subject' => $this->composerSubject,
                'current_body_html' => $composerBodyForAi,
                'user_instruction' => $this->composerAiInstruction,
            ];

            if ($this->composerMode === SendEmailComposerMessage::MODE_COMPOSE) {
                $account = $this->selectedComposeAccount();

                if (! $account) {
                    throw ValidationException::withMessages([
                        'ai' => 'Choose a send-authorized mailbox before using Mail AI.',
                    ]);
                }

                $result = app(AssistEmailComposerWithAi::class)->handleNew($account, $user, $payload);
            } else {
                $placement = $this->selectedPlacementForAction();

                if (! $placement?->message) {
                    throw ValidationException::withMessages([
                        'ai' => 'Select a message before using Mail AI.',
                    ]);
                }

                $accountIds = $this->ordinaryAccessibleAccounts(app(MailboxAccess::class))
                    ->pluck('id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->all();

                $result = app(AssistEmailComposerWithAi::class)->handle(
                    $placement,
                    $user,
                    $payload,
                    $this->conversationPlacements($placement, $accountIds),
                );
            }
        } catch (AuthorizationException $exception) {
            $this->setComposerActionStatus('warning', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setComposerActionStatus(
                'warning',
                collect($exception->errors())->flatten()->first() ?: 'Mail AI could not update the composer.',
            );

            return;
        } catch (\Throwable $exception) {
            $this->setComposerActionStatus('danger', 'Mail AI failed: '.$exception->getMessage());

            return;
        }

        $this->composerAiResult = $result;
        if (($result['applied'] ?? true) === false) {
            $this->setComposerActionStatus(
                'info',
                (string) ($result['notice'] ?? 'Mail AI does not recommend drafting a reply for this message.'),
            );
            $this->classificationEditorOpen = false;
            $this->resetMoveForm();
            $this->resetPersonalRuleForm();

            return;
        }

        $this->composerBodyHtml = $this->composerAiResultBodyHtml((string) ($result['body_html'] ?? '<p><br></p>'), $preservedForwardBlock);
        $this->persistComposerDraft(false);
        $this->setComposerActionStatus('success', 'AI draft was applied to the composer. Review it before sending.');
        $this->classificationEditorOpen = false;
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function composerBodyForAi(): array
    {
        if ($this->composerMode !== SendEmailComposerMessage::MODE_FORWARD) {
            return [$this->composerBodyHtml, null];
        }

        $markerPosition = strpos($this->composerBodyHtml, EmailSignatureRenderer::FORWARDED_MESSAGE_MARKER);

        if ($markerPosition === false) {
            return [$this->composerBodyHtml, null];
        }

        $intro = trim(substr($this->composerBodyHtml, 0, $markerPosition));
        $forwardedBlock = substr($this->composerBodyHtml, $markerPosition);

        return [$intro !== '' ? $intro : '<p><br></p>', $forwardedBlock];
    }

    private function composerAiResultBodyHtml(string $bodyHtml, ?string $preservedForwardBlock): string
    {
        if ($preservedForwardBlock === null) {
            return $bodyHtml;
        }

        $bodyHtml = trim($bodyHtml);

        if ($bodyHtml === '') {
            $bodyHtml = '<p><br></p>';
        }

        return rtrim($bodyHtml).$preservedForwardBlock;
    }

    public function toggleClassificationEditor(): void
    {
        $placement = $this->selectedPlacementForAction();

        if (! $placement) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before changing category or tags.',
            ];

            return;
        }

        if (! $this->canOrganizePlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Organize access before changing email category or tags.',
            ];

            return;
        }

        $this->classificationEditorOpen = ! $this->classificationEditorOpen;

        if ($this->classificationEditorOpen) {
            $this->syncClassificationForm($placement);
        }
    }

    public function saveClassification(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before changing category or tags.',
            ];

            return;
        }

        if (! $this->canOrganizePlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Organize access before changing email category or tags.',
            ];

            return;
        }

        $this->validate([
            'classificationCategoryId' => ['nullable', 'integer', 'exists:categories,id'],
            'classificationTagsInput' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $classification = app(UpdateEmailConversationClassification::class)->handle(
                $placement,
                $user,
                $this->positiveId($this->classificationCategoryId),
                $this->classificationTagNames(),
            );
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'The email classification could not be updated.',
            ];

            return;
        }

        $this->selectedPlacementId = $placement->id;
        $this->classificationCategoryId = $classification->category_id ?: '';
        $this->classificationTagsInput = $classification->tags->pluck('name')->implode(', ');
        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Conversation category and tags were updated.',
        ];
        $this->classificationEditorOpen = false;

        $this->dispatch('mail-state-changed');
    }

    public function clearClassification(): void
    {
        $this->classificationCategoryId = '';
        $this->classificationTagsInput = '';
        $this->saveClassification();
    }

    public function startReply(): void
    {
        $this->startComposer(SendEmailComposerMessage::MODE_REPLY);
    }

    public function startReplyAll(): void
    {
        if (! $this->canReplyAllFromPlacement($this->selectedPlacementForAction())) {
            $this->mailActionStatus = [
                'type' => 'info',
                'message' => 'Reply all is only available when the message has additional recipients.',
            ];

            return;
        }

        $this->startComposer(SendEmailComposerMessage::MODE_REPLY_ALL);
    }

    public function startForward(): void
    {
        $this->startComposer(SendEmailComposerMessage::MODE_FORWARD);
    }

    public function editProviderDraft(): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement || ! $user || ! $this->canEditProviderDraftPlacement($placement)) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a provider Drafts message from a send-authorized mailbox before editing it.',
            ];

            return;
        }

        try {
            $draft = app(EmailComposerDraftService::class)->captureProviderDraftPlacement($user, $placement);
        } catch (AuthorizationException $exception) {
            $this->setComposerOrMailActionStatus('warning', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->composerOpen = true;
        $this->composerMode = SendEmailComposerMessage::MODE_PROVIDER_DRAFT;
        $this->composerAccountId = $placement->account_id;
        $this->composerTo = (string) $draft->to_recipients;
        $this->composerCc = (string) $draft->cc_recipients;
        $this->composerSubject = (string) $draft->subject;
        $this->composerBodyHtml = (string) ($draft->body_html ?: '<p><br></p>');
        $this->composerAttachments = [];
        $this->composerIdempotencyKey = (string) ($draft->idempotency_key ?: Str::uuid());
        $this->resetComposerAiState();
        $this->resetMailAiSummary();
        $this->syncComposerDraftMetadata($draft, 'restored');
        $this->composerDraftAttachments = $this->composerDraftAttachmentList($draft);
        $this->composerDraftHasUnsavedAttachments = false;
        $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();
        $this->setComposerActionStatus('info', 'Provider draft was opened for editing.');
    }

    public function startCompose(): void
    {
        $account = $this->preferredComposeAccount();

        if (! $account) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Send access before composing a new message.',
            ];

            return;
        }

        $this->composerOpen = true;
        $this->composerMode = SendEmailComposerMessage::MODE_COMPOSE;
        $this->composerAccountId = $account->id;
        $this->composerTo = '';
        $this->composerCc = '';
        $this->composerSubject = '';
        $this->composerBodyHtml = '<p><br></p>';
        $this->composerAttachments = [];
        $this->composerIdempotencyKey = (string) Str::uuid();
        $this->resetComposerAiState();
        $this->composerActionStatus = null;
        $this->classificationEditorOpen = false;
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetMailAiSummary();
        $this->mailActionStatus = null;
        $this->resetErrorBag();
        $this->restoreComposerDraftIfAvailable($account, null);
    }

    public function cancelComposer(): void
    {
        if ($this->composerShared) {
            if ($this->composerSharedEditable()) {
                $this->persistComposerDraft(false);
                $this->releaseComposerSharedLease(false);
            }
            $this->resetComposer();

            return;
        }

        $this->persistComposerDraft(false);
        $this->resetComposer();
    }

    public function cancelReply(): void
    {
        $this->cancelComposer();
    }

    public function removeComposerAttachment(mixed $index): void
    {
        $index = $this->positiveId($index);

        if ($index === null) {
            return;
        }

        $zeroIndex = $index - 1;
        unset($this->composerAttachments[$zeroIndex]);
        $this->composerAttachments = array_values($this->composerAttachments);
    }

    public function removeComposerDraftAttachment(mixed $attachmentId): void
    {
        $id = $this->positiveId($attachmentId);
        $user = $this->user();

        if (! $id || ! $user) {
            return;
        }

        $attachment = EmailComposerDraftAttachment::query()
            ->whereKey($id)
            ->when(! $this->composerShared, fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (! $attachment) {
            return;
        }
        if ($this->composerShared && $this->removeSharedComposerDraftAttachment($attachment)) {
            return;
        }

        try {
            $expectedVersion = app(EmailDraftFence::class)->version(
                $attachment->draft,
                $this->composerDraftFence,
            );
            $draft = app(EmailComposerDraftService::class)->removeAttachment(
                $user,
                $attachment,
                $expectedVersion,
            );
        } catch (EmailDraftConflictException $exception) {
            $this->setComposerOrMailActionStatus('warning', $exception->getMessage());

            return;
        } catch (AuthorizationException $exception) {
            $this->setComposerOrMailActionStatus('warning', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setComposerOrMailActionStatus(
                'warning',
                collect($exception->errors())->flatten()->first() ?: 'The draft attachment could not be removed.',
            );

            return;
        }

        $this->composerDraftAttachments = $this->composerDraftAttachmentList($draft);
        $this->syncComposerDraftMetadata($draft, 'saved');
        $this->composerDraftProviderStatus = EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY;
        $this->composerDraftProviderMessage = 'Local draft only';
        $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();
        $this->setComposerOrMailActionStatus('success', 'Draft attachment removed.');
    }

    public function removeReplyAttachment(mixed $index): void
    {
        $this->removeComposerAttachment($index);
    }

    public function toggleNewFolderForm(): void
    {
        if (! $this->canCreateFolderForSelectedAccount()) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Choose one mailbox with Organize access before creating a provider folder.',
            ];

            return;
        }

        $this->newFolderFormOpen = ! $this->newFolderFormOpen;
        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->resetValidation(['newFolderName', 'newFolderParentId']);
    }

    public function createProviderFolder(): void
    {
        $account = $this->selectedFolderCreateAccount();
        $user = $this->user();

        if (! $account || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Choose one mailbox with Organize access before creating a provider folder.',
            ];

            return;
        }

        $this->validate([
            'newFolderName' => ['required', 'string', 'max:180'],
            'newFolderParentId' => ['nullable'],
        ]);

        try {
            $folderPath = $this->newProviderFolderPath();
            $folder = app(CreateProviderEmailFolder::class)->handle($account, $user, $folderPath);
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Provider folder could not be created: '.$exception->getMessage(),
            ];

            return;
        }

        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->revealFolderInManager($folder);
        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Provider folder '.$folder->path.' was created.',
        ];
        $this->dispatch('mail-state-changed');
    }

    public function toggleRemoteOperations(): void
    {
        $this->remoteOperationsOpen = ! $this->remoteOperationsOpen;
    }

    public function retryRemoteOperation(int $operationId): void
    {
        $operation = $this->remoteOperationForAction($operationId);

        if (! $operation) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'The selected mailbox operation is no longer available for retry.',
            ];

            return;
        }

        try {
            $updated = app(RetryEmailRemoteOperation::class)->handle($operation, $this->user());
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = ['type' => 'warning', 'message' => $exception->getMessage()];

            return;
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first() ?: 'The mailbox operation cannot be retried.',
            ];

            return;
        }
        $this->mailActionStatus = [
            'type' => $updated->status === EmailRemoteOperation::STATUS_SUCCEEDED
                ? 'success'
                : ($updated->status === EmailRemoteOperation::STATUS_FAILED ? 'danger' : 'info'),
            'message' => $updated->status === EmailRemoteOperation::STATUS_SUCCEEDED
                ? 'Mailbox operation retried successfully.'
                : ($updated->error_message ?: 'Mailbox operation retry is still waiting for provider acknowledgement.'),
        ];

        $this->dispatch('mail-state-changed');
    }

    public function cancelRemoteOperation(int $operationId): void
    {
        $operation = $this->remoteOperationForAction($operationId);

        if (! $operation) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'The selected mailbox operation cannot be cancelled.',
            ];

            return;
        }

        try {
            app(CancelEmailRemoteOperation::class)->handle($operation, $this->user());
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = ['type' => 'warning', 'message' => $exception->getMessage()];

            return;
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first() ?: 'The mailbox operation cannot be cancelled.',
            ];

            return;
        }

        $this->mailActionStatus = [
            'type' => 'success',
            'message' => 'Mailbox operation was cancelled.',
        ];

        $this->dispatch('mail-state-changed');
    }

    public function undoRemoteOperation(int $operationId): void
    {
        $operation = $this->remoteOperationForAction($operationId);

        if (! $operation) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'The selected mailbox operation is no longer available for Undo.',
            ];

            return;
        }

        try {
            $inverse = app(UndoEmailRemoteOperation::class)->handle($operation, $this->user());
        } catch (AuthorizationException $exception) {
            $this->mailActionStatus = ['type' => 'warning', 'message' => $exception->getMessage()];

            return;
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first() ?: 'This mailbox operation can no longer be undone safely.',
            ];

            return;
        }

        $this->mailActionStatus = [
            'type' => match ($inverse->status) {
                EmailRemoteOperation::STATUS_SUCCEEDED => 'success',
                EmailRemoteOperation::STATUS_FAILED => 'danger',
                EmailRemoteOperation::STATUS_SUPERSEDED => 'warning',
                default => 'info',
            },
            'message' => match ($inverse->status) {
                EmailRemoteOperation::STATUS_SUCCEEDED => 'Mailbox operation was undone through a verified provider inverse.',
                EmailRemoteOperation::STATUS_FAILED => $inverse->error_message ?: 'Undo is waiting for safe provider recovery.',
                EmailRemoteOperation::STATUS_SUPERSEDED => $inverse->status_reason_message ?: 'Provider or mailbox state changed, so Undo was stopped without a write.',
                default => 'Undo was recorded and is waiting for provider acknowledgement.',
            },
        ];

        $this->dispatch('mail-state-changed');
    }

    public function saveComposerDraft(bool $manual = true): void
    {
        try {
            $draft = $this->persistComposerDraft($manual);
        } catch (AuthorizationException $exception) {
            $this->setComposerOrMailActionStatus('warning', $exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->setComposerOrMailActionStatus(
                'warning',
                collect($exception->errors())->flatten()->first() ?: 'The draft could not be saved.',
            );

            return;
        } catch (\Throwable $exception) {
            $this->setComposerOrMailActionStatus('danger', 'The draft could not be saved: '.$exception->getMessage());

            return;
        }

        if ($manual && ! $draft) {
            $this->setComposerOrMailActionStatus(
                'info',
                $this->composerOpen
                    ? 'Add recipients, subject, or message content before saving a draft.'
                    : 'Open a composer before saving a draft.',
            );
        }
    }

    public function discardComposerDraft(): void
    {
        if ($this->composerShared && $this->discardSharedComposerDraft()) {
            return;
        }

        $context = $this->composerDraftContext();
        $user = $this->user();
        $draft = null;

        if ($context && $user) {
            try {
                $draftId = $this->positiveId($this->composerDraftId);
                $activeDraft = $draftId
                    ? EmailComposerDraft::query()
                        ->whereKey($draftId)
                        ->where('user_id', $user->id)
                        ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
                        ->where('status', EmailComposerDraft::STATUS_ACTIVE)
                        ->first()
                    : null;
                $draft = $activeDraft
                    ? app(EmailComposerDraftService::class)->discardDraft(
                        $user,
                        $activeDraft,
                        app(EmailDraftFence::class)->version($activeDraft, $this->composerDraftFence),
                    )
                    : null;
            } catch (EmailDraftConflictException $exception) {
                $this->mailActionStatus = [
                    'type' => 'warning',
                    'message' => $exception->getMessage(),
                ];

                return;
            } catch (AuthorizationException $exception) {
                $this->mailActionStatus = [
                    'type' => 'warning',
                    'message' => $exception->getMessage(),
                ];

                return;
            }
        }

        $this->resetComposer();
        $this->mailActionStatus = [
            'type' => $draft?->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_ERROR ? 'warning' : 'success',
            'message' => $this->composerDraftDiscardedMessage($draft),
        ];
    }

    public function sendComposer(): void
    {
        $context = $this->composerDraftContext();
        $placement = $context['placement'] ?? null;

        $user = $this->user();

        if (! $placement || ! $user) {
            if ($this->composerMode === SendEmailComposerMessage::MODE_COMPOSE && $user) {
                $placement = null;
            } else {
                $this->mailActionStatus = [
                    'type' => 'warning',
                    'message' => 'Select a message before sending mail.',
                ];

                return;
            }
        }

        $this->validate([
            'composerMode' => ['required', 'string', 'in:reply,reply_all,forward,compose,provider_draft'],
            'composerAccountId' => ['required_if:composerMode,compose', 'nullable', 'integer', 'exists:email_accounts,id'],
            'composerTo' => ['required', 'string', 'max:2000'],
            'composerCc' => ['nullable', 'string', 'max:2000'],
            'composerSubject' => ['required', 'string', 'max:512'],
            'composerBodyHtml' => ['required', 'string', 'max:120000'],
            'composerAttachments' => ['array', 'max:5'],
            'composerAttachments.*' => ['file', 'max:10240'],
        ]);

        if (count($this->composerDraftAttachments) + count($this->composerAttachments) > 5) {
            throw ValidationException::withMessages([
                'composerAttachments' => 'A Mail message can include up to 5 attachments.',
            ]);
        }

        try {
            // Web and API callers persist, fence, preview, reserve, and submit
            // through the same Email-owned boundary. No direct SMTP path may
            // bypass the version-specific outbound submission ledger.
            $draft = $this->persistComposerDraft(false);

            if (! $draft && $context) {
                $draft = app(EmailComposerDraftService::class)->activeDraft(
                    $user,
                    $this->composerMode,
                    $context['account'],
                    $context['placement'],
                );
            }

            if (! $draft && $this->composerDraftId) {
                $draft = EmailComposerDraft::query()
                    ->with(['account', 'placement.message', 'attachments'])
                    ->whereKey($this->composerDraftId)
                    ->where('user_id', $user->id)
                    ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
                    ->whereIn('status', [
                        EmailComposerDraft::STATUS_SEND_RESERVED,
                        EmailComposerDraft::STATUS_SENT,
                    ])
                    ->first();
            }

            if (! $draft) {
                throw ValidationException::withMessages([
                    'composer' => 'Save a current private draft before sending.',
                ]);
            }

            $submission = app(SubmitEmailComposerDraft::class)->submit(
                $draft,
                $user,
                $this->composerIdempotencyKey ?: (string) Str::uuid(),
                SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
                app(EmailDraftFence::class)->version($draft, $this->composerDraftFence),
                $this->composerShared ? $this->sharedComposerLeaseContext() : null,
            );
            $sentLog = $submission->emailLog;
            $sentDraft = $submission->draft;
            $senderAddress = $draft->account?->address;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (EmailDraftConflictException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (EmailSubmissionConflictException $exception) {
            $this->mailActionStatus = [
                'type' => $exception->submission->status === EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED
                    ? 'danger'
                    : 'warning',
                'message' => $exception->getMessage(),
            ];

            return;
        } catch (\Throwable $exception) {
            try {
                Log::error('Mail composer failed before a confirmed provider delivery.', [
                    'user_id' => $user->id,
                    'mode' => $this->composerMode,
                    'exception' => $exception::class,
                ]);
            } catch (\Throwable) {
                // Logging must not replace the safe user-facing failure.
            }

            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'The '.$this->composerModeLabel().' could not be prepared for sending. Review the composer and try again.',
            ];

            return;
        }

        $draftCleanupWarning = $submission->reason_code === 'SMTP_ACCEPTED_DRAFT_CLEANUP_FAILED'
            ? ' The message was accepted, but local draft cleanup could not be completed. Do not resend it.'
            : null;
        $postSendWarning = $this->composerPostSendWarning($sentLog)
            ?? match ($submission->reason_code) {
                'SMTP_ACCEPTED_LOG_FINALIZATION_FAILED' => ' The message was accepted, but its local send log could not be finalized. Do not resend it.',
                'SMTP_ACCEPTED_SENT_RECONCILIATION_RECORD_FAILED' => ' The message was accepted, but Sent-folder tracking could not be recorded. Do not resend it.',
                'SMTP_ACCEPTED_SENT_SNAPSHOT_FAILED' => ' The message was accepted, but its local Sent snapshot could not be stored. Do not resend it.',
                default => null,
            };
        $this->mailActionStatus = [
            'type' => $sentDraft?->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_ERROR
                || $draftCleanupWarning !== null
                || $postSendWarning !== null
                ? 'warning'
                : 'success',
            'message' => $this->composerSentMessage((string) $senderAddress, $sentDraft)
                .($draftCleanupWarning ?? '')
                .($postSendWarning ?? ''),
        ];
        $this->resetComposer();
    }

    public function sendReply(): void
    {
        $this->sendComposer();
    }

    public function updatedSearch(): void
    {
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetMailAiSummary();
        $this->resetPage();
    }

    public function updatedListFilter(): void
    {
        if (! array_key_exists($this->listFilter, $this->listFilterOptions())) {
            $this->listFilter = 'all';
        }

        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetMailAiSummary();
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->selectedPlacementId = null;
        $this->mailActionStatus = null;
        $this->resetComposer();
        $this->resetClassificationForm();
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->resetMailAiSummary();
        $this->resetPage();
    }

    public function render(): View
    {
        $navigation = $this->navigationData();
        $accountIds = $navigation['accountIds'];
        $placements = $this->conversationPlacementPaginator($accountIds);
        $selectedPlacement = $this->selectedPlacement($accountIds);
        $conversationThread = $selectedPlacement
            ? $this->conversationThread($selectedPlacement, $accountIds)
            : $this->emptyConversationThread();
        $canonicalContent = app(EmailCanonicalContentResolver::class);
        $contentPlacements = collect($placements->items())
            ->merge($conversationThread['placements'])
            ->when($selectedPlacement, fn (Collection $items): Collection => $items->push($selectedPlacement))
            ->filter(fn ($placement): bool => $placement instanceof EmailMailboxPlacement)
            ->unique('id')
            ->values();
        $canonicalContent->prime($contentPlacements);
        $this->projectCanonicalContent($contentPlacements, $canonicalContent);
        // Legacy thread windows can change when a child is selected. Keep the
        // parent highlight tied to the same stable key used by list grouping.
        $selectedConversationGroupKey = $selectedPlacement
            ? $this->placementConversationGroupKey($selectedPlacement, app(EmailConversationProjector::class))
            : null;

        return view('email::Livewire.Tech.mail-workspace', [
            'placements' => $placements,
            'selectedPlacement' => $selectedPlacement,
            'selectedConversationGroupKey' => $selectedConversationGroupKey,
            'conversationPlacements' => $conversationThread['placements'],
            'conversationPlacementsTotal' => $conversationThread['total'],
            'conversationPlacementsTruncated' => $conversationThread['truncated'],
            'conversationPlacementsCanLoadMore' => $conversationThread['can_load_more'],
            'legacyConversationListTruncated' => $this->legacyConversationListTruncated,
            'mailCategories' => $this->classificationCategories(),
            'mailTags' => $this->classificationTags(),
            'sendableAccounts' => $this->sendableAccounts(app(MailboxAccess::class)),
            'remoteOperationsDashboard' => $this->remoteOperationsDashboard(),
        ] + $navigation);
    }

    public function canCreateFolderForSelectedAccount(): bool
    {
        return (bool) $this->selectedFolderCreateAccount();
    }

    public function canManageFoldersForSelectedAccount(): bool
    {
        return $this->folderManagerAccounts()->isNotEmpty();
    }

    public function canSendAndReceiveMail(): bool
    {
        return $this->manualRefreshAccounts()->isNotEmpty();
    }

    public function canRefreshSelectedFolder(): bool
    {
        return (bool) $this->selectedFolderForRefresh();
    }

    public function openFolderManager(): void
    {
        $account = $this->selectedFolderCreateAccount() ?: $this->folderManagerAccounts()->first();

        if (! $account) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Choose one mailbox with Organize access before managing provider folders.',
            ];

            return;
        }

        $this->folderManagerOpen = true;
        $this->folderManagerAccountId = $account->id;
        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->newFolderFormOpen = true;
        $this->resetFolderManagerForms();
        $this->folderManagerExpandedPaths = [];
    }

    public function closeFolderManager(): void
    {
        $this->folderManagerOpen = false;
        $this->newFolderFormOpen = false;
        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->folderManagerExpandedPaths = [];
        $this->resetFolderManagerForms();
    }

    public function changeFolderManagerAccount(mixed $accountId): void
    {
        $id = $this->positiveId($accountId);
        $account = $id
            ? $this->folderManagerAccounts()->firstWhere('id', $id)
            : null;

        if (! $account) {
            $this->folderManagerWarning('Choose a mailbox with Organize access.');

            return;
        }

        $this->folderManagerAccountId = $account->id;
        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->folderManagerExpandedPaths = [];
        $this->resetFolderManagerForms();
    }

    public function toggleFolderManagerFolder(int $folderId): void
    {
        $folder = $this->folderManagerFolder($folderId);

        if (! $folder || app(ManageProviderEmailFolder::class)->childFolderCount($folder) === 0) {
            return;
        }

        $path = (string) $folder->path;
        $expanded = collect($this->folderManagerExpandedPaths)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values();

        $this->folderManagerExpandedPaths = $expanded->contains($path)
            ? $expanded->reject(fn (string $value): bool => $value === $path)->values()->all()
            : $expanded->push($path)->unique()->values()->all();
    }

    public function startFolderRename(int $folderId): void
    {
        $folder = $this->managedFolderForAction($folderId);

        if (! $folder) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        $blockers = app(ManageProviderEmailFolder::class)->mutationBlockers($folder);
        if ($blockers !== []) {
            $this->folderManagerWarning('This provider folder cannot be renamed: '.implode(', ', $blockers).'.');

            return;
        }

        $this->folderRenameFolderId = $folder->id;
        $this->folderRenameName = $folder->name ?: basename(str_replace('\\', '/', $folder->path)) ?: $folder->path;
        $this->resetValidation(['folderRenameName']);
    }

    public function cancelFolderRename(): void
    {
        $this->folderRenameFolderId = '';
        $this->folderRenameName = '';
        $this->resetValidation(['folderRenameName']);
    }

    public function startFolderMove(int $folderId): void
    {
        $folder = $this->managedFolderForAction($folderId);

        if (! $folder) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        $blockers = app(ManageProviderEmailFolder::class)->mutationBlockers($folder);
        if ($blockers !== []) {
            $this->folderManagerWarning('This provider folder cannot be moved: '.implode(', ', $blockers).'.');

            return;
        }

        $this->folderMoveFolderId = $folder->id;
        $this->folderMoveParentFolderId = $this->defaultFolderMoveParentId($folder);
        $this->resetValidation(['folderMoveParentFolderId']);
    }

    public function cancelFolderMove(): void
    {
        $this->folderMoveFolderId = '';
        $this->folderMoveParentFolderId = '';
        $this->resetValidation(['folderMoveParentFolderId']);
    }

    public function moveProviderFolder(): void
    {
        $folder = $this->managedFolderForAction($this->positiveId($this->folderMoveFolderId));
        $targetParent = $this->folderMoveParentFolder();
        $user = $this->user();

        if (! $folder || ! $user) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        try {
            $operation = app(ManageProviderEmailFolder::class)->move($folder, $user, $targetParent);
        } catch (AuthorizationException $exception) {
            $this->folderManagerWarning($exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->folderManagerWarning('Provider folder could not be moved: '.$exception->getMessage(), 'danger');

            return;
        }

        $this->cancelFolderMove();

        if ($operation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
            if ((int) $this->folderId === (int) $folder->id) {
                $this->selectFolder($folder->id);
            }

            $this->mailActionStatus = [
                'type' => 'success',
                'message' => 'Provider folder was moved to '.$operation->target_folder_path.'.',
            ];
        } else {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Provider folder move failed: '.($operation->error_message ?: 'The mail server rejected the move.'),
            ];
        }

        $this->dispatch('mail-state-changed');
    }

    public function renameProviderFolder(): void
    {
        $folder = $this->managedFolderForAction($this->positiveId($this->folderRenameFolderId));
        $user = $this->user();

        if (! $folder || ! $user) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        $this->validate([
            'folderRenameName' => ['required', 'string', 'max:180'],
        ]);

        try {
            $operation = app(ManageProviderEmailFolder::class)->rename($folder, $user, $this->folderRenameName);
        } catch (AuthorizationException $exception) {
            $this->folderManagerWarning($exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->folderManagerWarning('Provider folder could not be renamed: '.$exception->getMessage(), 'danger');

            return;
        }

        $this->folderRenameFolderId = '';
        $this->folderRenameName = '';
        $renamed = $operation->fresh('folder');

        if ($operation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
            if ((int) $this->folderId === (int) $folder->id) {
                $this->selectFolder($folder->id);
            }

            $this->mailActionStatus = [
                'type' => 'success',
                'message' => 'Provider folder was renamed to '.($renamed->target_folder_path ?: $folder->fresh()?->path).'.',
            ];
        } else {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Provider folder rename failed: '.($operation->error_message ?: 'The mail server rejected the rename.'),
            ];
        }

        $this->dispatch('mail-state-changed');
    }

    public function startFolderDelete(int $folderId): void
    {
        $folder = $this->managedFolderForAction($folderId);

        if (! $folder) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        $manager = app(ManageProviderEmailFolder::class);
        $blockers = $manager->mutationBlockers($folder);
        if ($blockers !== []) {
            $this->folderManagerWarning('This provider folder cannot be deleted: '.implode(', ', $blockers).'.');

            return;
        }

        $this->folderDeleteFolderId = $folder->id;
        $this->folderMoveSourceFolderId = '';
        $this->folderMoveTargetFolderId = '';
        $this->resetValidation(['folderDeleteFolderId', 'folderMoveTargetFolderId']);

        if ($manager->activePlacementCount($folder) > 0) {
            $this->folderMoveSourceFolderId = $folder->id;
            $this->folderMoveTargetFolderId = $this->defaultFolderMoveTargetId($folder) ?: '';

            return;
        }

        $deleteBlockers = $manager->mutationBlockers($folder, true);
        if ($deleteBlockers !== []) {
            $this->folderDeleteFolderId = '';
            $this->folderManagerWarning('This provider folder cannot be deleted: '.implode(', ', $deleteBlockers).'.');
        }
    }

    public function cancelFolderDelete(): void
    {
        $this->folderDeleteFolderId = '';
        $this->folderMoveSourceFolderId = '';
        $this->folderMoveTargetFolderId = '';
        $this->resetValidation(['folderDeleteFolderId', 'folderMoveTargetFolderId']);
    }

    public function moveManagedFolderMail(): void
    {
        $source = $this->managedFolderForAction($this->positiveId($this->folderMoveSourceFolderId));
        $target = $this->managedFolderMoveTarget();
        $user = $this->user();

        if (! $source || ! $target || ! $user) {
            $this->folderManagerWarning('Choose a source folder and a different target folder.');

            return;
        }

        $placements = EmailMailboxPlacement::query()
            ->where('email_folder_id', $source->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $moved = 0;
        $failed = 0;

        foreach ($placements as $placement) {
            try {
                $freshPlacement = $placement->fresh(['account', 'folder', 'message']);
                if (! $freshPlacement) {
                    $failed++;

                    continue;
                }

                $operation = app(PerformEmailRemoteOperation::class)->handle(
                    $freshPlacement,
                    PerformEmailRemoteOperation::MOVE,
                    $user,
                    $target,
                );

                $operation->status === EmailRemoteOperation::STATUS_SUCCEEDED ? $moved++ : $failed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $remaining = app(ManageProviderEmailFolder::class)->activePlacementCount($source->fresh());
        $this->folderMoveSourceFolderId = $remaining > 0 ? $source->id : '';
        $this->folderMoveTargetFolderId = $remaining > 0 ? $target->id : '';
        $this->mailActionStatus = [
            'type' => $failed > 0 ? 'warning' : ($remaining > 0 ? 'info' : 'success'),
            'message' => match (true) {
                $failed > 0 => 'Moved '.$this->mailCountLabel($moved)." to {$target->path}; ".$this->mailCountLabel($failed).' could not be moved. Check Mailbox operations.',
                $remaining > 0 => 'Moved '.$this->mailCountLabel($moved)." to {$target->path}; ".$this->mailCountLabel($remaining).' still remain. Run Move mails again before deleting.',
                default => 'Moved '.$this->mailCountLabel($moved)." to {$target->path}. The source folder can now be deleted.",
            },
        ];

        $this->dispatch('mail-state-changed');
    }

    public function deleteProviderFolder(): void
    {
        $folder = $this->managedFolderForAction($this->positiveId($this->folderDeleteFolderId));
        $user = $this->user();

        if (! $folder || ! $user) {
            $this->folderManagerWarning('Choose a custom folder from the selected mailbox.');

            return;
        }

        try {
            $operation = app(ManageProviderEmailFolder::class)->delete($folder, $user);
        } catch (AuthorizationException $exception) {
            $this->folderManagerWarning($exception->getMessage());

            return;
        } catch (ValidationException $exception) {
            $this->folderManagerWarning(collect($exception->errors())->flatten()->first() ?: 'Move mail out of this folder before deleting it.');

            return;
        } catch (\Throwable $exception) {
            $this->folderManagerWarning('Provider folder could not be deleted: '.$exception->getMessage(), 'danger');

            return;
        }

        if ($operation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
            if ((int) $this->folderId === (int) $folder->id) {
                $this->setView('inbox');
            }

            $this->cancelFolderDelete();
            $this->mailActionStatus = [
                'type' => 'success',
                'message' => 'Provider folder '.$operation->source_folder_path.' was deleted.',
            ];
        } else {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => 'Provider folder delete failed: '.($operation->error_message ?: 'The mail server rejected the delete.'),
            ];
        }

        $this->dispatch('mail-state-changed');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function folderManagerRows(): array
    {
        $account = $this->selectedFolderCreateAccount();

        if (! $account) {
            return [];
        }

        $manager = app(ManageProviderEmailFolder::class);
        $folders = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('sync_enabled', true)
            ->orderBy('path')
            ->get();
        $foldersByPath = $folders->keyBy('path');
        $foldersByParent = $folders->groupBy(function (EmailFolder $folder) use ($foldersByPath): string {
            $parent = trim((string) $folder->parent_path);

            return $parent !== '' && $foldersByPath->has($parent) ? $parent : '';
        });
        $rows = [];

        $walk = function (Collection $siblings, int $depth) use (&$walk, &$rows, $foldersByParent, $manager): void {
            $this->sortFolderManagerSiblings($siblings)->each(function (EmailFolder $folder) use (&$walk, &$rows, $depth, $foldersByParent, $manager): void {
                $activePlacements = $manager->activePlacementCount($folder);
                $rules = $manager->ruleReferenceCount($folder);
                $mutationBlockers = $manager->mutationBlockers($folder);
                $deleteBlockers = $manager->mutationBlockers($folder, true);
                $children = $foldersByParent->get($folder->path, collect());
                $hasChildren = $children->isNotEmpty();
                $isExpanded = in_array($folder->path, $this->folderManagerExpandedPaths, true);
                $canRename = $mutationBlockers === [];
                $canMoveFolder = $mutationBlockers === [];
                $canDelete = $deleteBlockers === [] && $activePlacements === 0;
                $canMoveBeforeDelete = $mutationBlockers === [] && $activePlacements > 0;
                $hasActions = $canRename || $canMoveFolder || $canDelete || $canMoveBeforeDelete;

                $rows[] = [
                    'id' => $folder->id,
                    'name' => $folder->name ?: $folder->path,
                    'path' => $folder->path,
                    'role' => $folder->role,
                    'is_selectable' => $folder->is_selectable,
                    'depth' => $depth,
                    'has_children' => $hasChildren,
                    'is_expanded' => $isExpanded,
                    'active_placements' => $activePlacements,
                    'rule_references' => $rules,
                    'operation_count' => $manager->activeOperationCount($folder),
                    'child_count' => $manager->childFolderCount($folder),
                    'mutation_blockers' => $mutationBlockers,
                    'delete_blockers' => $deleteBlockers,
                    'action_blockers' => $hasActions ? [] : array_values(array_unique($deleteBlockers ?: $mutationBlockers)),
                    'can_rename' => $canRename,
                    'can_move_folder' => $canMoveFolder,
                    'can_delete' => $canDelete,
                    'can_move_before_delete' => $canMoveBeforeDelete,
                    'has_actions' => $hasActions,
                ];

                if ($hasChildren && $isExpanded) {
                    $walk($children, $depth + 1);
                }
            });
        };

        $walk($foldersByParent->get('', collect()), 0);

        return $rows;
    }

    public function folderManagerAccountLabel(): string
    {
        return $this->selectedFolderCreateAccount()?->address ?: 'Select one mailbox';
    }

    /**
     * @return Collection<int, EmailAccount>
     */
    public function folderManagerAccounts(): Collection
    {
        return app(MailboxAccess::class)->scopeAccounts(
            EmailAccount::query()
                ->where('is_active', true)
                ->orderBy('account_kind')
                ->orderBy('address'),
            $this->user(),
            MailboxAccess::ORGANIZE,
        )->get();
    }

    private function sortFolderManagerSiblings(Collection $folders): Collection
    {
        return $folders
            ->sort(function (EmailFolder $first, EmailFolder $second): int {
                $firstSort = [
                    $this->folderManagerRoleOrder($first->role),
                    mb_strtolower((string) ($first->name ?: $first->path)),
                    mb_strtolower((string) $first->path),
                ];
                $secondSort = [
                    $this->folderManagerRoleOrder($second->role),
                    mb_strtolower((string) ($second->name ?: $second->path)),
                    mb_strtolower((string) $second->path),
                ];

                return $firstSort <=> $secondSort;
            })
            ->values();
    }

    private function folderManagerRoleOrder(?string $role): int
    {
        return match ($role) {
            EmailFolder::ROLE_INBOX => 0,
            EmailFolder::ROLE_DRAFTS => 1,
            EmailFolder::ROLE_SENT => 2,
            EmailFolder::ROLE_ARCHIVE => 3,
            EmailFolder::ROLE_TRASH => 4,
            EmailFolder::ROLE_JUNK => 5,
            default => 6,
        };
    }

    public function mailCountLabel(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'mail' : 'mails');
    }

    public function conversationCountLabel(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'conversation' : 'conversations');
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    public function folderParentTargetsFor(?int $sourceFolderId = null): Collection
    {
        $account = $this->selectedFolderCreateAccount();

        if (! $account) {
            return collect();
        }

        $source = $sourceFolderId ? $this->folderManagerFolder($sourceFolderId) : null;
        $sourcePath = $source?->path;
        $delimiter = $source?->delimiter ?: '/';

        return EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('sync_enabled', true)
            ->when($source, function (Builder $query) use ($source, $sourcePath, $delimiter): void {
                $query
                    ->whereKeyNot($source->id)
                    ->where('path', 'not like', $sourcePath.$delimiter.'%');
            })
            ->orderBy('path')
            ->get();
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    public function folderMoveTargetsFor(int $sourceFolderId): Collection
    {
        $source = $this->managedFolderForAction($sourceFolderId);

        if (! $source) {
            return collect();
        }

        return EmailFolder::query()
            ->where('account_id', $source->account_id)
            ->where('is_selectable', true)
            ->whereKeyNot($source->id)
            ->orderByRaw("CASE WHEN role = 'archive' THEN 0 WHEN role = 'inbox' THEN 1 ELSE 2 END")
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    public function senderLabel(EmailMessage $message): string
    {
        $name = trim((string) $message->from_name);
        $email = trim((string) $message->from_email);

        if ($name !== '' && $email !== '') {
            return "{$name} <{$email}>";
        }

        return $name !== '' ? $name : ($email !== '' ? $email : 'Unknown sender');
    }

    public function senderInitial(EmailMessage $message): string
    {
        $source = trim((string) ($message->from_name ?: $message->from_email ?: '?'));
        $initial = (string) Str::of($source)->trim()->substr(0, 1)->upper();

        return $initial !== '' ? $initial : '?';
    }

    public function recipientSummary(EmailMessage $message): string
    {
        return collect($message->to_json ?? [])
            ->map(function (mixed $recipient): ?string {
                if (is_array($recipient)) {
                    return $recipient['email']
                        ?? $recipient['address']
                        ?? $recipient['name']
                        ?? null;
                }

                return is_scalar($recipient) ? (string) $recipient : null;
            })
            ->filter()
            ->unique()
            ->take(4)
            ->implode(', ');
    }

    public function messagePreview(EmailMessage $message): string
    {
        $body = (string) ($message->body_text ?: strip_tags((string) $message->body_html_sanitized));
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        return $body !== '' ? Str::limit($body, 160) : 'No preview text.';
    }

    public function isUnreadForMe(EmailMessage $message): bool
    {
        $user = $this->user();

        $message->loadMissing('account');

        if (! $user || ! $this->hasOrdinaryViewAccess($message->account)) {
            return false;
        }

        if (array_key_exists($message->id, $this->unreadForMeByMessage)) {
            return $this->unreadForMeByMessage[$message->id];
        }

        return $this->unreadForMeByMessage[$message->id] = app(EmailUnreadForMeResolver::class)
            ->resolve($message, $user) ?? false;
    }

    /**
     * @return array<string, string>
     */
    public function listFilterOptions(): array
    {
        $options = [
            'all' => 'All',
            'unread_for_me' => 'Unread for me',
            'mailbox_unread' => 'Mailbox unread',
            'flagged' => 'Flagged',
            'provider_drafts' => 'Provider drafts',
            'has_attachments' => 'Has attachments',
            'ticket_linked' => 'Ticket linked',
        ];

        if (! $this->hasAnyOrdinaryMailboxAccess()) {
            unset($options['unread_for_me'], $options['mailbox_unread'], $options['ticket_linked']);
        }

        return $options;
    }

    public function usesBreakGlassForPlacement(?EmailMailboxPlacement $placement): bool
    {
        if (! $placement?->account || ! $this->user()) {
            return false;
        }

        return app(ResolveMailboxAccessDecision::class)
            ->resolve(
                $this->user(),
                $placement->account,
                ResolveMailboxAccessDecision::CONTENT_VIEW,
            )
            ->source === MailboxAccessDecision::SOURCE_BREAK_GLASS;
    }

    public function canUsePersonalUnreadForPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null && $this->hasOrdinaryViewAccess($placement->account);
    }

    public function canUseTicketContextForPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null && $this->hasOrdinaryViewAccess($placement->account);
    }

    public function canViewRawSourceForPlacement(?EmailMailboxPlacement $placement): bool
    {
        $user = $this->user();

        return $placement?->account !== null
            && $placement->message !== null
            && filled($placement->message->raw_path)
            && $user !== null
            && app(ResolveMailboxAccessDecision::class)
                ->resolve($user, $placement->account, ResolveMailboxAccessDecision::RAW_SOURCE)
                ->allowed;
    }

    public function folderLabel(?EmailMailboxPlacement $placement): string
    {
        if (! $placement) {
            return 'No folder';
        }

        return $placement->folder?->name
            ?: $placement->folder?->path
            ?: $placement->folder_path
            ?: 'No folder';
    }

    public function canOrganizePlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && app(MailboxAccess::class)->canAccessAccount($this->user(), $placement->account, MailboxAccess::ORGANIZE);
    }

    public function canSendFromPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && ! $this->isProviderDraftPlacement($placement)
            && app(\App\Modules\Email\Services\EmailAccountProviderRuntimeResolver::class)
                ->databaseReady($placement->account)
            && app(MailboxAccess::class)->canAccessAccount($this->user(), $placement->account, MailboxAccess::VIEW)
            && app(MailboxAccess::class)->canAccessAccount($this->user(), $placement->account, MailboxAccess::SEND);
    }

    public function canReplyAllFromPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $this->canSendFromPlacement($placement)
            && $this->replyAllRecipientCount($placement) > 1;
    }

    public function canEditProviderDraftPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && $this->isProviderDraftPlacement($placement)
            && app(\App\Modules\Email\Services\EmailAccountProviderRuntimeResolver::class)
                ->databaseReady($placement->account)
            && app(MailboxAccess::class)->canAccessAccount($this->user(), $placement->account, MailboxAccess::VIEW)
            && app(MailboxAccess::class)->canAccessAccount($this->user(), $placement->account, MailboxAccess::SEND);
    }

    public function canReplyToPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $this->canSendFromPlacement($placement);
    }

    public function canMarkSpamPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->message !== null
            && ! $this->isProviderDraftPlacement($placement)
            && $placement->message->ticket_id === null
            && $this->canOrganizePlacement($placement);
    }

    public function canCreateTicketFromPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->message !== null
            && ! $this->isProviderDraftPlacement($placement)
            && $placement->message->ticket_id === null
            && $this->canOrganizePlacement($placement)
            && $this->user()?->can('ticket.create');
    }

    public function canOpenTicketFromPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->message?->ticket_id !== null
            && $this->canUseTicketContextForPlacement($placement)
            && $this->user()?->can('ticket.view');
    }

    public function canLinkTicketFromPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->message !== null
            && ! $this->isProviderDraftPlacement($placement)
            && $this->canOrganizePlacement($placement)
            && $this->user()?->can('ticket.update');
    }

    public function canUseAiTicketCreateAction(?EmailMailboxPlacement $placement): bool
    {
        if (! $placement?->message || ! $this->mailAiSummary || ! $this->canCreateTicketFromPlacement($placement)) {
            return false;
        }

        $user = $this->user();

        if (! $user) {
            return false;
        }

        return (bool) app(MailAiAgentRuntime::class)->writeAvailability($user, [
            'tickets.create',
            'tickets.update',
        ])['available'];
    }

    /**
     * @return Collection<int, EmailTicketConversationLink>
     */
    public function ticketConversationLinksForPlacement(?EmailMailboxPlacement $placement): Collection
    {
        if (! $placement?->message || ! $this->canUseTicketContextForPlacement($placement)) {
            return collect();
        }

        $conversations = app(EmailConversationProjector::class);
        $conversationKey = $conversations->conversationKey($placement->message);
        $conversationId = $conversations->available()
            ? $this->positiveId($placement->email_conversation_id)
            : null;

        return EmailTicketConversationLink::query()
            ->with('ticket')
            ->where(function (Builder $links) use ($conversationId, $conversationKey, $placement): void {
                if ($conversationId) {
                    $links->where('email_conversation_id', $conversationId);
                }

                $legacyScope = function (Builder $legacy) use ($conversationKey, $placement): void {
                    $legacy
                        ->where('conversation_key', $conversationKey)
                        ->where(function (Builder $accountScope) use ($placement): void {
                            $accountScope
                                ->where('account_id', $placement->account_id)
                                ->orWhereNull('account_id');
                        });
                };

                $conversationId
                    ? $links->orWhere($legacyScope)
                    : $links->where($legacyScope);
            })
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->latest('linked_at')
            ->latest('id')
            ->limit(6)
            ->get();
    }

    public function canUseRuleAction(?EmailMailboxPlacement $placement): bool
    {
        return ! $this->isProviderDraftPlacement($placement)
            && (
                $this->canCreatePersonalRuleForPlacement($placement)
                || $this->canOpenAdminRulesForPlacement($placement)
            );
    }

    public function canUseAiAction(?EmailMailboxPlacement $placement): bool
    {
        if (! $placement?->account || ! $placement->message || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
            return false;
        }

        $user = $this->user();

        if (! $user || ! app(MailboxAccess::class)->canAccessAccount($user, $placement->account, MailboxAccess::VIEW)) {
            return false;
        }

        return (bool) app(MailAiAgentRuntime::class)->availability($user)['available'];
    }

    public function canUseComposerAiAction(?EmailMailboxPlacement $placement): bool
    {
        if (! $this->composerOpen) {
            return false;
        }

        if ($this->composerMode === SendEmailComposerMessage::MODE_COMPOSE) {
            $account = $this->selectedComposeAccount();
            $user = $this->user();

            return $account !== null
                && $user !== null
                && app(MailboxAccess::class)->canAccessAccount($user, $account, MailboxAccess::SEND)
                && (bool) app(MailAiAgentRuntime::class)->availability($user)['available'];
        }

        if (! in_array($this->composerMode, [
            SendEmailComposerMessage::MODE_REPLY,
            SendEmailComposerMessage::MODE_REPLY_ALL,
            SendEmailComposerMessage::MODE_FORWARD,
        ], true)) {
            return false;
        }

        return $this->canSendFromPlacement($placement)
            && $this->canUseAiAction($placement);
    }

    public function canUseComposerAiDraftReply(?EmailMailboxPlacement $placement): bool
    {
        return in_array($this->composerMode, [
            SendEmailComposerMessage::MODE_REPLY,
            SendEmailComposerMessage::MODE_REPLY_ALL,
        ], true)
            && $this->canUseComposerAiAction($placement);
    }

    private function mailAiUnavailableMessage(User $user): string
    {
        $availability = app(MailAiAgentRuntime::class)->availability($user);

        return 'Mail AI is not available: '.($availability['reason'] ?: 'default_agent_not_available').'.';
    }

    public function canCreatePersonalRuleForPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null
            && $placement->account->isPersonal()
            && (int) $placement->account->owner_id === (int) ($this->user()?->id ?? 0)
            && $this->canOrganizePlacement($placement)
            && ($this->hasMoveTargets($placement) || $this->hasArchiveTarget($placement));
    }

    public function canOpenAdminRulesForPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->account !== null
            && ! $placement->account->isPersonal()
            && (bool) $this->user()?->can('email.rule_manage');
    }

    /**
     * @return array<string, string>
     */
    public function personalRuleConditionOptions(): array
    {
        return [
            'from' => 'From address',
            'from_domain' => 'From domain',
            'subject' => 'Subject contains',
            'to' => 'To contains',
            'cc' => 'Cc contains',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function personalRuleActionOptions(?EmailMailboxPlacement $placement): array
    {
        $options = [];

        if ($this->hasMoveTargets($placement)) {
            $options[CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER] = 'Move to folder';
        }

        if ($this->hasArchiveTarget($placement)) {
            $options[CreatePersonalEmailRule::ACTION_ARCHIVE] = 'Archive';
        }

        return $options;
    }

    /**
     * @return Collection<int, EmailRuleExecutionAttempt>
     */
    public function ruleExecutionAttemptsForPlacement(?EmailMailboxPlacement $placement): Collection
    {
        if (! $placement?->message) {
            return collect();
        }

        return EmailRuleExecutionAttempt::query()
            ->with('rule')
            ->where('email_message_id', $placement->email_message_id)
            ->where(function (Builder $attempts) use ($placement): void {
                $attempts
                    ->where('email_mailbox_placement_id', $placement->id)
                    ->orWhereNull('email_mailbox_placement_id');
            })
            ->latest('started_at')
            ->latest('id')
            ->limit(8)
            ->get();
    }

    public function hasArchiveTarget(?EmailMailboxPlacement $placement): bool
    {
        return $this->hasTargetFolder($placement, EmailFolder::ROLE_ARCHIVE);
    }

    public function hasTrashTarget(?EmailMailboxPlacement $placement): bool
    {
        return $this->hasTargetFolder($placement, EmailFolder::ROLE_TRASH);
    }

    /**
     * @return Collection<int, EmailFolder>
     */
    public function moveTargetFolders(?EmailMailboxPlacement $placement): Collection
    {
        if (! $this->canOrganizePlacement($placement)) {
            return collect();
        }

        return EmailFolder::query()
            ->where('account_id', $placement->account_id)
            ->where('is_selectable', true)
            ->whereKeyNot((int) $placement->email_folder_id)
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    public function hasMoveTargets(?EmailMailboxPlacement $placement): bool
    {
        return $this->moveTargetFolders($placement)->isNotEmpty();
    }

    public function classificationForPlacement(
        ?EmailMailboxPlacement $placement,
    ): EmailConversationClassification|EmailMessageClassification|null {
        if (! $placement?->message) {
            return null;
        }

        if ($placement->email_conversation_id) {
            if ($placement->relationLoaded('conversation') && $placement->conversation?->relationLoaded('classification')) {
                return $placement->conversation->classification;
            }

            return EmailConversationClassification::query()
                ->with(['category', 'tags'])
                ->where('account_id', $placement->account_id)
                ->where('email_conversation_id', $placement->email_conversation_id)
                ->first();
        }

        // Placements created before the durable projector is available retain a read-only
        // compatibility view until they receive an account-scoped conversation.
        if ($placement->message->relationLoaded('classifications')) {
            return $placement->message->classifications
                ->firstWhere('account_id', $placement->account_id);
        }

        return EmailMessageClassification::query()
            ->with(['category', 'tags'])
            ->where('account_id', $placement->account_id)
            ->where('email_message_id', $placement->email_message_id)
            ->first();
    }

    /**
     * @return array{accounts: Collection, accountIds: array<int>, folders: Collection, foldersByAccount: Collection, stats: array<string, int>}
     */
    protected function navigationData(): array
    {
        $mailboxAccess = app(MailboxAccess::class);
        $operation = trim($this->search) !== ''
            ? ResolveMailboxAccessDecision::SEARCH
            : ResolveMailboxAccessDecision::CONTENT_VIEW;
        $accounts = $this->authorizedContentAccounts(
            $this->contentAccounts($operation),
            $operation,
        );
        $accountIds = $accounts->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
        $ordinaryAccountIds = $this->ordinaryAccessibleAccounts($mailboxAccess)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->normalizeFilters($accountIds, $ordinaryAccountIds);

        $folders = $this->folderQuery($accountIds)->get();

        return [
            'accounts' => $accounts,
            'accountIds' => $accountIds,
            'folders' => $folders,
            'foldersByAccount' => $folders->groupBy('account_id'),
            'stats' => $this->stats($accountIds, $ordinaryAccountIds),
            'hasOrdinaryMailboxAccess' => $ordinaryAccountIds !== [],
            'activeBreakGlassAccesses' => $this->activeBreakGlassAccesses(),
        ];
    }

    private function contentAccounts(string $operation): Collection
    {
        return app(MailboxAccess::class)->scopeContentAccounts(
            EmailAccount::query()
                ->where('is_active', true)
                ->orderBy('account_kind')
                ->orderBy('address'),
            $this->user(),
            $operation,
        )->get();
    }

    private function ordinaryAccessibleAccounts(MailboxAccess $mailboxAccess): Collection
    {
        $accounts = $mailboxAccess->scopeAccounts(
            EmailAccount::query()
                ->where('is_active', true)
                ->orderBy('account_kind')
                ->orderBy('address'),
            $this->user(),
            MailboxAccess::VIEW,
        )->get();

        $this->ordinaryAccountIdsCache = $accounts
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        return $accounts;
    }

    /**
     * Reauthorize and audit every account before the subsequent list/search query can load mail.
     * Revocation or expiry removes only that account; durable audit failures intentionally bubble
     * and fail the whole content boundary closed.
     */
    private function authorizedContentAccounts(Collection $accounts, string $operation): Collection
    {
        $resourceType = $operation === ResolveMailboxAccessDecision::SEARCH ? 'search' : 'mailbox';

        return $accounts
            ->filter(fn (mixed $account): bool => $account instanceof EmailAccount
                && $this->authorizeContentUse(
                    $account,
                    $operation,
                    $resourceType,
                    (int) $account->id,
                ))
            ->values();
    }

    private function authorizeContentUse(
        EmailAccount $account,
        string $operation,
        string $resourceType,
        ?int $resourceId,
    ): bool {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        try {
            $decision = app(MailboxAccessUseGuard::class)->authorize(
                $user,
                $account,
                $operation,
                $resourceType,
                $resourceId,
            );
        } catch (AuthorizationException) {
            return false;
        }

        if ($operation === ResolveMailboxAccessDecision::SEARCH
            && ! app(ResolveMailboxAccessDecision::class)
                ->resolve($user, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)
                ->allowed) {
            return false;
        }

        // Ordinary content use is also the entitlement boundary for per-user unread epochs.
        // Break-glass decisions never enter this branch and therefore never start/reset an epoch.
        return $decision->usesBreakGlass()
            || app(MailboxAccess::class)->canAccessAccount($user, $account, MailboxAccess::VIEW);
    }

    /** @return array<int> */
    private function ordinaryAccountIds(): array
    {
        if ($this->ordinaryAccountIdsCache !== null) {
            return $this->ordinaryAccountIdsCache;
        }

        $this->ordinaryAccessibleAccounts(app(MailboxAccess::class));

        return $this->ordinaryAccountIdsCache ?? [];
    }

    private function hasAnyOrdinaryMailboxAccess(): bool
    {
        return $this->ordinaryAccountIds() !== [];
    }

    private function hasOrdinaryViewAccess(?EmailAccount $account): bool
    {
        $user = $this->user();

        if (! $account || ! $user) {
            return false;
        }

        $accountId = (int) $account->id;

        return $this->ordinaryViewAccessByAccount[$accountId] ??= app(ResolveMailboxAccessDecision::class)
            ->resolve($user, $account, MailboxAccess::VIEW)
            ->allowed;
    }

    /** @return Collection<int, EmailBreakGlassAccess> */
    private function activeBreakGlassAccesses(): Collection
    {
        $user = $this->user();

        if (! $user
            || ! $user->can('email.break_glass_activate')
            || ! $this->hasCompletedAdvancedMailboxAccessSchema()) {
            return collect();
        }

        return EmailBreakGlassAccess::query()
            ->where('actor_id', $user->id)
            ->whereHas('account', function (Builder $accounts): void {
                $accounts
                    ->where('is_active', true)
                    ->where('account_kind', EmailAccount::KIND_PERSONAL);
            })
            ->with('account:id,address,account_kind,is_active')
            ->effective()
            ->orderBy('expires_at')
            ->get()
            ->filter(function (EmailBreakGlassAccess $access) use ($user): bool {
                $account = $access->account;

                if (! $account) {
                    return false;
                }

                return collect(ResolveMailboxAccessDecision::BREAK_GLASS_OPERATIONS)
                    ->contains(function (string $operation) use ($access, $account, $user): bool {
                        $decision = app(ResolveMailboxAccessDecision::class)
                            ->resolve($user, $account, $operation);

                        return $decision->usesBreakGlass()
                            && (int) $decision->breakGlassAccessId === (int) $access->id;
                    });
            })
            ->values();
    }

    /**
     * Do not expose a partially deployed break-glass surface before its
     * append-only access ledger and migration seal are both present.
     */
    private function hasCompletedAdvancedMailboxAccessSchema(): bool
    {
        return Schema::hasTable('email_mailbox_delegations')
            && Schema::hasTable('email_break_glass_accesses')
            && Schema::hasTable('email_mailbox_access_events')
            && Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where(
                    'migration',
                    '2026_08_16_103000_create_email_mailbox_delegation_break_glass_access',
                )
                ->exists();
    }

    private function sendableAccounts(MailboxAccess $mailboxAccess): Collection
    {
        return $mailboxAccess->scopeAccounts(
            EmailAccount::query()
                ->where('is_active', true)
                ->orderBy('account_kind')
                ->orderBy('address'),
            $this->user(),
            MailboxAccess::SEND,
        )->get()->filter(
            fn (EmailAccount $account): bool => app(\App\Modules\Email\Services\EmailAccountProviderRuntimeResolver::class)
                ->databaseReady($account),
        )->values();
    }

    /**
     * @return Collection<int, EmailAccount>
     */
    private function manualRefreshAccounts(): Collection
    {
        return app(MailboxAccess::class)->scopeAccounts(
            EmailAccount::query()
                ->where('is_active', true)
                ->orderBy('account_kind')
                ->orderBy('address'),
            $this->user(),
            MailboxAccess::ORGANIZE,
        )->get();
    }

    private function selectedFolderForRefresh(): ?EmailFolder
    {
        $folderId = $this->positiveId($this->folderId);

        if (! $folderId) {
            return null;
        }

        $folder = EmailFolder::query()
            ->with('account')
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKey($folderId)
            ->first();

        if (! $folder?->account?->is_active) {
            return null;
        }

        return app(MailboxAccess::class)->canAccessAccount($this->user(), $folder->account, MailboxAccess::ORGANIZE)
            ? $folder
            : null;
    }

    private function manualFetchBatchSize(): int
    {
        $batchSize = CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'batch_size')
            ->value('value');

        return max(1, (int) ($batchSize ?? 20));
    }

    private function selectedFolderCreateAccount(): ?EmailAccount
    {
        $mailboxAccess = app(MailboxAccess::class);
        $accountId = $this->folderManagerOpen
            ? $this->positiveId($this->folderManagerAccountId)
            : 0;

        if (! $accountId) {
            $accountId = $this->positiveId($this->accountId);
        }

        if (! $accountId) {
            $folderId = $this->positiveId($this->folderId);
            if ($folderId) {
                $accountId = (int) EmailFolder::query()
                    ->whereKey($folderId)
                    ->value('account_id');
            }
        }

        if (! $accountId) {
            $accountId = $this->positiveId($this->folderManagerAccountId);
        }

        $query = EmailAccount::query()
            ->where('is_active', true)
            ->orderBy('account_kind')
            ->orderBy('address');

        if ($accountId) {
            return $mailboxAccess->scopeAccounts(
                $query->whereKey($accountId),
                $this->user(),
                MailboxAccess::ORGANIZE,
            )->first();
        }

        $accounts = $mailboxAccess->scopeAccounts(
            $query->limit(2),
            $this->user(),
            MailboxAccess::ORGANIZE,
        )->get();

        return $accounts->count() === 1 ? $accounts->first() : null;
    }

    private function folderManagerFolder(?int $folderId): ?EmailFolder
    {
        $account = $this->selectedFolderCreateAccount();

        if (! $account || ! $folderId) {
            return null;
        }

        return EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('sync_enabled', true)
            ->whereKey($folderId)
            ->first();
    }

    private function newProviderFolderPath(): string
    {
        $name = trim($this->newFolderName);
        $parent = $this->folderManagerFolder($this->positiveId($this->newFolderParentId));

        if (! $parent) {
            return $name;
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw ValidationException::withMessages([
                'newFolderName' => 'Enter a simple folder name when creating inside a parent folder.',
            ]);
        }

        $delimiter = $this->delimiterForFolderParent($parent);

        return rtrim((string) $parent->path, $delimiter).$delimiter.$name;
    }

    private function delimiterForFolderParent(EmailFolder $parent): string
    {
        $delimiter = trim((string) $parent->delimiter);

        if ($delimiter !== '') {
            return $delimiter;
        }

        $knownDelimiter = EmailFolder::query()
            ->where('account_id', $parent->account_id)
            ->where('sync_enabled', true)
            ->whereNotNull('delimiter')
            ->where('delimiter', '!=', '')
            ->value('delimiter');

        return $knownDelimiter ?: '/';
    }

    private function revealFolderInManager(EmailFolder $folder): void
    {
        $expanded = collect($this->folderManagerExpandedPaths)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '');
        $parentPath = trim((string) $folder->parent_path);
        $guard = 0;

        while ($parentPath !== '' && $guard < 20) {
            $expanded->push($parentPath);
            $parentPath = trim((string) EmailFolder::query()
                ->where('account_id', $folder->account_id)
                ->where('path', $parentPath)
                ->value('parent_path'));
            $guard++;
        }

        $this->folderManagerExpandedPaths = $expanded->unique()->values()->all();
    }

    private function folderMoveParentFolder(): ?EmailFolder
    {
        $targetParentId = $this->positiveId($this->folderMoveParentFolderId);

        return $targetParentId ? $this->folderManagerFolder($targetParentId) : null;
    }

    private function defaultFolderMoveParentId(EmailFolder $folder): mixed
    {
        if ($folder->parent_path) {
            return '';
        }

        return $this->folderParentTargetsFor((int) $folder->id)->first()?->id ?: '';
    }

    private function managedFolderForAction(?int $folderId): ?EmailFolder
    {
        $account = $this->selectedFolderCreateAccount();

        if (! $account || ! $folderId) {
            return null;
        }

        return EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->whereKey($folderId)
            ->first();
    }

    private function managedFolderMoveTarget(): ?EmailFolder
    {
        $source = $this->managedFolderForAction($this->positiveId($this->folderMoveSourceFolderId));
        $target = $this->managedFolderForAction($this->positiveId($this->folderMoveTargetFolderId));

        if (! $source || ! $target || (int) $source->id === (int) $target->id) {
            return null;
        }

        return $target;
    }

    private function defaultFolderMoveTargetId(EmailFolder $source): ?int
    {
        $preferred = EmailFolder::query()
            ->where('account_id', $source->account_id)
            ->where('is_selectable', true)
            ->whereKeyNot($source->id)
            ->whereIn('role', [EmailFolder::ROLE_ARCHIVE, EmailFolder::ROLE_INBOX])
            ->orderByRaw("CASE WHEN role = 'archive' THEN 0 WHEN role = 'inbox' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->first();

        if ($preferred) {
            return (int) $preferred->id;
        }

        return EmailFolder::query()
            ->where('account_id', $source->account_id)
            ->where('is_selectable', true)
            ->whereKeyNot($source->id)
            ->orderBy('role')
            ->orderBy('name')
            ->value('id');
    }

    private function folderManagerWarning(string $message, string $type = 'warning'): void
    {
        $this->mailActionStatus = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function preferredComposeAccount(): ?EmailAccount
    {
        $sendableAccounts = $this->sendableAccounts(app(MailboxAccess::class));
        $selectedPlacement = $this->selectedPlacementForAction();

        if ($selectedPlacement?->account && $this->canSendFromPlacement($selectedPlacement)) {
            $selectedAccount = $sendableAccounts->firstWhere('id', $selectedPlacement->account_id);

            if ($selectedAccount) {
                return $selectedAccount;
            }
        }

        $filteredAccountId = $this->positiveId($this->accountId);

        if ($filteredAccountId) {
            $filteredAccount = $sendableAccounts->firstWhere('id', $filteredAccountId);

            if ($filteredAccount) {
                return $filteredAccount;
            }
        }

        return $sendableAccounts->first();
    }

    private function selectedComposeAccount(): ?EmailAccount
    {
        $accountId = $this->positiveId($this->composerAccountId);

        if (! $accountId) {
            return null;
        }

        return $this->sendableAccounts(app(MailboxAccess::class))
            ->firstWhere('id', $accountId);
    }

    /**
     * @param  array<int>  $accountIds
     * @return Builder<EmailFolder>
     */
    private function folderQuery(array $accountIds): Builder
    {
        return EmailFolder::query()
            ->whereIn('account_id', $accountIds)
            ->where('is_selectable', true)
            ->orderBy('account_id')
            ->orderBy('role')
            ->orderBy('name');
    }

    /**
     * @param  array<int>  $accountIds
     * @return Builder<EmailMailboxPlacement>
     */
    private function basePlacementQuery(array $accountIds): Builder
    {
        $userId = $this->user()?->id ?: 0;

        return EmailMailboxPlacement::query()
            ->with([
                'account',
                'conversation.classification.category',
                'conversation.classification.tags',
                'folder',
                'message' => fn ($messages) => $messages->withCount('attachments'),
                'message.account',
                'message.attachments',
                'message.classifications' => fn ($classifications) => $classifications
                    ->whereIn('account_id', $accountIds)
                    ->with(['category', 'tags']),
                'message.tags',
                'message.ticket',
                'message.userStates' => fn ($states) => $states->where('user_id', $userId),
                'sentReconciliations',
            ])
            ->whereIn('account_id', $accountIds)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('message');
    }

    /**
     * @param  array<int>  $accountIds
     * @return Builder<EmailMailboxPlacement>
     */
    private function filteredPlacementQuery(array $accountIds): Builder
    {
        $query = $this->basePlacementQuery($accountIds);
        $accountId = $this->positiveId($this->accountId);
        $folderId = $this->positiveId($this->folderId);
        $term = trim($this->search);

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        if ($folderId) {
            $query->where('email_folder_id', $folderId);
        } elseif ($this->viewMode === 'unread') {
            $this->scopeInboxPlacements($query);
            $this->scopeUnreadForMe($query);
        } elseif ($this->viewMode === 'inbox') {
            $this->scopeInboxPlacements($query);
        } elseif ($this->viewMode === 'drafts') {
            $this->scopeDraftPlacements($query);
        } else {
            $this->scopeNonTrashPlacements($query);
        }

        if ($term !== '') {
            $query->whereHas(
                'message',
                fn (Builder $messages): Builder => $messages->searchText($term),
            );
        }

        $this->applyListFilter($query);

        return $this->orderPlacements($query);
    }

    /**
     * @param  array<int>  $accountIds
     * @return LengthAwarePaginator<int, EmailMailboxPlacement>
     */
    private function conversationPlacementPaginator(array $accountIds): LengthAwarePaginator
    {
        $page = max(1, (int) $this->getPage());
        $legacy = $this->legacyConversationListData($accountIds);
        $this->legacyConversationListTruncated = $legacy['truncated'];
        $durableLeaderIds = $this->durableConversationLeaderIds($accountIds);

        // The durable branch stays entirely in SQL: the window identifies one
        // exact, correctly sorted leader for each account-scoped conversation,
        // and the outer query paginates only those leaders. Legacy null IDs are
        // the sole bounded compatibility input to this query.
        $leaders = $this->basePlacementQuery($accountIds)
            ->where(function (Builder $query) use ($durableLeaderIds, $legacy): void {
                $query->whereIn('email_mailbox_placements.id', $durableLeaderIds);

                if ($legacy['leader_ids'] !== []) {
                    $query->orWhereIn('email_mailbox_placements.id', $legacy['leader_ids']);
                }
            });

        /** @var LengthAwarePaginator<int, EmailMailboxPlacement> $paginator */
        $paginator = $this->orderPlacements($leaders)
            ->paginate($this->perPage, ['*'], 'page', $page)
            ->withQueryString();

        $this->hydrateConversationListMetadata($paginator, $legacy['metadata'], $accountIds);

        return $paginator;
    }

    /**
     * Return a SQL subquery containing one placement ID per durable,
     * account-scoped conversation after all current list filters are applied.
     */
    private function durableConversationLeaderIds(array $accountIds): QueryBuilder
    {
        $ranked = $this->filteredPlacementQuery($accountIds)
            ->withoutEagerLoads()
            ->reorder()
            ->whereNotNull('email_mailbox_placements.email_conversation_id')
            ->select('email_mailbox_placements.id')
            ->selectRaw(<<<'SQL'
                ROW_NUMBER() OVER (
                    PARTITION BY email_mailbox_placements.account_id, email_mailbox_placements.email_conversation_id
                    ORDER BY
                        (
                            SELECT email_messages.received_at
                            FROM email_messages
                            WHERE email_messages.id = email_mailbox_placements.email_message_id
                            LIMIT 1
                        ) DESC,
                        (
                            SELECT email_messages.created_at
                            FROM email_messages
                            WHERE email_messages.id = email_mailbox_placements.email_message_id
                            LIMIT 1
                        ) DESC,
                        email_mailbox_placements.id DESC
                ) AS mail_conversation_rank
                SQL);

        return DB::query()
            ->fromSub($ranked, 'mail_durable_conversation_candidates')
            ->where('mail_conversation_rank', 1)
            ->select('id');
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{
     *     leader_ids: array<int, int>,
     *     metadata: array<int, array{count: int, placement_ids: array<int, int>, unread_for_me: int, mailbox_unread: int, group_key: string}>,
     *     truncated: bool
     * }
     */
    private function legacyConversationListData(array $accountIds): array
    {
        $conversations = app(EmailConversationProjector::class);
        $placements = $this->filteredPlacementQuery($accountIds)
            ->whereNull('email_mailbox_placements.email_conversation_id')
            ->limit(self::LEGACY_CONVERSATION_LIST_LIMIT + 1)
            ->get();
        $truncated = $placements->count() > self::LEGACY_CONVERSATION_LIST_LIMIT;

        $groups = $placements
            ->take(self::LEGACY_CONVERSATION_LIST_LIMIT)
            ->groupBy(fn (EmailMailboxPlacement $placement): string => $this->placementConversationGroupKey($placement, $conversations));
        $metadata = [];

        foreach ($groups as $groupKey => $group) {
            /** @var EmailMailboxPlacement|null $leader */
            $leader = $group->first();

            if (! $leader) {
                continue;
            }

            $metadata[(int) $leader->id] = [
                'count' => $group->count(),
                'placement_ids' => $group->pluck('id')->map(fn (int|string $id): int => (int) $id)->values()->all(),
                'unread_for_me' => $group
                    ->filter(fn (EmailMailboxPlacement $placement): bool => $placement->message !== null && $this->isUnreadForMe($placement->message))
                    ->count(),
                'mailbox_unread' => $group
                    ->filter(fn (EmailMailboxPlacement $placement): bool => ! $placement->provider_seen)
                    ->count(),
                'group_key' => (string) $groupKey,
            ];
        }

        return [
            'leader_ids' => array_keys($metadata),
            'metadata' => $metadata,
            'truncated' => $truncated,
        ];
    }

    /**
     * Hydrate the list-only aggregate attributes without loading each durable
     * conversation's placements into PHP.
     *
     * @param  LengthAwarePaginator<int, EmailMailboxPlacement>  $paginator
     * @param  array<int, array{count: int, placement_ids: array<int, int>, unread_for_me: int, mailbox_unread: int, group_key: string}>  $legacyMetadata
     * @param  array<int>  $accountIds
     */
    private function hydrateConversationListMetadata(
        LengthAwarePaginator $paginator,
        array $legacyMetadata,
        array $accountIds,
    ): void {
        $durableLeaders = $paginator->getCollection()
            ->filter(fn (EmailMailboxPlacement $placement): bool => $placement->email_conversation_id !== null);
        $durableMetadata = $this->durableConversationListMetadata($durableLeaders, $accountIds);

        $conversations = app(EmailConversationProjector::class);

        $paginator->getCollection()->each(function (EmailMailboxPlacement $leader) use ($durableMetadata, $legacyMetadata, $conversations): void {
            $metadata = $leader->email_conversation_id
                ? ($durableMetadata[$this->durableConversationKey($leader)] ?? null)
                : ($legacyMetadata[(int) $leader->id] ?? null);

            if (! $metadata) {
                $metadata = [
                    'count' => 1,
                    'placement_ids' => [(int) $leader->id],
                    'unread_for_me' => $leader->message && $this->isUnreadForMe($leader->message) ? 1 : 0,
                    'mailbox_unread' => $leader->provider_seen ? 0 : 1,
                    'group_key' => $this->placementConversationGroupKey($leader, $conversations),
                ];
            }

            $leader->setAttribute('mail_conversation_count', $metadata['count']);
            $leader->setAttribute('mail_conversation_placement_ids', $metadata['placement_ids']);
            $leader->setAttribute('mail_conversation_unread_for_me_count', $metadata['unread_for_me']);
            $leader->setAttribute('mail_conversation_mailbox_unread_count', $metadata['mailbox_unread']);
            $leader->setAttribute('mail_conversation_group_key', $metadata['group_key']);
        });
    }

    /**
     * @param  Collection<int, EmailMailboxPlacement>  $leaders
     * @param  array<int>  $accountIds
     * @return array<string, array{count: int, placement_ids: array<int, int>, unread_for_me: int, mailbox_unread: int, group_key: string}>
     */
    private function durableConversationListMetadata(Collection $leaders, array $accountIds): array
    {
        if ($leaders->isEmpty()) {
            return [];
        }

        $user = $this->user();

        if (! $user) {
            return [];
        }

        [$unreadSql, $unreadBindings] = app(EmailUnreadForMeResolver::class)->sqlExpression(
            $user,
            'email_mailbox_placements.email_message_id',
            'email_mailbox_placements.account_id',
        );
        $query = $this->filteredPlacementQuery($accountIds)
            ->withoutEagerLoads()
            ->reorder()
            ->whereNotNull('email_mailbox_placements.email_conversation_id')
            ->where(function (Builder $pairs) use ($leaders): void {
                foreach ($leaders as $leader) {
                    $pairs->orWhere(function (Builder $pair) use ($leader): void {
                        $pair
                            ->where('email_mailbox_placements.account_id', $leader->account_id)
                            ->where('email_mailbox_placements.email_conversation_id', $leader->email_conversation_id);
                    });
                }
            })
            ->select([
                'email_mailbox_placements.account_id',
                'email_mailbox_placements.email_conversation_id',
            ])
            ->selectRaw('COUNT(*) AS mail_conversation_count')
            ->selectRaw(<<<SQL
                SUM(
                    CASE WHEN ({$unreadSql}) = 1 THEN 1 ELSE 0 END
                ) AS mail_conversation_unread_for_me_count
                SQL, $unreadBindings)
            ->selectRaw(<<<'SQL'
                SUM(
                    CASE WHEN email_mailbox_placements.provider_seen = 0 THEN 1 ELSE 0 END
                ) AS mail_conversation_mailbox_unread_count
                SQL)
            ->groupBy([
                'email_mailbox_placements.account_id',
                'email_mailbox_placements.email_conversation_id',
            ]);

        return $query
            ->get()
            ->mapWithKeys(function (EmailMailboxPlacement $aggregate): array {
                $key = $aggregate->account_id.'|'.$aggregate->email_conversation_id;
                $groupKey = $aggregate->account_id.'|conversation:'.$aggregate->email_conversation_id;

                return [$key => [
                    'count' => (int) $aggregate->getAttribute('mail_conversation_count'),
                    // Durable selection highlighting compares the account and
                    // conversation IDs, so no unbounded placement-ID list is needed.
                    'placement_ids' => [],
                    'unread_for_me' => (int) $aggregate->getAttribute('mail_conversation_unread_for_me_count'),
                    'mailbox_unread' => (int) $aggregate->getAttribute('mail_conversation_mailbox_unread_count'),
                    'group_key' => $groupKey,
                ]];
            })
            ->all();
    }

    private function durableConversationKey(EmailMailboxPlacement $placement): string
    {
        return $placement->account_id.'|'.$placement->email_conversation_id;
    }

    private function placementConversationGroupKey(
        EmailMailboxPlacement $placement,
        EmailConversationProjector $conversations,
    ): string {
        if ($placement->email_conversation_id) {
            return $placement->account_id.'|conversation:'.$placement->email_conversation_id;
        }

        if (! $placement->message) {
            return 'placement:'.$placement->id;
        }

        return $placement->account_id.'|'.$conversations->conversationKey($placement->message);
    }

    private function applyListFilter(Builder $query): void
    {
        if (! array_key_exists($this->listFilter, $this->listFilterOptions())) {
            $this->listFilter = 'all';
        }

        switch ($this->listFilter) {
            case 'unread_for_me':
                $this->scopeUnreadForMe($query);
                break;
            case 'mailbox_unread':
                $query->where('provider_seen', false);
                break;
            case 'flagged':
                $query->where('provider_flagged', true);
                break;
            case 'provider_drafts':
                $this->scopeDraftPlacements($query);
                break;
            case 'has_attachments':
                $query->whereHas('message.attachments');
                break;
            case 'ticket_linked':
                $query->whereHas('message', fn (Builder $messages): Builder => $messages->whereNotNull('ticket_id'));
                break;
        }
    }

    /**
     * @param  array<int>  $accountIds
     */
    private function selectedPlacement(array $accountIds): ?EmailMailboxPlacement
    {
        $id = $this->positiveId($this->selectedPlacementId);

        if (! $id) {
            return null;
        }

        $metadata = EmailMailboxPlacement::query()
            ->whereIn('account_id', $accountIds)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereKey($id)
            ->first(['id', 'account_id', 'email_message_id']);
        $account = $metadata
            ? EmailAccount::query()->whereKey($metadata->account_id)->first()
            : null;

        if (! $metadata
            || ! $account
            || ! $this->authorizeContentUse(
                $account,
                ResolveMailboxAccessDecision::CONTENT_VIEW,
                'message',
                (int) $metadata->email_message_id,
            )) {
            return null;
        }

        return $this->basePlacementQuery($accountIds)
            ->whereKey($id)
            ->first();
    }

    /**
     * Overlay only common canonical content on already authorized source placements. Message,
     * account, Ticket, unread, rule, attachment-route, and provider identities stay source-scoped.
     *
     * @param  iterable<EmailMailboxPlacement>  $placements
     */
    private function projectCanonicalContent(
        iterable $placements,
        EmailCanonicalContentResolver $canonicalContent,
    ): void {
        foreach ($placements as $placement) {
            if (! $placement->message) {
                continue;
            }

            $resolution = $canonicalContent->resolve($placement, $placement->message);
            $placement->setRelation('message', $resolution->message);
        }
    }

    public function sentReconciliationForPlacement(EmailMailboxPlacement $placement): ?EmailSentReconciliation
    {
        if ($placement->folder?->role !== EmailFolder::ROLE_SENT) {
            return null;
        }

        return $placement->sentReconciliations
            ->firstWhere('status', EmailSentReconciliation::STATUS_RECONCILED);
    }

    public function remoteOperationLabel(string $status): string
    {
        return match ($status) {
            EmailRemoteOperation::STATUS_SUCCEEDED => 'Succeeded',
            EmailRemoteOperation::STATUS_FAILED => 'Failed',
            EmailRemoteOperation::STATUS_RUNNING => 'Running',
            EmailRemoteOperation::STATUS_CANCELLED => 'Cancelled',
            EmailRemoteOperation::STATUS_SUPERSEDED => 'Superseded',
            default => 'Pending',
        };
    }

    public function remoteOperationBadgeClass(string $status): string
    {
        return match ($status) {
            EmailRemoteOperation::STATUS_SUCCEEDED => 'text-bg-success',
            EmailRemoteOperation::STATUS_FAILED => 'text-bg-danger',
            EmailRemoteOperation::STATUS_RUNNING => 'text-bg-info',
            EmailRemoteOperation::STATUS_CANCELLED,
            EmailRemoteOperation::STATUS_SUPERSEDED => 'text-bg-secondary',
            default => 'text-bg-light border',
        };
    }

    public function isProviderDraftPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->provider_draft === true
            || $placement?->folder?->role === EmailFolder::ROLE_DRAFTS;
    }

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, EmailMailboxPlacement>
     */
    private function conversationPlacements(EmailMailboxPlacement $selectedPlacement, array $accountIds): Collection
    {
        return $this->conversationThread($selectedPlacement, $accountIds)['placements'];
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{
     *     placements: Collection<int, EmailMailboxPlacement>,
     *     total: int,
     *     truncated: bool,
     *     can_load_more: bool
     * }
     */
    private function conversationThread(EmailMailboxPlacement $selectedPlacement, array $accountIds): array
    {
        $message = $selectedPlacement->message;

        if (! $message) {
            return $this->emptyConversationThread();
        }

        if ($selectedPlacement->email_conversation_id) {
            $placements = $this->orderPlacements(
                $this->basePlacementQuery($accountIds)
                    ->where('account_id', $selectedPlacement->account_id)
                    ->where('email_conversation_id', $selectedPlacement->email_conversation_id),
            )
                ->get();

            return [
                'placements' => $placements,
                'total' => $placements->count(),
                'truncated' => false,
                'can_load_more' => false,
            ];
        }

        $identifiers = collect([$message->message_id, $message->in_reply_to])
            ->merge(preg_split('/\s+/', (string) $message->references) ?: [])
            ->map(fn (?string $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        // Header matching is compatibility-only. Keep it both account-scoped
        // and null-conversation-scoped so identical headers in another granted
        // mailbox, or a now-projected durable thread, can never bleed in.
        $query = $this->basePlacementQuery($accountIds)
            ->where('email_mailbox_placements.account_id', $selectedPlacement->account_id)
            ->whereNull('email_mailbox_placements.email_conversation_id');

        if ($identifiers->isEmpty()) {
            $query->whereKey($selectedPlacement->id);
        } else {
            $query->whereHas('message', function (Builder $messages) use ($identifiers): void {
                $messages
                    ->whereIn('message_id', $identifiers)
                    ->orWhereIn('in_reply_to', $identifiers)
                    ->orWhere(function (Builder $referenceQuery) use ($identifiers): void {
                        foreach ($identifiers as $identifier) {
                            $referenceQuery->orWhere('references', 'like', '%'.$identifier.'%');
                        }
                    });
            });
        }

        $total = (clone $query)->count();
        $limit = min(
            self::LEGACY_CONVERSATION_THREAD_LIMIT,
            max(self::LEGACY_CONVERSATION_INITIAL_THREAD_LIMIT, $this->legacyConversationThreadLimit),
        );
        $placements = $this->orderPlacements($query)
            ->limit($limit)
            ->get();

        // Direct links may select an older legacy placement outside the
        // compatibility window. Keep the selected mail readable while still
        // refusing an unbounded header scan.
        if (! $placements->contains('id', $selectedPlacement->id)) {
            $placements->push($selectedPlacement);
            $placements = $placements
                ->sortByDesc(fn (EmailMailboxPlacement $placement): string => sprintf(
                    '%s|%s|%020d',
                    $placement->message?->received_at?->format('Y-m-d H:i:s.u') ?? '',
                    $placement->message?->created_at?->format('Y-m-d H:i:s.u') ?? '',
                    (int) $placement->id,
                ))
                ->values();
        }

        return [
            'placements' => $placements,
            'total' => $total,
            'truncated' => $placements->count() < $total,
            'can_load_more' => $placements->count() < $total && $limit < self::LEGACY_CONVERSATION_THREAD_LIMIT,
        ];
    }

    /**
     * @return array{
     *     placements: Collection<int, EmailMailboxPlacement>,
     *     total: int,
     *     truncated: bool,
     *     can_load_more: bool
     * }
     */
    private function emptyConversationThread(): array
    {
        return [
            'placements' => collect(),
            'total' => 0,
            'truncated' => false,
            'can_load_more' => false,
        ];
    }

    /**
     * @param  array<int>  $accountIds
     * @return array<string, int>
     */
    private function stats(array $accountIds, array $ordinaryAccountIds): array
    {
        $base = $this->basePlacementQuery($accountIds);
        $ordinaryBase = $this->basePlacementQuery(array_values(array_intersect(
            $accountIds,
            $ordinaryAccountIds,
        )));
        $unreadForMe = clone $ordinaryBase;
        $inbox = clone $base;
        $drafts = clone $base;
        $all = clone $base;
        $unread = clone $ordinaryBase;

        $this->scopeInboxPlacements($unreadForMe);
        $this->scopeUnreadForMe($unreadForMe);
        $this->scopeInboxPlacements($inbox);
        $this->scopeDraftPlacements($drafts);
        $this->scopeNonTrashPlacements($all);

        return [
            'unread_for_me' => $unreadForMe->count(),
            'inbox' => $inbox->count(),
            'drafts' => $drafts->count(),
            'all' => $all->count(),
            'provider_unread' => $unread->where('provider_seen', false)->count(),
        ];
    }

    private function scopeInboxPlacements(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->whereHas('folder', fn (Builder $folders): Builder => $folders->where('role', EmailFolder::ROLE_INBOX))
                ->orWhere(function (Builder $legacy): void {
                    $legacy
                        ->whereNull('email_folder_id')
                        ->whereIn('folder_path', ['INBOX', 'Inbox', 'inbox']);
                });
        });
    }

    private function scopeNonTrashPlacements(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->whereDoesntHave('folder')
                ->orWhereHas('folder', function (Builder $folders): void {
                    $folders->whereNotIn('role', [EmailFolder::ROLE_TRASH, EmailFolder::ROLE_JUNK]);
                });
        });
    }

    private function scopeDraftPlacements(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->where('provider_draft', true)
                ->orWhereHas('folder', fn (Builder $folders): Builder => $folders->where('role', EmailFolder::ROLE_DRAFTS));
        });
    }

    private function scopeUnreadForMe(Builder $query): void
    {
        $user = $this->user();
        $ordinaryAccountIds = $this->ordinaryAccountIds();

        if (! $user || $ordinaryAccountIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('email_mailbox_placements.account_id', $ordinaryAccountIds);
        $query->whereHas(
            'message',
            fn (Builder $messages): Builder => app(EmailUnreadForMeResolver::class)
                ->scopeUnreadMessages($messages, $user),
        );
    }

    private function orderPlacements(Builder $query): Builder
    {
        return $query
            ->orderByDesc(
                EmailMessage::query()
                    ->select('received_at')
                    ->whereColumn('email_messages.id', 'email_mailbox_placements.email_message_id')
                    ->limit(1)
            )
            ->orderByDesc(
                EmailMessage::query()
                    ->select('created_at')
                    ->whereColumn('email_messages.id', 'email_mailbox_placements.email_message_id')
                    ->limit(1)
            )
            ->orderByDesc('email_mailbox_placements.id');
    }

    /**
     * @return array{visible: bool, stats: array<string, int>, items: array<int, array<string, mixed>>}
     */
    private function remoteOperationsDashboard(): array
    {
        $accountIds = app(MailboxAccess::class)->scopeAccounts(
            EmailAccount::query()->where('is_active', true),
            $this->user(),
            MailboxAccess::ORGANIZE,
        )
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($accountIds === []) {
            return [
                'visible' => false,
                'stats' => [],
                'items' => [],
            ];
        }

        $base = EmailRemoteOperation::query()
            ->with([
                'account:id,address,is_active,account_kind,owner_id',
                'placement.message:id,subject',
                'attemptRecords',
                'inverseOperation:id,inverse_of_email_remote_operation_id,status',
            ])
            ->whereIn('account_id', $accountIds);

        $stats = [
            'pending' => (clone $base)->where('status', EmailRemoteOperation::STATUS_PENDING)->count(),
            'running' => (clone $base)->where('status', EmailRemoteOperation::STATUS_RUNNING)->count(),
            'failed' => (clone $base)->where('status', EmailRemoteOperation::STATUS_FAILED)->count(),
            'recent' => (clone $base)
                ->where('status', EmailRemoteOperation::STATUS_SUCCEEDED)
                ->whereNull('inverse_of_email_remote_operation_id')
                ->whereIn('operation_type', PerformEmailRemoteOperation::allowedOperations())
                ->where('result_snapshot_captured_at', '>=', now()->subMinutes(EmailRemoteOperationUndoEligibility::WINDOW_MINUTES))
                ->count(),
        ];

        if (array_sum($stats) === 0) {
            return [
                'visible' => false,
                'stats' => $stats,
                'items' => [],
            ];
        }

        $items = (clone $base)
            ->where(function (Builder $operations): void {
                $operations
                    ->whereIn('status', [
                        EmailRemoteOperation::STATUS_PENDING,
                        EmailRemoteOperation::STATUS_RUNNING,
                        EmailRemoteOperation::STATUS_FAILED,
                    ])
                    ->orWhere(function (Builder $recent): void {
                        $recent
                            ->where('status', EmailRemoteOperation::STATUS_SUCCEEDED)
                            ->whereNull('inverse_of_email_remote_operation_id')
                            ->whereIn('operation_type', PerformEmailRemoteOperation::allowedOperations())
                            ->where('result_snapshot_captured_at', '>=', now()->subMinutes(EmailRemoteOperationUndoEligibility::WINDOW_MINUTES));
                    });
            })
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(function (EmailRemoteOperation $operation): array {
                $undo = $operation->status === EmailRemoteOperation::STATUS_SUCCEEDED
                    ? app(EmailRemoteOperationUndoEligibility::class)->evaluate($operation, $this->user())
                    : null;

                return [
                    'id' => $operation->id,
                    'account' => $operation->account?->address ?: 'Mailbox #'.$operation->account_id,
                    'type' => str_replace('_', ' ', (string) $operation->operation_type),
                    'status' => $operation->status,
                    'subject' => Str::limit((string) ($operation->placement?->message?->displaySubject() ?: '(no subject)'), 80, ''),
                    'message' => Str::limit((string) (app(EmailRemoteOperationEvidenceSanitizer::class)->message(
                        $operation->status_reason_message ?: $operation->error_message ?: $operation->target_folder_path ?: $operation->source_folder_path,
                    ) ?: 'Waiting for provider acknowledgement.'), 160, ''),
                    'failure_classification' => $operation->failure_classification,
                    'mutation_attempts' => (int) $operation->attempts,
                    'provider_attempts' => $operation->providerAttemptCount(),
                    'max_attempts' => (int) ($operation->max_attempts ?: EmailRemoteOperation::DEFAULT_MAX_ATTEMPTS),
                    'attempt_records' => $operation->attemptRecords->count(),
                    'next_attempt_at' => $operation->next_attempt_at?->format('Y-m-d H:i'),
                    'can_retry' => $operation->canBeRetried(),
                    'can_cancel' => in_array($operation->status, [
                        EmailRemoteOperation::STATUS_PENDING,
                        EmailRemoteOperation::STATUS_FAILED,
                    ], true),
                    'can_undo' => (bool) ($undo['eligible'] ?? false),
                    'undo_reason' => $undo['reason_message'] ?? null,
                    'undo_expires_at' => ($undo['expires_at'] ?? null)?->format('Y-m-d H:i:s'),
                    'inverse_operation_id' => $undo['inverse_operation_id'] ?? null,
                    'inverse_operation_status' => $undo['inverse_operation_status'] ?? null,
                    'updated_at' => $operation->updated_at?->format('Y-m-d H:i'),
                ];
            })
            ->values()
            ->all();

        return [
            'visible' => true,
            'stats' => $stats,
            'items' => $items,
        ];
    }

    private function remoteOperationForAction(int $operationId): ?EmailRemoteOperation
    {
        $user = $this->user();

        if (! $user) {
            return null;
        }

        $operation = EmailRemoteOperation::query()
            ->with('account')
            ->find($operationId);

        if (! $operation?->account) {
            return null;
        }

        if (! app(MailboxAccess::class)->canAccessAccount($user, $operation->account, MailboxAccess::ORGANIZE)) {
            return null;
        }

        return $operation;
    }

    private function runProviderOperation(string $operation, bool $clearSelectionOnSuccess = false): void
    {
        $placement = $this->selectedPlacementForAction();
        $user = $this->user();

        if (! $placement || ! $user) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'Select a message before running a mailbox action.',
            ];

            return;
        }

        try {
            $remoteOperation = app(PerformEmailRemoteOperation::class)->handle($placement, $operation, $user);
        } catch (ValidationException $exception) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'The mailbox action cannot be completed for this placement.',
            ];

            return;
        } catch (RuntimeException $exception) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => $exception->getMessage(),
            ];

            return;
        }

        if ($remoteOperation->status === EmailRemoteOperation::STATUS_SUCCEEDED) {
            $this->mailActionStatus = [
                'type' => 'success',
                'message' => $this->operationSuccessMessage($operation),
            ];

            if ($clearSelectionOnSuccess) {
                $this->selectedPlacementId = null;
                $this->resetClassificationForm();
                $this->resetMoveForm();
                $this->resetPersonalRuleForm();
                $this->resetMailAiSummary();
            }
        } elseif ($remoteOperation->status === EmailRemoteOperation::STATUS_FAILED) {
            $this->mailActionStatus = [
                'type' => 'danger',
                'message' => $remoteOperation->error_message ?: 'The mail server rejected the mailbox action.',
            ];
        } else {
            $this->mailActionStatus = [
                'type' => 'info',
                'message' => 'The mailbox action is still waiting for mail server acknowledgement.',
            ];
        }

        $this->dispatch('mail-state-changed');
    }

    private function selectedPlacementForAction(): ?EmailMailboxPlacement
    {
        $id = $this->positiveId($this->selectedPlacementId);

        if (! $id) {
            return null;
        }

        $accountIds = $this->ordinaryAccessibleAccounts(app(MailboxAccess::class))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        return $this->basePlacementQuery($accountIds)
            ->whereKey($id)
            ->first();
    }

    private function operationSuccessMessage(string $operation): string
    {
        return match ($operation) {
            PerformEmailRemoteOperation::MARK_SEEN => 'Message was marked read in the mailbox.',
            PerformEmailRemoteOperation::MARK_UNSEEN => 'Message was marked unread in the mailbox.',
            PerformEmailRemoteOperation::FLAG => 'Message was flagged in the mailbox.',
            PerformEmailRemoteOperation::UNFLAG => 'Message was unflagged in the mailbox.',
            PerformEmailRemoteOperation::ARCHIVE => 'Message was archived in the mailbox.',
            PerformEmailRemoteOperation::TRASH => 'Message was moved to mailbox Trash.',
            PerformEmailRemoteOperation::MOVE => 'Message was moved in the mailbox.',
            default => 'Mailbox action completed.',
        };
    }

    private function hasTargetFolder(?EmailMailboxPlacement $placement, string $role): bool
    {
        if (! $this->canOrganizePlacement($placement)) {
            return false;
        }

        return EmailFolder::query()
            ->where('account_id', $placement->account_id)
            ->where('role', $role)
            ->where('is_selectable', true)
            ->exists();
    }

    private function prefillPersonalRuleForm(EmailMailboxPlacement $placement): void
    {
        $condition = $this->adminRuleCondition($placement);
        $actionOptions = $this->personalRuleActionOptions($placement);
        $defaultAction = array_key_exists(CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER, $actionOptions)
            ? CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER
            : CreatePersonalEmailRule::ACTION_ARCHIVE;
        $targetFolder = $this->moveTargetFolders($placement)->first();

        $this->personalRuleConditionField = $condition['field'];
        $this->personalRuleConditionValue = $condition['value'];
        $this->personalRuleActionType = $defaultAction;
        $this->personalRuleTargetFolderId = $targetFolder?->id ?: '';
        $this->personalRuleName = Str::limit(
            ($defaultAction === CreatePersonalEmailRule::ACTION_ARCHIVE ? 'Archive' : 'Move')
                .' mail from '.$condition['value'],
            255,
            '',
        );
        $this->resetValidation([
            'personalRuleName',
            'personalRuleConditionField',
            'personalRuleConditionValue',
            'personalRuleActionType',
            'personalRuleTargetFolderId',
        ]);
    }

    /**
     * @return array{field: string, value: string}
     */
    private function adminRuleCondition(EmailMailboxPlacement $placement): array
    {
        $message = $placement->message;
        $from = trim((string) $message?->from_email);

        if ($from !== '') {
            return [
                'field' => 'from',
                'value' => $from,
            ];
        }

        return [
            'field' => 'subject',
            'value' => trim((string) ($message?->subject ?: '(no subject)')),
        ];
    }

    /**
     * @param  array<int>  $accountIds
     */
    private function normalizeFilters(array $accountIds, array $ordinaryAccountIds): void
    {
        if (! in_array($this->viewMode, ['unread', 'inbox', 'drafts', 'all', 'folder'], true)) {
            $this->viewMode = 'unread';
        }

        if ($ordinaryAccountIds === [] && $this->viewMode === 'unread') {
            $this->viewMode = 'inbox';
        }

        if ($ordinaryAccountIds === []
            && in_array($this->listFilter, ['unread_for_me', 'mailbox_unread', 'ticket_linked'], true)) {
            $this->listFilter = 'all';
        }

        $accountId = $this->positiveId($this->accountId);
        if ($accountId && ! in_array($accountId, $accountIds, true)) {
            $this->accountId = '';
            $this->folderId = '';
            $this->selectedPlacementId = null;
            $this->resetClassificationForm();
            $this->resetMoveForm();
            $this->resetPersonalRuleForm();
        }

        $folderId = $this->positiveId($this->folderId);
        if ($folderId && ! $this->folderQuery($accountIds)->whereKey($folderId)->exists()) {
            $this->folderId = '';
            $this->selectedPlacementId = null;
            $this->viewMode = 'unread';
            $this->resetClassificationForm();
            $this->resetMoveForm();
            $this->resetPersonalRuleForm();
        }
    }

    private function recordOpened(EmailMailboxPlacement $placement): void
    {
        $user = $this->user();

        if (! $user
            || ! $placement->message
            || ! $placement->account
            || ! app(MailboxAccess::class)->canAccessAccount(
                $user,
                $placement->account,
                MailboxAccess::VIEW,
            )) {
            return;
        }

        try {
            app(RecordEmailMessageOpened::class)->handle($user, $placement->message, $placement);
        } catch (AuthorizationException) {
            return;
        }
    }

    private function syncClassificationForm(EmailMailboxPlacement $placement): void
    {
        $classification = $this->classificationForPlacement($placement);

        $this->classificationCategoryId = $classification?->category_id ?: '';
        $this->classificationTagsInput = $classification
            ? $classification->tags->pluck('name')->implode(', ')
            : '';
        $this->resetValidation(['classificationCategoryId', 'classificationTagsInput']);
    }

    private function resetClassificationForm(): void
    {
        $this->classificationCategoryId = '';
        $this->classificationTagsInput = '';
        $this->classificationEditorOpen = false;
        $this->resetValidation(['classificationCategoryId', 'classificationTagsInput']);
    }

    private function resetMoveForm(): void
    {
        $this->moveTargetFolderId = '';
        $this->movePanelOpen = false;
        $this->resetValidation(['moveTargetFolderId']);
    }

    private function resetPersonalRuleForm(): void
    {
        $this->personalRuleModalOpen = false;
        $this->personalRuleName = '';
        $this->personalRuleConditionField = 'from';
        $this->personalRuleConditionValue = '';
        $this->personalRuleActionType = CreatePersonalEmailRule::ACTION_MOVE_TO_FOLDER;
        $this->personalRuleTargetFolderId = '';
        $this->resetValidation([
            'personalRuleName',
            'personalRuleConditionField',
            'personalRuleConditionValue',
            'personalRuleActionType',
            'personalRuleTargetFolderId',
        ]);
    }

    private function resetTicketLinkPanel(): void
    {
        $this->ticketLinkPanelOpen = false;
        $this->ticketLinkTarget = '';
        $this->resetValidation(['ticketLinkTarget']);
    }

    private function resolveTicketLinkTarget(): ?Ticket
    {
        $target = trim($this->ticketLinkTarget);

        if ($target === '') {
            return null;
        }

        return Ticket::withTrashed()
            ->where(function (Builder $tickets) use ($target): void {
                if (is_numeric($target)) {
                    $tickets->whereKey((int) $target);
                }

                $tickets->orWhere('ticket_key', Str::upper($target));
            })
            ->first();
    }

    private function resetNewFolderForm(): void
    {
        $this->folderManagerOpen = false;
        $this->folderManagerAccountId = '';
        $this->newFolderFormOpen = false;
        $this->newFolderName = '';
        $this->newFolderParentId = '';
        $this->resetFolderManagerForms();
        $this->resetValidation(['newFolderName', 'newFolderParentId']);
    }

    private function resetFolderManagerForms(): void
    {
        $this->folderRenameFolderId = '';
        $this->folderRenameName = '';
        $this->folderDeleteFolderId = '';
        $this->folderMoveSourceFolderId = '';
        $this->folderMoveTargetFolderId = '';
        $this->folderMoveFolderId = '';
        $this->folderMoveParentFolderId = '';
        $this->resetValidation([
            'folderRenameFolderId',
            'folderRenameName',
            'folderDeleteFolderId',
            'folderMoveSourceFolderId',
            'folderMoveTargetFolderId',
            'folderMoveFolderId',
            'folderMoveParentFolderId',
        ]);
    }

    private function resetMailAiSummary(): void
    {
        $this->mailAiSummary = null;
    }

    /**
     * @return array<int, string>
     */
    private function classificationTagNames(): array
    {
        return collect(preg_split('/[,;\n]+/', $this->classificationTagsInput) ?: [])
            ->map(fn (string $name): string => trim((string) preg_replace('/\s+/', ' ', $name)))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Category>
     */
    private function classificationCategories(): Collection
    {
        return Category::query()
            ->active()
            ->where('type', Category::TYPE_EMAIL)
            ->orderBy('name')
            ->limit(250)
            ->get();
    }

    /**
     * @return Collection<int, Tag>
     */
    private function classificationTags(): Collection
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->limit(250)
            ->get();
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function dispatchFilterChange(): void
    {
        $this->dispatch('mail-filters-changed',
            viewMode: $this->viewMode,
            accountId: $this->accountId ?: '',
            folderId: $this->folderId ?: '',
        );
    }

    private function replyAllRecipientCount(?EmailMailboxPlacement $placement): int
    {
        if (! $placement?->message || ! $placement->account) {
            return 0;
        }

        $composer = app(SendEmailComposerMessage::class);
        $fields = $composer->defaultReplyAllRecipientFields($placement->message, $placement->account);
        $recipientField = collect([$fields['to'], $fields['cc']])
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->implode("\n");

        return count($composer->parseRecipients($recipientField));
    }

    private function startComposer(string $mode): void
    {
        if (! in_array($mode, [
            SendEmailComposerMessage::MODE_REPLY,
            SendEmailComposerMessage::MODE_REPLY_ALL,
            SendEmailComposerMessage::MODE_FORWARD,
        ], true)) {
            return;
        }

        $placement = $this->selectedPlacementForAction();

        if (! $this->canSendFromPlacement($placement) || ! $placement?->message) {
            $this->mailActionStatus = [
                'type' => 'warning',
                'message' => 'You need mailbox Send access before sending from this mailbox.',
            ];

            return;
        }

        $composer = app(SendEmailComposerMessage::class);
        $replyAllRecipients = $mode === SendEmailComposerMessage::MODE_REPLY_ALL && $placement->account
            ? $composer->defaultReplyAllRecipientFields($placement->message, $placement->account)
            : ['to' => '', 'cc' => ''];

        $this->composerOpen = true;
        $this->composerMode = $mode;
        $this->composerAccountId = $placement->account_id;
        $this->composerTo = match ($mode) {
            SendEmailComposerMessage::MODE_REPLY => trim((string) $placement->message->from_email),
            SendEmailComposerMessage::MODE_REPLY_ALL => $replyAllRecipients['to'],
            default => '',
        };
        $this->composerCc = $mode === SendEmailComposerMessage::MODE_REPLY_ALL
            ? $replyAllRecipients['cc']
            : '';
        $this->composerSubject = $mode === SendEmailComposerMessage::MODE_FORWARD
            ? $composer->defaultForwardSubject($placement->message)
            : $composer->defaultReplySubject($placement->message);
        $this->composerBodyHtml = $mode === SendEmailComposerMessage::MODE_FORWARD
            ? $composer->defaultForwardBodyHtml($placement->message)
            : $composer->defaultReplyBodyHtml();
        $this->composerAttachments = [];
        $this->composerIdempotencyKey = (string) Str::uuid();
        $this->resetComposerAiState();
        $this->composerActionStatus = null;
        $this->classificationEditorOpen = false;
        $this->resetMoveForm();
        $this->resetPersonalRuleForm();
        $this->mailActionStatus = null;
        $this->resetErrorBag();

        // Shared editing is entered only through the explicit Order 9 API
        // share/lease/fence contract. The ordinary private composer must not
        // acquire the quarantined conversation-wide SQL lock implicitly.
        $this->restoreComposerDraftIfAvailable($placement->account, $placement);
    }

    private function resetComposer(): void
    {
        $this->composerOpen = false;
        $this->composerMode = SendEmailComposerMessage::MODE_REPLY;
        $this->composerAccountId = '';
        $this->composerTo = '';
        $this->composerCc = '';
        $this->composerSubject = '';
        $this->composerBodyHtml = '';
        $this->composerAttachments = [];
        $this->composerIdempotencyKey = '';
        $this->resetComposerDraftState();
        $this->resetComposerAiState();
        $this->composerActionStatus = null;
        $this->resetErrorBag();
    }

    /**
     * @return array{account: EmailAccount, placement: EmailMailboxPlacement|null}|null
     */
    private function composerDraftContext(): ?array
    {
        if (! $this->composerOpen) {
            return null;
        }

        if ($this->composerMode === SendEmailComposerMessage::MODE_COMPOSE) {
            $account = $this->selectedComposeAccount();

            return $account ? ['account' => $account, 'placement' => null] : null;
        }

        $placement = $this->selectedPlacementForAction();

        if (! $placement?->account) {
            return null;
        }

        return ['account' => $placement->account, 'placement' => $placement];
    }

    private function persistComposerDraft(bool $manual): ?EmailComposerDraft
    {
        if ($this->composerShared) {
            return $this->persistSharedComposerDraft($manual);
        }

        $user = $this->user();
        $context = $this->composerDraftContext();

        if (! $user || ! $context || ! $this->composerShouldPersistDraft($manual)) {
            return null;
        }

        $draftService = app(EmailComposerDraftService::class);
        $activeDraft = $draftService->activeDraft(
            $user,
            $this->composerMode,
            $context['account'],
            $context['placement'],
        );
        $expectedVersion = $activeDraft
            ? app(EmailDraftFence::class)->version($activeDraft, $this->composerDraftFence)
            : null;
        $draft = $draftService->save(
            $user,
            $this->composerMode,
            $context['account'],
            $context['placement'],
            [
                'to' => $this->composerTo,
                'cc' => $this->composerCc,
                'subject' => $this->composerSubject,
                'body_html' => $this->composerBodyHtml,
                'idempotency_key' => $this->composerIdempotencyKey ?: (string) Str::uuid(),
            ],
            false,
            $expectedVersion,
        );

        if ($this->composerAttachments !== []) {
            $draft = $draftService->storeAttachments(
                $user,
                $draft,
                $this->composerAttachments,
                (int) $draft->version,
            );
            $this->composerAttachments = [];
        }

        if ($manual) {
            $draft = $draftService->syncProviderDraft($user, $draft, (int) $draft->version);
        }

        $this->syncComposerDraftMetadata($draft, 'saved');
        $this->composerDraftAttachments = $this->composerDraftAttachmentList($draft);
        $this->composerDraftHasUnsavedAttachments = $this->composerAttachments !== [];
        $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();

        if ($manual) {
            $this->setComposerActionStatus(
                $draft->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_ERROR ? 'warning' : 'success',
                $this->composerDraftSavedMessage($draft, true),
            );
        }

        return $draft;
    }

    private function restoreComposerDraftIfAvailable(EmailAccount $account, ?EmailMailboxPlacement $placement): void
    {
        if ($this->loadSharedComposerDraftIfAvailable($account, $placement)) {
            return;
        }

        $user = $this->user();

        if (! $user) {
            return;
        }

        try {
            $draft = app(EmailComposerDraftService::class)->activeDraft($user, $this->composerMode, $account, $placement);
        } catch (AuthorizationException) {
            return;
        }

        if (! $draft) {
            $this->resetComposerDraftState();
            $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();

            return;
        }

        $this->composerTo = (string) $draft->to_recipients;
        $this->composerCc = (string) $draft->cc_recipients;
        $this->composerSubject = (string) $draft->subject;
        $this->composerBodyHtml = (string) ($draft->body_html ?: '<p><br></p>');
        $this->composerIdempotencyKey = (string) ($draft->idempotency_key ?: Str::uuid());
        $this->composerAttachments = [];
        $this->composerDraftAttachments = $this->composerDraftAttachmentList($draft);
        $this->composerDraftHasUnsavedAttachments = false;
        $this->syncComposerDraftMetadata($draft, 'restored');
        $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();
        $this->setComposerActionStatus('info', 'Draft restored.');
    }

    private function syncComposerDraftMetadata(EmailComposerDraft $draft, string $status): void
    {
        $this->composerDraftId = $draft->id;
        $this->composerDraftFence = app(EmailDraftFence::class)->issue($draft);
        $this->composerDraftStatus = $status;
        $this->composerDraftSavedAt = $draft->last_saved_at?->format('Y-m-d H:i') ?? now()->format('Y-m-d H:i');
        $this->composerDraftProviderStatus = (string) $draft->provider_draft_status;
        $this->composerDraftProviderMessage = $this->composerDraftProviderStatusMessage($draft);
    }

    /**
     * @return array<int, array{id: int, filename: string, size_bytes: int, content_type: string|null}>
     */
    private function composerDraftAttachmentList(EmailComposerDraft $draft): array
    {
        $draft->loadMissing('attachments');

        return $draft->attachments
            ->map(fn (EmailComposerDraftAttachment $attachment): array => [
                'id' => (int) $attachment->id,
                'filename' => (string) $attachment->filename,
                'size_bytes' => (int) $attachment->size_bytes,
                'content_type' => $attachment->content_type,
            ])
            ->values()
            ->all();
    }

    public function composerDraftAttachmentSizeLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private function composerDraftSavedMessage(EmailComposerDraft $draft, bool $providerSyncAttempted): string
    {
        if ($this->composerDraftHasUnsavedAttachments) {
            return 'Draft saved locally. Attachments are not stored in provider Drafts yet.';
        }

        return match ($draft->provider_draft_status) {
            EmailComposerDraft::PROVIDER_DRAFT_SYNCED => 'Draft saved and synced to provider Drafts.',
            EmailComposerDraft::PROVIDER_DRAFT_PENDING => 'Draft saved locally. Provider Drafts accepted it and will confirm on the next sync.',
            EmailComposerDraft::PROVIDER_DRAFT_ERROR => 'Draft saved locally, but provider Drafts sync failed: '
                .($draft->provider_draft_error_message ?: 'The provider rejected the draft sync.'),
            default => $providerSyncAttempted ? 'Draft saved locally.' : 'Draft saved locally.',
        };
    }

    private function composerDraftDiscardedMessage(?EmailComposerDraft $draft): string
    {
        if ($draft?->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_ERROR) {
            return 'Draft discarded locally, but provider Drafts cleanup failed: '
                .($draft->provider_draft_error_message ?: 'The provider copy could not be removed.');
        }

        return 'Draft discarded.';
    }

    private function composerSentMessage(string $senderAddress, ?EmailComposerDraft $draft): string
    {
        $message = Str::ucfirst($this->composerModeLabel()).' sent from '.$senderAddress.'.';

        if ($draft?->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_ERROR) {
            $message .= ' Provider draft cleanup failed: '
                .($draft->provider_draft_error_message ?: 'The provider copy could not be removed.');
        }

        return $message;
    }

    private function composerPostSendWarning(?EmailLog $log): ?string
    {
        if (! $log) {
            return null;
        }

        $providerSent = (array) data_get($log->context_json, 'provider_sent', []);

        if (data_get($log->context_json, 'smtp_delivery.local_log_status') === 'finalize_failed') {
            return ' The message was accepted, but its local send log could not be finalized. Do not resend it.';
        }

        if (($providerSent['status'] ?? null) === 'record_failed') {
            return ' The message was accepted, but Sent-folder tracking could not be recorded. Do not resend it.';
        }

        if (($providerSent['snapshot_status'] ?? null) === 'failed') {
            return ' The message was accepted, but its local Sent snapshot could not be stored. Do not resend it.';
        }

        return null;
    }

    private function composerDraftProviderStatusMessage(EmailComposerDraft $draft): string
    {
        return match ($draft->provider_draft_status) {
            EmailComposerDraft::PROVIDER_DRAFT_SYNCED => 'Provider Drafts synced',
            EmailComposerDraft::PROVIDER_DRAFT_PENDING => 'Provider Drafts pending',
            EmailComposerDraft::PROVIDER_DRAFT_ERROR => 'Provider Drafts issue',
            EmailComposerDraft::PROVIDER_DRAFT_DELETED => 'Provider Drafts cleaned up',
            default => 'Local draft only',
        };
    }

    private function composerShouldPersistDraft(bool $manual): bool
    {
        if (! $this->composerHasDraftContent()) {
            return false;
        }

        return $manual || $this->composerDraftPayloadHash() !== $this->composerDraftBaselineHash;
    }

    private function composerHasDraftContent(): bool
    {
        $bodyText = BodyNormalizer::toText($this->composerBodyHtml) ?: '';

        return trim($bodyText) !== ''
            || trim($this->composerTo) !== ''
            || trim($this->composerCc) !== ''
            || trim($this->composerSubject) !== ''
            || $this->composerAttachments !== []
            || $this->composerDraftAttachments !== [];
    }

    private function composerDraftPayloadHash(): string
    {
        return sha1(json_encode([
            'mode' => $this->composerMode,
            'account_id' => (string) $this->composerAccountId,
            'to' => trim($this->composerTo),
            'cc' => trim($this->composerCc),
            'subject' => trim($this->composerSubject),
            'body_html' => HtmlSanitizer::sanitize((string) $this->composerBodyHtml) ?: '',
            'draft_attachments' => collect($this->composerDraftAttachments)
                ->map(fn (array $attachment): array => [
                    'id' => (int) ($attachment['id'] ?? 0),
                    'filename' => (string) ($attachment['filename'] ?? ''),
                    'size_bytes' => (int) ($attachment['size_bytes'] ?? 0),
                ])
                ->values()
                ->all(),
            'upload_attachments' => collect($this->composerAttachments)
                ->map(fn (mixed $attachment): string => is_object($attachment) && method_exists($attachment, 'getClientOriginalName')
                    ? $attachment->getClientOriginalName().'|'.(method_exists($attachment, 'getSize') ? (string) $attachment->getSize() : '')
                    : '')
                ->filter()
                ->values()
                ->all(),
        ]));
    }

    private function resetComposerDraftState(): void
    {
        $this->composerDraftId = '';
        $this->composerDraftFence = '';
        $this->composerDraftStatus = '';
        $this->composerDraftSavedAt = '';
        $this->composerDraftProviderStatus = '';
        $this->composerDraftProviderMessage = '';
        $this->composerDraftHasUnsavedAttachments = false;
        $this->composerDraftAttachments = [];
        $this->composerDraftBaselineHash = '';
        $this->resetSharedComposerDraftState();
    }

    private function resetComposerAiState(): void
    {
        $this->composerAiInstruction = '';
        $this->composerAiResult = null;
        $this->resetValidation(['composerAiInstruction']);
    }

    private function setComposerOrMailActionStatus(string $type, string $message): void
    {
        if ($this->composerOpen) {
            $this->setComposerActionStatus($type, $message);

            return;
        }

        $this->composerActionStatus = null;
        $this->mailActionStatus = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function setComposerActionStatus(string $type, string $message): void
    {
        $this->composerActionStatus = [
            'type' => $type,
            'message' => $message,
        ];
        $this->mailActionStatus = null;
    }

    public function composerModeLabel(): string
    {
        return match ($this->composerMode) {
            SendEmailComposerMessage::MODE_REPLY_ALL => 'reply all',
            SendEmailComposerMessage::MODE_FORWARD => 'forward',
            SendEmailComposerMessage::MODE_COMPOSE => 'message',
            SendEmailComposerMessage::MODE_PROVIDER_DRAFT => 'draft',
            default => 'reply',
        };
    }

    private function user(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
