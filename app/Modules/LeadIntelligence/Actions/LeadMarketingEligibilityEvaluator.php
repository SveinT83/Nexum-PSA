<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use App\Modules\LeadIntelligence\Models\ContactMarketingEligibility;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;
use App\Modules\LeadIntelligence\Models\MarketingSuppressionEntry;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LeadMarketingEligibilityEvaluator
{
    private const GENERIC_LOCALS = [
        'post',
        'info',
        'firmapost',
        'kontakt',
        'mail',
        'office',
        'kundeservice',
    ];

    private const ROLE_BASED_LOCALS = [
        'innkjop',
        'innkjøp',
        'it',
        'support',
        'booking',
        'okonomi',
        'økonomi',
        'regnskap',
        'faktura',
        'kontor',
        'salg',
        'marked',
    ];

    private const PRIVATE_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'hotmail.com',
        'hotmail.no',
        'outlook.com',
        'outlook.no',
        'icloud.com',
        'me.com',
        'live.com',
        'live.no',
        'msn.com',
        'yahoo.com',
        'yahoo.no',
        'proton.me',
        'protonmail.com',
        'online.no',
    ];

    public function __construct(private readonly LeadIntelligenceSettings $settings)
    {
    }

    public function evaluate(
        Contact $contact,
        ?Client $client = null,
        array $settings = [],
        ?LeadSourceEvidence $evidence = null,
    ): array {
        $settings = $this->settings->normalize($settings ?: $this->settings->get());
        $email = $this->primaryEmail($contact);
        $emailType = $this->classifyEmail($email);
        $role = trim((string) $contact->job_title) ?: null;
        $reason = null;
        $eligible = true;
        $requiredReview = false;

        if (! $email) {
            return $this->result(false, 'unknown', 'Contact has no email address.', [], true, null, $role);
        }

        if ($contact->do_not_email) {
            return $this->result(false, $emailType, 'Contact is marked do not email.', [], false, $email, $role);
        }

        if ($this->isSuppressed($email, $contact, $client)) {
            return $this->result(false, $emailType, 'Email, domain, contact, or client is suppressed.', [], false, $email, $role);
        }

        if ($emailType === 'private' && $settings['never_auto_use_private_email_domains']) {
            $eligible = false;
            $requiredReview = true;
            $reason = 'Private email domains are not auto eligible.';
        }

        if ($eligible && $emailType === 'generic_company' && ! $settings['allow_generic_company_emails']) {
            $eligible = false;
            $requiredReview = true;
            $reason = 'Generic company emails are not allowed by Lead Intelligence settings.';
        }

        if ($eligible && $emailType === 'role_based' && ! $settings['allow_role_based_emails']) {
            $eligible = false;
            $requiredReview = true;
            $reason = 'Role-based emails are not allowed by Lead Intelligence settings.';
        }

        if ($eligible && $emailType === 'named_work' && ! $settings['allow_named_work_emails']) {
            $eligible = false;
            $requiredReview = true;
            $reason = 'Named work emails are not allowed by Lead Intelligence settings.';
        }

        if ($eligible && $settings['require_source_url_for_contacts'] && ! $evidence?->source_url) {
            $eligible = false;
            $requiredReview = true;
            $reason = 'Contact requires source evidence with a URL.';
        }

        if ($eligible && $emailType === 'named_work' && $settings['require_role_for_named_contacts']) {
            if (! $role) {
                $eligible = false;
                $requiredReview = true;
                $reason = 'Named work contact requires a role.';
            } elseif (! $this->roleAllowed($role, $settings['allowed_roles'])) {
                $eligible = false;
                $requiredReview = true;
                $reason = 'Named work contact role is outside allowed roles.';
            }
        }

        if ($eligible && $evidence) {
            $scoreResult = $this->scoreResult($evidence, $settings);

            if (! $scoreResult['eligible']) {
                $eligible = false;
                $requiredReview = true;
                $reason = $scoreResult['reason'];
            }
        }

        return $this->result(
            $eligible,
            $emailType,
            $reason ?: 'Eligible under current Lead Intelligence settings.',
            $eligible ? $this->recommendedMarketingLists($settings, $evidence) : [],
            $requiredReview,
            $email,
            $role,
        );
    }

    public function evaluateAndPersist(
        Contact $contact,
        ?Client $client = null,
        ?LeadSourceEvidence $evidence = null,
        array $settings = [],
    ): ContactMarketingEligibility {
        $result = $this->evaluate($contact, $client, $settings, $evidence);
        $existing = ContactMarketingEligibility::query()
            ->where('contact_id', $contact->id)
            ->where('email', $result['email'])
            ->first();
        $metadata = (array) $existing?->metadata;
        $recommendedMarketingLists = $result['eligible']
            ? $this->mergeListIds(
                (array) ($metadata['recommended_marketing_lists'] ?? []),
                (array) $result['recommended_marketing_lists'],
            )
            : [];

        return ContactMarketingEligibility::query()->updateOrCreate(
            [
                'contact_id' => $contact->id,
                'email' => $result['email'],
            ],
            [
                'client_id' => $client?->id,
                'email_type' => $result['email_type'],
                'role' => $result['role'],
                'eligible' => $result['eligible'],
                'reason' => $result['reason'],
                'source_evidence_id' => $evidence?->id,
                'evaluated_at' => now(),
                'metadata' => array_merge($metadata, [
                    'recommended_marketing_lists' => $recommendedMarketingLists,
                    'required_review' => $result['required_review'],
                ]),
            ],
        );
    }

    private function mergeListIds(array $existing, array $current): array
    {
        return collect([...$existing, ...$current])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function classifyEmail(?string $email): string
    {
        $email = Str::lower(trim((string) $email));

        if (! str_contains($email, '@')) {
            return 'unknown';
        }

        [$local, $domain] = explode('@', $email, 2);
        $local = trim($local);
        $domain = trim($domain);
        $normalizedLocal = $this->normalize($local);

        if ($domain === '' || $local === '') {
            return 'unknown';
        }

        if (in_array($domain, self::PRIVATE_DOMAINS, true)) {
            return 'private';
        }

        if (in_array($normalizedLocal, array_map([$this, 'normalize'], self::GENERIC_LOCALS), true)) {
            return 'generic_company';
        }

        if (in_array($normalizedLocal, array_map([$this, 'normalize'], self::ROLE_BASED_LOCALS), true)) {
            return 'role_based';
        }

        return 'named_work';
    }

    private function primaryEmail(Contact $contact): ?string
    {
        $email = $contact->relationLoaded('emails')
            ? $contact->emails->sortByDesc('is_primary')->first()
            : $contact->emails()->orderByDesc('is_primary')->first();

        return $email?->email ? Str::lower(trim($email->email)) : null;
    }

    private function isSuppressed(string $email, Contact $contact, ?Client $client): bool
    {
        $domain = Str::after($email, '@');

        return MarketingSuppressionEntry::query()
            ->where(function ($query) use ($email, $domain, $contact, $client): void {
                $query->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhereRaw('LOWER(domain) = ?', [$domain])
                    ->orWhere('contact_id', $contact->id);

                if ($client) {
                    $query->orWhere('client_id', $client->id);
                }
            })
            ->exists();
    }

    private function scoreResult(LeadSourceEvidence $evidence, array $settings): array
    {
        $metadata = (array) $evidence->metadata;
        $companyScore = Arr::get($metadata, 'company_score');
        $contactScore = Arr::get($metadata, 'contact_score', $evidence->confidence);

        if (is_numeric($companyScore) && (int) $companyScore < $settings['minimum_company_score']) {
            return [
                'eligible' => false,
                'reason' => 'Company score is below the configured minimum.',
            ];
        }

        if (is_numeric($contactScore) && (int) $contactScore < $settings['minimum_contact_score']) {
            return [
                'eligible' => false,
                'reason' => 'Contact score is below the configured minimum.',
            ];
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function recommendedMarketingLists(array $settings, ?LeadSourceEvidence $evidence): array
    {
        if (! $settings['auto_add_to_marketing_lists']) {
            return [];
        }

        $ids = Arr::get((array) $evidence?->metadata, 'marketing_list_ids', []);

        if ($ids === [] && $evidence?->lead_research_run_id) {
            $ids = $evidence->researchRun()
                ->with('segment')
                ->first()
                ?->segment
                ?->marketing_list_ids_json ?: [];
        }

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function roleAllowed(string $role, array $allowedRoles): bool
    {
        $role = $this->normalize($role);

        foreach ($allowedRoles as $allowedRole) {
            $allowed = $this->normalize((string) $allowedRole);

            if ($allowed !== '' && (str_contains($role, $allowed) || str_contains($allowed, $role))) {
                return true;
            }
        }

        return false;
    }

    private function result(
        bool $eligible,
        string $emailType,
        string $reason,
        array $recommendedMarketingLists,
        bool $requiredReview,
        ?string $email,
        ?string $role,
    ): array {
        return [
            'eligible' => $eligible,
            'email_type' => $emailType,
            'reason' => $reason,
            'recommended_marketing_lists' => $recommendedMarketingLists,
            'required_review' => $requiredReview,
            'email' => $email,
            'role' => $role,
        ];
    }

    private function normalize(string $value): string
    {
        return Str::ascii(Str::lower(trim($value)));
    }
}
