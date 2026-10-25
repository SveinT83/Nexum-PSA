<?php

namespace App\Modules\Clients\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return app(\App\Modules\Clients\Actions\ClientFormatWorkflow::class)->data($request, $clientFormat);
    }
}
