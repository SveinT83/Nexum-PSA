<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\RecordEmailMessageOpened;
use App\Modules\Email\Actions\SetEmailSelectedReadState;
use App\Modules\Email\Actions\StreamEmailMailboxAttachment;
use App\Modules\Email\Actions\StreamEmailMailboxRawSource;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Queries\EmailMailboxReadQuery;
use App\Modules\Email\Resources\Api\V1\EmailMailboxPlacementResource;
use App\Modules\Email\Services\EmailCanonicalContentResolver;
use App\Modules\Email\Services\EmailLiveCurrentViewProjector;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\MailboxAccessUseGuard;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Email\Support\EmailApiAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Full Mail reads use placement authority; the legacy unrouted Inbox remains compatible. */
final class MailboxReadController extends Controller
{
    public function context(Request $request, EmailApiAccess $access)
    {
        $actor = $access->reader($request);
        $accounts = $access->accounts($request);
        $senders = app(MailboxAccess::class)->scopeAccounts(EmailAccount::query()->where('is_active', true),
            $actor, MailboxAccess::SEND)->orderBy('address')->get()
            ->filter(fn ($account) => app(\App\Modules\Email\Services\EmailAccountProviderRuntimeResolver::class)->databaseReady($account))->values();

        return response()->json(['data' => [
            'accounts' => $accounts->take(100)->map(fn ($account) => $this->account($account, $actor)),
            'senders' => $senders->take(100)->map(fn ($account) => $account->only(['id', 'address', 'from_name', 'description'])),
            'accounts_truncated' => $accounts->count() > 100,
            'senders_truncated' => $senders->count() > 100,
            'categories' => app(\App\Modules\Email\Services\EmailRuleFormReferences::class)->emailCategories(),
            'tags' => app(\App\Modules\Email\Services\EmailRuleFormReferences::class)->tags(),
            'views' => ['unread', 'inbox', 'all', 'drafts'],
            'filters' => (new EmailMailboxReadQuery($actor))->listFilterOptions(),
            'live_available' => app(EmailLiveRuntimeReadiness::class)->ready(),
            'navigation' => app(EmailLiveCurrentViewProjector::class)->project($actor, 'unread', null, null),
        ]]);
    }

    public function accounts(Request $request, EmailApiAccess $access)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $accounts = $access->accounts($request);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 25);

        return response()->json(['data' => $accounts->forPage($page, $size)
            ->map(fn ($account) => $this->account($account, $request->user()))->values(),
            'meta' => ['current_page' => $page, 'per_page' => $size, 'total' => $accounts->count()]]);
    }

    public function folders(Request $request, EmailAccount $account, EmailApiAccess $access)
    {
        abort_unless($access->accounts($request)->contains('id', $account->id), 404);
        $actor = $request->user();

        return response()->json(['data' => EmailFolder::query()->where('account_id', $account->id)
            ->orderBy('path')->get()->map(function ($folder) use ($actor): array {
                $query = EmailMailboxPlacement::query()->where('account_id', $folder->account_id)
                    ->where('email_folder_id', $folder->id)->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->whereNull('provider_missing_at')->whereHas('message');
                $unread = (clone $query)->whereHas('message', fn ($messages) => app(\App\Modules\Email\Services\EmailUnreadForMeResolver::class)->scopeUnreadMessages($messages, $actor));

                return $folder->only(['id', 'account_id', 'path', 'name', 'delimiter', 'parent_path', 'special_use',
                    'role', 'is_selectable', 'sync_enabled', 'sync_status', 'last_synced_at']) + [
                        'visible_placement_count' => (clone $query)->count(),
                        'unread_for_me_count' => $unread->count(),
                        'provider_unread_count' => (clone $query)->where('provider_seen', false)->count(),
                    ];
            })->values()]);
    }

    public function index(Request $request, EmailApiAccess $access)
    {
        $filters = $this->filters($request);
        $operation = trim($filters['q'] ?? '') !== '' ? ResolveMailboxAccessDecision::SEARCH : ResolveMailboxAccessDecision::CONTENT_VIEW;
        $accounts = $access->accounts($request, $operation);
        $this->validateSelection($filters, $accounts->pluck('id')->all());
        $query = new EmailMailboxReadQuery($request->user(), $filters);
        $page = $query->listing($accounts->pluck('id')->all());
        app(EmailCanonicalContentResolver::class)->prime($page->getCollection());

        return EmailMailboxPlacementResource::collection($page)->additional([
            'mail' => ['legacy_list_truncated' => $query->legacyConversationListTruncated],
        ]);
    }

    public function show(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        return new EmailMailboxPlacementResource($access->placement($request, $placement), true);
    }

    public function conversation(Request $request, string $conversation, EmailApiAccess $access)
    {
        $accountIds = $access->accounts($request)->pluck('id')->all();
        $placement = EmailMailboxPlacement::query()->whereIn('account_id', $accountIds)
            ->where('email_conversation_id', $conversation)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)->orderByDesc('id')->firstOrFail();

        return $this->thread($request, $placement, $access);
    }

    public function thread(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        $placement = $access->placement($request, $placement);
        $filters = $this->filters($request);
        $query = new EmailMailboxReadQuery($request->user(), $filters);
        $thread = $query->conversation($placement, [(int) $placement->account_id]);
        app(EmailCanonicalContentResolver::class)->prime($thread['placements']);
        $rows = $thread['placements'];
        unset($thread['placements']);

        return EmailMailboxPlacementResource::collection($rows)->additional(['meta' => $thread]);
    }

    public function opened(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, RecordEmailMessageOpened $record)
    {
        $placement = $access->placement($request, $placement);
        $state = $record->handle($request->user(), $placement->message, $placement);

        return response()->json(['data' => $state->only(['opened_count', 'first_opened_at', 'last_opened_at'])]);
    }

    public function readState(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, SetEmailSelectedReadState $set)
    {
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $placement = $access->placement($request, $placement);

        return response()->json(['data' => $set->handle($request->user(), $placement, (bool) $data['read'])]);
    }

    public function attachment(Request $request, EmailMailboxPlacement $placement, EmailAttachment $attachment,
        StreamEmailMailboxAttachment $stream, MailboxAccess $mailboxes, MailboxAccessUseGuard $guard, EmailCanonicalContentResolver $canonical, EmailApiAccess $access)
    {
        $access->reader($request);

        return $stream->handle($request, $placement, $attachment, $mailboxes, $guard, $canonical);
    }

    public function raw(Request $request, EmailMailboxPlacement $placement, StreamEmailMailboxRawSource $stream,
        MailboxAccessUseGuard $guard, MailboxAccess $mailboxes, EmailCanonicalContentResolver $canonical, EmailApiAccess $access)
    {
        $access->reader($request);

        return $stream->handle($request, $placement, $guard, $mailboxes, $canonical);
    }

    public function senders(Request $request, EmailApiAccess $access)
    {
        $actor = $access->actor($request);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = app(MailboxAccess::class)->scopeAccounts(EmailAccount::query()->where('is_active', true),
            $actor, MailboxAccess::SEND)->orderBy('address')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()
            ->filter(fn ($account) => app(\App\Modules\Email\Services\EmailAccountProviderRuntimeResolver::class)->databaseReady($account))
            ->map(fn ($account) => $account->only(['id', 'address', 'from_name', 'description']))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function awareness(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        $placement = $access->placement($request, $placement);
        $rows = \App\Modules\Email\Models\EmailMessageUserState::query()
            ->where('email_message_id', $placement->email_message_id)->where('opened_count', '>', 0)
            ->with('user:id,name,status,is_system_actor')->latest('last_opened_at')->limit(100)->get();
        $usesEpochs = app(\App\Modules\Email\Services\EmailUnreadSchemaState::class)->usesAccessEpochs();
        $opened = $rows->filter(function ($state) use ($placement, $usesEpochs): bool {
            $peer = $state->user;
            if (! $peer?->isActive() || $peer->isSystemActor()
                || ! app(\App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver::class)->hasCurrentViewAccess($placement->account, $peer)) {
                return false;
            }

            return ! $usesEpochs || \App\Modules\Email\Models\EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $placement->account_id)->where('user_id', $peer->id)
                ->where('ordinary_view_entitled', true)->where('access_epoch', $state->access_epoch)->exists();
        })->map(fn ($state) => ['user_id' => $state->user_id, 'name' => $state->user?->name,
            'opened_count' => $state->opened_count, 'first_opened_at' => $state->first_opened_at,
            'last_opened_at' => $state->last_opened_at])->values();

        return response()->json(['data' => ['opened_by' => $opened,
            'unread_for_me' => app(\App\Modules\Email\Services\EmailUnreadForMeResolver::class)->resolve($placement->message, $request->user())]]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'view' => ['sometimes', Rule::in(['unread', 'inbox', 'all', 'drafts'])],
            'filter' => ['sometimes', Rule::in(['all', 'unread_for_me', 'mailbox_unread', 'flagged', 'provider_drafts', 'has_attachments', 'ticket_linked'])],
            'account_id' => ['nullable', 'integer', 'min:1'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:500'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'legacy_limit' => ['sometimes', 'integer', 'min:50', 'max:200'],
        ]);
    }

    private function validateSelection(array $filters, array $accountIds): void
    {
        if (isset($filters['account_id'])) {
            abort_unless(in_array((int) $filters['account_id'], array_map('intval', $accountIds), true), 404);
        }
        if (isset($filters['folder_id'])) {
            $query = EmailFolder::query()->whereIn('account_id', $accountIds)->whereKey($filters['folder_id']);
            if (isset($filters['account_id'])) {
                $query->where('account_id', $filters['account_id']);
            }
            abort_unless($query->exists(), 404);
        }
    }

    private function account(EmailAccount $account, $actor): array
    {
        return $account->only(['id', 'address', 'from_name', 'description', 'account_kind']) + [
            'can_organize' => app(MailboxAccess::class)->canAccessAccount($actor, $account, MailboxAccess::ORGANIZE),
            'can_send' => app(MailboxAccess::class)->canAccessAccount($actor, $account, MailboxAccess::SEND),
        ];
    }
}
