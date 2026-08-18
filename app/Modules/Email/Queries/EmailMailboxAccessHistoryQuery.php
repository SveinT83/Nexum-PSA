<?php

namespace App\Modules\Email\Queries;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxAccessEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EmailMailboxAccessHistoryQuery
{
    /**
     * Return metadata-only history. Audit operators may review every mailbox; an owner is restricted
     * to personal accounts they currently own and cannot infer the existence of any other account.
     */
    public function forActor(
        User $actor,
        ?int $accountId = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        $query = EmailMailboxAccessEvent::query()
            ->with([
                'account:id,address,description,account_kind,owner_id',
                'actor:id,name',
                'affectedUser:id,name',
            ])
            ->latest('occurred_at')
            ->latest('id');

        if ($actor->can('email.break_glass_audit')) {
            if ($accountId !== null) {
                $query->where('email_account_id', $accountId);
            }

            return $query->paginate(min(max($perPage, 1), 100));
        }

        $ownedAccountIds = EmailAccount::query()
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('owner_id', $actor->id)
            ->pluck('id');

        if ($accountId !== null && ! $ownedAccountIds->contains($accountId)) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('email_account_id', $ownedAccountIds);

            if ($accountId !== null) {
                $query->where('email_account_id', $accountId);
            }
        }

        return $query->paginate(min(max($perPage, 1), 100));
    }

    /** @return Builder<EmailBreakGlassAccess> */
    public function activeBreakGlass(): Builder
    {
        return EmailBreakGlassAccess::query()
            ->with([
                'account:id,address,description,account_kind,owner_id,is_active',
                'account.owner:id,name',
                'actor:id,name,status,is_system_actor',
            ])
            ->whereHas('account', fn (Builder $accounts): Builder => $accounts
                ->where('account_kind', EmailAccount::KIND_PERSONAL)
                ->where('is_active', true))
            ->whereHas('actor', fn (Builder $actors): Builder => $actors
                ->where('status', User::STATUS_ACTIVE)
                ->where(function (Builder $humans): void {
                    $humans->whereNull('is_system_actor')->orWhere('is_system_actor', false);
                }))
            ->effective()
            ->latest('expires_at')
            ->latest('id');
    }
}
