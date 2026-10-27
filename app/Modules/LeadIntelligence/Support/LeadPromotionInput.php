<?php

namespace App\Modules\LeadIntelligence\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Frozen validated promotion contract, shared by claim and execution. */
class LeadPromotionInput
{
    public static function validate(Request $request): array
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'lead_research_run_id' => ['nullable', Rule::exists('lead_research_runs', 'id')],
            'marketing_list_ids' => ['nullable', 'array'],
            'marketing_list_ids.*' => ['integer', Rule::exists('marketing_lists', 'id')],
            'company' => ['required', 'array:name,org_no,website,email,shared_email,billing_email,source_type,source_url,source_title,excerpt,confidence,score,company_score'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.org_no' => ['nullable', 'string', 'max:50'],
            'company.website' => ['nullable', 'string', 'max:255'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.shared_email' => ['nullable', 'email', 'max:255'],
            'company.billing_email' => ['nullable', 'email', 'max:255'],
            'company.source_type' => ['nullable', 'string', 'max:100'],
            'company.source_url' => ['nullable', 'string', 'max:2000'],
            'company.source_title' => ['nullable', 'string', 'max:255'],
            'company.excerpt' => ['nullable', 'string', 'max:5000'],
            'company.confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'company.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'company.company_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts' => ['nullable', 'array', 'max:100'],
            'contacts.*' => ['array:name,display_name,email,role,job_title,phone,type,source_type,source_url,source_title,excerpt,confidence,score,contact_score,company_score'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.display_name' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:255'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.type' => ['nullable', 'string', 'max:50'],
            'contacts.*.source_type' => ['nullable', 'string', 'max:100'],
            'contacts.*.source_url' => ['nullable', 'string', 'max:2000'],
            'contacts.*.source_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.excerpt' => ['nullable', 'string', 'max:5000'],
            'contacts.*.confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.contact_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.company_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return $validated;
    }

    /** Read provider-owned durable intent rather than guessing from exception text. */
    public static function pendingProvider(string $commandId, int $actorId): bool
    {
        $key = hash_hmac('sha256', $actorId.':'.$commandId, config('app.key'));

        return \App\Modules\DataExchange\Models\TripletexCustomerLink::where('request_key', $key)
            ->whereNull('client_id')->whereIn('status', ['sending', 'verifying', 'verified'])->exists();
    }

    /** Overlapping company identities keep edited input/new keys behind unresolved provider intent. */
    public static function targets(Request $request): array
    {
        $company = $request->input('company', []);
        $website = trim((string) ($company['website'] ?? ''));
        if ($website && ! str_contains($website, '://')) {
            $website = 'https://'.$website;
        }
        $values = [
            'org' => preg_replace('/\D/', '', (string) ($company['org_no'] ?? '')),
            'host' => preg_replace('/^www\./', '', strtolower((string) parse_url($website, PHP_URL_HOST))),
            'name' => mb_strtolower(trim((string) ($company['name'] ?? ''))),
        ];
        $result = [];
        foreach ($values as $kind => $value) {
            if ($value !== '') {
                $result[] = hash_hmac('sha256', $kind.':'.$value, config('app.key'));
            }
        }

        return $result;
    }
}
