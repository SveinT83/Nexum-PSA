<?php

namespace App\Modules\Email\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailSmartInboxSuggestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'account_id' => (int) $this->account_id,
            'email_conversation_id' => (int) $this->email_conversation_id,
            'selected_email_mailbox_placement_id' => $this->selected_email_mailbox_placement_id
                ? (int) $this->selected_email_mailbox_placement_id
                : null,
            'effect_type' => (string) $this->effect_type,
            'proposal' => (array) $this->proposal_json,
            'explanation' => $this->explanation,
            'confidence' => $this->confidence,
            'status' => (string) $this->status,
            'schema_version' => (string) $this->schema_version,
            'source_message_ids' => array_values(array_map('intval', $this->source_message_ids_json ?? [])),
            'provenance' => [
                'execution_id' => $this->ai_execution_id,
                'agent' => $this->whenLoaded('aiAgent', fn (): ?array => $this->aiAgent ? [
                    'id' => (int) $this->aiAgent->id,
                    'name' => (string) $this->aiAgent->name,
                ] : null),
                'model' => $this->ai_model,
                'policy_revision' => $this->ai_policy_revision,
            ],
            'applied_reference' => $this->applied_reference_type ? [
                'type' => (string) $this->applied_reference_type,
                'id' => (string) $this->applied_reference_id,
            ] : null,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'corrected_at' => $this->corrected_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'stale_at' => $this->stale_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'id' => (int) $event->id,
                'event_type' => (string) $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => (string) $event->to_status,
                'reason_code' => $event->reason_code,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
