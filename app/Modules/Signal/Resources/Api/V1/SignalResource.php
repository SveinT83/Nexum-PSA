<?php

namespace App\Modules\Signal\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_domain' => $this->source_domain,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'contact_id' => $this->contact_id,
            'client_id' => $this->client_id,
            'signal_type' => $this->signal_type,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'status' => $this->status,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at,
            'created_at' => $this->created_at,
            'executions_count' => $this->whenCounted('executions'),
            'links' => [
                'admin' => route('tech.admin.system.signals.show', $this->id),
            ],
        ];
    }
}
