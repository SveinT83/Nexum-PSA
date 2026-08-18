<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Queries\EmailMailboxAccessHistoryQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MailboxAccessHistoryController extends Controller
{
    public function __invoke(Request $request, EmailMailboxAccessHistoryQuery $history): View
    {
        $actor = $request->user();
        abort_unless($actor?->isActive() && ! $actor->isSystemActor(), 403);

        $ownedAccounts = EmailAccount::query()
            ->where('account_kind', EmailAccount::KIND_PERSONAL)
            ->where('owner_id', $actor->id)
            ->orderBy('address')
            ->get(['id', 'address']);
        $canAudit = $actor->can('email.break_glass_audit');
        abort_unless($canAudit || $ownedAccounts->isNotEmpty(), 403);

        $accountId = $request->filled('account') && ctype_digit((string) $request->input('account'))
            ? (int) $request->input('account')
            : null;

        if ($accountId !== null && ! $canAudit && ! $ownedAccounts->contains('id', $accountId)) {
            abort(404);
        }

        return view('email::Tech.mailbox-access.history', [
            'events' => $history->forActor($actor, $accountId),
            'accountId' => $accountId,
            'accounts' => $canAudit
                ? EmailAccount::query()->orderBy('address')->get(['id', 'address'])
                : $ownedAccounts,
            'canAuditAll' => $canAudit,
        ]);
    }
}
