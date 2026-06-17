<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Contact\Models\Contact;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromoteLeadCandidate
{
    public function __construct(
        private readonly LeadIntelligenceSettings $settings,
        private readonly LeadMarketingEligibilityEvaluator $eligibilityEvaluator,
        private readonly PlanMarketingListPromotion $promotionPlanner,
        private readonly StoreContact $storeContact,
        private readonly SuggestClientNumber $suggestClientNumber,
    ) {
    }

    public function handle(array $payload): array
    {
        $settings = $this->settings->get();
        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $company = (array) $payload['company'];
        $run = isset($payload['lead_research_run_id'])
            ? LeadResearchRun::query()->with('segment')->find($payload['lead_research_run_id'])
            : null;
        $marketingListIds = $this->marketingListIds($payload, $run);

        if (! $settings['enabled']) {
            throw ValidationException::withMessages([
                'settings' => 'Lead Intelligence must be enabled before promoting candidates.',
            ]);
        }

        $existingClient = $this->findExistingClient($company);

        if (! $existingClient && ! $settings['auto_create_clients']) {
            throw ValidationException::withMessages([
                'company' => 'Lead Intelligence settings do not allow automatic Client creation.',
            ]);
        }

        if ($dryRun) {
            return $this->dryRunResult($company, $payload, $existingClient, $settings, $marketingListIds);
        }

        return DB::transaction(function () use ($company, $payload, $settings, $existingClient, $run, $marketingListIds): array {
            $client = $existingClient ?: $this->createClient($company, $settings);
            $this->fillMissingClientFields($client, $company);
            $site = $this->defaultSite($client);
            $companyEvidence = $this->storeEvidence($company, $run, $client, null, $marketingListIds, 'company');
            $contacts = [];

            if ($settings['auto_create_contacts']) {
                foreach ($this->contactCandidates($payload, $company, $client) as $candidate) {
                    $contact = $this->storeContactForClient($client, $site, $candidate);
                    $evidence = $this->storeEvidence($candidate, $run, $client, $contact, $marketingListIds, 'contact', $companyEvidence);
                    $eligibility = $this->eligibilityEvaluator->evaluateAndPersist($contact->load('emails'), $client, $evidence, $settings);
                    $promotion = $this->promotionPlanner->handle($eligibility);
                    $members = $this->promoteToMarketingLists($promotion, $contact, $client, $eligibility->id, $evidence->id);

                    $contacts[] = [
                        'contact_id' => $contact->id,
                        'email' => $eligibility->email,
                        'eligible' => (bool) $eligibility->eligible,
                        'email_type' => $eligibility->email_type,
                        'required_review' => (bool) ($eligibility->metadata['required_review'] ?? false),
                        'reason' => $eligibility->reason,
                        'marketing_list_member_ids' => $members,
                    ];
                }
            }

            return [
                'client_id' => $client->id,
                'client_created' => ! $existingClient,
                'client_name' => $client->name,
                'source_evidence_id' => $companyEvidence->id,
                'contacts' => $contacts,
                'contacts_skipped_reason' => $settings['auto_create_contacts'] ? null : 'Automatic Contact creation is disabled.',
                'marketing_list_ids' => $marketingListIds,
            ];
        });
    }

    private function dryRunResult(array $company, array $payload, ?Client $existingClient, array $settings, array $marketingListIds): array
    {
        $contacts = collect($this->contactCandidates($payload, $company, $existingClient))
            ->map(fn (array $candidate): array => [
                'name' => $this->contactName($candidate, $existingClient),
                'email' => $this->normalizeEmail($candidate['email'] ?? null),
                'email_type' => $this->eligibilityEvaluator->classifyEmail($candidate['email'] ?? null),
                'would_create_contact' => $settings['auto_create_contacts'],
            ])
            ->values()
            ->all();

        return [
            'dry_run' => true,
            'client_id' => $existingClient?->id,
            'client_created' => ! $existingClient,
            'client_name' => $existingClient?->name ?: trim((string) $company['name']),
            'contacts' => $contacts,
            'contacts_skipped_reason' => $settings['auto_create_contacts'] ? null : 'Automatic Contact creation is disabled.',
            'marketing_list_ids' => $marketingListIds,
        ];
    }

    private function createClient(array $company, array $settings): Client
    {
        $payload = [
            'name' => trim((string) $company['name']),
            'org_no' => $this->normalizeOrgNo($company['org_no'] ?? null),
            'website' => $this->normalizeUrl($company['website'] ?? null),
            'billing_email' => $this->normalizeEmail($company['billing_email'] ?? $company['email'] ?? null),
            'lead_temperature' => 3,
            'notes' => trim(implode("\n", array_filter([
                'Created by Lead Intelligence.',
                'Lead status: '.$settings['default_client_status'],
                filled($company['source_url'] ?? null) ? 'Source: '.$company['source_url'] : null,
            ]))),
            'active' => true,
        ];
        $lastException = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Client::query()->create(array_merge($payload, [
                    'client_number' => $this->suggestClientNumber->handle(),
                ]));
            } catch (QueryException $exception) {
                if (! $this->isClientNumberDuplicate($exception)) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw $lastException ?: ValidationException::withMessages([
            'client_number' => 'Unable to generate a unique client number.',
        ]);
    }

    private function fillMissingClientFields(Client $client, array $company): void
    {
        $updates = [];

        foreach ([
            'org_no' => $this->normalizeOrgNo($company['org_no'] ?? null),
            'website' => $this->normalizeUrl($company['website'] ?? null),
            'billing_email' => $this->normalizeEmail($company['billing_email'] ?? $company['email'] ?? null),
        ] as $field => $value) {
            if (blank($client->{$field}) && filled($value)) {
                $updates[$field] = $value;
            }
        }

        if ($updates !== []) {
            $client->forceFill($updates)->save();
        }
    }

    private function defaultSite(Client $client): ClientSite
    {
        return $client->sites()
            ->where('is_default', true)
            ->orderBy('name')
            ->first()
            ?: ClientSite::query()->create([
                'client_id' => $client->id,
                'name' => 'Main Office',
                'is_default' => true,
            ]);
    }

    private function storeContactForClient(Client $client, ClientSite $site, array $candidate): Contact
    {
        return $this->storeContact->handle([
            'type' => $candidate['type'] ?? 'person',
            'status' => 'active',
            'display_name' => $this->contactName($candidate, $client),
            'organization_name' => $client->name,
            'job_title' => $candidate['role'] ?? $candidate['job_title'] ?? null,
            'email' => $this->normalizeEmail($candidate['email'] ?? null),
            'phone' => $candidate['phone'] ?? null,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'relation_type' => 'lead_contact',
            'update_existing' => false,
        ]);
    }

    private function storeEvidence(
        array $source,
        ?LeadResearchRun $run,
        Client $client,
        ?Contact $contact,
        array $marketingListIds,
        string $kind,
        ?LeadSourceEvidence $companyEvidence = null,
    ): LeadSourceEvidence {
        $companyMetadata = (array) $companyEvidence?->metadata;

        return LeadSourceEvidence::query()->create([
            'lead_research_run_id' => $run?->id,
            'client_id' => $client->id,
            'contact_id' => $contact?->id,
            'source_type' => $source['source_type'] ?? 'lead_intelligence',
            'source_url' => $source['source_url'] ?? $companyEvidence?->source_url,
            'source_title' => $source['source_title'] ?? $companyEvidence?->source_title,
            'excerpt' => $source['excerpt'] ?? $companyEvidence?->excerpt,
            'confidence' => (int) ($source['confidence'] ?? $source['score'] ?? 0),
            'metadata' => [
                'kind' => $kind,
                'marketing_list_ids' => $marketingListIds,
                'company_score' => (int) ($source['company_score'] ?? $source['score'] ?? $companyMetadata['company_score'] ?? 0),
                'contact_score' => (int) ($source['contact_score'] ?? $source['score'] ?? $source['confidence'] ?? 0),
                'parent_source_evidence_id' => $companyEvidence?->id,
            ],
        ]);
    }

    private function promoteToMarketingLists(array $promotion, Contact $contact, Client $client, int $eligibilityId, int $evidenceId): array
    {
        if (! ($promotion['can_promote'] ?? false)) {
            return [];
        }

        return collect($promotion['marketing_list_ids'])
            ->filter(fn (int $listId): bool => $this->marketingListAllowsContact($listId, $contact))
            ->map(function (int $listId) use ($contact, $client, $promotion, $eligibilityId, $evidenceId): int {
                $member = MarketingListMember::query()->updateOrCreate(
                    [
                        'marketing_list_id' => $listId,
                        'source_type' => 'contact',
                        'source_id' => $contact->id,
                    ],
                    [
                        'contact_id' => $contact->id,
                        'client_id' => $client->id,
                        'email' => $promotion['email'],
                        'name' => $contact->display_name,
                        'status' => 'eligible',
                        'metadata' => [
                            'source' => 'lead_intelligence',
                            'eligibility_id' => $eligibilityId,
                            'source_evidence_id' => $evidenceId,
                        ],
                    ],
                );

                return $member->id;
            })
            ->all();
    }

    private function marketingListAllowsContact(int $listId, Contact $contact): bool
    {
        $list = MarketingList::query()->find($listId);

        if (! $list) {
            return false;
        }

        $excludedContactIds = collect(($list->segment_criteria ?? [])['excluded_contact_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->all();

        return ! in_array((int) $contact->id, $excludedContactIds, true);
    }

    private function findExistingClient(array $company): ?Client
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

    private function contactCandidates(array $payload, array $company, ?Client $client): array
    {
        $contacts = collect((array) ($payload['contacts'] ?? []))
            ->filter(fn (array $contact): bool => filled($contact['email'] ?? null) || filled($contact['name'] ?? null))
            ->values();
        $sharedEmail = $this->normalizeEmail($company['shared_email'] ?? $company['email'] ?? $company['billing_email'] ?? null);

        if ($sharedEmail && ! $contacts->contains(fn (array $contact): bool => $this->normalizeEmail($contact['email'] ?? null) === $sharedEmail)) {
            $contacts->push([
                'name' => ($client?->name ?: trim((string) $company['name'])).' shared mailbox',
                'email' => $sharedEmail,
                'role' => 'felles e-post',
                'type' => 'organization',
                'source_url' => $company['source_url'] ?? null,
                'source_title' => $company['source_title'] ?? null,
                'excerpt' => $company['excerpt'] ?? null,
                'confidence' => $company['confidence'] ?? null,
                'score' => $company['score'] ?? null,
            ]);
        }

        return $contacts->all();
    }

    private function marketingListIds(array $payload, ?LeadResearchRun $run): array
    {
        $ids = (array) ($payload['marketing_list_ids'] ?? []);

        if ($ids === [] && $run?->segment) {
            $ids = $run->segment->marketing_list_ids_json ?: [];
        }

        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function contactName(array $candidate, ?Client $client): string
    {
        $name = trim((string) ($candidate['name'] ?? $candidate['display_name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        $email = $this->normalizeEmail($candidate['email'] ?? null);

        if ($email) {
            return Str::before($email, '@');
        }

        return ($client?->name ?: 'Lead contact').' contact';
    }

    private function normalizeOrgNo(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim((string) $value));

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

    private function host(mixed $value): ?string
    {
        $url = $this->normalizeUrl($value);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $host ? Str::lower(preg_replace('/^www\./', '', $host) ?: $host) : null;
    }

    private function isClientNumberDuplicate(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'clients_client_number_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, 'client_number'))
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'clients.client_number'));
    }
}
