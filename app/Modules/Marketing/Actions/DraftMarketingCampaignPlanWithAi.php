<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class DraftMarketingCampaignPlanWithAi
{
    public function __construct(
        private readonly AiAgentResolver $agentResolver,
        private readonly AiChatResponder $responder,
    ) {
    }

    public function handle(User $user, MarketingCampaign $campaign, array $input): array
    {
        $agent = $this->agentResolver->defaultAgent($user, 'marketing');

        if (! $agent) {
            throw new RuntimeException('No active AI agent is available for marketing.');
        }

        $context = $this->context($campaign, $input);
        $chat = AiChat::query()->create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Marketing campaign plan '.$campaign->name,
            'status' => 'closed',
            'metadata' => [
                'source' => 'marketing_campaign_plan_draft',
                'marketing_campaign_id' => $campaign->id,
            ],
            'last_message_at' => now(),
        ]);
        $chat->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ]);

        try {
            $reply = $this->responder->complete($agent, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
            ]);
        } catch (\Throwable $exception) {
            $chat->messages()->create([
                'role' => 'assistant',
                'body' => 'AI provider error: '.$exception->getMessage(),
            ]);

            throw new RuntimeException('AI provider error: '.$exception->getMessage(), previous: $exception);
        }

        $chat->messages()->create([
            'role' => 'assistant',
            'body' => $reply,
        ]);

        return $this->sanitize($this->decodeJson($reply), $campaign);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You plan Nexum PSA marketing email campaigns.',
            'Return ONLY compact JSON. No Markdown, no explanation.',
            'Allowed JSON keys: campaign_name, campaign_description, emails.',
            'emails must be an array of objects with keys: email_name, email_subject, delay_minutes, body_html, body_text.',
            'Use the user prompt, campaign context, mailing list context, current sequence emails, and available Marketing templates.',
            'Plan a coherent ordered sequence. Keep delay_minutes relative to campaign start or the previous sequence step.',
            'Use normal http or https destination links when links are useful. Nexum PSA rewrites normal links for click tracking when tracking is enabled, so never invent tracking redirect URLs.',
            'Do not include unsubscribe links or tracking pixels. Nexum PSA adds those later.',
            'Use clear editable HTML that can be pasted into the current campaign email editor.',
            'Make body_text match body_html content in plain text.',
            'Use the same language as the prompt or current campaign when obvious.',
            'WordPress content is a future context source. Do not claim that WordPress content was pulled unless WordPress content is provided in the context.',
        ]);
    }

    private function context(MarketingCampaign $campaign, array $input): array
    {
        $campaign->loadMissing(['list.members.client', 'emails.template']);
        $members = $campaign->list?->members ?? collect();

        return [
            'user_prompt' => $input['prompt'],
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => Str::limit((string) $campaign->description, 2000),
                'status' => $campaign->status,
                'starts_at' => $campaign->starts_at?->toDateTimeString(),
                'send_interval_minutes' => $campaign->send_interval_minutes,
                'track_opens' => $campaign->track_opens,
                'track_clicks' => $campaign->track_clicks,
            ],
            'mailing_list' => [
                'name' => $campaign->list?->name,
                'description' => Str::limit((string) $campaign->list?->description, 1200),
                'audience_type' => $campaign->list?->audience_type,
                'segment_criteria' => $campaign->list?->segment_criteria,
                'member_count' => $members->count(),
                'sample_members' => $members
                    ->take(10)
                    ->map(fn ($member) => [
                        'name' => $member->name,
                        'client' => $member->client?->name,
                        'status' => $member->status,
                    ])
                    ->values()
                    ->all(),
            ],
            'current_sequence' => $campaign->emails
                ->sortBy('sequence_order')
                ->map(fn (MarketingCampaignEmail $email): array => [
                    'sequence_order' => $email->sequence_order,
                    'delay_minutes' => $email->delay_minutes,
                    'status' => $email->status,
                    'source_template' => $email->sourceTemplateName(),
                    'email_name' => $email->displayName(),
                    'email_subject' => $email->effectiveSubject(),
                    'body_html' => Str::limit((string) $email->effectiveBodyHtml(), 4000),
                    'body_text' => Str::limit((string) $email->effectiveBodyText(), 2500),
                ])
                ->values()
                ->all(),
            'available_marketing_templates' => EmailTemplate::query()
                ->where('scope', 'marketing')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'subject', 'variables'])
                ->map(fn (EmailTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'subject' => $template->subject,
                    'variables' => (array) $template->variables,
                ])
                ->all(),
            'future_content_sources' => [
                'wordpress' => 'Not available in this slice. Do not invent WordPress post data. When available later, WordPress posts, excerpts, source URLs, and links can be included here.',
            ],
            'link_tracking' => [
                'enabled_for_campaign' => $campaign->track_clicks,
                'rule' => 'Use normal destination URLs. Nexum PSA records clicks by rewriting http and https links at send time.',
            ],
        ];
    }

    private function decodeJson(string $reply): array
    {
        $json = trim($reply);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/', '', (string) $json);
        }

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI did not return valid JSON content.');
        }

        return $decoded;
    }

    private function sanitize(array $payload, MarketingCampaign $campaign): array
    {
        $payload = Arr::only($payload, ['campaign_name', 'campaign_description', 'emails']);
        $emails = collect($payload['emails'] ?? [])
            ->filter(fn ($email): bool => is_array($email))
            ->take(10)
            ->values()
            ->map(function (array $email, int $index): array {
                $html = trim((string) ($email['body_html'] ?? ''));
                $text = trim((string) ($email['body_text'] ?? ''));

                if ($text === '' && $html !== '') {
                    $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))) ?: '');
                }

                return [
                    'email_name' => Str::limit(trim((string) ($email['email_name'] ?? 'Campaign email '.($index + 1))), 255, ''),
                    'email_subject' => Str::limit(trim((string) ($email['email_subject'] ?? '')), 255, ''),
                    'delay_minutes' => max(0, min(525600, (int) ($email['delay_minutes'] ?? ($index * 1440)))),
                    'body_html' => $html,
                    'body_text' => $text,
                ];
            })
            ->all();

        return [
            'campaign_name' => Str::limit(trim((string) ($payload['campaign_name'] ?? $campaign->name)), 255, ''),
            'campaign_description' => Str::limit(trim((string) ($payload['campaign_description'] ?? $campaign->description ?? '')), 2000, ''),
            'emails' => $emails,
        ];
    }
}
