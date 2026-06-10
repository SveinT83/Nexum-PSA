<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function index(EnsureMarketingDefaults $defaults): View
    {
        $defaults->handle();

        return view('marketing::Tech.index', [
            'plannedCapabilities' => [
                'Lists and segmentation',
                'Email template selection',
                'Campaign approvals',
                'Automated sending',
                'Open and click tracking',
                'Bounce and suppression handling',
                'Sales follow-up signals',
                'WordPress content pull',
            ],
        ]);
    }
}
