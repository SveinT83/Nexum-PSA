<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Authoritative unsaved/snapshot preview, shared by web and API. */
class PreviewMarketingCampaignEmail
{
    public function handle(
        MarketingCampaign $campaign,
        Request $request,
        EmailTemplateRenderer $templateRenderer,
    ): array {
        $data = $request->validate([
            'email_template_id' => ['nullable', 'integer', 'exists:email_templates,id', 'required_without:campaign_email_id'],
            'campaign_email_id' => ['nullable', 'integer', 'exists:marketing_campaign_emails,id'],
            'email_name' => ['nullable', 'string', 'max:255'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:250000'],
            'body_text' => ['nullable', 'string', 'max:250000'],
        ]);
        $email = filled($data['campaign_email_id'] ?? null)
            ? MarketingCampaignEmail::query()->with('template')->findOrFail($data['campaign_email_id'])
            : null;
        abort_if($email && (int) $email->marketing_campaign_id !== (int) $campaign->id, 404);

        $baseTemplate = $email?->renderableTemplate()
            ?: EmailTemplate::query()
                ->whereKey($data['email_template_id'])
                ->where('scope', 'marketing')
                ->where('is_active', true)
                ->firstOrFail();
        $template = new EmailTemplate([
            'scope' => 'marketing',
            'key' => 'marketing_campaign_preview',
            'name' => $data['email_name'] ?? $email?->displayName() ?? $baseTemplate->name,
            'subject' => $data['email_subject'] ?? $email?->effectiveSubject() ?? $baseTemplate->subject,
            'body_html' => array_key_exists('body_html', $data)
                ? $data['body_html']
                : $email?->effectiveBodyHtml() ?? $baseTemplate->body_html,
            'body_text' => array_key_exists('body_text', $data)
                ? $data['body_text']
                : $email?->effectiveBodyText() ?? $baseTemplate->body_text,
            'layout_mode' => $baseTemplate->layout_mode ?? EmailTemplate::LAYOUT_BRANDING,
            'layout_html' => $baseTemplate->layout_html,
            'variables' => $baseTemplate->variables,
            'is_active' => true,
        ]);

        try {
            $variables = $this->previewVariables(
                $template,
                $campaign,
                $email,
                $this->previewMember($campaign),
                $templateRenderer,
            );

            return $templateRenderer->render($template, $variables);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body_html' => $exception->getMessage()]);
        }
    }

    private function previewVariables(
        EmailTemplate $template,
        MarketingCampaign $campaign,
        ?MarketingCampaignEmail $email,
        ?MarketingListMember $member,
        EmailTemplateRenderer $templateRenderer,
    ): array {
        return array_merge($templateRenderer->sampleVariables($template), [
            'campaign_name' => $campaign->name,
            'campaign_email_name' => $email?->displayName() ?? $template->name,
            'contact_name' => $member ? ($member->name ?: 'there') : 'Ola Nordmann',
            'client_name' => $member ? ($member->client?->name ?? '') : 'Example Client AS',
            'unsubscribe_url' => url('/marketing/unsubscribe/example'),
        ]);
    }

    private function previewMember(MarketingCampaign $campaign): ?MarketingListMember
    {
        foreach ($campaign->audienceLists() as $list) {
            $member = $list->members()
                ->with('client')
                ->where('status', 'eligible')
                ->orderBy('id')
                ->first();

            if ($member) {
                return $member;
            }
        }

        foreach ($campaign->audienceLists() as $list) {
            $member = $list->members()
                ->with('client')
                ->orderBy('id')
                ->first();

            if ($member) {
                return $member;
            }
        }

        return null;
    }
}
