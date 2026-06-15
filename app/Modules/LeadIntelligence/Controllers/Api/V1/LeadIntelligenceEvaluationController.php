<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use App\Modules\LeadIntelligence\Actions\LeadMarketingEligibilityEvaluator;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadIntelligenceEvaluationController extends Controller
{
    public function evaluateContact(Request $request, LeadMarketingEligibilityEvaluator $evaluator): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', Rule::exists('contacts', 'id')],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'source_evidence_id' => ['nullable', Rule::exists('lead_source_evidence', 'id')],
        ]);

        $contact = Contact::query()->with('emails')->findOrFail($validated['contact_id']);
        $client = isset($validated['client_id']) ? Client::query()->findOrFail($validated['client_id']) : null;
        $evidence = $this->resolveEvidence($validated, $contact, $client);
        $eligibility = $evaluator->evaluateAndPersist($contact, $client, $evidence);

        return response()->json([
            'data' => [
                'eligibility_id' => $eligibility->id,
                'contact_id' => $contact->id,
                'client_id' => $client?->id,
                'source_evidence_id' => $evidence?->id,
                'eligible' => (bool) $eligibility->eligible,
                'email_type' => $eligibility->email_type,
                'reason' => $eligibility->reason,
                'recommended_marketing_lists' => $eligibility->metadata['recommended_marketing_lists'] ?? [],
                'required_review' => (bool) ($eligibility->metadata['required_review'] ?? false),
                'email' => $eligibility->email,
                'role' => $eligibility->role,
            ],
        ]);
    }

    private function resolveEvidence(array $validated, Contact $contact, ?Client $client): ?LeadSourceEvidence
    {
        if (! empty($validated['source_evidence_id'])) {
            $evidence = $this->evidenceScopedToContact($contact, $client)
                ->whereKey($validated['source_evidence_id'])
                ->first();

            if (! $evidence) {
                throw ValidationException::withMessages([
                    'source_evidence_id' => 'The selected source evidence does not belong to the selected contact or client.',
                ]);
            }

            return $evidence;
        }

        return $this->evidenceScopedToContact($contact, $client)
            ->latest()
            ->first();
    }

    private function evidenceScopedToContact(Contact $contact, ?Client $client): Builder
    {
        return LeadSourceEvidence::query()
            ->where(function ($query) use ($contact): void {
                $query->where('contact_id', $contact->id)
                    ->orWhereNull('contact_id');
            })
            ->when($client, function ($query) use ($client): void {
                $query->where(function ($inner) use ($client): void {
                    $inner->where('client_id', $client->id)
                        ->orWhereNull('client_id');
                });
            }, function ($query) use ($contact): void {
                $query->where(function ($inner) use ($contact): void {
                    $inner->whereNull('client_id')
                        ->orWhere('contact_id', $contact->id);
                });
            });
    }
}
