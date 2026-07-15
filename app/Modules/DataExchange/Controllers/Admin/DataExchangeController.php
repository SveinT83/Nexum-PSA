<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Support\DataExchangeSourceRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataExchangeController extends Controller
{
    public function index(Request $request, DataExchangeSourceRegistry $sources, EnsureDataExchangeProfileTemplates $templates): View
    {
        $templates->handle($request->user());

        $profiles = DataExchangeProfile::query()
            ->with(['files' => fn ($query) => $query->latest()->limit(1)])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('dataexchange::Admin.index', [
            'profiles' => $profiles,
            'allProfiles' => DataExchangeProfile::query()->orderBy('name')->get(),
            'importProfiles' => DataExchangeProfile::query()
                ->where('direction', DataExchangeProfile::DIRECTION_IMPORT)
                ->where('status', DataExchangeProfile::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(),
            'schedules' => DataExchangeSchedule::query()->with(['profile', 'deliveryTarget'])->latest()->limit(10)->get(),
            'deliveryTargets' => DataExchangeDeliveryTarget::query()->with('profile')->latest()->limit(10)->get(),
            'importPreviews' => DataExchangeImportPreview::query()->with('profile')->latest()->limit(5)->get(),
            'registeredSources' => $sources->visibleFor($request->user()),
            'stats' => [
                'profiles' => DataExchangeProfile::query()->count(),
                'runs' => DataExchangeRun::query()->count(),
                'files' => DataExchangeFile::query()->count(),
                'schedules' => DataExchangeSchedule::query()->count(),
            ],
        ]);
    }
}
