<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Marketing\Actions\CountMarketingCampaignAudienceRecipients;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function index(
        EnsureMarketingDefaults $defaults,
        EnsureDefaultEmailTemplates $emailDefaults,
        DefaultEmailAccountResolver $accountResolver,
        MarketingSettings $settings,
        CountMarketingCampaignAudienceRecipients $audienceCounter,
    ): View {
        return view('marketing::Tech.index', app(\App\Modules\Marketing\Queries\MarketingDashboard::class)->handle());
    }
}
