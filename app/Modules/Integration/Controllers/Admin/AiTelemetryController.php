<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiModelRateCard;
use App\Modules\Integration\Models\AiModelUsageEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiTelemetryController extends Controller
{
    public function index(Request $request)
    {
        $query = AiModelUsageEvent::query()
            ->with(['agent', 'provider'])
            ->latest();

        if ($request->filled('domain')) {
            $query->where('domain', $request->domain);
        }

        if ($request->filled('feature')) {
            $query->where('feature_key', $request->feature);
        }

        $stats = [
            'total_tokens' => AiModelUsageEvent::sum('total_tokens'),
            'total_cost' => AiModelUsageEvent::sum('effective_cost'),
            'by_domain' => AiModelUsageEvent::query()
                ->select('domain', DB::raw('SUM(total_tokens) as tokens'), DB::raw('SUM(effective_cost) as cost'))
                ->groupBy('domain')
                ->get(),
        ];

        return view('integration::Tech.Admin.Telemetry.index', [
            'events' => $query->paginate(50),
            'stats' => $stats,
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
