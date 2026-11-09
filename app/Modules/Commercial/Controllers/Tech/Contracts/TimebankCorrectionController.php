<?php

namespace App\Modules\Commercial\Controllers\Tech\Contracts;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Actions\TimebankCorrectionWorkflow;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Server-rendered two-step review uses the same pure previews and commands as the API. */
class TimebankCorrectionController extends Controller
{
    public function index(Request $request, Client $client, TimebankCorrectionWorkflow $workflow)
    {
        $workflow->authorize($request);
        $rows = $workflow->history($request, $client);
        $page = max(1, (int) $request->input('page', 1));
        $history = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('commercial::Tech.Contracts.timebank-corrections', [
            'client' => $client, 'balances' => \App\Modules\Commercial\Models\Contracts\ContractItem::with(['contract', 'service'])
                ->whereHas('contract', fn ($q) => $q->where('client_id', $client->id)->whereIn('approval_status', ['approved', 'won']))
                ->whereHas('service', fn ($q) => $q->where('timebank_enabled', true))->get()
                ->map(fn ($item) => app(CalculateClientTimebankBalances::class)->forContractItem($client, $item->contract, $item,
                    $item->contract->end_date && $item->contract->end_date->lt(today()) ? $item->contract->end_date : today())),
            'history' => $history, 'preview' => null, 'result' => null,
        ]);
    }

    public function preview(Request $request, Client $client, string $kind, TimebankCorrectionWorkflow $workflow)
    {
        $input = $workflow->input($request, $kind);

        return view('commercial::Tech.Contracts.timebank-correction-preview', [
            'client' => $client, 'kind' => $kind, 'input' => $input,
            'preview' => $workflow->preview($request, $client, $kind, $input), 'result' => null,
        ]);
    }

    public function store(Request $request, Client $client, string $kind, TimebankCorrectionWorkflow $workflow)
    {
        $request->headers->set('If-Match', (string) $request->input('revision'));
        $request->headers->set('Idempotency-Key', (string) $request->input('command_key'));
        $request->request->remove('revision');
        $request->request->remove('command_key');
        $request->request->remove('_token');
        $result = $workflow->apply($request, $client, $kind);

        return view('commercial::Tech.Contracts.timebank-correction-preview', [
            'client' => $client, 'kind' => $kind, 'input' => [], 'preview' => null, 'result' => $result,
        ]);
    }
}
