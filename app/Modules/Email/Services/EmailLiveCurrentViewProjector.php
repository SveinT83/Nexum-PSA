<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Produces only the bounded authority/navigation envelope needed to refresh
 * the current Mail page. It never calls the workspace render path.
 */
class EmailLiveCurrentViewProjector
{
    private const NAVIGATION_LIMIT = 100;

    private const COUNT_LIMIT = 1000;

    public function project(
        User $user,
        string $viewMode,
        mixed $accountId,
        mixed $folderId,
    ): array {
        $content = app(MailboxAccess::class)->scopeContentAccounts(
            EmailAccount::query()->where('is_active', true)->orderBy('id'),
            $user,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
        )->limit(self::NAVIGATION_LIMIT + 1)->get(['id']);
        $accountsTruncated = $content->count() > self::NAVIGATION_LIMIT;
        $accountIds = $content->take(self::NAVIGATION_LIMIT)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $ordinary = app(MailboxAccess::class)->scopeAccounts(
            EmailAccount::query()->where('is_active', true)->whereIn('id', $accountIds)->orderBy('id'),
            $user,
            MailboxAccess::VIEW,
        )->limit(self::NAVIGATION_LIMIT + 1)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $folders = EmailFolder::query()
            ->whereIn('account_id', $accountIds)
            ->orderBy('account_id')
            ->orderBy('id')
            ->limit(self::NAVIGATION_LIMIT + 1)
            ->pluck('id');
        $foldersTruncated = $folders->count() > self::NAVIGATION_LIMIT;

        $base = EmailMailboxPlacement::query()
            ->whereIn('account_id', $accountIds)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE);
        $ordinaryBase = EmailMailboxPlacement::query()
            ->whereIn('account_id', array_values(array_intersect($accountIds, $ordinary)))
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE);

        $inbox = clone $base;
        $this->inbox($inbox);
        $drafts = clone $base;
        $this->drafts($drafts);
        $all = clone $base;
        $this->nonTrash($all);
        $providerUnread = (clone $ordinaryBase)->where('provider_seen', false);

        $counts = [];
        $truncated = [];
        foreach ([
            'inbox' => $inbox,
            'drafts' => $drafts,
            'all' => $all,
            'provider_unread' => $providerUnread,
        ] as $key => $query) {
            [$counts[$key], $truncated[$key]] = $this->boundedCount($query);
        }

        // Personal unread needs the exact user resolver and remains independent
        // from provider Seen. It is capped like every other periodic count.
        $unread = clone $ordinaryBase;
        $this->inbox($unread);
        $unread->whereHas('message', fn (Builder $messages): Builder => app(EmailUnreadForMeResolver::class)
            ->scopeUnreadMessages($messages, $user));
        [$counts['unread_for_me'], $truncated['unread_for_me']] = $this->boundedCount($unread);

        $breakGlassIds = EmailBreakGlassAccess::query()
            ->where('actor_id', $user->id)
            ->effective()
            ->orderBy('id')
            ->limit(self::NAVIGATION_LIMIT + 1)
            ->pluck('id');

        return [
            'account_ids' => $accountIds,
            'ordinary_account_ids' => array_slice($ordinary, 0, self::NAVIGATION_LIMIT),
            'folder_ids' => $folders->take(self::NAVIGATION_LIMIT)->map(fn ($id): int => (int) $id)->all(),
            'break_glass_ids' => $breakGlassIds->take(self::NAVIGATION_LIMIT)->map(fn ($id): int => (int) $id)->all(),
            'stats' => $counts,
            'stats_truncated' => $truncated,
            'navigation_truncated' => $accountsTruncated || $foldersTruncated || $breakGlassIds->count() > self::NAVIGATION_LIMIT,
            'requested_account_id' => filter_var($accountId, FILTER_VALIDATE_INT) !== false ? (int) $accountId : null,
            'requested_folder_id' => filter_var($folderId, FILTER_VALIDATE_INT) !== false ? (int) $folderId : null,
            'view_mode' => $viewMode,
        ];
    }

    /** @return array{int, bool} */
    private function boundedCount(Builder $query): array
    {
        $ids = (clone $query)->withoutEagerLoads()->reorder()->select('email_mailbox_placements.id')->limit(self::COUNT_LIMIT + 1);
        $count = DB::query()->fromSub($ids, 'mail_bounded_count')->count();

        return [min(self::COUNT_LIMIT, $count), $count > self::COUNT_LIMIT];
    }

    private function inbox(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->whereHas('folder', fn (Builder $folders): Builder => $folders->where('role', EmailFolder::ROLE_INBOX))
                ->orWhere(fn (Builder $legacy): Builder => $legacy
                    ->whereNull('email_folder_id')
                    ->whereIn('folder_path', ['INBOX', 'Inbox', 'inbox']));
        });
    }

    private function drafts(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->where('provider_draft', true)
                ->orWhereHas('folder', fn (Builder $folders): Builder => $folders->where('role', EmailFolder::ROLE_DRAFTS));
        });
    }

    private function nonTrash(Builder $query): void
    {
        $query->where(function (Builder $placements): void {
            $placements
                ->whereDoesntHave('folder')
                ->orWhereHas('folder', fn (Builder $folders): Builder => $folders
                    ->whereNotIn('role', [EmailFolder::ROLE_TRASH, EmailFolder::ROLE_JUNK]));
        });
    }
}
