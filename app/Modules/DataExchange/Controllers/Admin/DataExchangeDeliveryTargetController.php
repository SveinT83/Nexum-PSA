<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DataExchangeDeliveryTargetController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $input = $request->except(['_token', '_method', 'revision']);
        $input['active'] = $request->boolean('active');
        app(\App\Modules\DataExchange\Actions\SaveDataExchangeOperations::class)->target($input, $request->user());

        return back()->with('success', 'Configuration saved.');
    }

    public function update(Request $request, DataExchangeDeliveryTarget $deliveryTarget): RedirectResponse
    {
        $input = $request->except(['_token', '_method', 'revision']);
        $input['active'] = $request->boolean('active');
        app(\App\Modules\DataExchange\Actions\SaveDataExchangeOperations::class)->target($input, $request->user(), $deliveryTarget, $request->input('revision'), false);

        return back()->with('success', 'Configuration updated.');
    }
}
