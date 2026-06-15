<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Models\ContactMarketingEligibility;

class PlanMarketingListPromotion
{
    public function handle(ContactMarketingEligibility $eligibility): array
    {
        $metadata = (array) $eligibility->metadata;
        $listIds = array_values(array_unique(array_map(
            'intval',
            (array) ($metadata['recommended_marketing_lists'] ?? []),
        )));
        $requiresReview = (bool) ($metadata['required_review'] ?? false);

        if (! $eligibility->eligible) {
            return $this->blocked('Contact is not marketing eligible.', $listIds);
        }

        if ($requiresReview) {
            return $this->blocked('Contact requires manual review before marketing promotion.', $listIds);
        }

        if ($listIds === []) {
            return $this->blocked('No recommended marketing lists were returned by policy.', $listIds);
        }

        return [
            'can_promote' => true,
            'marketing_list_ids' => $listIds,
            'blocked_reason' => null,
            'contact_id' => $eligibility->contact_id,
            'client_id' => $eligibility->client_id,
            'email' => $eligibility->email,
        ];
    }

    private function blocked(string $reason, array $listIds): array
    {
        return [
            'can_promote' => false,
            'marketing_list_ids' => $listIds,
            'blocked_reason' => $reason,
        ];
    }
}

