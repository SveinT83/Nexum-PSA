<?php

namespace App\Modules\Economy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\Economy\Actions\EconomyExportWorkflow;
use App\Modules\Economy\Support\EconomyInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Export commands return the real owner run/file evidence without asserting invoice submission. */
class EconomyExportController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        EconomyInput::validate($request, []);

        return response()->json(['data' => app(EconomyExportWorkflow::class)->preview()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(app(EconomyExportWorkflow::class)->command($request), 201);
    }

    public function show(Request $request, int $run): JsonResponse
    {
        EconomyInput::validate($request, []);
        $record = DataExchangeRun::query()->whereHas('profile', fn ($q) => $q->where('key', 'economy_orders_export'))->findOrFail($run);

        return response()->json(['data' => EconomyExportWorkflow::projection($record)]);
    }
}
