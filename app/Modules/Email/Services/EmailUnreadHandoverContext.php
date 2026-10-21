<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\ExpireEmailUnreadHandoverPreviews;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxDelegation;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EmailUnreadHandoverContext
{
    public function __construct(
        private readonly EmailUnreadHandoverAuthorization $authorization,
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
    ) {}

    public function handle(
        EmailAccount $account,
        Request $request,
        ExpireEmailUnreadHandoverPreviews $expirePreviews,
    ): array {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorizeManagerOrNotFound($actor, $account);

        $runs = EmailUnreadHandoverRun::query()
            ->where('email_account_id', $account->id)
            ->with([
                'requestedBy:id,name',
                'targetUser:id,name',
            ])
            ->withCount('items')
            ->latest('id')
            ->limit(50)
            ->get();
        $scopeFolderIds = $runs
            ->flatMap(fn (EmailUnreadHandoverRun $run): array => (array) $run->folder_scope_json)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $folderLabels = EmailFolder::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $scopeFolderIds)
            ->get(['id', 'path'])
            ->mapWithKeys(fn (EmailFolder $folder): array => [$folder->id => $folder->path]);

        return [
            'account' => $account,
            'actor' => $actor,
            'folders' => EmailFolder::query()
                ->where('account_id', $account->id)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->orderBy('path')
                ->get(['id', 'name', 'path', 'role']),
            'folderLabels' => $folderLabels,
            'runs' => $runs,
            'targets' => $this->currentTargets($account),
            'idempotencyKey' => (string) Str::uuid(),
        ];
    }

    /** @return Collection<int, User> */
    private function currentTargets(EmailAccount $account): Collection
    {
        $candidateIds = $account->isPersonal()
            ? collect([$account->owner_id])->merge(
                EmailMailboxDelegation::query()
                    ->where('email_account_id', $account->id)
                    ->where('owner_id', $account->owner_id)
                    ->where('can_view', true)
                    ->effective()
                    ->pluck('delegate_id'),
            )
            : $account->userGrants()->where('can_view', true)->pluck('user_id');

        return User::query()
            ->whereIn('id', $candidateIds->filter()->unique())
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email', 'status', 'is_system_actor'])
            ->filter(fn (User $user): bool => ! $user->isSystemActor()
                && $user->can('email.inbox_view')
                && $this->entitlements->hasCurrentViewAccess($account, $user))
            ->values();
    }

    private function authorizeManagerOrNotFound(User $actor, EmailAccount $account): void
    {
        try {
            $this->authorization->authorizeManager($actor, $account);
        } catch (AuthorizationException) {
            // Existing and nonexistent personal mailboxes must have the same
            // externally visible response for unauthorized technicians.
            abort(404);
        }
    }
}
