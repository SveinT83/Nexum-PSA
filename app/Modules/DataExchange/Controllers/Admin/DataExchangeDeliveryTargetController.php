<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataExchangeDeliveryTargetController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.delivery'), 403);

        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');
        $data['created_by'] = $request->user()?->id;
        $data['settings'] = [
            'notes' => $request->input('notes'),
        ];

        DataExchangeDeliveryTarget::query()->create($data);

        return back()->with('success', 'Delivery target saved.');
    }

    public function update(Request $request, DataExchangeDeliveryTarget $deliveryTarget): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.delivery'), 403);

        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');
        $data['settings'] = [
            'notes' => $request->input('notes'),
        ];

        $deliveryTarget->forceFill($data)->save();

        return back()->with('success', 'Delivery target updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'profile_id' => ['nullable', 'exists:data_exchange_profiles,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['local', 'ftp', 'sftp'])],
            'direction' => ['required', Rule::in(['export', 'import', 'both'])],
            'credential_reference' => ['nullable', 'string', 'max:255'],
            'filesystem_disk' => ['nullable', 'string', 'max:255'],
            'remote_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
