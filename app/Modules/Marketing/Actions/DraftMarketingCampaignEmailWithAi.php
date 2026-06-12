<?php

namespace App\Modules\Marketing\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class DraftMarketingCampaignEmailWithAi
{
    public function __construct(
        private readonly AiAgentResolver $agentResolver,
        private readonly AiChatResponder $responder,
    ) {
    }

    public function handle(User $user, MarketingCampaign $campaign, ?MarketingCampaignEmail $email, array $input): array
    {
        $agent = $this->agentResolver->defaultAgent($user, 'marketing');

        if (! $agent) {
            throw new RuntimeException('No active AI agent is available for marketing.');
        }

        if ($email && (int) $email->marketing_campaign_id !== (int) $campaign->id) {
            throw new RuntimeException('Campaign email does not belong to this campaign.');
        }

        $context = $this->context($campaign, $email, $input);
        $chat = AiChat::query()->create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Marketing email draft '.$campaign->name,
            'status' => 'closed',
            'metadata' => [
                'source' => 'marketing_campaign_email_draft',
                'marketing_campaign_id' => $campaign->id,
                'marketing_campaign_email_id' => $email?->id,
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

        return $this->sanitize($this->decodeJson($reply), $input);
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You write and edit Nexum PSA marketing campaign emails.',
            'Return ONLY compact JSON. No Markdown, no explanation.',
            'Allowed JSON keys: email_name, email_subject, body_html, body_text.',
            'Use the user prompt, campaign context, mailing list context, and current email draft.',
            'Known placeholders you may use: {{ contact_name }}, {{ client_name }}, {{ company_name }}, {{ unsubscribe_url }}.',
            'If HTML already exists, preserve useful layout, links, inline styles, and variables while improving copy.',
            'Make body_text match body_html content in plain text.',
            'Every marketing email must include an unsubscribe link using {{ unsubscribe_url }} in body_html and an Unsubscribe: {{ unsubscribe_url }} line in body_text.',
            'Do not include tracking pixels. Nexum PSA adds those later.',
            'Use the same language as the prompt or current campaign when obvious.',
            'External website fetching is not available in this slice. If the prompt includes URLs, treat them only as user-provided destination links or brand hints; do not claim that you visited or read them.',
            'Do not claim that WordPress content was pulled unless WordPress content is provided in the context.',
        ]);
    }

    private function context(MarketingCampaign $campaign, ?MarketingCampaignEmail $email, array $input): array
    {
        $campaign->loadMissing(['list.members.client']);
        $members = $campaign->list?->members ?? collect();

        return [
            'user_prompt' => $input['prompt'],
            'campaign' => [
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
            'current_email' => [
                'id' => $email?->id,
                'source_template' => $email?->sourceTemplateName(),
                'email_name' => $input['email_name'] ?? $email?->displayName(),
                'email_subject' => $input['email_subject'] ?? $email?->effectiveSubject(),
                'body_html' => Str::limit((string) ($input['body_html'] ?? $email?->effectiveBodyHtml()), 12000),
                'body_text' => Str::limit((string) ($input['body_text'] ?? $email?->effectiveBodyText()), 8000),
            ],
            'future_content_sources' => [
                'external_websites' => 'Not available in this slice. URLs in the prompt are destination links or brand hints only; do not claim that external website content was read.',
                'wordpress' => 'Not available in this slice. Do not invent WordPress post data.',
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

    private function sanitize(array $payload, array $input): array
    {
        $payload = Arr::only($payload, ['email_name', 'email_subject', 'body_html', 'body_text']);
        $html = trim((string) ($payload['body_html'] ?? $input['body_html'] ?? ''));
        $text = trim((string) ($payload['body_text'] ?? ''));

        if ($text === '' && $html !== '') {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))) ?: '');
        }

        return [
            'email_name' => Str::limit(trim((string) ($payload['email_name'] ?? $input['email_name'] ?? '')), 255, ''),
            'email_subject' => Str::limit(trim((string) ($payload['email_subject'] ?? $input['email_subject'] ?? '')), 255, ''),
            'body_html' => $this->ensureUnsubscribeHtml($html),
            'body_text' => $this->ensureUnsubscribeText($text),
        ];
    }

    private function ensureUnsubscribeHtml(string $html): string
    {
        if ($this->containsUnsubscribePlaceholder($html)) {
            return $html;
        }

        return rtrim($html).'<p style="margin-top:24px;color:#6c757d;font-size:12px;">'
            .'You can unsubscribe at any time: <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';
    }

    private function ensureUnsubscribeText(string $text): string
    {
        if ($this->containsUnsubscribePlaceholder($text)) {
            return $text;
        }

        return rtrim($text)."\n\nUnsubscribe: {{ unsubscribe_url }}";
    }

    private function containsUnsubscribePlaceholder(string $content): bool
    {
        return str_contains($content, '{{ unsubscribe_url }}') || str_contains($content, '{{unsubscribe_url}}');
    }
}
