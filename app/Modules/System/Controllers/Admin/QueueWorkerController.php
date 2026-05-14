<?php

namespace App\Modules\System\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\System\Actions\QueueMaintenanceAction;
use App\Modules\System\Queries\QueueWorkerStatusQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueWorkerController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Queue and worker operations
    |--------------------------------------------------------------------------
    |
    | This controller intentionally delegates status reads and maintenance work
    | to module-local classes. The controller only validates UI input and returns
    | the admin views/responses.
    |
    */
    public function index(QueueWorkerStatusQuery $status): View
    {
        return view('system::Admin.queues-workers', [
            'status' => $status->get(),
        ]);
    }

    public function restartWorkers(QueueMaintenanceAction $maintenance): RedirectResponse
    {
        return back()->with('success', $maintenance->restartWorkers());
    }

    public function clearQueue(Request $request, QueueMaintenanceAction $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'queue' => 'nullable|string|max:100',
        ]);

        return back()->with('success', $maintenance->clearQueue($data['queue'] ?? null));
    }

    public function retryFailed(Request $request, QueueMaintenanceAction $maintenance): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => 'nullable|string|max:100',
        ]);

        return back()->with('success', $maintenance->retryFailed($data['job_id'] ?? null));
    }

    public function flushFailed(QueueMaintenanceAction $maintenance): RedirectResponse
    {
        return back()->with('success', $maintenance->flushFailed());
    }
}
