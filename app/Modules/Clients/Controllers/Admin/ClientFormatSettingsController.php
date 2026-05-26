<?php

namespace App\Modules\Clients\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientFormatSettingsController extends Controller
{
    public function index(): View
    {
        return view('clients::Admin.Settings.client-formats.index', [
            'formats' => ClientFormat::query()
                ->withCount('clients')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ClientFormat::query()->create($this->clientFormatData($request));

        return back()->with('success', 'Client format created.');
    }

    public function update(Request $request, ClientFormat $clientFormat): RedirectResponse
    {
        $clientFormat->update($this->clientFormatData($request, $clientFormat));

        return back()->with('success', 'Client format updated.');
    }

    private function clientFormatData(Request $request, ?ClientFormat $clientFormat = null): array
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('client_formats', 'name')->ignore($clientFormat?->id),
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('client_formats', 'code')->ignore($clientFormat?->id),
            ],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:1000000',
            'is_active' => 'sometimes|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
