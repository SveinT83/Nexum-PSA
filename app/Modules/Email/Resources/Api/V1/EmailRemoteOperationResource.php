<?php

namespace App\Modules\Email\Resources\Api\V1;

use App\Modules\Email\Services\EmailRemoteOperationEvidenceSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailRemoteOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sanitizer = app(EmailRemoteOperationEvidenceSanitizer::class);

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'email_folder_id' => $this->email_folder_id,
            'email_mailbox_placement_id' => $this->email_mailbox_placement_id,
            'requested_by' => $this->requested_by,
            'provider' => $this->provider,
            'operation_type' => $this->operation_type,
            'status' => $this->status,
            'inverse_of_email_remote_operation_id' => $this->inverse_of_email_remote_operation_id,
            'inverse_operation_id' => $this->resource->relationLoaded('inverseOperation')
                ? $this->inverseOperation?->id
                : null,
            'source_folder_path' => $this->source_folder_path,
            'target_folder_path' => $this->target_folder_path,
            'request' => $sanitizer->sanitize($this->request_json ?? []),
            'provider_response' => $sanitizer->sanitize($this->provider_response_json ?? []),
            'result_snapshot' => $this->result_snapshot_json,
            'result_snapshot_captured_at' => $this->result_snapshot_captured_at,
            'undo_verified_at' => $this->undo_verified_at,
            'attempts' => $this->attempts,
            'provider_attempt_count' => $this->providerAttemptCount(),
            'max_attempts' => $this->max_attempts,
            'next_attempt_at' => $this->next_attempt_at,
            'last_attempt_at' => $this->last_attempt_at,
            'failure_classification' => $this->failure_classification,
            'status_reason_code' => $this->status_reason_code,
            'status_reason_message' => $this->status_reason_message,
            'reconciliation_required_at' => $this->reconciliation_required_at,
            'reconciled_at' => $this->reconciled_at,
            'started_at' => $this->started_at,
            'acknowledged_at' => $this->acknowledged_at,
            'failed_at' => $this->failed_at,
            'cancelled_at' => $this->cancelled_at,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'attempt_records' => EmailRemoteOperationAttemptResource::collection($this->whenLoaded('attemptRecords')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
