<?php

namespace App\Modules\LeadIntelligence\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LeadIntelligenceSettings
{
    private const TYPE = 'lead_intelligence';

    private const NAME = 'settings';

    public const DEFAULT_AI_CANDIDATE_REVIEW_PROMPT = <<<'PROMPT'
You are the Nexum PSA Lead Intelligence reviewer for Norwegian B2B prospecting.

You receive only verified source data from configured discovery sources such as BRREG and public website pages.
Do not invent companies, people, roles, email addresses, phone numbers, URLs, facts, or scores.
Use only the provided evidence.

Return ONLY compact JSON with this shape:
{
  "decision": "promote|skip|review",
  "company_score": 0-100,
  "reason": "short grounded reason",
  "company_is_b2b": true,
  "contacts": [
    {
      "email": "email from evidence only",
      "decision": "promote|skip|review",
      "contact_score": 0-100,
      "role": "role from evidence or null",
      "reason": "short grounded reason"
    }
  ]
}

Promote only B2B company leads that match the segment objective and have at least one public contact email in evidence.
Skip voluntary associations, clubs, hobby groups, ideological/non-profit entities, private individuals, and entities outside the segment.
Prefer shared company emails and decision-maker contacts when evidence supports them.
Use review when evidence is ambiguous, not when data is missing.
PROMPT;

    public const DEFAULT_AI_DISCOVERY_PLANNING_PROMPT = <<<'PROMPT'
You are the Nexum PSA Lead Intelligence discovery planner for Norwegian B2B prospecting.

You do not create leads, contacts, emails, or facts. You only plan how the worker should search configured sources.
Use the segment goal prompt as the primary instruction. Use structured segment fields as stronger metadata when present. Use scan ledger context to avoid recently blocked or not-due domains when possible.

Return ONLY compact JSON with this shape:
{
  "reason": "short grounded planning note",
  "search_queries": ["Norwegian web search queries"],
  "brreg_municipalities": ["municipality names from the goal"],
  "keywords": ["source keywords"],
  "target_roles": ["roles to prioritize"],
  "seed_urls": ["source URLs only when explicitly present in the segment goal"],
  "max_candidates": 10
}

Search queries should find official company websites, contact pages, employee pages, decision makers, and shared company mailboxes.
Never include invented company names, invented people, invented email addresses, or guessed URLs.
Prefer Norwegian terms such as kontakt, ansatte, daglig leder, post, info, firmapost, kommune, bedrift, and the municipalities from the objective.
PROMPT;

    public const DEFAULT_ALLOWED_ROLES = [
        'daglig leder',
        'innkjøp',
        'IT',
        'økonomi',
        'kontorleder',
        'marked/salg',
    ];

    public const DEFAULTS = [
        'enabled' => false,
        'auto_create_clients' => false,
        'default_client_status' => 'lead_candidate',
        'auto_create_contacts' => false,
        'auto_add_to_marketing_lists' => false,
        'allow_generic_company_emails' => false,
        'allow_role_based_emails' => false,
        'allow_named_work_emails' => false,
        'never_auto_use_private_email_domains' => true,
        'allowed_roles' => self::DEFAULT_ALLOWED_ROLES,
        'default_rescan_days' => 90,
        'max_pages_per_domain' => 10,
        'max_tokens_per_run' => 20000,
        'max_new_leads_per_run' => 25,
        'require_source_url_for_contacts' => true,
        'require_role_for_named_contacts' => true,
        'minimum_company_score' => 60,
        'minimum_contact_score' => 60,
        'ai_discovery_planning_enabled' => true,
        'ai_discovery_planning_required' => false,
        'ai_discovery_planning_prompt' => self::DEFAULT_AI_DISCOVERY_PLANNING_PROMPT,
        'ai_candidate_review_enabled' => true,
        'ai_candidate_review_required' => false,
        'ai_candidate_review_prompt' => self::DEFAULT_AI_CANDIDATE_REVIEW_PROMPT,
        'discovery_sources' => ['brreg'],
        'brreg_base_url' => 'https://data.brreg.no/enhetsregisteret/api',
        'web_search_enabled' => false,
        'web_search_provider' => 'ai_provider',
        'web_search_endpoint_url' => null,
        'web_search_results_per_query' => 10,
    ];

    public function get(): array
    {
        $payload = [];

        if ($this->settingsTableExists()) {
            $setting = CommonSetting::query()
                ->where('type', self::TYPE)
                ->where('name', self::NAME)
                ->first();

            $payload = json_decode($setting?->json ?: '[]', true) ?: [];
        }

        return $this->normalize($payload);
    }

    public function update(array $payload): array
    {
        $settings = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Lead Intelligence automation, prospecting, and marketing eligibility policy.',
                'value' => $settings['enabled'] ? 'enabled' : 'disabled',
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function normalize(array $payload): array
    {
        $settings = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        if (! array_key_exists('web_search_provider', $payload) && filled($payload['web_search_endpoint_url'] ?? null)) {
            $settings['web_search_provider'] = 'endpoint';
        }

        foreach ([
            'enabled',
            'auto_create_clients',
            'auto_create_contacts',
            'auto_add_to_marketing_lists',
            'allow_generic_company_emails',
            'allow_role_based_emails',
            'allow_named_work_emails',
            'never_auto_use_private_email_domains',
            'require_source_url_for_contacts',
            'require_role_for_named_contacts',
            'ai_discovery_planning_enabled',
            'ai_discovery_planning_required',
            'ai_candidate_review_enabled',
            'ai_candidate_review_required',
            'web_search_enabled',
        ] as $key) {
            $settings[$key] = (bool) $settings[$key];
        }

        foreach ([
            'default_rescan_days',
            'max_pages_per_domain',
            'max_tokens_per_run',
            'max_new_leads_per_run',
            'minimum_company_score',
            'minimum_contact_score',
            'web_search_results_per_query',
        ] as $key) {
            $settings[$key] = max(0, (int) $settings[$key]);
        }

        $settings['default_rescan_days'] = max(1, $settings['default_rescan_days']);
        $settings['max_pages_per_domain'] = max(1, $settings['max_pages_per_domain']);
        $settings['max_tokens_per_run'] = max(1, $settings['max_tokens_per_run']);
        $settings['max_new_leads_per_run'] = max(1, $settings['max_new_leads_per_run']);
        $settings['minimum_company_score'] = min(100, $settings['minimum_company_score']);
        $settings['minimum_contact_score'] = min(100, $settings['minimum_contact_score']);
        $settings['web_search_results_per_query'] = max(1, min(50, $settings['web_search_results_per_query']));
        $settings['default_client_status'] = trim((string) $settings['default_client_status']) ?: self::DEFAULTS['default_client_status'];
        $settings['allowed_roles'] = $this->normalizeList($settings['allowed_roles'], self::DEFAULT_ALLOWED_ROLES);
        $settings['discovery_sources'] = $this->normalizeList($settings['discovery_sources'], ['brreg']);
        $settings['ai_discovery_planning_prompt'] = trim((string) $settings['ai_discovery_planning_prompt']) ?: self::DEFAULT_AI_DISCOVERY_PLANNING_PROMPT;
        $settings['ai_candidate_review_prompt'] = trim((string) $settings['ai_candidate_review_prompt']) ?: self::DEFAULT_AI_CANDIDATE_REVIEW_PROMPT;
        $settings['brreg_base_url'] = rtrim(trim((string) $settings['brreg_base_url']), '/') ?: self::DEFAULTS['brreg_base_url'];
        $settings['web_search_provider'] = $this->normalizeWebSearchProvider($settings['web_search_provider']);
        $webSearchEndpoint = trim((string) ($settings['web_search_endpoint_url'] ?? ''));
        $settings['web_search_endpoint_url'] = $webSearchEndpoint !== '' ? rtrim($webSearchEndpoint, '/') : null;

        return $settings;
    }

    private function normalizeWebSearchProvider(mixed $value): string
    {
        $provider = strtolower(trim((string) $value));

        return in_array($provider, ['ai_provider', 'endpoint', 'disabled'], true)
            ? $provider
            : self::DEFAULTS['web_search_provider'];
    }

    private function normalizeList(mixed $value, array $fallback): array
    {
        $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        $items = array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items ?: [],
        )));

        return $items === [] ? $fallback : array_values(array_unique($items));
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('common_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
