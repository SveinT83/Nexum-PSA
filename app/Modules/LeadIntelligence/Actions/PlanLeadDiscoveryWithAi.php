<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadScanLedger;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Support\Str;

class PlanLeadDiscoveryWithAi
{
    private const TIMEOUT_SECONDS = 120;

    public function __construct(private readonly AiChatResponder $responder)
    {
    }

    public function handle(LeadSegment $segment, LeadResearchRun $run, array $settings): array
    {
        if (! (bool) ($settings['ai_discovery_planning_enabled'] ?? false)) {
            return $this->fallback('disabled', 'AI discovery planning is disabled.', $segment);
        }

        $agent = $this->agent();

        if (! $agent) {
            return $this->fallback('unavailable', 'No active Lead Intelligence AI agent is available.', $segment, $settings);
        }

        try {
            $reply = $this->responder->complete($agent, [
                ['role' => 'system', 'content' => $this->systemPrompt($settings)],
                ['role' => 'user', 'content' => json_encode($this->context($segment, $run, $settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ], self::TIMEOUT_SECONDS);

            $plan = $this->sanitize($this->decodeJson($reply), $segment, $settings);
            $plan['used_ai'] = true;
            $plan['status'] = 'planned';
            $plan['reason'] = $plan['reason'] ?: 'AI generated the discovery plan from the segment objective.';
            $plan['raw_reply'] = Str::limit($reply, 5000, '');

            return $plan;
        } catch (\Throwable $exception) {
            return $this->fallback('error', 'AI discovery planning failed: '.$exception->getMessage(), $segment, $settings);
        }
    }

    private function agent(): ?AiAgent
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->first(fn (AiAgent $agent): bool => in_array('lead_intelligence', $agent->default_domains ?? [], true));
    }

    private function systemPrompt(array $settings): string
    {
        return trim((string) ($settings['ai_discovery_planning_prompt'] ?? ''));
    }

    private function context(LeadSegment $segment, LeadResearchRun $run, array $settings): array
    {
        return [
            'run_id' => $run->id,
            'segment' => [
                'name' => $segment->name,
                'goal_prompt' => $segment->description,
                'geography' => $segment->geography_json ?: [],
                'industries' => $segment->industries_json ?: [],
                'nace_codes' => $segment->nace_codes_json ?: [],
                'keywords' => $segment->keywords_json ?: [],
                'excluded_keywords' => $segment->excluded_keywords_json ?: [],
                'target_roles' => $segment->target_roles_json ?: [],
                'marketing_list_ids' => $segment->marketing_list_ids_json ?: [],
                'target_new_leads_per_period' => $segment->target_new_leads_per_period,
                'token_budget_unlimited' => $segment->token_budget_unlimited,
                'token_budget_per_period' => $segment->token_budget_per_period,
            ],
            'available_sources' => $settings['discovery_sources'] ?? [],
            'scan_ledger' => $this->scanLedgerContext(),
            'limits' => [
                'max_new_leads_per_run' => $settings['max_new_leads_per_run'] ?? null,
                'max_pages_per_domain' => $settings['max_pages_per_domain'] ?? null,
                'web_search_enabled' => $settings['web_search_enabled'] ?? false,
                'web_search_provider' => $settings['web_search_provider'] ?? null,
                'web_search_results_per_query' => $settings['web_search_results_per_query'] ?? null,
            ],
            'policy' => [
                'allowed_roles' => $settings['allowed_roles'] ?? [],
                'allow_generic_company_emails' => $settings['allow_generic_company_emails'] ?? false,
                'allow_role_based_emails' => $settings['allow_role_based_emails'] ?? false,
                'allow_named_work_emails' => $settings['allow_named_work_emails'] ?? false,
                'require_source_url_for_contacts' => $settings['require_source_url_for_contacts'] ?? true,
                'require_role_for_named_contacts' => $settings['require_role_for_named_contacts'] ?? true,
            ],
        ];
    }

    private function scanLedgerContext(): array
    {
        $dueQuery = LeadScanLedger::query()
            ->where(function ($query): void {
                $query->whereNull('next_scan_after')
                    ->orWhere('next_scan_after', '<=', now());
            });

        return [
            'due_count' => (clone $dueQuery)->count(),
            'blocked_until_future_count' => LeadScanLedger::query()
                ->whereNotNull('next_scan_after')
                ->where('next_scan_after', '>', now())
                ->count(),
            'recently_scanned_count' => LeadScanLedger::query()
                ->where('last_scanned_at', '>=', now()->subDays(30))
                ->count(),
            'due_sample' => (clone $dueQuery)
                ->orderByDesc('last_scanned_at')
                ->limit(20)
                ->get(['domain', 'org_no', 'url', 'status', 'last_scanned_at'])
                ->map(fn (LeadScanLedger $ledger): array => [
                    'domain' => $ledger->domain,
                    'org_no' => $ledger->org_no,
                    'url' => $ledger->url,
                    'status' => $ledger->status,
                    'last_scanned_at' => $ledger->last_scanned_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
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
            throw new \RuntimeException('AI did not return valid JSON content.');
        }

        return $decoded;
    }

    private function sanitize(array $payload, LeadSegment $segment, array $settings): array
    {
        $queries = $this->limitedList($payload['search_queries'] ?? [], 10, 180);
        $urls = collect((array) ($payload['seed_urls'] ?? []))
            ->map(fn (mixed $url): ?string => $this->normalizeUrl($url))
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        return [
            'used_ai' => false,
            'status' => 'planned',
            'reason' => Str::limit(trim((string) ($payload['reason'] ?? '')), 500, ''),
            'search_queries' => $queries ?: $this->fallbackQueries($segment),
            'brreg_municipalities' => $this->limitedList($payload['brreg_municipalities'] ?? ($segment->geography_json ?: []), 20, 100),
            'keywords' => $this->limitedList($payload['keywords'] ?? ($segment->keywords_json ?: []), 20, 100),
            'target_roles' => $this->limitedList($payload['target_roles'] ?? ($segment->target_roles_json ?: []), 20, 100),
            'seed_urls' => $urls,
            'max_candidates' => max(1, min((int) ($payload['max_candidates'] ?? $settings['max_new_leads_per_run'] ?? 25), (int) ($settings['max_new_leads_per_run'] ?? 25))),
        ];
    }

    private function fallback(string $status, string $reason, LeadSegment $segment, array $settings = []): array
    {
        $required = (bool) ($settings['ai_discovery_planning_required'] ?? false);

        return [
            'used_ai' => false,
            'status' => $status,
            'decision' => $required ? 'stop' : 'fallback',
            'reason' => $reason,
            'search_queries' => $this->fallbackQueries($segment),
            'brreg_municipalities' => (array) ($segment->geography_json ?: []),
            'keywords' => (array) ($segment->keywords_json ?: []),
            'target_roles' => (array) ($segment->target_roles_json ?: []),
            'seed_urls' => [],
            'max_candidates' => max(1, (int) ($segment->target_new_leads_per_period ?: $settings['max_new_leads_per_run'] ?? 25)),
        ];
    }

    private function fallbackQueries(LeadSegment $segment): array
    {
        $parts = array_values(array_filter([
            $segment->description,
            implode(' ', (array) ($segment->geography_json ?: [])),
            implode(' ', (array) ($segment->industries_json ?: [])),
            implode(' ', (array) ($segment->keywords_json ?: [])),
            implode(' ', (array) ($segment->target_roles_json ?: [])),
            'kontakt bedrift Norge',
        ]));

        return [Str::limit(trim(implode(' ', $parts)), 180, '')];
    }

    private function limitedList(mixed $value, int $limit, int $length): array
    {
        return collect((array) $value)
            ->map(fn (mixed $item): string => Str::limit(trim((string) $item), $length, ''))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        $url = Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url;

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }
}
