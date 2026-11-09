<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\ExecuteCommercialCommand;
use App\Modules\Commercial\Actions\TimebankCorrectionWorkflow;
use App\Modules\Commercial\Models\CommercialCommandReceipt;
use App\Modules\Commercial\Models\TimebankCorrection;
use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;

/** Typed correction transport; the workflow owns current actor, source and financial policies. */
class TimebankCorrectionController extends Controller
{
    public function preview(Request $request, Client $client, string $kind, TimebankCorrectionWorkflow $workflow)
    {
        return response()->json(['data' => $workflow->preview($request, $client, $kind)]);
    }

    public function store(Request $request, Client $client, string $kind, TimebankCorrectionWorkflow $workflow)
    {
        return response()->json($workflow->apply($request, $client, $kind), 201);
    }

    public function show(Request $request, Client $client, int $correction, TimebankCorrectionWorkflow $workflow)
    {
        return response()->json(['data' => $workflow->read($request, $client, TimebankCorrection::findOrFail($correction))]);
    }

    public function operation(Request $request, Client $client, int $operation, TimebankCorrectionWorkflow $workflow)
    {
        $workflow->authorize($request);
        $receipt = CommercialCommandReceipt::where('actor_key', ExecuteCommercialCommand::actorKey($request))->findOrFail($operation);
        $id = data_get($receipt->result, 'data.id');
        abort_unless((str_contains($receipt->operation, 'timebank.corrections.') || str_contains($receipt->operation, 'timebank-corrections.')) && $id, 404);

        return response()->json(['data' => $workflow->read($request, $client, TimebankCorrection::findOrFail($id)),
            'operation_id' => $receipt->id]);
    }

    public function history(Request $request, Client $client, TimebankCorrectionWorkflow $workflow)
    {
        return response()->json(DomainApiRead::paginateCollection($workflow->history($request, $client), $request));
    }
}
