<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExchangeRunController extends Controller
{
    public function store(Request $request, DataExchangeProfile $profile, RunDataExchangeExport $export): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.run'), 403);

        $run = $export->handle($profile, $request->user());

        return redirect()->route('tech.admin.system.data-exchange.runs.show', $run)
            ->with('success', 'Data Exchange export completed.');
    }

    public function show(DataExchangeRun $run): View
    {
        abort_unless(request()->user()?->can('data_exchange.view'), 403);

        $run->load(['profile', 'files', 'actor']);

        return view('dataexchange::Admin.runs.show', [
            'run' => $run,
            'auditEvents' => DataExchangeAuditEvent::query()
                ->where('run_id', $run->id)
                ->latest('occurred_at')
                ->get(),
        ]);
    }

    public function download(Request $request, DataExchangeFile $file): StreamedResponse
    {
        abort_unless($request->user()?->can('data_exchange.download'), 403);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $file->forceFill(['downloaded_at' => now()])->save();

        return Storage::disk($file->disk)->download($file->path, $file->filename);
    }
}
