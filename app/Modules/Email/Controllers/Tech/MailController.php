<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class MailController extends Controller
{
    /**
     * Show the technician Mail workspace.
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        $hasEmergencyAccess = $actor?->isActive()
            && ! $actor->isSystemActor()
            && $actor->can('email.break_glass_activate')
            && EmailBreakGlassAccess::query()
                ->where('actor_id', $actor->id)
                ->whereHas('account', fn ($accounts) => $accounts
                    ->where('account_kind', EmailAccount::KIND_PERSONAL)
                    ->where('is_active', true))
                ->effective()
                ->exists();

        abort_unless($actor?->can('email.inbox_view') || $hasEmergencyAccess, 403);

        return view('email::Tech.mail');
    }
}
