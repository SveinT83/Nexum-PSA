<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Marketing\Actions\CountMarketingCampaignAudienceRecipients;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
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
    ): View
    {
        $defaults->handle();
        $emailDefaults->handle();

        $campaignStatusCounts = MarketingCampaign::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $recipientStatusCounts = MarketingCampaignRecipient::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $eventTypeCounts = MarketingCampaignEvent::query()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $marketingDefaultAccount = $accountResolver->forScope('marketing');
        $recentCampaigns = MarketingCampaign::query()
            ->with(['list.members', 'lists.members', 'emailAccount'])
            ->withCount(['emails', 'recipients'])
            ->latest('updated_at')
            ->limit(8)
            ->get();

        foreach ($recentCampaigns as $campaign) {
            $campaign->setAttribute('audience_recipients_count', $audienceCounter->handle($campaign));
        }

        return view('marketing::Tech.index', [
            'dashboard' => [
                'campaigns_total' => MarketingCampaign::query()->count(),
                'campaigns_active' => (int) ($campaignStatusCounts['active'] ?? 0),
                'campaigns_approved' => (int) ($campaignStatusCounts['approved'] ?? 0),
                'campaigns_draft' => (int) ($campaignStatusCounts['draft'] ?? 0),
                'lists_total' => MarketingList::query()->count(),
                'members_total' => MarketingListMember::query()->where('status', 'active')->count(),
                'recipients_pending' => (int) ($recipientStatusCounts['pending'] ?? 0),
                'recipients_due' => MarketingCampaignRecipient::query()
                    ->where('status', 'pending')
                    ->where('due_at', '<=', now())
                    ->count(),
                'recipients_sent' => (int) ($recipientStatusCounts['sent'] ?? 0),
                'opens' => (int) ($eventTypeCounts['open'] ?? 0),
                'clicks' => (int) ($eventTypeCounts['click'] ?? 0),
                'unsubscribes' => (int) ($eventTypeCounts['unsubscribe'] ?? 0),
                'templates_active' => EmailTemplate::query()
                    ->where('scope', 'marketing')
                    ->where('is_active', true)
                    ->count(),
                'marketing_sender_accounts' => EmailAccount::query()
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (EmailAccount $account): bool => in_array('marketing', $account->defaults_for ?? [], true))
                    ->count(),
            ],
            'marketingDefaultAccount' => $marketingDefaultAccount,
            'recentCampaigns' => $recentCampaigns,
            'dueRecipients' => MarketingCampaignRecipient::query()
                ->with(['campaign', 'campaignEmail.template'])
                ->where('status', 'pending')
                ->orderBy('due_at')
                ->limit(8)
                ->get(),
            'recentEvents' => MarketingCampaignEvent::query()
                ->with(['campaign', 'recipient'])
                ->latest('occurred_at')
                ->limit(8)
                ->get(),
            'settings' => $settings->get(),
        ]);
    }
}
