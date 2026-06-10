<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Signal\Models\Signal;
use Illuminate\View\View;

class SignalController extends Controller
{
    public function index(): View
    {
        return view('signal::Tech.index', [
            'signals' => Signal::query()
                ->with(['contact', 'client'])
                ->withCount('executions')
                ->latest('occurred_at')
                ->paginate(50),
        ]);
    }

    public function show(Signal $signal): View
    {
        return view('signal::Tech.show', [
            'signal' => $signal->load(['contact', 'client', 'executions.rule']),
        ]);
    }
}
