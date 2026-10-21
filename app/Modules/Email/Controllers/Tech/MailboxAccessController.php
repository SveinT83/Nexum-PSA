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
        return view('email::Tech.mailbox-access.index',
            app(\App\Modules\Email\Queries\EmailMailboxAccessContext::class)->handle($request));
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
