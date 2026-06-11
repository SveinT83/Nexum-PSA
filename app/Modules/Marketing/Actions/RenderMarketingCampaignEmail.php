<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingListMember;
use RuntimeException;

class RenderMarketingCampaignEmail
{
    public function __construct(private readonly EmailTemplateRenderer $renderer)
    {
    }

    public function handle(MarketingCampaign $campaign, MarketingCampaignEmail $email, array $overrides = []): array
    {
        if ((int) $email->marketing_campaign_id !== (int) $campaign->id) {
            throw new RuntimeException('Campaign email does not belong to this campaign.');
        }

        $template = $this->templateFor($campaign, $email, $overrides);

        if (! $template || $template->scope !== 'marketing') {
            throw new RuntimeException('No campaign email content exists for this email.');
        }

        return $this->renderer->render($template, $this->variables($campaign, $email));
    }

    private function templateFor(MarketingCampaign $campaign, MarketingCampaignEmail $email, array $overrides): ?EmailTemplate
    {
        $template = $email->renderableTemplate();

        if (! $template) {
            return null;
        }

        $subject = $this->filledString($overrides['email_subject'] ?? null)
            ?: $email->effectiveSubject()
            ?: $campaign->name;

        return new EmailTemplate([
            'scope' => 'marketing',
            'key' => 'marketing_campaign_email_'.$email->id,
            'name' => $this->filledString($overrides['email_name'] ?? null) ?: $email->displayName(),
            'subject' => $subject,
            'body_html' => array_key_exists('body_html', $overrides)
                ? (string) $overrides['body_html']
                : $email->effectiveBodyHtml(),
            'body_text' => array_key_exists('body_text', $overrides)
                ? (string) $overrides['body_text']
                : $email->effectiveBodyText(),
            'variables' => $email->variables_snapshot ?: (array) $email->template?->variables,
            'is_default' => false,
            'is_active' => true,
        ]);
    }

    private function variables(MarketingCampaign $campaign, MarketingCampaignEmail $email): array
    {
        $campaign->loadMissing(['list.members.client']);
        $member = $this->sampleMember($campaign);

        return [
            'campaign_name' => $campaign->name,
            'campaign_email_name' => $email->displayName(),
            'contact_name' => $member?->name ?: 'there',
            'client_name' => $member?->client?->name ?? '',
            'unsubscribe_url' => url('/marketing/unsubscribe/example'),
        ];
    }

    private function sampleMember(MarketingCampaign $campaign): ?MarketingListMember
    {
        $members = $campaign->list?->members;

        if (! $members) {
            return null;
        }

        return $members->firstWhere('status', 'eligible') ?: $members->first();
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
