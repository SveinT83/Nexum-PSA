<?php

namespace App\Modules\Commercial\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Support\ClientTimebankQuickPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientTimebankPolicyController extends Controller
{
    public function edit(ClientTimebankQuickPolicy $policy): View
    {
        return view('commercial::Admin.settings.timebank-policy', [
            'policy' => $policy->get(),
        ]);
    }

    public function update(Request $request, ClientTimebankQuickPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'quick_timebank_enabled' => ['sometimes', 'boolean'],
            'quick_timebank_require_remaining' => ['sometimes', 'boolean'],
            'quick_timebank_allow_overuse' => ['sometimes', 'boolean'],
            'quick_timebank_require_note' => ['sometimes', 'boolean'],
            'quick_timebank_max_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        foreach ([
            'quick_timebank_enabled',
            'quick_timebank_require_remaining',
            'quick_timebank_allow_overuse',
            'quick_timebank_require_note',
        ] as $key) {
            $data[$key] = $request->boolean($key);
        }

        $policy->update($data);

        return redirect()
            ->route('tech.admin.settings.cs.timebank-policy')
            ->with('status', 'Timebank policy updated.');
    }
}
