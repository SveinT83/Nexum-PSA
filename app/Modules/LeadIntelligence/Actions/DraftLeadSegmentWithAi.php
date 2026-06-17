<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class DraftLeadSegmentWithAi
{
    private const SEGMENT_DRAFT_TIMEOUT_SECONDS = 90;

    public function __construct(
        private readonly AiAgentResolver $agentResolver,
        private readonly AiChatResponder $responder,
    ) {
    }

    public function handle(User $user, array $input, ?LeadSegment $segment = null): array
    {
        $agent = $this->agentResolver->defaultAgent($user, 'lead_intelligence');

        if (! $agent) {
            throw new RuntimeException('No active AI agent is available for Lead Intelligence.');
        }

        $context = $this->context($input, $segment);
        $chat = AiChat::query()->create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Lead segment draft '.($segment?->name ?: 'new segment'),
            'status' => 'closed',
            'metadata' => [
                'source' => 'lead_intelligence_segment_draft',
                'lead_segment_id' => $segment?->id,
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
            ], self::SEGMENT_DRAFT_TIMEOUT_SECONDS);
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

        return $this->sanitize($this->decodeJson($reply));
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You draft Nexum PSA Lead Intelligence segment settings for Norwegian B2B prospecting.',
            'Return ONLY compact JSON. No Markdown, no explanation.',
            'Allowed JSON keys: name, description, geography, industries, nace_codes, keywords, excluded_keywords, target_roles, schedule_period, schedule_weekdays, schedule_time, run_interval_days, target_new_leads_per_period, token_budget_per_period, token_budget_unlimited, max_runs_per_period.',
            'This action only drafts segment settings. Do not perform live research, enumerate real companies, or find actual contacts.',
            'description is the human goal prompt and may carry most of the objective if the structured fields are sparse.',
            'Use structured metadata fields when the prompt clearly mentions geography, industry, exclusions, target roles, cadence, weekly/daily/monthly goals, or token budget.',
            'If the user says all industries, return industries as an empty array.',
            'NACE codes are optional Norwegian industry classification codes. Only return NACE codes when the prompt clearly provides them or asks for a known code.',
            'Normalize obvious typos when safe, for example "Indrustrier" as industries.',
            'Use target role "daglig leder" for CEO/general manager intent.',
            'If the user asks for decision makers, include "beslutningstaker" and "daglig leder" in target_roles.',
            'If the user asks for employees when no decision maker is found, include "ansatt" in target_roles and preserve that fallback in description.',
            'If the user asks for shared or common company email addresses, preserve that requirement in description and include common-address keywords such as post@, info@, and firmapost@.',
            'Use schedule_period daily, weekly, or monthly.',
            'Use ISO weekdays 1-7 for schedule_weekdays when a weekday is requested.',
            'If the user asks for a weekly goal, set schedule_period weekly and target_new_leads_per_period to that goal.',
            'If the user asks for unlimited tokens until goal is met, set token_budget_unlimited true and token_budget_per_period null.',
            'Do not claim that you visited websites, queried BRREG, created leads, added contacts to marketing lists, or sent email.',
        ]);
    }

    private function context(array $input, ?LeadSegment $segment): array
    {
        return [
            'user_prompt' => $input['prompt'],
            'current_segment' => $segment ? [
                'name' => $segment->name,
                'description' => $segment->description,
                'geography' => $segment->geography_json ?: [],
                'industries' => $segment->industries_json ?: [],
                'nace_codes' => $segment->nace_codes_json ?: [],
                'keywords' => $segment->keywords_json ?: [],
                'excluded_keywords' => $segment->excluded_keywords_json ?: [],
                'target_roles' => $segment->target_roles_json ?: [],
                'schedule_period' => $segment->schedule_period,
                'schedule_weekdays' => $segment->schedule_weekdays_json ?: [],
                'schedule_time' => $segment->schedule_time,
                'run_interval_days' => $segment->run_interval_days,
                'target_new_leads_per_period' => $segment->target_new_leads_per_period,
                'token_budget_per_period' => $segment->token_budget_per_period,
                'token_budget_unlimited' => $segment->token_budget_unlimited,
                'max_runs_per_period' => $segment->max_runs_per_period,
            ] : null,
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

    private function sanitize(array $payload): array
    {
        $payload = Arr::only($payload, [
            'name',
            'description',
            'geography',
            'industries',
            'nace_codes',
            'keywords',
            'excluded_keywords',
            'target_roles',
            'schedule_period',
            'schedule_weekdays',
            'schedule_time',
            'run_interval_days',
            'target_new_leads_per_period',
            'token_budget_per_period',
            'token_budget_unlimited',
            'max_runs_per_period',
        ]);

        foreach (['geography', 'industries', 'nace_codes', 'keywords', 'excluded_keywords', 'target_roles'] as $key) {
            $payload[$key] = $this->stringList($payload[$key] ?? []);
        }

        $payload['schedule_weekdays'] = collect((array) ($payload['schedule_weekdays'] ?? []))
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->values()
            ->all();
        $payload['name'] = Str::limit(trim((string) ($payload['name'] ?? '')), 255, '');
        $payload['description'] = Str::limit(trim((string) ($payload['description'] ?? '')), 5000, '');
        $payload['schedule_period'] = in_array($payload['schedule_period'] ?? '', array_keys(LeadSegment::SCHEDULE_PERIODS), true)
            ? $payload['schedule_period']
            : 'weekly';
        $payload['schedule_time'] = preg_match('/^\d{2}:\d{2}$/', (string) ($payload['schedule_time'] ?? ''))
            ? $payload['schedule_time']
            : '08:00';
        $payload['run_interval_days'] = max(1, min(365, (int) ($payload['run_interval_days'] ?? 1)));
        $payload['target_new_leads_per_period'] = $this->nullablePositiveInt($payload['target_new_leads_per_period'] ?? null);
        $payload['token_budget_unlimited'] = (bool) ($payload['token_budget_unlimited'] ?? false);
        $payload['token_budget_per_period'] = $payload['token_budget_unlimited']
            ? null
            : $this->nullablePositiveInt($payload['token_budget_per_period'] ?? null);
        $payload['max_runs_per_period'] = $this->nullablePositiveInt($payload['max_runs_per_period'] ?? null);

        return $payload;
    }

    private function stringList(mixed $items): array
    {
        return collect(is_array($items) ? $items : preg_split('/[\r\n,]+/', (string) $items))
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }
}
