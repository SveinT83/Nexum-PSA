<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use RuntimeException;

class SendMarketingCampaignEmailTest
{
    public function __construct(
        private readonly DefaultEmailAccountResolver $accountResolver,
        private readonly SmtpAccountMailer $mailer,
        private readonly RenderMarketingCampaignEmail $renderEmail,
    ) {
    }

    public function handle(MarketingCampaign $campaign, MarketingCampaignEmail $email, User $user, array $data): string
    {
        $account = $campaign->emailAccount ?: $this->accountResolver->forScope('marketing');

        if (! $account) {
            throw new RuntimeException('No active marketing outbound account is configured.');
        }

        $overrides = [];

        foreach (['email_name', 'email_subject', 'body_html', 'body_text'] as $key) {
            if (array_key_exists($key, $data)) {
                $overrides[$key] = $data[$key];
            }
        }

        $rendered = $this->renderEmail->handle($campaign, $email, $overrides);
        $messageId = $this->mailer->send(
            $account,
            $data['to_email'],
            $data['to_name'] ?? null,
            '[Test] '.$rendered['subject'],
            $rendered['html'],
            $rendered['text'],
        );

        EmailLog::query()->create([
            'direction' => 'outbound',
            'account_id' => $account->id,
            'scope' => 'marketing',
            'level' => 'info',
            'code' => 'MARKETING_EMAIL_TEST_SENT',
            'message' => 'Marketing campaign test email sent.',
            'context_json' => [
                'marketing_campaign_id' => $campaign->id,
                'marketing_campaign_email_id' => $email->id,
                'to' => $data['to_email'],
                'user_id' => $user->id,
            ],
            'rfc_message_id' => $messageId,
        ]);

        return $messageId;
    }
}
