<?php

namespace App\Modules\Email\Concerns;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailUnreadForMeResolver;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One placement/conversation query implementation for browser and bearer API. */
trait QueriesEmailMailboxPlacements
{
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

    private function emptyConversationThread(): array
    {
        return [
            'placements' => collect(),
            'total' => 0,
            'truncated' => false,
            'can_load_more' => false,
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

    private function positiveId(mixed $value): ?int
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function user(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
