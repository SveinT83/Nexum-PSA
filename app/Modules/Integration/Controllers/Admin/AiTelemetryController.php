<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Services\AiTelemetryRead;
use Illuminate\Http\Request;

class AiTelemetryController extends Controller
{
    public function index(Request $request)
    {
        $read = app(AiTelemetryRead::class);
        $query = $read->query($request);

        return view('integration::Tech.Admin.Telemetry.index', [
            'events' => (clone $query)->with(['agent', 'provider'])->latest()->orderBy('id')
                ->paginate((int) $request->input('per_page', 50))->withQueryString(),
            'stats' => $read->summary($query),
        ]);
    }

    public function show(AiModelUsageEvent $event)
    {
        return view('integration::Tech.Admin.Telemetry.show', [
            'event' => $event,
        ]);
    }

    public function rateCards()
    {
        $cards = AiModelRateCard::query()
            ->with(['provider', 'rates'])
            ->orderBy('is_active', 'desc')
            ->orderBy('effective_from', 'desc')
            ->get();

        return view('integration::Tech.Admin.Telemetry.rate_cards', [
            'cards' => $cards,
        ]);
    }
}
