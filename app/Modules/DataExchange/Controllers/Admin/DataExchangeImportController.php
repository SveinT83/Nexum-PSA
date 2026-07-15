<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\CommitDataExchangeImportPreview;
use App\Modules\DataExchange\Actions\RunDataExchangeImportDryRun;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataExchangeImportController extends Controller
{
    public function dryRun(Request $request, RunDataExchangeImportDryRun $dryRun): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.import'), 403);

        $data = $request->validate([
            'profile_id' => ['required', 'exists:data_exchange_profiles,id'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $profile = DataExchangeProfile::query()->findOrFail($data['profile_id']);
        $preview = $dryRun->handle($profile, $data['file'], $request->user());

        return redirect()->route('tech.admin.system.data-exchange.imports.show', $preview)
            ->with('success', 'Import dry-run completed.');
    }

    public function show(DataExchangeImportPreview $preview): View
    {
        abort_unless(request()->user()?->can('data_exchange.view'), 403);

        $preview->load(['profile', 'creator', 'committer']);

        return view('dataexchange::Admin.imports.show', [
            'preview' => $preview,
        ]);
    }

    public function commit(Request $request, DataExchangeImportPreview $preview, CommitDataExchangeImportPreview $commit): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.approve_import'), 403);

        $commit->handle($preview, $request->user());

        return redirect()->route('tech.admin.system.data-exchange.imports.show', $preview)
            ->with('success', 'Import committed.');
    }
}
