<?php

namespace App\Modules\Email\Queries;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxDelegation;
use Illuminate\Http\Request;

/** Current-owner delegation and emergency metadata; no mailbox message content. */
final class EmailMailboxAccessContext
{
    public function handle(Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $accounts = EmailAccount::query()
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('owner_id', $actor->id)
            ->with(['owner:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('address')
            ->get();
        $accountIds = $accounts->pluck('id');

        $delegations = EmailMailboxDelegation::query()
            ->whereIn('email_account_id', $accountIds)
            ->with(['account:id,address', 'delegate:id,name,email', 'creator:id,name', 'revoker:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get();

        $delegates = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereKeyNot($actor->id)
            ->where(function ($query): void {
                $query->whereNull('is_system_actor')->orWhere('is_system_actor', false);
            })
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email']);

        $activeBreakGlass = EmailBreakGlassAccess::query()
            ->whereIn('email_account_id', $accountIds)
            ->whereHas('account', fn ($accounts) => $accounts
                ->where('account_kind', EmailAccount::KIND_PERSONAL)
                ->where('is_active', true))
            ->whereHas('actor', fn ($actors) => $actors
                ->where('status', User::STATUS_ACTIVE)
                ->where(function ($humans): void {
                    $humans->whereNull('is_system_actor')->orWhere('is_system_actor', false);
                }))
            ->with(['account:id,address', 'actor:id,name,status,is_system_actor'])
            ->effective()
            ->orderBy('expires_at')
            ->get()
            ->filter(fn (EmailBreakGlassAccess $access): bool => $access->actor?->can('email.break_glass_activate') === true);

        return [
            'accounts' => $accounts,
            'delegations' => $delegations,
            'delegates' => $delegates,
            'activeBreakGlass' => $activeBreakGlass,
        ];
    }
}
