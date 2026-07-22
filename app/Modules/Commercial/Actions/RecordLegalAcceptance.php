<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Terms\LegalAcceptanceEvent;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use Illuminate\Http\Request;

class RecordLegalAcceptance
{
    public function forContract(
        Request $request,
        CustomerPortalContext $context,
        Contracts $contract,
        string $confirmedByName,
    ): LegalAcceptanceEvent {
        $contract->loadMissing('termSnapshots');
        $documents = $contract->termSnapshots
            ->sortBy('id')
            ->map(fn ($snapshot): array => [
                'term_id' => $snapshot->term_id,
                'term_version_id' => $snapshot->term_version_id,
                'name' => $snapshot->name,
                'version_label' => $snapshot->version_label,
                'checksum' => $snapshot->checksum,
            ])
            ->values()
            ->all();

        return $this->create(
            $request,
            $context,
            'contract_acceptance',
            $contract,
            null,
            null,
            null,
            null,
            null,
            $confirmedByName,
            $documents,
        );
    }

    public function forLicence(
        Request $request,
        CustomerPortalContext $context,
        string $action,
        ContractItem $item,
        Offer $offer,
        ?Subscription $subscription,
        int $quantity,
        ?int $previousQuantity,
        string $confirmedByName,
    ): LegalAcceptanceEvent {
        $item->loadMissing(['contract', 'service.serviceTerms.currentVersion']);
        $documents = $item->service->serviceTerms
            ->map(function ($term): array {
                $version = $term->currentVersion;

                return [
                    'term_id' => $term->id,
                    'term_version_id' => $version?->id,
                    'name' => $version?->name ?? $term->name,
                    'version_label' => $version?->version_label,
                    'checksum' => $version?->checksum,
                    'origin' => $term->origin,
                    'issuer' => $version?->issuer ?? $term->issuer,
                    'source_url' => $version?->source_url ?? $term->source_url,
                ];
            })
            ->sortBy(['origin', 'name'])
            ->values()
            ->all();

        return $this->create(
            $request,
            $context,
            $action,
            $item->contract,
            $item,
            $offer,
            $subscription,
            $quantity,
            $previousQuantity,
            $confirmedByName,
            $documents,
        );
    }

    private function create(
        Request $request,
        CustomerPortalContext $context,
        string $action,
        Contracts $contract,
        ?ContractItem $item,
        ?Offer $offer,
        ?Subscription $subscription,
        ?int $quantity,
        ?int $previousQuantity,
        string $confirmedByName,
        array $documents,
    ): LegalAcceptanceEvent {
        $evidence = [
            'action' => $action,
            'client_id' => $context->client->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item?->id,
            'service_id' => $item?->service_id,
            'cloudfactory_offer_id' => $offer?->id,
            'cloudfactory_subscription_id' => $subscription?->id,
            'quantity' => $quantity,
            'previous_quantity' => $previousQuantity,
            'unit_price' => $item?->unit_price,
            'currency' => $offer?->currency,
            'commitment' => [
                'recurrence_term' => $offer?->recurrence_term,
                'billing_term' => $offer?->billing_term,
                'commitment_start_date' => $item?->commitment_start_date?->toDateString(),
                'commitment_end_date' => $item?->commitment_end_date?->toDateString(),
            ],
            'documents' => $documents,
            'confirmed_by_name' => $confirmedByName,
            'confirmed_at' => now()->toIso8601String(),
        ];

        return LegalAcceptanceEvent::query()->create([
            'client_id' => $context->client->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item?->id,
            'service_id' => $item?->service_id,
            'cloudfactory_offer_id' => $offer?->id,
            'cloudfactory_subscription_id' => $subscription?->id,
            'customer_portal_account_id' => $context->account->id,
            'customer_portal_membership_id' => $context->membership->id,
            'contact_id' => $context->contact->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'status' => 'recorded',
            'confirmed_by_name' => $confirmedByName,
            'term_version_ids' => collect($documents)->pluck('term_version_id')->filter()->values()->all(),
            'evidence' => $evidence,
            'evidence_hash' => hash('sha256', json_encode(
                $evidence,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )),
            'quantity' => $quantity,
            'previous_quantity' => $previousQuantity,
            'unit_price' => $item?->unit_price,
            'currency' => $offer?->currency,
            'confirmed_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
