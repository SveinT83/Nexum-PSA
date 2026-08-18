<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailRemoteOperationAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_number' => $this->attempt_number,
            'attempt_kind' => $this->attempt_kind,
            'trigger' => $this->trigger,
            'triggered_by' => $this->triggered_by,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'failure_classification' => $this->failure_classification,
            'reason_code' => $this->reason_code,
            'reason_message' => $this->reason_message,
            'request' => $this->request_json,
            'response' => $this->response_json,
            'error' => $this->error_json,
            'started_at' => $this->started_at,
            'provider_started_at' => $this->provider_started_at,
            'provider_finished_at' => $this->provider_finished_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
