<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\CreateEmailMailboxDelegation;
use App\Modules\Email\Actions\RevokeEmailBreakGlassAccess;
use App\Modules\Email\Actions\RevokeEmailMailboxDelegation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxDelegation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailboxAccessController extends Controller
{
    public function index(Request $request): View
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

        return view('email::Tech.mailbox-access.index', [
            'accounts' => $accounts,
            'delegations' => $delegations,
            'delegates' => $delegates,
            'activeBreakGlass' => $activeBreakGlass,
        ]);
    }

    public function store(
        Request $request,
        int $accountId,
        CreateEmailMailboxDelegation $create,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $account = $this->ownedPersonalAccount($actor, $accountId);
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', 'exists:user_management,id'],
            'can_view' => ['nullable', 'boolean'],
            'can_organize' => ['nullable', 'boolean'],
            'can_send' => ['nullable', 'boolean'],
            'can_view_raw_source' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date'],
        ]);
        $delegate = User::query()->findOrFail((int) $data['delegate_id']);

        $create->handle($account, $actor, $delegate, $data);

        return back()->with('status', 'Mailbox delegation created.');
    }

    public function revokeDelegation(
        Request $request,
        int $accountId,
        int $delegationId,
        RevokeEmailMailboxDelegation $revoke,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $account = $this->ownedPersonalAccount($actor, $accountId);
        $delegation = EmailMailboxDelegation::query()
            ->where('email_account_id', $account->id)
            ->findOrFail($delegationId);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $revoke->handle($delegation, $actor, $data['reason']);

        return back()->with('status', 'Mailbox delegation revoked.');
    }

    public function revokeBreakGlass(
        Request $request,
        int $accessId,
        RevokeEmailBreakGlassAccess $revoke,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $accessQuery = EmailBreakGlassAccess::query()->whereKey($accessId);
        if (! $actor->can('email.break_glass_activate')) {
            $accessQuery->where(function ($authorized) use ($actor): void {
                $authorized
                    ->where('actor_id', $actor->id)
                    ->orWhereHas('account', fn ($accounts) => $accounts->where('owner_id', $actor->id));
            });
        }
        $access = $accessQuery->firstOrFail();
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $revoke->handle($access, $actor, $data['reason']);

        return back()->with('status', 'Emergency mailbox access revoked.');
    }

    private function ownedPersonalAccount(User $actor, int $accountId): EmailAccount
    {
        return EmailAccount::query()
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('owner_id', $actor->id)
            ->findOrFail($accountId);
    }
}
