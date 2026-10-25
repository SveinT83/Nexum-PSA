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
        app(\App\Modules\Commercial\Actions\TimebankPolicyWorkflow::class)->save($request);

        return redirect()
            ->route('tech.admin.settings.cs.timebank-policy')
            ->with('status', 'Timebank policy updated.');
    }
}
