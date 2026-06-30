<?php

namespace App\Modules\Telephony\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Telephony\Actions\EnsureTelephonyToken;
use App\Modules\Telephony\Models\TelephonyCall;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelephonyProfileController extends Controller
{
    public function show(Request $request, EnsureTelephonyToken $tokens): View
    {
        $token = $tokens->handle($request->user());

        return view('telephony::Tech.profile', [
            'token' => $token,
            'recentCalls' => TelephonyCall::query()
                ->with(['contact', 'client', 'linkedTicket'])
                ->where('answered_by_user_id', $request->user()->id)
                ->latest('answered_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function rotate(Request $request, EnsureTelephonyToken $tokens): RedirectResponse
    {
        $tokens->rotate($request->user());

        return redirect()
            ->route('tech.telephony.profile')
            ->with('success', 'Telephony intake URL rotated.');
    }
}
