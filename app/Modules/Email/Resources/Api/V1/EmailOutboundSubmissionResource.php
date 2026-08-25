<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailOutboundSubmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'draft_id' => $this->relationLoaded('draft') ? $this->draft?->public_id : null,
            'account_id' => $this->email_account_id ? (int) $this->email_account_id : null,
            'mode' => $this->mode,
            'caller_channel' => $this->caller_channel,
            'signature_source' => $this->signature_source,
            'status' => $this->status,
            'result_code' => $this->result_code,
            'reason_code' => $this->reason_code,
            'message_id' => $this->reserved_message_id,
            'provider_write_started_at' => $this->provider_write_started_at,
            'accepted_at' => $this->accepted_at,
            'reconciled_at' => $this->reconciled_at,
            'email_log' => $this->relationLoaded('emailLog') && $this->emailLog
                ? [
                    'direction' => $this->emailLog->direction,
                    'level' => $this->emailLog->level,
                    'code' => $this->emailLog->code,
                    'delivery_status' => data_get($this->emailLog->context_json, 'smtp_delivery.status'),
                    'sent_status' => data_get($this->emailLog->context_json, 'provider_sent.status'),
                ]
                : null,
            'sent_reconciliation' => $this->relationLoaded('sentReconciliation')
                ? [
                    'status' => $this->sentReconciliation?->status,
                    'candidate_count' => $this->sentReconciliation?->candidate_count,
                    'last_checked_at' => $this->sentReconciliation?->last_checked_at,
                    'reconciled_at' => $this->sentReconciliation?->reconciled_at,
                ]
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
