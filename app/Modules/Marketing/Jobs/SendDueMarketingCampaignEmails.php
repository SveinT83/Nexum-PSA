<?php

namespace App\Modules\Marketing\Jobs;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Support\MarketingSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SendDueMarketingCampaignEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public ?int $campaignId = null)
    {
    }

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        MarketingSettings $settings,
        SyncMarketingCampaignRecipients $syncRecipients,
    ): void {
        $campaigns = MarketingCampaign::query()
            ->with(['emailAccount', 'emails.template', 'list.members'])
            ->whereIn('status', ['approved', 'active'])
            ->when($this->campaignId, fn ($query) => $query->whereKey($this->campaignId))
            ->get();

        foreach ($campaigns as $campaign) {
            $syncRecipients->handle($campaign);
            $this->sendCampaignDueRecipients($campaign->fresh(['emailAccount']), $accountResolver, $renderer, $mailer, $settings);
        }
    }

    private function sendCampaignDueRecipients(
        MarketingCampaign $campaign,
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        MarketingSettings $settings,
    ): void {
        $account = $campaign->emailAccount ?: $accountResolver->forScope('marketing');

        if (! $account) {
            $this->log(null, $campaign->id, null, null, 'error', 'MARKETING_EMAIL_NO_ACCOUNT', 'No active marketing outbound account is configured.');
            return;
        }

        $settingsPayload = $settings->get();

        if ($this->isInsideQuietHours($settingsPayload)) {
            $this->log($account->id, $campaign->id, null, null, 'info', 'MARKETING_EMAIL_QUIET_HOURS', 'Marketing send skipped during quiet hours.');
            return;
        }

        $limit = $campaign->batch_size ?: $settingsPayload['default_batch_size'];

        MarketingCampaignRecipient::query()
            ->with(['campaignEmail.template', 'campaign.list'])
            ->where('marketing_campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (MarketingCampaignRecipient $recipient) use ($campaign, $account, $renderer, $mailer, $settingsPayload): void {
                $campaignEmail = $recipient->campaignEmail;
                $template = $campaignEmail?->renderableTemplate();

                if (! $campaignEmail || ! $template || $template->scope !== 'marketing') {
                    $this->markFailed($recipient, 'MARKETING_EMAIL_NO_CONTENT', 'No campaign email content exists for this recipient.');
                    return;
                }

                if (! $campaignEmail->hasSnapshotContent() && (! $template->is_active || $template->scope !== 'marketing')) {
                    $this->markFailed($recipient, 'MARKETING_EMAIL_NO_TEMPLATE', 'No active marketing template exists for this legacy campaign email.');
                    return;
                }

                try {
                    $rendered = $renderer->render($template, $this->variables($campaign, $recipient, $campaignEmail));
                    $subject = $rendered['subject'];
                    $html = $this->appendTrackingPixel($campaign, $recipient, $this->appendUnsubscribeHtml($this->rewriteLinks($campaign, $recipient, $rendered['html']), $recipient, $settingsPayload));
                    $text = $this->appendUnsubscribeText($rendered['text'], $recipient, $settingsPayload);

                    $messageId = $mailer->send($account, $recipient->email, $recipient->name, $subject, $html, $text);

                    DB::transaction(function () use ($recipient, $messageId): void {
                        $recipient->forceFill([
                            'status' => 'sent',
                            'sent_at' => now(),
                            'attempts' => $recipient->attempts + 1,
                            'rfc_message_id' => $messageId,
                            'last_error' => null,
                        ])->save();
                    });

                    $this->log($account->id, $campaign->id, $recipient->marketing_campaign_email_id, $recipient->id, 'info', 'MARKETING_EMAIL_SENT', 'Marketing email sent.', [
                        'to' => $recipient->email,
                        'rfc_message_id' => $messageId,
                    ], $messageId);
                } catch (\Throwable $e) {
                    $recipient->forceFill([
                        'status' => 'failed',
                        'attempts' => $recipient->attempts + 1,
                        'last_error' => $e->getMessage(),
                    ])->save();

                    $account->forceFill([
                        'last_error_code' => 'SMTP_SEND',
                        'last_error_message' => $e->getMessage(),
                    ])->save();

                    $this->log($account->id, $campaign->id, $recipient->marketing_campaign_email_id, $recipient->id, 'error', 'MARKETING_EMAIL_SEND_FAILED', $e->getMessage(), [
                        'to' => $recipient->email,
                    ]);

                    throw $e;
                }
            });

        if ($campaign->status === 'approved') {
            $campaign->forceFill(['status' => 'active'])->save();
        }
    }

    private function variables(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, MarketingCampaignEmail $campaignEmail): array
    {
        return [
            'campaign_name' => $campaign->name,
            'campaign_email_name' => $campaignEmail->displayName(),
            'campaign_subject' => $campaignEmail->effectiveSubject() ?? $campaign->name,
            'campaign_heading' => $campaignEmail->displayName(),
            'campaign_intro' => $campaign->description ?: 'Here is an update from our team.',
            'campaign_body' => '',
            'contact_name' => $recipient->name ?: 'there',
            'client_name' => $recipient->client?->name ?? '',
            'primary_cta_label' => 'Read more',
            'primary_cta_url' => url('/'),
            'unsubscribe_url' => route('marketing.unsubscribe', $recipient->tracking_token),
        ];
    }

    private function appendTrackingPixel(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, string $html): string
    {
        if (! $campaign->track_opens || $html === '') {
            return $html;
        }

        return rtrim($html).'<img src="'.e(route('marketing.track.open', $recipient->tracking_token)).'" width="1" height="1" alt="" style="display:none;">';
    }

    private function rewriteLinks(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, string $html): string
    {
        if (! $campaign->track_clicks || $html === '') {
            return $html;
        }

        return preg_replace_callback('/href=(["\'])(https?:\/\/[^"\']+)\1/i', function (array $matches) use ($recipient): string {
            return 'href='.$matches[1].e($this->clickUrl($recipient, $matches[2])).$matches[1];
        }, $html) ?: $html;
    }

    private function clickUrl(MarketingCampaignRecipient $recipient, string $url): string
    {
        return route('marketing.track.click', [
            'token' => $recipient->tracking_token,
            'url' => base64_encode($url),
        ]);
    }

    private function appendUnsubscribeHtml(string $html, MarketingCampaignRecipient $recipient, array $settings): string
    {
        $footer = trim((string) ($settings['unsubscribe_footer'] ?? ''));

        if ($footer === '') {
            $footer = 'You can unsubscribe at any time.';
        }

        return rtrim($html)
            .'<p style="margin-top:24px;color:#6c757d;font-size:12px;">'
            .e($footer).' <a href="'.e(route('marketing.unsubscribe', $recipient->tracking_token)).'">Unsubscribe</a></p>';
    }

    private function appendUnsubscribeText(string $text, MarketingCampaignRecipient $recipient, array $settings): string
    {
        $footer = trim((string) ($settings['unsubscribe_footer'] ?? ''));

        return rtrim($text)."\n\n".($footer !== '' ? $footer."\n" : '').'Unsubscribe: '.route('marketing.unsubscribe', $recipient->tracking_token);
    }

    private function isInsideQuietHours(array $settings): bool
    {
        $start = $settings['quiet_hours_start'] ?? null;
        $end = $settings['quiet_hours_end'] ?? null;

        if (! $start || ! $end || $start === $end) {
            return false;
        }

        $now = Carbon::now()->format('H:i');

        return $start < $end
            ? $now >= $start && $now < $end
            : $now >= $start || $now < $end;
    }

    private function markFailed(MarketingCampaignRecipient $recipient, string $code, string $message): void
    {
        $recipient->forceFill([
            'status' => 'failed',
            'attempts' => $recipient->attempts + 1,
            'last_error' => $message,
        ])->save();

        $this->log(null, $recipient->marketing_campaign_id, $recipient->marketing_campaign_email_id, $recipient->id, 'error', $code, $message);
    }

    private function log(?int $accountId, int $campaignId, ?int $campaignEmailId, ?int $recipientId, string $level, string $code, string $message, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'marketing',
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context_json' => array_merge([
                'marketing_campaign_id' => $campaignId,
                'marketing_campaign_email_id' => $campaignEmailId,
                'marketing_campaign_recipient_id' => $recipientId,
            ], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }
}
