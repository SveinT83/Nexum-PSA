<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use App\Modules\Commercial\Support\ContractCustomerDocument;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class CommercialContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $documentReadiness = [
            'ready' => true,
            'code' => 'ready',
            'message' => null,
        ];

        try {
            $documents = app(ContractCustomerDocument::class);
            $customerDocument = $documents->resolve($this->resource);
        } catch (DomainException|UnexpectedValueException) {
            $message = 'Kundedokumentet krever manuell verifisering; ingen live fallback ble returnert.';

            // One historical row must not make an otherwise valid paginated
            // admin listing unusable. Detail reads still fail closed with 409.
            if (! $this->allowsUnavailableDocumentInCollection($request)) {
                abort(409, $message);
            }

            $customerDocument = null;
            $documentReadiness = [
                'ready' => false,
                'code' => 'manual_verification_required',
                'message' => $message,
            ];
        }

        // Do not present mutable live economics as the customer-document total
        // when historical evidence is unavailable.
        $pricing = $customerDocument['totals'] ?? null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'sla_id' => $this->sla_id,
            'created_by' => $this->created_by,
            'description' => $this->description,
            'approval_status' => $this->approval_status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'binding_end_date' => $this->binding_end_date,
            'auto_renew' => $this->auto_renew,
            'renewal_months' => $this->renewal_months,
            'allow_indexing_during_binding' => $this->allow_indexing_during_binding,
            'allow_decrease_during_binding' => $this->allow_decrease_during_binding,
            'max_index_pct_binding' => $this->max_index_pct_binding,
            'post_binding_index_pct' => $this->post_binding_index_pct,
            'total_monthly_amount' => $pricing['monthly']['decimal'] ?? null,
            'pricing' => $pricing,
            'customer_document' => $customerDocument,
            'customer_document_readiness' => $documentReadiness,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'client_number' => $this->client?->client_number,
            ]),
            'sla' => $this->whenLoaded('sla', fn () => [
                'id' => $this->sla?->id,
                'name' => $this->sla?->name,
                'is_default' => $this->sla?->is_default,
            ]),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function allowsUnavailableDocumentInCollection(Request $request): bool
    {
        $routeName = (string) ($request->route()?->getName() ?? '');

        return str_ends_with($routeName, 'commercial.contracts.index');
    }
}
