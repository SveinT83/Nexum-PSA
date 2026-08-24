<?php

namespace App\Modules\Marketing\Jobs;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Marketing\Actions\AdvanceMarketingCampaignLifecycle;
use App\Modules\Marketing\Actions\AuthorizeMarketingCampaignRecipientProgression;
use App\Modules\Marketing\Actions\ClaimMarketingCampaignDelivery;
use App\Modules\Marketing\Actions\MarketingSuppressionGuard;
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

class SendDueMarketingCampaignEmails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /** @var array<int, array{account_id: int|null, provider_binding_version: int|null}> */
    public array $providerBindingSnapshots = [];

    public function __construct(public ?int $campaignId = null)
    {
        $bindings = app(EmailProviderBindingSnapshot::class);
        $default = $bindings->captureScope('marketing');

        MarketingCampaign::query()
            ->when($campaignId, fn ($query) => $query->whereKey($campaignId))
            ->when(! $campaignId, fn ($query) => $query->whereIn('status', ['approved', 'active']))
            ->get(['id', 'email_account_id'])
            ->each(function (MarketingCampaign $campaign) use ($bindings, $default): void {
                $this->providerBindingSnapshots[(int) $campaign->id] = $campaign->email_account_id
                    ? $bindings->captureAccount($campaign->emailAccount()->first())
                    : $default;
            });
    }

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        MarketingSettings $settings,
        SyncMarketingCampaignRecipients $syncRecipients,
        MarketingSuppressionGuard $suppressionGuard,
        AuthorizeMarketingCampaignRecipientProgression $progression,
        ClaimMarketingCampaignDelivery $deliveryClaims,
        AdvanceMarketingCampaignLifecycle $lifecycle,
    ): void {
        $campaigns = MarketingCampaign::query()
            ->with('emailAccount')
            ->whereIn('status', ['approved', 'active'])
            ->when($this->campaignId, fn ($query) => $query->whereKey($this->campaignId))
            ->get();

        foreach ($campaigns as $campaign) {
            $syncRecipients->handle($campaign);
            $campaignContext = $campaign->fresh([
                'emailAccount',
                'emails.template',
                'recipients.delivery',
                'lists.members',
                'list.members',
            ]);

            if (! $campaignContext) {
                continue;
            }

            $this->sendCampaignDueRecipients(
                $campaignContext,
                $accountResolver,
                $renderer,
                $mailer,
                $settings,
                $suppressionGuard,
                $progression,
                $deliveryClaims,
            );
            $lifecycle->handle($campaignContext);
        }
    }

    private function sendCampaignDueRecipients(
        MarketingCampaign $campaign,
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
        MarketingSettings $settings,
        MarketingSuppressionGuard $suppressionGuard,
        AuthorizeMarketingCampaignRecipientProgression $progression,
        ClaimMarketingCampaignDelivery $deliveryClaims,
    ): void {
        $account = $campaign->emailAccount ?: $accountResolver->forScope('marketing');

        if (! $account) {
            $this->log(null, $campaign->id, null, null, 'error', 'MARKETING_EMAIL_NO_ACCOUNT', 'No active marketing outbound account is configured.');

            return;
        }

        $snapshot = $this->providerBindingSnapshots[(int) $campaign->id] ?? null;
        try {
            $account = app(EmailProviderBindingSnapshot::class)->resolveAccount(
                $account,
                $snapshot['account_id'] ?? null,
                $snapshot['provider_binding_version'] ?? null,
            );
        } catch (\App\Modules\Integration\Exceptions\EmailProviderSecurityException) {
            $this->log($account->id, $campaign->id, null, null, 'error', 'MARKETING_EMAIL_PROVIDER_BINDING_STALE', 'The outbound Email provider binding changed after this campaign job was dispatched.');

            return;
        }
        $providerBindingVersion = (int) $snapshot['provider_binding_version'];

        $settingsPayload = $settings->get();

        if ($this->isInsideQuietHours($settingsPayload)) {
            $this->log($account->id, $campaign->id, null, null, 'info', 'MARKETING_EMAIL_QUIET_HOURS', 'Marketing send skipped during quiet hours.');

            return;
        }

        $limit = $campaign->batch_size ?: $settingsPayload['default_batch_size'];

        MarketingCampaignRecipient::query()
            ->with(['campaignEmail.template', 'campaign.list', 'contact'])
            ->where('marketing_campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (MarketingCampaignRecipient $recipient) use ($campaign, $account, $renderer, $mailer, $providerBindingVersion, $settingsPayload, $suppressionGuard, $progression, $deliveryClaims): void {
                $suppressionReason = $suppressionGuard->reasonForRecipient($recipient, $settingsPayload);

                if ($suppressionReason !== null) {
                    $this->markSuppressed($recipient, $suppressionReason);

                    return;
                }

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

                // Rendering is deliberately completed before the durable
                // transmission claim. A deterministic content failure is safe
                // to correct without consuming the lifetime delivery guard.
                try {
                    $rendered = $renderer->render($template, $this->variables($campaign, $recipient, $campaignEmail));
                    $subject = $rendered['subject'];
                    $html = $this->appendTrackingPixel($campaign, $recipient, $this->appendUnsubscribeHtml($this->rewriteLinks($campaign, $recipient, $rendered['html']), $recipient, $settingsPayload));
                    $text = $this->appendUnsubscribeText($rendered['text'], $recipient, $settingsPayload);
                } catch (\Throwable) {
                    $this->markFailed(
                        $recipient,
                        'MARKETING_EMAIL_RENDER_FAILED',
                        'Campaign content could not be rendered before transmission.',
                    );

                    return;
                }

                if (! $progression->handle($recipient, $campaign)) {
                    $this->log(
                        $account->id,
                        $campaign->id,
                        $recipient->marketing_campaign_email_id,
                        $recipient->id,
                        'info',
                        'MARKETING_EMAIL_PROGRESSION_NOT_AUTHORIZED',
                        'The recipient is not authorized for this sequence step.',
                    );

                    return;
                }

                $delivery = $deliveryClaims->handle($recipient, $account);

                if (! $delivery) {
                    $recipient->refresh();

                    if ($recipient->status === 'duplicate_skipped') {
                        $this->log(
                            $account->id,
                            $campaign->id,
                            $recipient->marketing_campaign_email_id,
                            $recipient->id,
                            'info',
                            'MARKETING_EMAIL_DUPLICATE_SKIPPED',
                            'A lifetime delivery guard blocked a duplicate Marketing transmission.',
                        );
                    }

                    return;
                }

                try {
                    $started = $deliveryClaims->markProviderWriteStarted(
                        (int) $delivery->id,
                        (string) $delivery->claim_token,
                    );
                } catch (\Throwable) {
                    // The durable claimed row still blocks replay. Never enter
                    // SMTP when the provider-write transition cannot be saved.
                    $this->log(
                        $account->id,
                        $campaign->id,
                        $recipient->marketing_campaign_email_id,
                        $recipient->id,
                        'warning',
                        'MARKETING_EMAIL_PROVIDER_WRITE_NOT_STARTED',
                        'The durable claim could not be advanced to provider write; automatic replay remains blocked.',
                        [],
                        $delivery->rfc_message_id,
                    );

                    return;
                }

                if (! $started) {
                    return;
                }

                try {
                    $messageId = $mailer->send(
                        $account,
                        $recipient->email,
                        $recipient->name,
                        $subject,
                        $html,
                        $text,
                        [],
                        [],
                        [
                            'provider_binding_version' => $providerBindingVersion,
                            'message_id' => $delivery->rfc_message_id,
                        ],
                    );
                } catch (\Throwable) {
                    try {
                        $deliveryClaims->markOutcomeUnknown(
                            (int) $delivery->id,
                            (string) $delivery->claim_token,
                        );
                    } catch (\Throwable) {
                        // provider_write_started itself is already a durable,
                        // non-replayable state when local finalization fails.
                    }

                    try {
                        $account->forceFill([
                            'last_error_code' => 'SMTP_SEND_OUTCOME_UNRESOLVED',
                            'last_error_message' => 'The SMTP provider outcome could not be confirmed.',
                        ])->save();
                    } catch (\Throwable) {
                    }

                    $this->log(
                        $account->id,
                        $campaign->id,
                        $recipient->marketing_campaign_email_id,
                        $recipient->id,
                        'warning',
                        'MARKETING_EMAIL_SEND_OUTCOME_UNKNOWN',
                        'The SMTP provider outcome could not be confirmed. Automatic resend is blocked pending review.',
                        ['to' => $recipient->email],
                        $delivery->rfc_message_id,
                    );

                    return;
                }

                try {
                    $finalized = $deliveryClaims->markSent(
                        (int) $delivery->id,
                        (string) $delivery->claim_token,
                        $messageId,
                    );
                } catch (\Throwable) {
                    // SMTP already accepted the stable Message-ID. The
                    // provider_write_started guard must remain non-replayable.
                    $finalized = false;
                }

                if (! $finalized) {
                    try {
                        $deliveryClaims->markOutcomeUnknown(
                            (int) $delivery->id,
                            (string) $delivery->claim_token,
                            'SMTP_ACCEPTED_FINALIZE_FAILED',
                        );
                    } catch (\Throwable) {
                        // provider_write_started remains a durable no-replay
                        // guard if even the review-state update is unavailable.
                    }

                    $this->log(
                        $account->id,
                        $campaign->id,
                        $recipient->marketing_campaign_email_id,
                        $recipient->id,
                        'warning',
                        'MARKETING_EMAIL_ACCEPTED_FINALIZE_FAILED',
                        'SMTP accepted the Marketing email, but local finalization requires review. Automatic resend is blocked.',
                        [],
                        $delivery->rfc_message_id,
                    );

                    return;
                }

                $messageId = trim((string) $messageId) ?: (string) $delivery->rfc_message_id;
                $this->log($account->id, $campaign->id, $recipient->marketing_campaign_email_id, $recipient->id, 'info', 'MARKETING_EMAIL_SENT', 'Marketing email sent.', [
                    'to' => $recipient->email,
                    'rfc_message_id' => $messageId,
                ], $messageId);
            });

        MarketingCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', 'approved')
            ->update(['status' => 'active']);
    }

    private function variables(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, MarketingCampaignEmail $campaignEmail): array
    {
        return [
            'campaign_name' => $campaign->name,
            'campaign_email_name' => $campaignEmail->displayName(),
            'contact_name' => $recipient->name ?: 'there',
            'client_name' => $recipient->client?->name ?? '',
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
            if ($this->isUnsubscribeUrl($matches[2], $recipient)) {
                return $matches[0];
            }

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
        if ($this->containsRenderedUnsubscribeUrl($html, $recipient)) {
            return rtrim($html);
        }

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
        if ($this->containsRenderedUnsubscribeUrl($text, $recipient)) {
            return rtrim($text);
        }

        $footer = trim((string) ($settings['unsubscribe_footer'] ?? ''));

        return rtrim($text)."\n\n".($footer !== '' ? $footer."\n" : '').'Unsubscribe: '.route('marketing.unsubscribe', $recipient->tracking_token);
    }

    private function containsRenderedUnsubscribeUrl(string $content, MarketingCampaignRecipient $recipient): bool
    {
        return str_contains($content, route('marketing.unsubscribe', $recipient->tracking_token))
            || str_contains($content, '/marketing/unsubscribe/'.$recipient->tracking_token);
    }

    private function isUnsubscribeUrl(string $url, MarketingCampaignRecipient $recipient): bool
    {
        return $url === route('marketing.unsubscribe', $recipient->tracking_token)
            || str_contains($url, '/marketing/unsubscribe/'.$recipient->tracking_token);
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
            'last_error' => $message,
        ])->save();

        $this->log(null, $recipient->marketing_campaign_id, $recipient->marketing_campaign_email_id, $recipient->id, 'error', $code, $message);
    }

    private function markSuppressed(MarketingCampaignRecipient $recipient, string $message): void
    {
        $recipient->forceFill([
            'status' => 'suppressed',
            'last_error' => $message,
        ])->save();

        $this->log(null, $recipient->marketing_campaign_id, $recipient->marketing_campaign_email_id, $recipient->id, 'info', 'MARKETING_EMAIL_SUPPRESSED', $message, [
            'to' => $recipient->email,
        ]);
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
