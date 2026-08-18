<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class EmailCanonicalCutoverAuthorization
{
    public function __construct(private readonly ResolveMailboxAccessDecision $accessDecisions) {}

    /**
     * @param  list<int>  $accountIds
     * @return array{actor:User,accounts:Collection<int,EmailAccount>}
     */
    public function authorize(User $actor, array $accountIds, bool $lock = false): array
    {
        $actor = User::query()->find($actor->id);
        $accountIds = collect($accountIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (! $actor?->isActive()
            || $actor->isSystemActor()
            || ! $actor->can('email.canonical_cutover_manage')
            || ! $actor->can('email.mailbox_sync_manage')
            || $accountIds === []) {
            throw new AuthorizationException('The canonical cutover scope is not available.');
        }

        $query = EmailAccount::query()->whereKey($accountIds)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $accounts = $query->get()->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new AuthorizationException('The canonical cutover scope is not available.');
        }

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account?->is_active
                || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                // VIEW is deliberately used instead of a content/break-glass operation. Emergency
                // access can never qualify an actor to correlate or remap mailbox content.
                throw new AuthorizationException('The canonical cutover scope is not available.');
            }
        }

        return compact('actor', 'accounts');
    }
}
