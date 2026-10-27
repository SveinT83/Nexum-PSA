<?php

namespace App\Modules\Integration\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Services\AiTelemetryRead;
use App\Modules\Integration\Support\DomainApiRead;
use Illuminate\Http\Request;

/** Bounded read-only view of sanitized execution/cost facts; no raw provider payloads. */
class AiTelemetryController extends Controller
{
    public function index(Request $request, AiTelemetryRead $read)
    {
        $query = $read->query($request);
        $page = (clone $query)->latest()->orderBy('id')->paginate(DomainApiRead::pageSize($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($event) => $read->event($event))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => $read->apiSummary($query)]);
    }

    public function show(string $event, AiTelemetryRead $read)
    {
        return response()->json(['data' => $read->event(AiModelUsageEvent::findOrFail($event))]);
    }

    public function rateCards(Request $request)
    {
        $page = AiModelRateCard::with('rates')->orderByDesc('effective_from')->orderBy('id')
            ->paginate(DomainApiRead::pageSize($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($card) => [
            'id' => $card->id, 'provider_id' => $card->ai_provider_id, 'name' => $card->name,
            'model_pattern' => $card->model_pattern, 'currency' => $card->currency,
            'active' => (bool) $card->is_active, 'effective_from' => $card->effective_from?->toIso8601String(),
            'effective_to' => $card->effective_to?->toIso8601String(),
            'rates' => $card->rates->map(fn ($rate) => ['metric' => $rate->metric, 'rate' => $rate->rate, 'unit_quantity' => $rate->unit_quantity])->all(),
        ])->all(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
