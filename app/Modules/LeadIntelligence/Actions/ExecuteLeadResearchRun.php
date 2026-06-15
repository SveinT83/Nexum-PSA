<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Models\Clients\Client;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadScanLedger;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExecuteLeadResearchRun
{
    private const BRREG_BASE_URL = 'https://data.brreg.no/enhetsregisteret/api';

    private const DEFAULT_ALLOWED_ORG_FORMS = [
        'AS',
        'ASA',
        'ENK',
        'ANS',
        'DA',
        'NUF',
        'SA',
        'BA',
        'KS',
        'IKS',
        'KF',
        'FKF',
        'SF',
    ];

    private const DEFAULT_EXCLUDED_ORG_FORMS = [
        'FLI',
        'STI',
        'ESEK',
        'BRL',
        'KIRK',
        'ORGL',
    ];

    private const DEFAULT_EXCLUDED_SECTOR_CODES = [
        '7000',
    ];

    private const FALLBACK_MUNICIPALITIES = [
        'steinkjer' => '5006',
        'snasa' => '5041',
        'snåsa' => '5041',
        'inderoy' => '5053',
        'inderøy' => '5053',
    ];

    public function __construct(
        private readonly LeadIntelligenceSettings $settings,
        private readonly PromoteLeadCandidate $promoteLeadCandidate,
        private readonly LeadMarketingEligibilityEvaluator $eligibilityEvaluator,
        private readonly PlanLeadDiscoveryWithAi $discoveryPlanner,
        private readonly ReviewLeadCandidateWithAi $aiReviewer,
        private readonly SearchLeadWebWithAi $aiWebSearch,
    ) {
    }

    public function handle(LeadResearchRun $run): LeadResearchRun
    {
        $run->load('segment');
        $segment = $run->segment;
        $settings = $this->settings->get();
        $startedAt = now();

        $summary = $this->baseSummary($segment, $settings);

        if ($run->status !== LeadResearchRun::STATUS_QUEUED) {
            return $run->fresh(['segment', 'evidence']);
        }

        $claimed = LeadResearchRun::query()
            ->whereKey($run->id)
            ->where('status', LeadResearchRun::STATUS_QUEUED)
            ->update([
                'status' => LeadResearchRun::STATUS_RUNNING,
                'started_at' => $startedAt,
                'finished_at' => null,
                'summary_json' => $summary,
                'tokens_used' => 0,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $run->fresh(['segment', 'evidence']);
        }

        $run = $run->fresh(['segment']);
        $segment = $run->segment;

        $run->forceFill([
            'status' => LeadResearchRun::STATUS_RUNNING,
            'started_at' => $startedAt,
            'finished_at' => null,
            'summary_json' => $summary,
            'tokens_used' => 0,
        ])->save();

        if (! $segment) {
            return $this->fail($run, $summary, 'Research run must belong to a lead segment.');
        }

        if (! $settings['enabled']) {
            return $this->fail($run, $summary, 'Lead Intelligence is disabled in settings.');
        }

        try {
            $sources = $this->discoverySources($settings);
            $targetNewLeads = $this->targetNewLeads($segment, $settings);
            $targetMetric = $this->targetMetric($segment, $settings);
            $summary['target_new_leads'] = $targetNewLeads;
            $summary['target_metric'] = $targetMetric;
            $summary['remaining_new_leads'] = $targetNewLeads;
            $summary['remaining_target'] = $targetNewLeads;
            $discoveryPlan = $this->discoveryPlanner->handle($segment, $run, $settings);
            $summary['ai_discovery_plan'] = Arr::except($discoveryPlan, ['raw_reply']);
            $this->persistProgress($run, $summary);

            if (($discoveryPlan['decision'] ?? null) === 'stop') {
                return $this->fail($run, $summary, $discoveryPlan['reason'] ?? 'AI discovery planning is required but unavailable.');
            }

            $municipalities = $this->municipalitiesForSegment($segment, $settings, $discoveryPlan);

            if ($municipalities === [] && ! $this->webSearchConfigured($settings) && empty($discoveryPlan['seed_urls'])) {
                $summary['skipped'][] = [
                    'reason' => 'No supported municipality was found from the segment geography.',
                    'geography' => $segment->geography_json ?: [],
                ];

                return $this->complete($run, $summary);
            }

            foreach ($municipalities as $municipality) {
                if ($this->targetReached($summary, $targetNewLeads)) {
                    break;
                }

                $units = in_array('brreg', $sources, true)
                    ? $this->fetchBrregUnits($municipality['number'], $segment, $targetNewLeads, $settings)
                    : [];

                foreach ($units as $unit) {
                    if ($this->targetReached($summary, $targetNewLeads)) {
                        break;
                    }

                    $summary['companies_seen']++;

                    $skipReason = $this->unitSkipReason($unit, $segment);

                    if ($skipReason) {
                        $summary['companies_skipped']++;
                        $summary['skipped'][] = [
                            'reason' => $skipReason,
                            'org_no' => $unit['organisasjonsnummer'] ?? null,
                            'name' => $unit['navn'] ?? null,
                            'org_form' => data_get($unit, 'organisasjonsform.kode'),
                        ];
                        $this->persistProgress($run, $summary);

                        continue;
                    }

                    $ledger = $this->ledgerForUnit($unit);

                    if ($ledger->exists && $ledger->next_scan_after && $ledger->next_scan_after->isFuture()) {
                        $summary['companies_skipped']++;
                        $summary['skipped'][] = [
                            'reason' => 'Scan ledger says this company is not due yet.',
                            'org_no' => $unit['organisasjonsnummer'] ?? null,
                            'name' => $unit['navn'] ?? null,
                            'next_scan_after' => $ledger->next_scan_after->toDateTimeString(),
                        ];
                        $this->persistProgress($run, $summary);

                        continue;
                    }

                    $candidate = $this->candidateFromBrregUnit($unit, $segment, $settings);

                    if ($this->skipExistingClientCandidate($run, $ledger, $candidate, ['contacts' => [], 'shared_email' => null, 'pages_scanned' => 0, 'urls' => []], $summary, [
                        'unit' => $unit,
                        'org_no' => $unit['organisasjonsnummer'] ?? null,
                        'name' => $unit['navn'] ?? null,
                        'org_form' => data_get($unit, 'organisasjonsform.kode'),
                    ])) {
                        continue;
                    }

                    $websiteDiscovery = $this->discoverWebsiteContacts($candidate['company']['website'] ?? null, $settings);
                    $this->mergeWebsiteDiscoveryIntoCandidate($candidate, $websiteDiscovery);

                    if (! $this->candidateHasContactEvidence($candidate)) {
                        $webSearchDiscovery = $this->discoverCandidateWebsiteWithWebSearch($unit, $candidate, $segment, $settings, $summary);
                        $websiteDiscovery = $this->mergeWebsiteDiscoveries($websiteDiscovery, $webSearchDiscovery);
                        $this->mergeWebsiteDiscoveryIntoCandidate($candidate, $webSearchDiscovery);
                    }

                    if (! $this->candidateHasContactEvidence($candidate)) {
                        $summary['companies_skipped']++;
                        $summary['skipped'][] = [
                            'reason' => 'No public contact email found. Client was not created because this run targets contactable leads.',
                            'org_no' => $unit['organisasjonsnummer'] ?? null,
                            'name' => $unit['navn'] ?? null,
                            'org_form' => data_get($unit, 'organisasjonsform.kode'),
                        ];

                        $this->updateLedger($ledger, $unit, $candidate, $websiteDiscovery, 'no_contact_email');
                        $this->persistProgress($run, $summary);

                        continue;
                    }

                    $this->reviewAndPromoteCandidate($run, $segment, $settings, $ledger, $candidate, $websiteDiscovery, $summary, [
                        'unit' => $unit,
                        'org_no' => $unit['organisasjonsnummer'] ?? null,
                        'name' => $unit['navn'] ?? null,
                        'org_form' => data_get($unit, 'organisasjonsform.kode'),
                    ]);
                }
            }

            if (! $this->targetReached($summary, $targetNewLeads)) {
                $this->processWebSearchPlan($run, $segment, $settings, $discoveryPlan, $targetNewLeads, $summary);
            }

            return $this->complete($run, $summary);
        } catch (Throwable $exception) {
            return $this->fail($run, $summary, $exception->getMessage());
        }
    }

    private function baseSummary(?LeadSegment $segment, array $settings): array
    {
        return [
            'execution_engine' => 'ai_led_discovery_worker',
            'source' => 'AI-planned Lead Intelligence discovery using configured source adapters.',
            'goal_prompt' => $segment?->description,
            'segment_filters' => [
                'geography' => $segment?->geography_json ?: [],
                'industries' => $segment?->industries_json ?: [],
                'nace_codes' => $segment?->nace_codes_json ?: [],
                'keywords' => $segment?->keywords_json ?: [],
                'excluded_keywords' => $segment?->excluded_keywords_json ?: [],
                'target_roles' => $segment?->target_roles_json ?: [],
            ],
            'limits' => [
                'max_new_leads_per_run' => $settings['max_new_leads_per_run'],
                'max_pages_per_domain' => $settings['max_pages_per_domain'],
            ],
            'companies_seen' => 0,
            'companies_promoted' => 0,
            'companies_skipped' => 0,
            'new_leads_created' => 0,
            'existing_clients_skipped' => 0,
            'existing_clients_updated' => 0,
            'contacts_promoted' => 0,
            'marketing_members_created' => 0,
            'target_metric' => 'new_leads_created',
            'target_progress' => 0,
            'ai_discovery_plan' => null,
            'ai_reviewed' => 0,
            'ai_review_fallbacks' => 0,
            'ai_reviews' => [],
            'web_search_results_seen' => 0,
            'skipped' => [],
            'errors' => [],
        ];
    }

    private function complete(LeadResearchRun $run, array $summary): LeadResearchRun
    {
        $summary = $this->withTargetStatus($summary, true);

        $run->forceFill([
            'status' => LeadResearchRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'summary_json' => $summary,
            'tokens_used' => 0,
        ])->save();

        $run->segment?->forceFill(['last_run_at' => now()])->save();

        return $run->fresh(['segment', 'evidence']);
    }

    private function fail(LeadResearchRun $run, array $summary, string $message): LeadResearchRun
    {
        $summary['errors'][] = $message;
        $summary = $this->withTargetStatus($summary, true, 'failed');

        $run->forceFill([
            'status' => LeadResearchRun::STATUS_FAILED,
            'finished_at' => now(),
            'summary_json' => $summary,
            'tokens_used' => 0,
        ])->save();

        return $run->fresh(['segment', 'evidence']);
    }

    private function persistProgress(LeadResearchRun $run, array &$summary): void
    {
        $summary = $this->withTargetStatus($summary, false);

        $run->forceFill([
            'summary_json' => $summary,
            'tokens_used' => (int) ($summary['tokens_used'] ?? 0),
        ])->save();
    }

    private function withTargetStatus(array $summary, bool $finished, ?string $forcedCompletionReason = null): array
    {
        $target = (int) ($summary['target_new_leads'] ?? 0);

        if ($target > 0) {
            $metric = (string) ($summary['target_metric'] ?? 'new_leads_created');
            $progress = (int) ($summary[$metric] ?? 0);
            $summary['target_progress'] = $progress;
            $summary['remaining_new_leads'] = max(0, $target - $progress);
            $summary['remaining_target'] = max(0, $target - $progress);
            $summary['target_reached'] = $progress >= $target;

            if ($finished) {
                $summary['completion_reason'] = $forcedCompletionReason
                    ?: ($summary['target_reached'] ? 'target_reached' : 'sources_exhausted_before_target');
            }
        }

        return $summary;
    }

    private function targetNewLeads(LeadSegment $segment, array $settings): int
    {
        $target = (int) ($segment->target_new_leads_per_period ?: $settings['max_new_leads_per_run']);

        return max(1, min($target, (int) $settings['max_new_leads_per_run']));
    }

    private function targetMetric(LeadSegment $segment, array $settings): string
    {
        $marketingListIds = collect((array) ($segment->marketing_list_ids_json ?: []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->all();

        if ((bool) ($settings['auto_add_to_marketing_lists'] ?? false) && $marketingListIds !== []) {
            return 'marketing_members_created';
        }

        return 'new_leads_created';
    }

    private function targetReached(array $summary, int $target): bool
    {
        if ($target <= 0) {
            return false;
        }

        $metric = (string) ($summary['target_metric'] ?? 'new_leads_created');

        return (int) ($summary[$metric] ?? 0) >= $target;
    }

    private function municipalitiesForSegment(LeadSegment $segment, array $settings, array $discoveryPlan = []): array
    {
        $terms = array_values(array_filter(array_map('trim', array_merge(
            (array) ($segment->geography_json ?: []),
            (array) ($discoveryPlan['brreg_municipalities'] ?? []),
        ))));

        if ($terms === []) {
            return [];
        }

        $registry = $this->municipalityRegistry($settings);
        $matched = [];

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalizeMunicipalityTerm($term);

            foreach ($registry as $municipality) {
                $normalizedName = $this->normalizeMunicipalityTerm($municipality['name']);

                if ($normalizedTerm === $normalizedName || str_contains($normalizedTerm, $normalizedName) || str_contains($normalizedName, $normalizedTerm)) {
                    $matched[$municipality['number']] = $municipality;
                }
            }

            if (isset(self::FALLBACK_MUNICIPALITIES[$normalizedTerm])) {
                $number = self::FALLBACK_MUNICIPALITIES[$normalizedTerm];
                $matched[$number] ??= ['number' => $number, 'name' => $term];
            }
        }

        return array_values($matched);
    }

    private function municipalityRegistry(array $settings): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get($this->brregBaseUrl($settings).'/kommuner', ['size' => 500])
                ->throw();

            return collect(data_get($response->json(), '_embedded.kommuner', []))
                ->map(fn (array $municipality): array => [
                    'number' => (string) ($municipality['nummer'] ?? ''),
                    'name' => (string) ($municipality['navn'] ?? ''),
                ])
                ->filter(fn (array $municipality): bool => $municipality['number'] !== '' && $municipality['name'] !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return collect(self::FALLBACK_MUNICIPALITIES)
                ->map(fn (string $number, string $name): array => ['number' => $number, 'name' => $name])
                ->values()
                ->all();
        }
    }

    private function fetchBrregUnits(string $municipalityNumber, LeadSegment $segment, int $targetNewLeads, array $settings): array
    {
        $size = 50;
        $maxPages = max(1, min(10, $targetNewLeads * 2));
        $units = collect();

        for ($page = 0; $page < $maxPages; $page++) {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get($this->brregBaseUrl($settings).'/enheter', [
                    'kommunenummer' => $municipalityNumber,
                    'page' => $page,
                    'size' => $size,
                ])
                ->throw();

            $payload = $response->json();
            $units = $units->merge(data_get($payload, '_embedded.enheter', []));
            $totalPages = (int) data_get($payload, 'page.totalPages', $page + 1);

            if ($page + 1 >= $totalPages) {
                break;
            }
        }

        return $units
            ->filter(fn (array $unit): bool => ! (bool) ($unit['konkurs'] ?? false))
            ->filter(fn (array $unit): bool => ! (bool) ($unit['underAvvikling'] ?? false))
            ->filter(fn (array $unit): bool => ! (bool) ($unit['underTvangsavviklingEllerTvangsopplosning'] ?? false))
            ->unique(fn (array $unit): string => (string) ($unit['organisasjonsnummer'] ?? spl_object_id((object) $unit)))
            ->values()
            ->all();
    }

    private function unitSkipReason(array $unit, LeadSegment $segment): ?string
    {
        if (! $this->isBusinessEntity($unit, $segment)) {
            $orgForm = trim((string) data_get($unit, 'organisasjonsform.kode'));
            $description = trim((string) data_get($unit, 'organisasjonsform.beskrivelse'));

            return trim('BRREG unit is outside B2B company discovery'.($orgForm ? ': '.$orgForm : '').($description ? ' '.$description : '').'.');
        }

        $haystack = $this->unitSearchText($unit);
        $excluded = $this->normalizedList($segment->excluded_keywords_json);

        foreach ($excluded as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return 'Company matched an excluded keyword.';
            }
        }

        foreach ([
            $this->normalizedList($segment->keywords_json),
            $this->normalizedList($segment->industries_json),
        ] as $requiredGroup) {
            if ($requiredGroup !== [] && ! collect($requiredGroup)->contains(fn (string $needle): bool => $needle !== '' && str_contains($haystack, $needle))) {
                return 'Company did not match required segment keywords or industries.';
            }
        }

        $naceCodes = array_values(array_filter(array_map(
            fn (mixed $value): string => preg_replace('/\D+/', '', (string) $value) ?: '',
            (array) $segment->nace_codes_json,
        )));

        if ($naceCodes !== []) {
            $unitNace = preg_replace('/\D+/', '', (string) data_get($unit, 'naeringskode1.kode')) ?: '';

            if (! collect($naceCodes)->contains(fn (string $code): bool => $code !== '' && Str::startsWith($unitNace, $code))) {
                return 'Company did not match required NACE codes.';
            }
        }

        return null;
    }

    private function isBusinessEntity(array $unit, LeadSegment $segment): bool
    {
        $settings = (array) $segment->settings_json;
        $allowedOrgForms = $this->normalizedCodeList($settings['allowed_org_forms'] ?? self::DEFAULT_ALLOWED_ORG_FORMS);
        $excludedOrgForms = $this->normalizedCodeList($settings['excluded_org_forms'] ?? self::DEFAULT_EXCLUDED_ORG_FORMS);
        $excludedSectorCodes = $this->normalizedCodeList($settings['excluded_sector_codes'] ?? self::DEFAULT_EXCLUDED_SECTOR_CODES);
        $orgForm = Str::upper(trim((string) data_get($unit, 'organisasjonsform.kode')));
        $sectorCode = trim((string) data_get($unit, 'institusjonellSektorkode.kode'));

        if ($orgForm === '') {
            return false;
        }

        if (in_array($orgForm, $excludedOrgForms, true)) {
            return false;
        }

        if ($sectorCode !== '' && in_array($sectorCode, $excludedSectorCodes, true)) {
            return false;
        }

        return in_array($orgForm, $allowedOrgForms, true);
    }

    private function candidateFromBrregUnit(array $unit, LeadSegment $segment, array $settings): array
    {
        $orgNo = preg_replace('/\D+/', '', (string) ($unit['organisasjonsnummer'] ?? '')) ?: null;
        $sourceUrl = data_get($unit, '_links.self.href') ?: ($orgNo ? $this->brregBaseUrl($settings).'/enheter/'.$orgNo : null);
        $website = $this->normalizeUrl($unit['hjemmeside'] ?? null);
        $email = $this->normalizeEmail($unit['epostadresse'] ?? null);
        $excerpt = $this->companyExcerpt($unit);
        $company = [
            'name' => trim((string) ($unit['navn'] ?? '')),
            'org_no' => $orgNo,
            'website' => $website,
            'source_type' => 'brreg',
            'source_url' => $sourceUrl,
            'source_title' => 'Enhetsregisteret',
            'excerpt' => $excerpt,
            'score' => 85,
            'confidence' => 85,
        ];
        $contacts = [];

        if ($email) {
            $emailType = $this->eligibilityEvaluator->classifyEmail($email);

            if (in_array($emailType, ['generic_company', 'role_based'], true)) {
                $company['shared_email'] = $email;
            } else {
                $contacts[] = [
                    'name' => $this->nameFromEmail($email),
                    'email' => $email,
                    'role' => null,
                    'source_type' => 'brreg',
                    'source_url' => $sourceUrl,
                    'source_title' => 'Enhetsregisteret',
                    'excerpt' => $excerpt,
                    'score' => 70,
                    'confidence' => 70,
                ];
            }
        }

        return [
            'company' => $company,
            'contacts' => $contacts,
            'segment' => [
                'target_roles' => $segment->target_roles_json ?: [],
            ],
        ];
    }

    private function candidateHasContactEvidence(array $candidate): bool
    {
        if ($this->normalizeEmail(data_get($candidate, 'company.shared_email'))) {
            return true;
        }

        foreach ((array) ($candidate['contacts'] ?? []) as $contact) {
            if ($this->normalizeEmail($contact['email'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function discoverySources(array $settings): array
    {
        return collect((array) ($settings['discovery_sources'] ?? ['brreg']))
            ->map(fn (mixed $source): string => Str::lower(trim((string) $source)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function webSearchConfigured(array $settings): bool
    {
        if (! (bool) ($settings['web_search_enabled'] ?? false)) {
            return false;
        }

        return match ($this->webSearchProvider($settings)) {
            'ai_provider' => true,
            'endpoint' => filled($settings['web_search_endpoint_url'] ?? null),
            default => false,
        };
    }

    private function webSearchProvider(array $settings): string
    {
        $provider = Str::lower(trim((string) ($settings['web_search_provider'] ?? 'ai_provider')));

        return in_array($provider, ['ai_provider', 'endpoint', 'disabled'], true) ? $provider : 'ai_provider';
    }

    private function processWebSearchPlan(
        LeadResearchRun $run,
        LeadSegment $segment,
        array $settings,
        array $discoveryPlan,
        int $targetNewLeads,
        array &$summary,
    ): void {
        $results = $this->fetchWebSearchResults($discoveryPlan, $settings, $summary);

        foreach ($results as $result) {
            if ($this->targetReached($summary, $targetNewLeads)) {
                break;
            }

            $url = $this->normalizeUrl($result['url'] ?? null);

            if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $summary['companies_seen']++;
            $summary['web_search_results_seen']++;
            $ledger = $this->ledgerForUrl($url);

            if ($ledger->exists && $ledger->next_scan_after && $ledger->next_scan_after->isFuture()) {
                $summary['companies_skipped']++;
                $summary['skipped'][] = [
                    'reason' => 'Scan ledger says this web result is not due yet.',
                    'url' => $url,
                    'next_scan_after' => $ledger->next_scan_after->toDateTimeString(),
                ];
                $this->persistProgress($run, $summary);

                continue;
            }

            $candidate = $this->candidateFromWebSearchResult($result, ['contacts' => [], 'shared_email' => null], $segment);

            if ($this->skipExistingClientCandidate($run, $ledger, $candidate, ['contacts' => [], 'shared_email' => null, 'pages_scanned' => 0, 'urls' => []], $summary, [
                'unit' => [],
                'org_no' => null,
                'name' => $candidate['company']['name'] ?? null,
                'org_form' => null,
                'url' => $url,
            ])) {
                continue;
            }

            $websiteDiscovery = $this->discoverWebsiteContacts($url, $settings);
            $candidate = $this->candidateFromWebSearchResult($result, $websiteDiscovery, $segment);

            if (! $this->candidateHasContactEvidence($candidate)) {
                $summary['companies_skipped']++;
                $summary['skipped'][] = [
                    'reason' => 'No public contact email found on web-search result. Client was not created.',
                    'url' => $url,
                    'name' => $candidate['company']['name'] ?? null,
                ];

                $this->updateLedger($ledger, [], $candidate, $websiteDiscovery, 'no_contact_email');
                $this->persistProgress($run, $summary);

                continue;
            }

            $this->reviewAndPromoteCandidate($run, $segment, $settings, $ledger, $candidate, $websiteDiscovery, $summary, [
                'unit' => [],
                'org_no' => null,
                'name' => $candidate['company']['name'] ?? null,
                'org_form' => null,
                'url' => $url,
            ]);
        }
    }

    private function fetchWebSearchResults(array $discoveryPlan, array $settings, array &$summary): array
    {
        $results = collect((array) ($discoveryPlan['seed_urls'] ?? []))
            ->map(fn (mixed $url): ?array => ($normalized = $this->normalizeUrl($url)) ? [
                'title' => parse_url($normalized, PHP_URL_HOST) ?: $normalized,
                'url' => $normalized,
                'snippet' => 'Seed URL from AI discovery plan.',
                'source' => 'seed_url',
            ] : null)
            ->filter()
            ->values();

        if (! $this->webSearchConfigured($settings)) {
            return $results
                ->unique('url')
                ->values()
                ->all();
        }

        $limit = max(1, min(50, (int) ($settings['web_search_results_per_query'] ?? 10)));

        foreach ((array) ($discoveryPlan['search_queries'] ?? []) as $query) {
            $query = trim((string) $query);

            if ($query === '') {
                continue;
            }

            foreach ($this->fetchWebSearchResultsForQuery($query, $settings, $summary, $limit, [
                'discovery_plan' => Arr::only($discoveryPlan, ['reason', 'keywords', 'target_roles', 'max_candidates']),
            ]) as $result) {
                $results->push($result);
            }
        }

        return $results
            ->unique('url')
            ->values()
            ->take(max(1, $limit * max(1, count((array) ($discoveryPlan['search_queries'] ?? [])))))
            ->all();
    }

    private function fetchWebSearchResultsForQuery(string $query, array $settings, array &$summary, ?int $limit = null, array $context = []): array
    {
        if (! $this->webSearchConfigured($settings)) {
            return [];
        }

        $limit = max(1, min(50, $limit ?: (int) ($settings['web_search_results_per_query'] ?? 10)));

        try {
            return match ($this->webSearchProvider($settings)) {
                'ai_provider' => $this->aiWebSearch->search($query, $limit, $context),
                'endpoint' => $this->fetchEndpointWebSearchResults($query, $settings, $limit),
                default => [],
            };
        } catch (Throwable $exception) {
            $summary['errors'][] = 'Web-search adapter failed for query "'.Str::limit($query, 120, '').'": '.$exception->getMessage();

            return [];
        }
    }

    private function fetchEndpointWebSearchResults(string $query, array $settings, int $limit): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->get((string) $settings['web_search_endpoint_url'], [
                'q' => $query,
                'limit' => $limit,
            ])
            ->throw();

        $payload = $response->json();
        $items = data_get($payload, 'results', is_array($payload) ? $payload : []);

        return collect((array) $items)
            ->map(function (array $item) use ($query): ?array {
                $url = $this->normalizeUrl($item['url'] ?? $item['link'] ?? null);

                if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
                    return null;
                }

                return [
                    'title' => Str::limit(trim((string) ($item['title'] ?? parse_url($url, PHP_URL_HOST) ?? $url)), 180, ''),
                    'url' => $url,
                    'snippet' => Str::limit(trim((string) ($item['snippet'] ?? $item['description'] ?? '')), 500, ''),
                    'source' => 'web_search',
                    'query' => $query,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function discoverCandidateWebsiteWithWebSearch(
        array $unit,
        array &$candidate,
        LeadSegment $segment,
        array $settings,
        array &$summary,
    ): array {
        if (! $this->webSearchConfigured($settings)) {
            return ['contacts' => [], 'shared_email' => null, 'pages_scanned' => 0, 'urls' => []];
        }

        $query = $this->candidateWebsiteSearchQuery($unit, $candidate, $segment);
        $results = $this->fetchWebSearchResultsForQuery($query, $settings, $summary, min(5, (int) ($settings['web_search_results_per_query'] ?? 10)), [
            'company' => [
                'name' => data_get($candidate, 'company.name'),
                'org_no' => data_get($candidate, 'company.org_no'),
                'brreg_url' => data_get($candidate, 'company.source_url'),
                'municipality' => data_get($unit, 'forretningsadresse.kommune') ?: data_get($unit, 'postadresse.kommune'),
            ],
            'segment' => [
                'goal_prompt' => $segment->description,
                'target_roles' => $segment->target_roles_json ?: [],
                'keywords' => $segment->keywords_json ?: [],
            ],
        ]);
        $combined = ['contacts' => [], 'shared_email' => null, 'pages_scanned' => 0, 'urls' => [], 'web_search_results' => []];

        foreach ($results as $result) {
            $url = $this->normalizeUrl($result['url'] ?? null);

            if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $summary['web_search_results_seen']++;
            $combined['web_search_results'][] = $result;

            if (! data_get($candidate, 'company.website')) {
                data_set($candidate, 'company.website', $this->rootUrl($url));
            }

            $discovery = $this->discoverWebsiteContacts($url, $settings);
            $combined = $this->mergeWebsiteDiscoveries($combined, $discovery);

            if ($this->normalizeEmail($discovery['shared_email'] ?? null) || count($discovery['contacts'] ?? []) > 0) {
                if (! data_get($candidate, 'company.source_url')) {
                    data_set($candidate, 'company.source_url', $url);
                    data_set($candidate, 'company.source_title', $result['title'] ?? 'AI web search result');
                    data_set($candidate, 'company.source_type', $result['source'] ?? 'ai_web_search');
                }

                break;
            }
        }

        return $combined;
    }

    private function candidateWebsiteSearchQuery(array $unit, array $candidate, LeadSegment $segment): string
    {
        return Str::limit(trim(implode(' ', array_filter([
            data_get($candidate, 'company.name'),
            data_get($candidate, 'company.org_no'),
            data_get($unit, 'forretningsadresse.kommune') ?: data_get($unit, 'postadresse.kommune'),
            implode(' ', (array) ($segment->target_roles_json ?: [])),
            'offisiell hjemmeside kontakt ansatte daglig leder post info firmapost',
        ]))), 220, '');
    }

    private function mergeWebsiteDiscoveryIntoCandidate(array &$candidate, array $websiteDiscovery): void
    {
        $contacts = collect(array_merge((array) ($candidate['contacts'] ?? []), (array) ($websiteDiscovery['contacts'] ?? [])))
            ->filter(fn (array $contact): bool => filled($contact['email'] ?? null))
            ->unique(fn (array $contact): string => Str::lower((string) $contact['email']))
            ->values()
            ->all();

        $candidate['contacts'] = $contacts;

        if (! ($candidate['company']['shared_email'] ?? null) && $websiteDiscovery['shared_email']) {
            $candidate['company']['shared_email'] = $websiteDiscovery['shared_email'];
        }
    }

    private function mergeWebsiteDiscoveries(array $primary, array $secondary): array
    {
        return [
            'contacts' => collect(array_merge((array) ($primary['contacts'] ?? []), (array) ($secondary['contacts'] ?? [])))
                ->filter(fn (array $contact): bool => filled($contact['email'] ?? null))
                ->unique(fn (array $contact): string => Str::lower((string) $contact['email']))
                ->values()
                ->all(),
            'shared_email' => $primary['shared_email'] ?? $secondary['shared_email'] ?? null,
            'pages_scanned' => (int) ($primary['pages_scanned'] ?? 0) + (int) ($secondary['pages_scanned'] ?? 0),
            'urls' => collect(array_merge((array) ($primary['urls'] ?? []), (array) ($secondary['urls'] ?? [])))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'web_search_results' => collect(array_merge((array) ($primary['web_search_results'] ?? []), (array) ($secondary['web_search_results'] ?? [])))
                ->filter()
                ->unique('url')
                ->values()
                ->all(),
        ];
    }

    private function candidateFromWebSearchResult(array $result, array $websiteDiscovery, LeadSegment $segment): array
    {
        $url = $this->normalizeUrl($result['url'] ?? null);
        $host = $this->host($url);
        $companyName = $this->companyNameFromWebResult($result, $host);
        $sourceTitle = trim((string) ($result['title'] ?? $host ?? $url));
        $excerpt = trim((string) ($result['snippet'] ?? ''));

        return [
            'company' => [
                'name' => $companyName,
                'org_no' => null,
                'website' => $this->rootUrl($url),
                'shared_email' => $websiteDiscovery['shared_email'] ?? null,
                'source_type' => $result['source'] ?? 'web_search',
                'source_url' => $url,
                'source_title' => $sourceTitle ?: 'Web search result',
                'excerpt' => $excerpt ?: 'Web search result discovered by Lead Intelligence.',
                'score' => 65,
                'confidence' => 65,
            ],
            'contacts' => $websiteDiscovery['contacts'] ?? [],
            'segment' => [
                'target_roles' => $segment->target_roles_json ?: [],
            ],
        ];
    }

    private function companyNameFromWebResult(array $result, ?string $host): string
    {
        $title = trim((string) ($result['title'] ?? ''));

        if ($title !== '') {
            $title = preg_replace('/\s+[-|]\s+.*$/u', '', $title) ?: $title;
            $title = preg_replace('/\b(kontakt|contact|ansatte|medarbeidere|om oss)\b/i', '', $title) ?: $title;
            $title = trim(preg_replace('/\s+/', ' ', $title) ?: $title);
        }

        if ($title !== '') {
            return Str::limit($title, 180, '');
        }

        return $host ? Str::headline(Str::before($host, '.')) : 'Unknown web prospect';
    }

    private function reviewAndPromoteCandidate(
        LeadResearchRun $run,
        LeadSegment $segment,
        array $settings,
        LeadScanLedger $ledger,
        array $candidate,
        array $websiteDiscovery,
        array &$summary,
        array $sourceMeta,
    ): void {
        $review = $this->aiReviewer->handle($candidate, $segment, $run, $settings);
        $summary['ai_reviews'][] = [
            'org_no' => $sourceMeta['org_no'] ?? null,
            'name' => $sourceMeta['name'] ?? data_get($candidate, 'company.name'),
            'url' => $sourceMeta['url'] ?? data_get($candidate, 'company.source_url'),
            'used_ai' => (bool) ($review['used_ai'] ?? false),
            'status' => $review['status'] ?? null,
            'decision' => $review['decision'] ?? null,
            'company_score' => $review['company_score'] ?? null,
            'reason' => $review['reason'] ?? null,
        ];

        if (($review['used_ai'] ?? false) === true) {
            $summary['ai_reviewed']++;
        } elseif (($review['status'] ?? null) !== 'disabled') {
            $summary['ai_review_fallbacks']++;
        }

        if (($review['decision'] ?? 'promote') !== 'promote') {
            $summary['companies_skipped']++;
            $summary['skipped'][] = [
                'reason' => 'AI review did not approve automatic creation: '.($review['reason'] ?? 'No reason returned.'),
                'org_no' => $sourceMeta['org_no'] ?? null,
                'name' => $sourceMeta['name'] ?? data_get($candidate, 'company.name'),
                'org_form' => $sourceMeta['org_form'] ?? null,
                'url' => $sourceMeta['url'] ?? data_get($candidate, 'company.source_url'),
                'ai_decision' => $review['decision'] ?? null,
            ];

            $this->updateLedger($ledger, $sourceMeta['unit'] ?? [], $candidate, $websiteDiscovery, 'ai_'.$review['decision']);
            $this->persistProgress($run, $summary);

            return;
        }

        if (($review['used_ai'] ?? false) === true) {
            $candidate = $this->aiReviewer->apply($candidate, $review);
        }

        if (! $this->candidateHasContactEvidence($candidate)) {
            $summary['companies_skipped']++;
            $summary['skipped'][] = [
                'reason' => 'AI review removed all contactable evidence. Client was not created.',
                'org_no' => $sourceMeta['org_no'] ?? null,
                'name' => $sourceMeta['name'] ?? data_get($candidate, 'company.name'),
                'org_form' => $sourceMeta['org_form'] ?? null,
                'url' => $sourceMeta['url'] ?? data_get($candidate, 'company.source_url'),
            ];

            $this->updateLedger($ledger, $sourceMeta['unit'] ?? [], $candidate, $websiteDiscovery, 'ai_no_contact_email');
            $this->persistProgress($run, $summary);

            return;
        }

        try {
            $result = $this->promoteLeadCandidate->handle([
                'lead_research_run_id' => $run->id,
                'company' => $candidate['company'],
                'contacts' => $candidate['contacts'],
                'marketing_list_ids' => $segment->marketing_list_ids_json ?: [],
            ]);
        } catch (ValidationException $exception) {
            $summary['companies_skipped']++;
            $summary['skipped'][] = [
                'reason' => Arr::first(Arr::flatten($exception->errors())) ?: $exception->getMessage(),
                'org_no' => $sourceMeta['org_no'] ?? null,
                'name' => $sourceMeta['name'] ?? data_get($candidate, 'company.name'),
                'url' => $sourceMeta['url'] ?? data_get($candidate, 'company.source_url'),
            ];

            $this->updateLedger($ledger, $sourceMeta['unit'] ?? [], $candidate, $websiteDiscovery, 'skipped');
            $this->persistProgress($run, $summary);

            return;
        }

        $summary['companies_promoted']++;
        $summary['new_leads_created'] += $result['client_created'] ? 1 : 0;
        $summary['existing_clients_updated'] += $result['client_created'] ? 0 : 1;
        $summary['contacts_promoted'] += count($result['contacts'] ?? []);
        $summary['marketing_members_created'] += collect($result['contacts'] ?? [])
            ->sum(fn (array $contact): int => count($contact['marketing_list_member_ids'] ?? []));

        $this->updateLedger($ledger, $sourceMeta['unit'] ?? [], $candidate, $websiteDiscovery, 'completed');
        $this->persistProgress($run, $summary);
    }

    private function skipExistingClientCandidate(
        LeadResearchRun $run,
        LeadScanLedger $ledger,
        array $candidate,
        array $websiteDiscovery,
        array &$summary,
        array $sourceMeta,
    ): bool {
        $existingClient = $this->findExistingClientForCandidate((array) ($candidate['company'] ?? []));

        if (! $existingClient) {
            return false;
        }

        $summary['companies_skipped']++;
        $summary['existing_clients_skipped']++;
        $summary['skipped'][] = [
            'reason' => 'Client already exists in Nexum; skipped before website discovery and AI review to avoid repeat token usage.',
            'client_id' => $existingClient->id,
            'client_name' => $existingClient->name,
            'org_no' => $sourceMeta['org_no'] ?? data_get($candidate, 'company.org_no'),
            'name' => $sourceMeta['name'] ?? data_get($candidate, 'company.name'),
            'org_form' => $sourceMeta['org_form'] ?? null,
            'url' => $sourceMeta['url'] ?? data_get($candidate, 'company.source_url'),
        ];

        $this->updateLedger($ledger, $sourceMeta['unit'] ?? [], $candidate, $websiteDiscovery, 'existing_client_skipped', [
            'existing_client_id' => $existingClient->id,
            'existing_client_name' => $existingClient->name,
        ]);
        $this->persistProgress($run, $summary);

        return true;
    }

    private function findExistingClientForCandidate(array $company): ?Client
    {
        $orgNo = $this->normalizeOrgNo($company['org_no'] ?? null);
        $websiteHost = $this->host($company['website'] ?? null);
        $name = trim((string) ($company['name'] ?? ''));

        if ($orgNo) {
            $client = Client::query()->where('org_no', $orgNo)->first();

            if ($client) {
                return $client;
            }
        }

        if ($websiteHost) {
            $client = Client::query()
                ->whereNotNull('website')
                ->get()
                ->first(fn (Client $client): bool => $this->host($client->website) === $websiteHost);

            if ($client) {
                return $client;
            }
        }

        if ($name !== '') {
            return Client::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->first();
        }

        return null;
    }

    private function brregBaseUrl(array $settings): string
    {
        return rtrim((string) ($settings['brreg_base_url'] ?? self::BRREG_BASE_URL), '/');
    }

    private function discoverWebsiteContacts(?string $website, array $settings): array
    {
        if (! $website) {
            return ['contacts' => [], 'shared_email' => null, 'pages_scanned' => 0, 'urls' => []];
        }

        $urls = $this->websiteDiscoverySeeds($website);
        $contacts = [];
        $sharedEmail = null;
        $pagesScanned = 0;
        $maxPages = max(1, (int) $settings['max_pages_per_domain']);
        $scannedUrls = [];

        while ($urls !== [] && $pagesScanned < $maxPages) {
            $url = array_shift($urls);

            try {
                $response = Http::timeout(8)
                    ->withHeaders([
                        'User-Agent' => 'Nexum PSA Lead Intelligence',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    ])
                    ->get($url);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $pagesScanned++;
            $scannedUrls[] = $url;
            $html = (string) $response->body();

            foreach ($this->emailsFromHtml($html) as $email) {
                $emailType = $this->eligibilityEvaluator->classifyEmail($email);

                if (! $sharedEmail && in_array($emailType, ['generic_company', 'role_based'], true)) {
                    $sharedEmail = $email;
                }

                if ($emailType === 'generic_company') {
                    continue;
                }

                $contacts[$email] = [
                    'name' => $this->nameFromEmail($email),
                    'email' => $email,
                    'role' => $this->roleFromEmailAndHtml($email, $html),
                    'source_type' => 'website',
                    'source_url' => $url,
                    'source_title' => parse_url($url, PHP_URL_HOST) ?: $url,
                    'excerpt' => $this->emailExcerpt($email, $html),
                    'score' => 75,
                    'confidence' => 75,
                ];
            }

            foreach ($this->contactLinks($html, $url) as $link) {
                if (! in_array($link, $urls, true) && ! in_array($link, $scannedUrls, true)) {
                    array_unshift($urls, $link);
                    $urls = array_values(array_unique($urls));
                    $remainingSlots = max(0, $maxPages - $pagesScanned);
                    $urls = array_slice($urls, 0, $remainingSlots);
                }
            }
        }

        return [
            'contacts' => array_values($contacts),
            'shared_email' => $sharedEmail,
            'pages_scanned' => $pagesScanned,
            'urls' => $scannedUrls,
        ];
    }

    private function websiteDiscoverySeeds(string $website): array
    {
        $url = $this->normalizeUrl($website);

        if (! $url) {
            return [];
        }

        $root = $this->rootUrl($url);
        $haystack = Str::lower($url);
        $isLikelyContactPage = Str::contains($haystack, [
            'kontakt',
            'contact',
            'om-oss',
            'about',
            'ansatte',
            'team',
            'medarbeidere',
        ]);

        return collect($isLikelyContactPage ? [$url, $root] : [$root, $url])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function emailsFromHtml(string $html): array
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $decoded, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $email): ?string => $this->normalizeEmail($email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function contactLinks(string $html, string $baseUrl): array
    {
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match) use ($baseUrl): ?string {
                $href = trim((string) ($match[1] ?? ''));
                $label = Str::lower(strip_tags((string) ($match[2] ?? '')));
                $candidate = Str::lower($href.' '.$label);

                if (! Str::contains($candidate, ['kontakt', 'contact', 'om-oss', 'about', 'ansatte', 'team', 'medarbeidere'])) {
                    return null;
                }

                return $this->absoluteUrl($href, $baseUrl);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function absoluteUrl(string $href, string $baseUrl): ?string
    {
        if ($href === '' || Str::startsWith($href, ['mailto:', 'tel:', '#'])) {
            return null;
        }

        if (Str::startsWith($href, ['http://', 'https://'])) {
            return $href;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        if (! $host) {
            return null;
        }

        if (Str::startsWith($href, '//')) {
            return $scheme.':'.$href;
        }

        if (Str::startsWith($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        $path = isset($parts['path']) ? rtrim(dirname($parts['path']), '/\\') : '';

        return $scheme.'://'.$host.($path ? '/'.$path : '').'/'.$href;
    }

    private function ledgerForUnit(array $unit): LeadScanLedger
    {
        $orgNo = preg_replace('/\D+/', '', (string) ($unit['organisasjonsnummer'] ?? '')) ?: null;
        $website = $this->normalizeUrl($unit['hjemmeside'] ?? null);
        $domain = $this->host($website);

        $query = LeadScanLedger::query();

        if ($orgNo) {
            $existing = (clone $query)->where('org_no', $orgNo)->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($domain) {
            $existing = (clone $query)->where('domain', $domain)->first();

            if ($existing) {
                return $existing;
            }
        }

        return new LeadScanLedger([
            'org_no' => $orgNo,
            'domain' => $domain,
            'url' => $website,
        ]);
    }

    private function ledgerForUrl(string $url): LeadScanLedger
    {
        $website = $this->rootUrl($url) ?: $this->normalizeUrl($url);
        $domain = $this->host($website);

        if ($domain) {
            $existing = LeadScanLedger::query()->where('domain', $domain)->first();

            if ($existing) {
                return $existing;
            }
        }

        return new LeadScanLedger([
            'domain' => $domain,
            'url' => $website,
        ]);
    }

    private function updateLedger(LeadScanLedger $ledger, array $unit, array $candidate, array $websiteDiscovery, string $status, array $extraMetadata = []): void
    {
        $settings = $this->settings->get();
        $orgNo = preg_replace('/\D+/', '', (string) ($unit['organisasjonsnummer'] ?? '')) ?: null;
        $website = $candidate['company']['website'] ?? null;
        $resultHash = sha1(json_encode([
            'unit' => Arr::only($unit, ['organisasjonsnummer', 'navn', 'hjemmeside', 'epostadresse', 'naeringskode1']),
            'contacts' => $candidate['contacts'],
            'shared_email' => $candidate['company']['shared_email'] ?? null,
        ]));

        $ledger->forceFill([
            'org_no' => $ledger->org_no ?: $orgNo,
            'domain' => $ledger->domain ?: $this->host($website),
            'url' => $ledger->url ?: $website,
            'last_scanned_at' => now(),
            'next_scan_after' => now()->addDays((int) $settings['default_rescan_days']),
            'last_result_hash' => $resultHash,
            'pages_scanned' => (int) ($websiteDiscovery['pages_scanned'] ?? 0),
            'tokens_used' => 0,
            'status' => $status,
            'metadata' => array_merge([
                'source' => 'lead_intelligence',
                'brreg_name' => $unit['navn'] ?? null,
                'brreg_source_url' => data_get($unit, '_links.self.href'),
                'website_urls_scanned' => $websiteDiscovery['urls'] ?? [],
                'web_search_results' => $websiteDiscovery['web_search_results'] ?? [],
            ], $extraMetadata),
        ])->save();
    }

    private function unitSearchText(array $unit): string
    {
        return $this->normalizeText(implode(' ', array_filter([
            $unit['navn'] ?? null,
            data_get($unit, 'naeringskode1.kode'),
            data_get($unit, 'naeringskode1.beskrivelse'),
            implode(' ', (array) ($unit['aktivitet'] ?? [])),
            data_get($unit, 'forretningsadresse.kommune'),
            data_get($unit, 'postadresse.kommune'),
        ])));
    }

    private function companyExcerpt(array $unit): string
    {
        return trim(implode(' ', array_filter([
            data_get($unit, 'forretningsadresse.kommune') ?: data_get($unit, 'postadresse.kommune'),
            data_get($unit, 'naeringskode1.kode'),
            data_get($unit, 'naeringskode1.beskrivelse'),
            implode(' ', (array) ($unit['aktivitet'] ?? [])),
        ])));
    }

    private function roleFromEmailAndHtml(string $email, string $html): ?string
    {
        $local = Str::before($email, '@');
        $normalizedLocal = $this->normalizeText($local);

        if (Str::contains($normalizedLocal, ['it'])) {
            return 'IT';
        }

        if (Str::contains($normalizedLocal, ['innkjop', 'innkjøp'])) {
            return 'innkjøp';
        }

        if (Str::contains($normalizedLocal, ['okonomi', 'økonomi', 'regnskap', 'faktura'])) {
            return 'økonomi';
        }

        if (Str::contains($normalizedLocal, ['salg', 'marked'])) {
            return 'marked/salg';
        }

        $excerpt = $this->normalizeText($this->emailExcerpt($email, $html));

        foreach (['daglig leder', 'ceo', 'general manager', 'it', 'innkjop', 'innkjøp', 'okonomi', 'økonomi', 'kontorleder', 'marked', 'salg'] as $role) {
            if (str_contains($excerpt, $this->normalizeText($role))) {
                return match ($this->normalizeText($role)) {
                    'ceo', 'general manager' => 'daglig leder',
                    'okonomi' => 'økonomi',
                    'innkjop' => 'innkjøp',
                    'marked', 'salg' => 'marked/salg',
                    default => $role,
                };
            }
        }

        return null;
    }

    private function emailExcerpt(string $email, string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: '';
        $position = stripos($text, $email);

        if ($position === false) {
            return Str::limit($text, 240, '');
        }

        return trim(Str::substr($text, max(0, $position - 120), 240));
    }

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $name = preg_replace('/[._+\-]+/', ' ', $local) ?: $local;

        return Str::headline($name);
    }

    private function normalizeOrgNo(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizedList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            function (mixed $item): string {
                $normalized = $this->normalizeText((string) $item);

                return in_array($normalized, ['alle', 'all', 'any', 'uansett', 'alle bransjer', 'alle industrier'], true)
                    ? ''
                    : $normalized;
            },
            (array) $value,
        )));
    }

    private function normalizedCodeList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): string => Str::upper(trim((string) $item)),
            (array) $value,
        )));
    }

    private function normalizeMunicipalityTerm(string $value): string
    {
        $value = $this->normalizeText($value);
        $value = preg_replace('/\b(kommune|municipality|hele|i|og)\b/u', ' ', $value) ?: $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
    }

    private function normalizeText(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9æøå\s@.\-]+/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim((string) $value));
        $email = trim($email, " \t\n\r\0\x0B.,;:()[]<>");

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        return Str::startsWith($url, ['http://', 'https://']) ? $url : 'https://'.$url;
    }

    private function rootUrl(?string $url): ?string
    {
        $url = $this->normalizeUrl($url);

        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        return $host ? $scheme.'://'.$host : $url;
    }

    private function host(mixed $value): ?string
    {
        $url = $this->normalizeUrl($value);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $host ? Str::lower(preg_replace('/^www\./', '', $host) ?: $host) : null;
    }
}
