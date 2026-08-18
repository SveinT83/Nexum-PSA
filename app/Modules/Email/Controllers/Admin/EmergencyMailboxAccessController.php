<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\ActivateEmailBreakGlassAccess;
use App\Modules\Email\Actions\RevokeEmailBreakGlassAccess;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Queries\EmailMailboxAccessHistoryQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmergencyMailboxAccessController extends Controller
{
    public function index(Request $request, EmailMailboxAccessHistoryQuery $history): View
    {
        $actor = $request->user();
        abort_unless(
            $actor?->isActive()
                && ! $actor->isSystemActor()
                && $actor->can('email.break_glass_activate'),
            403,
        );

        $activeAccesses = $history->activeBreakGlass()
            ->get()
            ->filter(fn (EmailBreakGlassAccess $access): bool => $access->actor?->can('email.break_glass_activate') === true);

        return view('email::Admin.EmergencyAccess.index', [
            'accounts' => EmailAccount::query()
                ->where('account_kind', EmailAccount::KIND_PERSONAL)
                ->where('is_active', true)
                ->whereNotNull('owner_id')
                ->where('owner_id', '!=', $actor->id)
                ->with('owner:id,name')
                ->orderBy('address')
                ->get(['id', 'address', 'description', 'owner_id', 'account_kind', 'is_active']),
            'activeAccesses' => $activeAccesses,
            'recentAccesses' => EmailBreakGlassAccess::query()
                ->where('actor_id', $actor->id)
                ->with(['account:id,address', 'revoker:id,name'])
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        ActivateEmailBreakGlassAccess $activate,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless(
            $actor?->isActive()
                && ! $actor->isSystemActor()
                && $actor->can('email.break_glass_activate'),
            403,
        );

        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'account_confirmation' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'can_view_content' => ['nullable', 'boolean'],
            'can_search' => ['nullable', 'boolean'],
            'can_download_attachments' => ['nullable', 'boolean'],
            'can_view_raw_source' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $account = EmailAccount::query()
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('is_active', true)
            ->findOrFail((int) $data['account_id']);

        $activate->handle($account, $actor, $data);

        return back()->with('status', 'Emergency mailbox access activated and notifications queued.');
    }

    public function revoke(
        Request $request,
        int $accessId,
        RevokeEmailBreakGlassAccess $revoke,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $access = EmailBreakGlassAccess::query()->findOrFail($accessId);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $revoke->handle($access, $actor, $data['reason']);

        return back()->with('status', 'Emergency mailbox access revoked.');
    }
}
