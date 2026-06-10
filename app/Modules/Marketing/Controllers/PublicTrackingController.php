<?php

namespace App\Modules\Marketing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Actions\RecordMarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicTrackingController extends Controller
{
    public function open(string $token, Request $request, RecordMarketingCampaignEvent $events): Response
    {
        $recipient = MarketingCampaignRecipient::query()
            ->where('tracking_token', $token)
            ->first();

        if ($recipient) {
            $events->handle($recipient, 'open', null, $request);
        }

        return response(base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=='), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(string $token, string $url, Request $request, RecordMarketingCampaignEvent $events): RedirectResponse
    {
        $target = base64_decode($url, true) ?: '/';
        $target = filter_var($target, FILTER_VALIDATE_URL) ? $target : url('/');

        $recipient = MarketingCampaignRecipient::query()
            ->where('tracking_token', $token)
            ->first();

        if ($recipient) {
            $events->handle($recipient, 'click', $target, $request);
        }

        return redirect()->away($target);
    }

    public function unsubscribe(string $token, Request $request, RecordMarketingCampaignEvent $events): Response
    {
        $recipient = MarketingCampaignRecipient::query()
            ->where('tracking_token', $token)
            ->first();

        if ($recipient) {
            $events->handle($recipient, 'unsubscribe', null, $request);
            $recipient->contact?->forceFill([
                'do_not_email' => true,
                'marketing_consent' => false,
            ])->save();
        }

        return response('You have been unsubscribed from marketing email.', 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
