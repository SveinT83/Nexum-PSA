<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;

class EmailSmartInboxSuggestionEventRecorder
{
    /**
     * Append a bounded immutable snapshot. The snapshot deliberately excludes
     * raw AI/provider material and contains only the normalized suggestion.
     */
    public function record(
        EmailSmartInboxSuggestion $suggestion,
        string $eventType,
        ?User $actor,
        ?array $before = null,
        ?string $reasonCode = null,
    ): EmailSmartInboxSuggestionEvent {
        $now = now();

        return EmailSmartInboxSuggestionEvent::query()->create([
            'email_smart_inbox_suggestion_id' => $suggestion->id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'from_status' => $before['status'] ?? null,
            'to_status' => $suggestion->status,
            'reason_code' => $reasonCode,
            'before_json' => $before,
            'after_json' => $this->snapshot($suggestion),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    public function snapshot(EmailSmartInboxSuggestion $suggestion): array
    {
        return [
            'status' => (string) $suggestion->status,
            'effect_type' => (string) $suggestion->effect_type,
            'proposal' => (array) $suggestion->proposal_json,
            'explanation' => $suggestion->explanation,
            'confidence' => $suggestion->confidence,
            'source_fingerprint' => (string) $suggestion->source_fingerprint,
            'source_fingerprint_schema' => $suggestion->source_fingerprint_schema
                ?: EmailConversationFingerprint::LEGACY_SCHEMA_VERSION,
            'corrected_at' => $suggestion->corrected_at?->toIso8601String(),
            'dismissed_at' => $suggestion->dismissed_at?->toIso8601String(),
            'stale_at' => $suggestion->stale_at?->toIso8601String(),
            'revoked_at' => $suggestion->revoked_at?->toIso8601String(),
            'applied_at' => $suggestion->applied_at?->toIso8601String(),
            'applied_reference_type' => $suggestion->applied_reference_type,
            'applied_reference_id' => $suggestion->applied_reference_id,
        ];
    }
}
